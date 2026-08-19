<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ArtifactIntegrityException.php";

/**
 * Read access to the hash-pinned handoff artifacts in `handoff/`.
 *
 * The R01 handoff freezes the counselor prompt, the SafetyScan prompt, and seven JSON schemas.
 * "Frozen" only means something if the running code checks, so every read recomputes the SHA-256
 * of the bytes it just read and compares it to `handoff/manifest.json`. A difference is not
 * recoverable and not repairable at runtime: it means the artifact on disk is not the artifact the
 * research team validated, so the class throws and returns nothing.
 *
 * The hash returned by getHash() is always the one computed from the loaded bytes, never the
 * manifest's copy - it is persisted onto `mica_turn` / `mica_scan_run` rows as the record of what
 * actually produced a given turn, so it has to describe reality rather than intent.
 *
 * Framework-free by design (see 06-implementation-plan/README.md, cross-cutting decision 1): no
 * REDCap bootstrap, no module handle, no logging. It throws; the caller decides what that means.
 */
class ArtifactRegistry
{
    private const MANIFEST = 'manifest.json';

    private string $dir;

    /** @var array<string,array{file:string,type:string,sha256:string}>|null decoded manifest artifacts */
    private ?array $pinned = null;

    /** @var array<string,string> logical name => raw file contents, loaded at most once per request */
    private array $contents = [];

    /** @var array<string,string> logical name => SHA-256 actually computed at load */
    private array $hashes = [];

    /** @var array<string,array> logical name => decoded JSON */
    private array $decoded = [];

    public function __construct(?string $handoffDir = null)
    {
        $this->dir = rtrim($handoffDir ?? __DIR__ . '/../handoff', '/');
    }

    /**
     * Raw contents of an artifact (prompt files, and the raw bytes of a schema if ever needed).
     *
     * @throws ArtifactIntegrityException
     */
    public function getText(string $name): string
    {
        $this->load($name);
        return $this->contents[$name];
    }

    /**
     * Decoded contents of a JSON artifact.
     *
     * @throws ArtifactIntegrityException      if the artifact cannot be vouched for, or is pinned
     *                                         as JSON but does not decode to an array
     * @throws \InvalidArgumentException        if the artifact is not pinned as JSON (caller bug)
     */
    public function getJson(string $name): array
    {
        $this->load($name);

        if (isset($this->decoded[$name])) {
            return $this->decoded[$name];
        }

        $type = $this->pinned[$name]['type'];
        if ($type !== 'json') {
            throw new \InvalidArgumentException("Artifact '$name' is pinned as '$type', not json");
        }

        $decoded = json_decode($this->contents[$name], true);
        if (!is_array($decoded)) {
            // The bytes matched their pin, so this is a packaging error rather than tampering -
            // but it is still an artifact we cannot use, so it fails the same way.
            throw new ArtifactIntegrityException(
                "Artifact '$name' has a matching hash but is not decodable JSON: " . json_last_error_msg()
            );
        }

        return $this->decoded[$name] = $decoded;
    }

    /**
     * SHA-256 of the bytes actually loaded. This is the value written to turn/scan rows.
     *
     * @throws ArtifactIntegrityException
     */
    public function getHash(string $name): string
    {
        $this->load($name);
        return $this->hashes[$name];
    }

    /** Logical names of every pinned artifact. */
    public function names(): array
    {
        return array_keys($this->manifest());
    }

    /**
     * Load and verify every pinned artifact, and additionally refuse any *unpinned* file sitting in
     * `handoff/`. Used by the test suite and available as a launch-readiness check.
     *
     * The unpinned-file check is the half that a per-artifact read cannot do: getText() can only
     * verify artifacts it was asked for, so on its own it would never notice a tenth file appearing
     * in the directory.
     *
     * @throws ArtifactIntegrityException
     */
    public function verifyAll(): void
    {
        foreach ($this->names() as $name) {
            $this->load($name);
        }

        $expected = array_column($this->manifest(), 'file');
        $expected[] = self::MANIFEST;

        // Dotfiles are excluded on purpose. Every pin is a plain basename and none begins with a
        // dot, so a dotfile can never be mistaken for an artifact - whereas a `.DS_Store` from a
        // developer opening the folder in Finder would otherwise be enough to fail a launch-
        // readiness check and refuse to start a session. What this check is for is a plausible
        // extra - a stray `..._v3.txt` that someone later wires up, or a botched re-vendoring.
        $present = array_values(array_filter(
            scandir($this->dir) ?: [],
            static fn($f) => !str_starts_with($f, '.')
        ));
        $unpinned = array_diff($present, $expected);
        if ($unpinned) {
            throw new ArtifactIntegrityException(
                'Unpinned file(s) in ' . $this->dir . ': ' . implode(', ', $unpinned)
                . ' - add them to ' . self::MANIFEST . ' with their SHA-256, or remove them'
            );
        }
    }

