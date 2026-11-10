<?php

namespace Stanford\MICA;

require_once __DIR__ . "/TranscriptRecordBuilder.php";

/**
 * What has to be written to make one slot's `mica_transcript` instances match the desired rows.
 *
 * Pure. Compares field by field, so an unchanged slot writes nothing: the sync runs on a cron over
 * every session, and rewriting identical values would put a data-change line in REDCap's logging
 * for every statement of every session on every pass.
 *
 * An existing instance beyond the desired count is cleared: every field blank, including the form
 * status, which with `overwrite` removes the instance. The set of statements only grows (see
 * TranscriptRecordBuilder), so this is for a `no_reply` row that a late reply made untrue, and for
 * instances somebody added by hand.
 */
final class TranscriptRecordDiff
{
    /**
     * @param list<array<string,string>>     $desired  index 0 is instance 1
     * @param array<int,array<string,string>> $existing instance => field => value, as stored
     * @return array<int,array<string,string>> instance => the full row to write
     */
    public static function changes(array $desired, array $existing): array
    {
        $writes = [];

        foreach ($desired as $i => $row) {
            $instance = $i + 1;
            $stored = $existing[$instance] ?? [];

            foreach (TranscriptRecordBuilder::FIELDS as $field) {
                $want = self::normalise((string) ($row[$field] ?? ''));
                if ($want !== self::normalise((string) ($stored[$field] ?? ''))) {
                    $writes[$instance] = $row;
                    break;
                }
            }
        }

        foreach (array_keys($existing) as $instance) {
            if ((int) $instance > count($desired)) {
                $writes[(int) $instance] = array_fill_keys(TranscriptRecordBuilder::FIELDS, '');
            }
        }

        ksort($writes);

        return $writes;
    }

    /**
     * REDCap stores notes text with Windows line endings when it came through a form; saveData
     * stores what it is given. Compared without the difference so a CRLF never forces a rewrite.
     */
    private static function normalise(string $value): string
    {
        return str_replace("\r\n", "\n", $value);
    }
}
