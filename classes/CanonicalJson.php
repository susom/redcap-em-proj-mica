<?php

namespace Stanford\MICA;

require_once __DIR__ . "/TranscriptException.php";

/**
 * The one serializer used for transcript hashing, the scan request body, and EM-log storage.
 *
 * One implementation on purpose (stage-3 §3.2): the transcript hash is what proves the bytes the
 * scanner read are the bytes the finalizer wrote, and two serializers that differ in any detail
 * turn that proof into a coin flip.
 *
 * Canonical form:
 *
 *   - associative arrays are key-sorted, recursively; lists keep their given order, because in this
 *     payload list order *is* data (messages[] is the conversation, and `sequence` mirrors it);
 *   - JSON_UNESCAPED_UNICODE, so participant text is stored as itself rather than as \uXXXX escapes
 *     - which also means the canonical string is genuinely multi-byte, and chunk() has to care;
 *   - JSON_UNESCAPED_SLASHES, because escaping them is a JavaScript-embedding habit with no meaning
 *     here and it only adds bytes;
 *   - JSON_THROW_ON_ERROR, so an unencodable payload is an exception rather than the string "false".
 *
 * Not RFC 8785 (JCS). JCS would additionally impose number canonicalization and UTF-16 code-unit
 * ordering; nothing here interoperates with another implementation of a canonical form, so the
 * requirement is only that *this* code produces the same bytes for the same payload every time.
 * Stated so the next reader does not assume compatibility that was never claimed.
 */
class CanonicalJson
{
    /**
     * redcap_external_modules_log_parameters.value is TEXT, so ~65,535 bytes. 60,000 leaves room
     * for the multi-byte snap-back in chunk() and for any encoding overhead in the write path,
     * without being so conservative that an ordinary session needs many rows.
     */
    public const CHUNK_BYTES = 60000;

    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function encode(array $payload): string
    {
        self::ksortRecursive($payload);

        try {
            return json_encode($payload, self::FLAGS);
        } catch (\JsonException $e) {
            // Most likely cause by far: a byte sequence in participant text that is not valid
            // UTF-8. Worth naming, because the alternative reading ("our payload shape is wrong")
            // sends the next person to the wrong place entirely.
            throw new TranscriptException(
                'The transcript payload could not be encoded as JSON, which usually means a '
                . 'message contains bytes that are not valid UTF-8. Underlying error: '
                . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public static function hash(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    /**
     * Split a canonical string for storage across payload_json, payload_json_2, ... .
     *
     * Counts bytes but never splits inside a character. The distinction matters: with
     * JSON_UNESCAPED_UNICODE the string holds literal multi-byte characters, and a blind 60,000-byte
     * offset can land mid-sequence. PHP would happily reassemble the halves - but each chunk is
     * stored in a utf8-collated TEXT column on the way out, and a truncated UTF-8 fragment does not
     * survive that round trip intact. The failure would then surface in Stage 4 as a transcript
     * hash mismatch on an apparently corrupt payload, with nothing pointing back to here.
     *
     * mb_strcut is exactly this operation: cut at a byte length, snapping down to a character
     * boundary.
     *
     * @return string[] one or more chunks; implode('') restores the input exactly
     */
    public static function chunk(string $canonical, int $maxBytes = self::CHUNK_BYTES): array
    {
        if ($maxBytes < 8) {
            // Below the longest UTF-8 sequence there is no guarantee of forward progress.
            throw new \InvalidArgumentException("Chunk size $maxBytes is too small to be safe.");
        }

        if ($canonical === '') {
            return [''];
        }

        $chunks = [];
        $offset = 0;
        $total = strlen($canonical);

        while ($offset < $total) {
            $piece = mb_strcut($canonical, $offset, $maxBytes, 'UTF-8');

            if ($piece === '') {
                // Cannot happen for valid UTF-8 with $maxBytes >= 4, but a silent infinite loop is
                // a far worse outcome than an exception, so it is checked rather than assumed.
                throw new TranscriptException(
                    'Chunking made no progress at byte offset ' . $offset
                    . '; the payload is probably not valid UTF-8.'
                );
            }

            $chunks[] = $piece;
            $offset += strlen($piece);
        }

        return $chunks;
    }

    /**
     * @param string[] $chunks in order
     */
    public static function join(array $chunks): string
    {
        return implode('', $chunks);
    }

    /**
     * Parameter names for a chunked payload: payload_json, payload_json_2, payload_json_3, ...
     *
     * The first has no suffix so that a single-row transcript (the overwhelming majority) reads
     * naturally, and so the scheme matches 02-data-model.md §1.2 exactly.
     *
     * @param string[] $chunks
     * @return array<string,string>
     */
    public static function asLogParameters(array $chunks, string $base = 'payload_json'): array
    {
        $params = [];
        foreach (array_values($chunks) as $i => $chunk) {
            $params[$i === 0 ? $base : $base . '_' . ($i + 1)] = $chunk;
        }

        return $params;
    }

    /**
     * Re-join a chunked payload read back out of the EM log.
     *
     * Reads until the first gap rather than counting keys: a missing payload_json_3 with a present
     * payload_json_4 must not silently produce a short string that then fails a hash check for an
     * unrelated-looking reason.
     *
     * @param array<string,mixed> $params the log row's parameters
     */
    public static function fromLogParameters(array $params, string $base = 'payload_json'): string
    {
        if (!isset($params[$base])) {
            throw new TranscriptException("Transcript log row has no '$base' parameter.");
        }

        $chunks = [(string) $params[$base]];

        for ($n = 2;; $n++) {
            $key = $base . '_' . $n;
            if (!isset($params[$key])) {
                break;
            }
            $chunks[] = (string) $params[$key];
        }

        // Anything beyond the first gap means the row was written or read incompletely.
        $expected = count($chunks) + 1;
        if (isset($params[$base . '_' . ($expected + 1)])) {
            throw new TranscriptException(
                "Transcript payload is missing chunk {$base}_{$expected} but has later chunks. "
                . 'The row is incomplete; do not scan it.'
            );
        }

        return self::join($chunks);
    }

    /** Sorts associative arrays by key, recursively, leaving lists in order. */
    private static function ksortRecursive(array &$value): void
    {
        $isList = array_is_list($value);

        foreach ($value as &$child) {
            if (is_array($child)) {
                self::ksortRecursive($child);
            }
        }
        unset($child);

        if (!$isList) {
            ksort($value, SORT_STRING);
        }
    }
}