    /**
     * Read the artifact once, verifying its hash. Subsequent calls are served from memory; a
     * verified artifact is never re-read within a request.
     *
     * @throws ArtifactIntegrityException
     */
    private function load(string $name): void
    {
        if (isset($this->contents[$name])) {
            return;
        }

        $pinned = $this->manifest();
        if (!isset($pinned[$name])) {
            throw new ArtifactIntegrityException(
                "No pinned artifact named '$name' (known: " . implode(', ', array_keys($pinned)) . ')'
            );
        }

        $path = $this->dir . '/' . $pinned[$name]['file'];
        if (!is_file($path) || !is_readable($path)) {
            throw new ArtifactIntegrityException("Pinned artifact '$name' is missing or unreadable: $path");
        }

        $body = file_get_contents($path);
        if ($body === false) {
            throw new ArtifactIntegrityException("Could not read pinned artifact '$name': $path");
        }

        $actual = hash('sha256', $body);
        if (!hash_equals($pinned[$name]['sha256'], $actual)) {
            throw new ArtifactIntegrityException(
                "Artifact '$name' does not match its pin. Expected {$pinned[$name]['sha256']}, got $actual. "
                . 'This artifact is not the one that was validated; refusing to use it.'
            );
        }

        $this->contents[$name] = $body;
        $this->hashes[$name]   = $actual;
    }

    /**
     * Decode and structurally validate the manifest itself, once per request.
     *
     * The manifest is the root of trust, so a malformed one is treated exactly like a bad artifact:
     * nothing is served. In particular a `file` value must be a plain basename - a manifest that
     * could point outside `handoff/` would let a pin describe a file the deployer never reviewed.
     *
     * @return array<string,array{file:string,type:string,sha256:string}>
     * @throws ArtifactIntegrityException
     */
    private function manifest(): array
    {
        if ($this->pinned !== null) {
            return $this->pinned;
        }

        $path = $this->dir . '/' . self::MANIFEST;
        if (!is_file($path) || !is_readable($path)) {
            throw new ArtifactIntegrityException("Handoff manifest is missing or unreadable: $path");
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (!is_array($manifest) || !isset($manifest['artifacts']) || !is_array($manifest['artifacts'])) {
            throw new ArtifactIntegrityException("Handoff manifest is not a JSON object with an 'artifacts' map: $path");
        }
        if ($manifest['artifacts'] === []) {
            throw new ArtifactIntegrityException("Handoff manifest pins no artifacts: $path");
        }

        foreach ($manifest['artifacts'] as $name => $entry) {
            if (!is_string($name) || $name === '') {
                throw new ArtifactIntegrityException('Handoff manifest has an artifact with a non-string name');
            }
            foreach (['file', 'type', 'sha256'] as $key) {
                if (!isset($entry[$key]) || !is_string($entry[$key]) || $entry[$key] === '') {
                    throw new ArtifactIntegrityException("Manifest entry '$name' is missing '$key'");
                }
            }
            if ($entry['file'] !== basename($entry['file'])) {
                throw new ArtifactIntegrityException(
                    "Manifest entry '$name' points outside the handoff directory: {$entry['file']}"
                );
            }
            if (!preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])) {
                throw new ArtifactIntegrityException("Manifest entry '$name' has a malformed sha256");
            }
            if (!in_array($entry['type'], ['text', 'json'], true)) {
                throw new ArtifactIntegrityException("Manifest entry '$name' has an unknown type '{$entry['type']}'");
            }
        }

        return $this->pinned = $manifest['artifacts'];
    }
}
