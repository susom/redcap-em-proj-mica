<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\CanonicalJson;
use Stanford\MICA\TranscriptException;

#[CoversClass(CanonicalJson::class)]
final class CanonicalJsonTest extends TestCase
{
    public function testKeyOrderDoesNotChangeTheHash(): void
    {
        // The whole point. The finalizer and any later re-derivation must agree byte for byte, and
        // PHP array literal order is not something to depend on across refactors.
        $a = ['b' => 1, 'a' => 2, 'nested' => ['z' => 3, 'y' => 4]];
        $b = ['a' => 2, 'nested' => ['y' => 4, 'z' => 3], 'b' => 1];

        $this->assertSame(CanonicalJson::encode($a), CanonicalJson::encode($b));
        $this->assertSame(
            CanonicalJson::hash(CanonicalJson::encode($a)),
            CanonicalJson::hash(CanonicalJson::encode($b))
        );
    }

    public function testListOrderIsPreserved(): void
    {
        // messages[] IS the conversation. Sorting it would reorder the transcript.
        $encoded = CanonicalJson::encode(['messages' => [['sequence' => 1], ['sequence' => 2]]]);

        $this->assertSame('{"messages":[{"sequence":1},{"sequence":2}]}', $encoded);
        $this->assertNotSame(
            $encoded,
            CanonicalJson::encode(['messages' => [['sequence' => 2], ['sequence' => 1]]]),
            'reversing the conversation must not produce the same bytes'
        );
    }

    public function testUnicodeIsNotEscaped(): void
    {
        $encoded = CanonicalJson::encode(['content' => 'caña 日本語 🙂']);

        $this->assertStringContainsString('caña 日本語 🙂', $encoded);
        $this->assertStringNotContainsString('\\u', $encoded);
    }

    public function testSlashesAreNotEscaped(): void
    {
        $this->assertSame('{"u":"a/b"}', CanonicalJson::encode(['u' => 'a/b']));
    }

    public function testInvalidUtf8IsAnExceptionNotTheStringFalse(): void
    {
        try {
            CanonicalJson::encode(['content' => "valid\xB1\x31invalid"]);
            $this->fail('json_encode returning false must never reach a hash');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('valid UTF-8', $e->getMessage(), 'name the likely cause');
        }
    }

    public function testHashIsSha256OfTheExactBytes(): void
    {
        $canonical = CanonicalJson::encode(['a' => 1]);

        $this->assertSame(hash('sha256', $canonical), CanonicalJson::hash($canonical));
        $this->assertSame(64, strlen(CanonicalJson::hash($canonical)));
    }

    #[DataProvider('chunkingCases')]
    public function testChunkRoundTripsAndKeepsTheHash(string $label, string $payload, int $max): void
    {
        $chunks = CanonicalJson::chunk($payload, $max);
        $rejoined = CanonicalJson::join($chunks);

        $this->assertSame($payload, $rejoined, "$label: chunk/join must be lossless");
        $this->assertSame(
            CanonicalJson::hash($payload),
            CanonicalJson::hash($rejoined),
            "$label: the hash is computed over the re-joined string, so it must survive"
        );

        foreach ($chunks as $i => $chunk) {
            $this->assertLessThanOrEqual($max, strlen($chunk), "$label: chunk $i exceeds the byte limit");
        }
    }

    public static function chunkingCases(): array
    {
        // 4-byte characters, so a naive byte offset lands mid-sequence on 3 out of 4 alignments.
        $emoji = str_repeat('🙂', 40);
        $mixed = str_repeat('a', 31) . str_repeat('日', 40) . str_repeat('b', 17);

        return [
            'ascii, exact multiple'      => ['ascii', str_repeat('a', 64), 16],
            'ascii, ragged tail'         => ['ragged', str_repeat('a', 70), 16],
            'shorter than one chunk'     => ['short', 'hello', 4096],
            'four-byte characters'       => ['emoji', $emoji, 17],
            'mixed widths'               => ['mixed', $mixed, 19],
            'boundary lands mid-char'    => ['straddle', str_repeat('a', 15) . '🙂' . str_repeat('b', 15), 16],
        ];
    }

