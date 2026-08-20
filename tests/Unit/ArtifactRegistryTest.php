<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactIntegrityException;
use Stanford\MICA\ArtifactRegistry;

/**
 * The whole value of hash-pinning is the refusal, so most of this file is about the failure paths.
 * The one thing every test asserts, whatever the flavour of corruption, is that nothing is
 * returned: a caller must never receive an artifact the registry could not vouch for.
 */
#[CoversClass(ArtifactRegistry::class)]
final class ArtifactRegistryTest extends TestCase
{
    private const HANDOFF = __DIR__ . '/../../handoff';

    /** @var string[] temp directories to remove after each test */
    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $dir) {
            // scandir, not glob() - glob skips dotfiles, and one of these fixtures plants a
            // .DS_Store, which would leave the directory non-empty and rmdir() warning.
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
                unlink("$dir/$f");
            }
            rmdir($dir);
        }
        $this->temps = [];
    }

    // ---------------------------------------------------------------- the real vendored artifacts

    public function testEveryVendoredArtifactMatchesItsPin(): void
    {
        $registry = new ArtifactRegistry(self::HANDOFF);

        $manifest = json_decode((string) file_get_contents(self::HANDOFF . '/manifest.json'), true);
        $this->assertCount(9, $manifest['artifacts'], 'the handoff pins 9 artifacts');

        foreach ($manifest['artifacts'] as $name => $entry) {
            $this->assertSame(
                $entry['sha256'],
                $registry->getHash($name),
                "computed hash of '$name' differs from the manifest"
            );
            $this->assertNotSame('', $registry->getText($name), "'$name' loaded empty");
        }
    }

    public function testVerifyAllPassesOnTheVendoredDirectory(): void
    {
        $registry = new ArtifactRegistry(self::HANDOFF);
        $registry->verifyAll();
        $this->assertCount(9, $registry->names());
    }

    /**
     * Guards the artifacts the R01 architecture actually turns on: the counselor contract must
     * still reject live-safety fields, and the prompt must still be the post-session one.
     */
    public function testCounselorSchemaStillRejectsLiveSafetyRouting(): void
    {
        $schema = (new ArtifactRegistry(self::HANDOFF))->getJson('counselor_output_schema');

        $this->assertFalse($schema['additionalProperties'], 'additionalProperties must stay false');
        $this->assertSame(
            ['assistant_text', 'next_phase', 'response_strategy', 'end_session'],
            $schema['required']
        );
        $this->assertArrayNotHasKey('safety_flag', $schema['properties']);
        $this->assertArrayNotHasKey('escalation', $schema['properties']);
    }

    #[DataProvider('jsonArtifacts')]
    public function testJsonArtifactsDecode(string $name): void
    {
        $decoded = (new ArtifactRegistry(self::HANDOFF))->getJson($name);
        $this->assertNotEmpty($decoded);
    }

    public static function jsonArtifacts(): array
    {
        $manifest = json_decode((string) file_get_contents(self::HANDOFF . '/manifest.json'), true);
        $cases = [];
        foreach ($manifest['artifacts'] as $name => $entry) {
            if ($entry['type'] === 'json') {
                $cases[$name] = [$name];
            }
        }
        return $cases;
    }

    // ------------------------------------------------------------------------------ failure paths

    public function testTamperedArtifactFailsClosed(): void
    {
        $dir  = $this->copyHandoff();
        $file = $dir . '/MICA_Prompt_R01_v2_postsession_safety.txt';

        // One byte. Nothing a reader would notice; the point is that the code does.
        $body = (string) file_get_contents($file);
        file_put_contents($file, substr($body, 0, -1) . 'X');

        $registry = new ArtifactRegistry($dir);

        try {
            $registry->getText('counselor_prompt');
            $this->fail('a tampered prompt was served instead of throwing');
        } catch (ArtifactIntegrityException $e) {
            $this->assertStringContainsString('does not match its pin', $e->getMessage());
        }

        // Every accessor must refuse, not just the one that happened to notice first.
        $this->expectException(ArtifactIntegrityException::class);
        $registry->getHash('counselor_prompt');
    }

    public function testTruncatedArtifactFailsClosed(): void
    {
        $dir = $this->copyHandoff();
        file_put_contents($dir . '/MICA_wrapper_schema_v2.json', '');

        $this->expectException(ArtifactIntegrityException::class);
        // Must fail on the *pin*, not incidentally on the JSON decode - an empty file would throw
        // either way, so without this the test would still pass with integrity checking removed.
        $this->expectExceptionMessageMatches('/does not match its pin/');
        (new ArtifactRegistry($dir))->getJson('wrapper_schema');
    }

    public function testUnpinnedFileInTheDirectoryFailsClosed(): void
    {
        $dir = $this->copyHandoff();
        file_put_contents($dir . '/MICA_Prompt_R01_v3_smuggled.txt', 'you are a different counselor');

        $this->expectException(ArtifactIntegrityException::class);
        $this->expectExceptionMessageMatches('/Unpinned file/');
        (new ArtifactRegistry($dir))->verifyAll();
    }

    public function testOperatingSystemJunkDoesNotFailTheDirectoryCheck(): void
    {
        // A developer opening handoff/ in Finder must not be able to block session start once
        // verifyAll() is wired into the Stage 6 launch-readiness gate.
        $dir = $this->copyHandoff();
        file_put_contents($dir . '/.DS_Store', "\x00\x01junk");

        (new ArtifactRegistry($dir))->verifyAll();
        $this->assertFileExists($dir . '/.DS_Store', 'the check must not delete anything either');
    }

    public function testMissingFileFailsClosed(): void
    {
        $dir = $this->copyHandoff();
        unlink($dir . '/MICA_SafetyScan_PostSession_prompt.txt');

        $this->expectException(ArtifactIntegrityException::class);
        $this->expectExceptionMessageMatches('/missing or unreadable/');
        (new ArtifactRegistry($dir))->getText('safetyscan_prompt');
    }

    public function testUnknownArtifactNameFailsClosed(): void
    {
        $this->expectException(ArtifactIntegrityException::class);
        $this->expectExceptionMessageMatches('/No pinned artifact named/');
        (new ArtifactRegistry(self::HANDOFF))->getText('counselor_prompt_v3');
    }

    public function testMissingManifestFailsClosed(): void
    {
        $dir = $this->copyHandoff();
        unlink($dir . '/manifest.json');

        $this->expectException(ArtifactIntegrityException::class);
        $this->expectExceptionMessageMatches('/manifest is missing or unreadable/');
        (new ArtifactRegistry($dir))->getText('counselor_prompt');
    }

    #[DataProvider('malformedManifests')]
    public function testMalformedManifestFailsClosed(string $manifest, string $expect): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/manifest.json', $manifest);
        file_put_contents($dir . '/prompt.txt', 'hello');

        $this->expectException(ArtifactIntegrityException::class);
        $this->expectExceptionMessageMatches($expect);
        (new ArtifactRegistry($dir))->getText('p');
    }

    public static function malformedManifests(): array
    {
        // Each case is the single `p` pin, mutated one way. json_encode rather than hand-written
        // JSON so the mutation under test is the only thing that differs from a valid manifest.
        $pin = static fn(array $entry): string => json_encode(['artifacts' => ['p' => $entry]]);
        $sha = hash('sha256', 'hello');

        return [
            'not json'          => ['{ not json', '/not a JSON object/'],
            'no artifacts key'  => ['{"source":{}}', '/not a JSON object/'],
            'artifacts not map' => ['{"artifacts":"nope"}', '/not a JSON object/'],
            'pins nothing'      => ['{"artifacts":{}}', '/pins no artifacts/'],
            'entry has no file' => [$pin(['type' => 'text', 'sha256' => $sha]), "/missing 'file'/"],
            'entry has no hash' => [$pin(['file' => 'prompt.txt', 'type' => 'text']), "/missing 'sha256'/"],
            'short hash'        => [$pin(self::pin(['sha256' => 'abc'])), '/malformed sha256/'],
            'unknown type'      => [$pin(self::pin(['type' => 'yaml'])), '/unknown type/'],
            // A pin that can reach outside handoff/ describes a file the deployer never reviewed.
            'path traversal'    => [$pin(self::pin(['file' => '../MICA.php'])), '/points outside/'],
        ];
    }

    /** A valid pin for `prompt.txt` ("hello"), with the field under test overridden. */
    private static function pin(array $override): array
    {
        return $override + ['file' => 'prompt.txt', 'type' => 'text', 'sha256' => hash('sha256', 'hello')];
    }

    public function testJsonRequestedForATextArtifactIsACallerBug(): void
    {
        // Distinct from an integrity failure: the artifact is fine, the caller is wrong.
        $this->expectException(\InvalidArgumentException::class);
        (new ArtifactRegistry(self::HANDOFF))->getJson('counselor_prompt');
    }

    // ------------------------------------------------------------------------------------ caching

    public function testArtifactIsReadFromDiskOnlyOnce(): void
    {
        $dir      = $this->copyHandoff();
        $registry = new ArtifactRegistry($dir);
        $first    = $registry->getText('counselor_prompt');

        // Corrupt the file *after* a successful verified load. A cached artifact was already
        // vouched for, so serving it again is correct; re-reading and re-verifying would be a
        // pointless per-turn cost on the counselor's hot path.
        file_put_contents($dir . '/MICA_Prompt_R01_v2_postsession_safety.txt', 'gutted');

        $this->assertSame($first, $registry->getText('counselor_prompt'));
        $this->assertSame(hash('sha256', $first), $registry->getHash('counselor_prompt'));

        // ...but a fresh registry (i.e. the next request) must catch it.
        $this->expectException(ArtifactIntegrityException::class);
        (new ArtifactRegistry($dir))->getText('counselor_prompt');
    }

    public function testDefaultDirectoryIsTheVendoredHandoff(): void
    {
        // No constructor argument: the module's own artifacts must resolve.
        $this->assertSame(
            (new ArtifactRegistry(self::HANDOFF))->getHash('counselor_prompt'),
            (new ArtifactRegistry())->getHash('counselor_prompt')
        );
    }

    // ------------------------------------------------------------------------------------ helpers

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mica-artifacts-' . bin2hex(random_bytes(6));
        mkdir($dir);
        return $this->temps[] = $dir;
    }

    private function copyHandoff(): string
    {
        $dir = $this->tempDir();
        foreach (glob(self::HANDOFF . '/*') ?: [] as $file) {
            copy($file, $dir . '/' . basename($file));
        }
        return $dir;
    }
}
