<?php

/**
 * Prove a chunked, multi-byte transcript payload survives a real EM-log write and read.
 *
 *   docker exec <web> php /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/verify-transcript-chunking.php
 *
 * Exit 0 = every payload round-tripped with its hash intact.
 *
 * Why this cannot be a PHPUnit test. CanonicalJsonTest already round-trips chunk/join in memory,
 * and it passes whether or not the split respects character boundaries: PHP concatenation restores
 * the bytes either way. The failure only exists on the way through the database - each chunk lands
 * in a utf8-collated TEXT column in redcap_external_modules_log_parameters, and a truncated UTF-8
 * sequence does not reliably survive that. So the assertion has to be made against a live REDCap,
 * with multi-byte characters deliberately positioned to straddle a 60 KB boundary.
 *
 * The fixtures below therefore exist to make the boundary land in the worst place available: on the
 * second, third and fourth byte of a 4-byte character.
 *
 * Cleans up after itself: every row it writes is removed before it exits.
 */

define('NOAUTH', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    foreach (['/var/www/html'] as $guess) {
        if (is_file("$guess/redcap_connect.php")) {
            return "$guess/redcap_connect.php";
        }
    }
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) {
            return "$d/redcap_connect.php";
        }
        if ($d === '/') {
            break;
        }
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT=/path/to/redcap web root.\n");
    exit(1);
}

use Stanford\MICA\CanonicalJson;

$PID = (int) ($argv[1] ?? 257);
$_GET['pid'] = $PID;

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}

$fails = 0;
$written = [];
/** @var array<string,bool> label => did a naive byte split actually break a character here */
$boundaryTested = [];

/**
 * Build a payload whose canonical encoding puts a 4-byte character across the chunk boundary at
 * exactly $shift bytes into the character.
 */
function fixture(int $shift): array
{
    // Pad with ASCII so the boundary can be positioned by the byte, then a run of 4-byte
    // characters so the boundary lands inside one of them.
    $pad = str_repeat('a', max(0, CanonicalJson::CHUNK_BYTES - 200 + $shift));

    return [
        'schema_version'       => '1.0',
        'transcript_finalized' => true,
        'messages'             => [
            ['message_id' => 'L1', 'sequence' => 1, 'speaker_role' => 'participant', 'content' => $pad],
            ['message_id' => 'L2', 'sequence' => 2, 'speaker_role' => 'mica', 'content' => str_repeat('🙂', 400)],
            ['message_id' => 'L3', 'sequence' => 3, 'speaker_role' => 'participant', 'content' => 'caña 日本語 🙂'],
        ],
    ];
}

$cases = [
    'boundary inside a 4-byte char (+1)' => fixture(1),
    'boundary inside a 4-byte char (+2)' => fixture(2),
    'boundary inside a 4-byte char (+3)' => fixture(3),
    'boundary on a char start'           => fixture(0),
    'several chunks, mixed widths'       => [
        'schema_version'       => '1.0',
        'transcript_finalized' => true,
        'messages'             => array_map(
            static fn(int $i): array => [
                'message_id'   => "L$i",
                'sequence'     => $i,
                'speaker_role' => $i % 2 ? 'participant' : 'mica',
                'content'      => str_repeat('caña 日本語 🙂 ', 300),
            ],
            range(1, 24)
        ),
    ],
];

echo "Transcript chunking through a live EM log (pid $PID)\n";