    public function testNoChunkEverSplitsACharacter(): void
    {
        // The failure this guards against is not visible in a PHP round trip - concatenation
        // restores the bytes either way. It shows up when each chunk is stored in a utf8 TEXT
        // column, where a truncated sequence does not survive. So assert on the chunks themselves.
        $payload = str_repeat('aé日🙂', 200);

        foreach (CanonicalJson::chunk($payload, 33) as $i => $chunk) {
            $this->assertSame(
                $chunk,
                mb_convert_encoding($chunk, 'UTF-8', 'UTF-8'),
                "chunk $i is not independently valid UTF-8, so it cannot survive a TEXT column"
            );
            $this->assertNotFalse(mb_check_encoding($chunk, 'UTF-8'), "chunk $i has a split character");
        }
    }

    public function testSixtyKilobyteDefaultFitsTheTextColumn(): void
    {
        // TEXT is 65,535 bytes. The margin is for the snap-back and any write-path overhead.
        $this->assertLessThan(65535, CanonicalJson::CHUNK_BYTES);
        $this->assertGreaterThan(32768, CanonicalJson::CHUNK_BYTES, 'needlessly many rows otherwise');
    }

    public function testAnAbsurdlySmallChunkSizeIsRejected(): void
    {
        // Below the longest UTF-8 sequence there is no guarantee of forward progress, and a silent
        // infinite loop inside a finalize is much worse than an exception.
        $this->expectException(\InvalidArgumentException::class);
        CanonicalJson::chunk('anything', 4);
    }

    public function testEmptyStringChunksToOneEmptyChunk(): void
    {
        $this->assertSame([''], CanonicalJson::chunk(''));
    }

    public function testLogParameterNamesMatchTheDataModel(): void
    {
        $params = CanonicalJson::asLogParameters(['one', 'two', 'three']);

        $this->assertSame(
            ['payload_json' => 'one', 'payload_json_2' => 'two', 'payload_json_3' => 'three'],
            $params,
            '02-data-model.md §1.2: payload_json, payload_json_2..n - the first has no suffix'
        );
    }

    public function testSingleChunkUsesTheUnsuffixedNameOnly(): void
    {
        $this->assertSame(['payload_json' => 'x'], CanonicalJson::asLogParameters(['x']));
    }

    public function testLogParametersRoundTrip(): void
    {
        $payload = CanonicalJson::encode(['messages' => [['content' => str_repeat('日', 30000)]]]);
        $params = CanonicalJson::asLogParameters(CanonicalJson::chunk($payload));

        $this->assertGreaterThan(1, count($params), 'this fixture must actually exercise chunking');
        $this->assertSame($payload, CanonicalJson::fromLogParameters($params));
    }

    public function testAMissingFirstChunkIsAnError(): void
    {
        $this->expectException(TranscriptException::class);
        $this->expectExceptionMessageMatches("/no 'payload_json' parameter/");
        CanonicalJson::fromLogParameters(['payload_json_2' => 'orphan']);
    }

    public function testAGapInTheChunksIsAnErrorNotAShortString(): void
    {
        // Reading up to the first gap and returning quietly would yield a payload that fails its
        // own hash check later, surfacing in Stage 4 as an unexplained corrupt transcript.
        $this->expectException(TranscriptException::class);
        $this->expectExceptionMessageMatches('/missing chunk payload_json_3/');

        CanonicalJson::fromLogParameters([
            'payload_json'   => 'a',
            'payload_json_2' => 'b',
            // payload_json_3 absent
            'payload_json_4' => 'd',
        ]);
    }

    public function testChunkedPayloadKeepsItsHashThroughTheFullWriteReadCycle(): void
    {
        // The invariant that matters end to end: hash(encode) == hash(fromLogParameters(write)).
        $payload = ['messages' => []];
        for ($i = 1; $i <= 60; $i++) {
            $payload['messages'][] = [
                'message_id' => "L$i",
                'sequence'   => $i,
                // Mixed 1/2/3/4-byte characters, so chunk boundaries land on every alignment.
                'content'    => str_repeat('caña 日本語 🙂 ', 200),
            ];
        }

        $canonical = CanonicalJson::encode($payload);
        $stored = CanonicalJson::asLogParameters(CanonicalJson::chunk($canonical));

        $this->assertGreaterThan(2, count($stored), 'fixture should span several chunks');
        $this->assertSame(CanonicalJson::hash($canonical), CanonicalJson::hash(
            CanonicalJson::fromLogParameters($stored)
        ));
    }
}