foreach ($cases as $label => $payload) {
  try {
    $canonical = CanonicalJson::encode($payload);
    $hash = CanonicalJson::hash($canonical);
    $chunks = CanonicalJson::chunk($canonical);
    $params = CanonicalJson::asLogParameters($chunks);

    // Write it the way TranscriptFinalizer will.
    $logId = $module->log('mica_transcript_chunking_probe', $params + [
        'record'            => '__probe__',
        'transcript_sha256' => $hash,
    ]);

    if (!$logId) {
        $fails++;
        printf("  [FAIL] %-38s could not write the log row\n", $label);
        continue;
    }
    $written[] = $logId;

    // Read it back the way TranscriptReader will. queryLogs() is not SQL over the log tables: its
    // select list is a list of *parameter names*, each of which comes back as a column on one row
    // (see ASEMLO::__construct). So the names have to be named - `select name, value` returns a
    // single row with one empty key, which is how the first version of this probe managed to
    // report "no payload_json parameter" for a row that had one.
    $names = array_keys($params);
    $result = $module->queryLogs(
        'select ' . implode(', ', $names) . ' where log_id = ?',
        [$logId]
    );
    $readBack = array_filter((array) $result->fetch_assoc(), static fn($v): bool => $v !== null);

    $rejoined = CanonicalJson::fromLogParameters($readBack);
    $rehash = CanonicalJson::hash($rejoined);

    // And independently, straight out of the parameters table, so a bug in queryLogs cannot make a
    // corrupt payload look intact.
    $raw = $module->query(
        'SELECT name AS n, value AS v FROM redcap_external_modules_log_parameters WHERE log_id = ?',
        [$logId]
    );
    $rawParams = [];
    while ($row = $raw->fetch_assoc()) {
        $rawParams[$row['n']] = $row['v'];
    }
    $rawHash = CanonicalJson::hash(CanonicalJson::fromLogParameters($rawParams));
    if ($rawHash !== $rehash) {
        $fails++;
        printf("         [FAIL] %-30s queryLogs and the raw table disagree\n", $label);
    }

    $ok = $rehash === $hash;
    if (!$ok) {
        $fails++;
    }

    printf(
        "  [%s] %-38s %d chunk(s), %s bytes, hash %s\n",
        $ok ? 'ok' : 'FAIL',
        $label,
        count($chunks),
        number_format(strlen($canonical)),
        $ok ? 'intact' : "MISMATCH (wrote $hash, read $rehash)"
    );

    if (!$ok) {
        printf(
            "         wrote %d bytes, read %d bytes; first divergence at byte %d\n",
            strlen($canonical),
            strlen($rejoined),
            strspn($canonical ^ str_pad($rejoined, strlen($canonical), "\0"), "\0")
        );
    }

    // Independent of the hash: each stored chunk must be valid UTF-8 on its own, which is the
    // property a naive byte split would break.
    foreach ($chunks as $i => $chunk) {
        if (!mb_check_encoding($chunk, 'UTF-8')) {
            $fails++;
            printf("         [FAIL] chunk %d is not independently valid UTF-8\n", $i);
        }
    }

    // Prove the fixture is not passing vacuously. A probe for "the boundary may land inside a
    // multi-byte character" is worthless if the boundary always lands on ASCII, and that is not
    // something to assume from the fixture's shape - it depends on JSON structure overhead. So
    // measure it: compare where a NAIVE byte split would have cut against where mb_strcut did.
    // A difference is proof the snap-back was needed here.
    $naive = substr($canonical, 0, CanonicalJson::CHUNK_BYTES);
    $snapped = $chunks[0];
    $backedOff = strlen($naive) - strlen($snapped);
    $naiveIsBroken = !mb_check_encoding($naive, 'UTF-8');

    $boundaryTested[$label] = $naiveIsBroken;

    printf(
        "         boundary: naive cut %s, snapped back %d byte(s)%s\n",
        $naiveIsBroken ? 'SPLITS a character' : 'lands on a boundary',
        $backedOff,
        $naiveIsBroken ? ' <- this is the case the probe exists for' : ''
    );
  } catch (\Throwable $e) {
    // Report and carry on: an uncaught throw here would skip the cleanup below and leave probe
    // rows in the log for the next person to wonder about.
    $fails++;
    printf("  [FAIL] %-38s threw %s: %s\n", $label, get_class($e), $e->getMessage());
  }
}

// Clean up. A probe that leaves rows behind is a probe nobody runs twice.
foreach ($written as $logId) {
    $module->removeLogs('log_id = ?', [$logId]);
}
printf("\n  removed %d probe row(s)\n", count($written));

$straddled = array_keys(array_filter($boundaryTested));
if ($straddled === []) {
    // Without this the whole probe could pass while never testing the thing it claims to test.
    $fails++;
    echo "\n  [FAIL] no fixture actually placed a multi-byte character across the chunk boundary,\n"
       . "         so this run proves nothing about the snap-back. Fix the fixtures.\n";
} else {
    printf("\n  %d of %d fixture(s) genuinely split a character with a naive cut: %s\n",
        count($straddled), count($boundaryTested), implode(', ', $straddled));
}

echo "\n" . ($fails === 0 ? "PASS - chunked multi-byte payloads survive the EM log intact\n"
                          : "FAIL - $fails check(s) failed\n");
exit($fails === 0 ? 0 : 1);
