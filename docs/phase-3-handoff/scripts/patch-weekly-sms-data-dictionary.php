<?php
/**
 * Apply the Arm-3 weekly-SMS `sunday` rewrite to a downloaded Data Dictionary CSV, for a project
 * where you have UI access only (prod, PID 35968).
 *
 *   php patch-weekly-sms-data-dictionary.php <in.csv> <out.csv> [--enrol-event=day_1_ed_arm_1]
 *
 * INTENDED USE:
 *   1. Project Setup -> Data Dictionary -> Download the current Data Dictionary.
 *      KEEP THAT FILE UNTOUCHED: it is your rollback.
 *   2. php patch-weekly-sms-data-dictionary.php prod-dd.csv prod-dd-WEEKLY-SMS.csv
 *   3. Read the report. It must end in "*** OK ***".
 *   4. Data Dictionary -> Upload prod-dd-WEEKLY-SMS.csv to the SAME project, straight away.
 *      REDCap shows its own change preview before committing: expect 63 branching changes and
 *      3 label changes on `sunday`, and 1 new field on `auditc`. Anything else means the download
 *      was stale or edited - cancel.
 *
 * WHAT IT CHANGES - exactly what apply-weekly-sms-branching.php does to redcap_metadata on a dev
 * project, from the same definition (weekly-sms-sunday-spec.php):
 *   - branching logic on the 63 gated `sunday` fields: `[event-name]="week_N_sms_arm_1"` week gates
 *     become `[current-instance] = N`, one message per week, with blank guards
 *   - `[baseline_arm_1]` -> `[day_1_ed_arm_1]` in `sunday` labels, and `gset`'s question text
 *   - one new field, `calc_gset_threshold`, directly after `calc_binge_threshold`
 *
 * WHAT IT NEVER TOUCHES: every row outside `sunday` (except inserting the one new field), and
 * every column other than Field Label and Branching Logic on `sunday` rows. The report proves it by
 * counting the rows that differ.
 *
 * Idempotent: a CSV that already has the rewrite comes back with 0 changes.
 *
 * WHY A CSV AND NOT THE ONLINE DESIGNER. 63 branching edits by hand is where a typo in week 7 goes
 * unnoticed until a participant receives week 8's message. The Data Dictionary upload is REDCap's
 * own bulk path and shows its own preview.
 *
 * Plain PHP, no REDCap dependency - runs on a laptop.
 */

require_once __DIR__ . '/weekly-sms-sunday-spec.php';

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => strpos($a, '--') !== 0));
$opts = array_filter(array_slice($argv, 1), fn($a) => strpos($a, '--') === 0);
[$in, $out] = $args + [null, null];
if (!$in || !$out) {
    fwrite(STDERR, "usage: php patch-weekly-sms-data-dictionary.php <in.csv> <out.csv> [--enrol-event=...]\n");
    exit(2);
}
if (realpath($in) !== false && realpath($in) === realpath($out)) {
    fwrite(STDERR, "refusing to overwrite the input - it is your rollback\n");
    exit(2);
}
$enrolEvent = MICA_WEEKLY_SMS_ENROL_EVENT;
foreach ($opts as $o) if (preg_match('/^--enrol-event=(.+)$/', $o, $m)) $enrolEvent = $m[1];

// Column positions in REDCap's Data Dictionary format (stable across versions).
const C_FIELD = 0, C_FORM = 1, C_TYPE = 3, C_LABEL = 4, C_CHOICES = 5, C_BRANCH = 11, C_ANNOT = 17;

// ---- read ------------------------------------------------------------------------------------
$raw = file_get_contents($in);
if ($raw === false) { fwrite(STDERR, "cannot read $in\n"); exit(1); }
$bom = strncmp($raw, "\xEF\xBB\xBF", 3) === 0;
if ($bom) $raw = substr($raw, 3);

$fh = fopen('php://memory', 'r+');
fwrite($fh, $raw);
rewind($fh);
$header = fgetcsv($fh, 0, ',', '"', '');
if (!$header || stripos($header[C_FIELD] ?? '', 'Variable') === false || stripos($header[C_BRANCH] ?? '', 'Branching') === false) {
    fwrite(STDERR, "$in does not look like a REDCap Data Dictionary (unexpected header)\n");
    exit(1);
}
$rows = [];
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($r === [null]) continue;                       // blank line
    $rows[] = array_pad($r, count($header), '');
}
fclose($fh);
$original = $rows;

echo "\n=== weekly-SMS data dictionary patch ===\n";
printf("  input   %s (%d fields)\n  enrolment event prefix  [%s]\n\n", $in, count($rows), $enrolEvent);

// ---- sunday rows -----------------------------------------------------------------------------
$branching     = mica_weekly_sms_branching($enrolEvent);
$labelRewrites = mica_weekly_sms_label_rewrites($enrolEvent);
$fullLabels    = mica_weekly_sms_full_labels($enrolEvent);

$changedLogic = $changedLabel = $alreadyOk = 0;
$sundayRows = 0;
foreach ($rows as $i => $r) {
    if ($r[C_FORM] !== MICA_WEEKLY_SMS_FORM) continue;
    $sundayRows++;
    $f = $r[C_FIELD];

    if (isset($branching[$f])) {
        if (trim($r[C_BRANCH]) !== $branching[$f]) {
            printf("  logic   %-18s %s\n", $f, $branching[$f]);
            $rows[$i][C_BRANCH] = $branching[$f];
            $changedLogic++;
        } else {
            $alreadyOk++;
        }
        unset($branching[$f]);
    }

    $label    = $r[C_LABEL];
    $newLabel = $fullLabels[$f] ?? str_replace(array_keys($labelRewrites), array_values($labelRewrites), $label);
    if ($newLabel !== $label) {
        printf("  label   %-18s %s\n", $f, mb_substr(preg_replace('/\s+/', ' ', $newLabel), 0, 70));
        $rows[$i][C_LABEL] = $newLabel;
        $changedLabel++;
    }
}

if ($sundayRows === 0) { fwrite(STDERR, "\n  [!!] no `sunday` fields in this dictionary\n"); exit(1); }
$missing = array_keys($branching);

// ---- the new field ---------------------------------------------------------------------------
$gset = mica_weekly_sms_gset_field();
$have = array_search($gset['field_name'], array_column($rows, C_FIELD), true);
$addedField = false;
if ($have !== false) {
    echo "  field   {$gset['field_name']} already present\n";
} else {
    $anchor = array_search($gset['after'], array_column($rows, C_FIELD), true);
    if ($anchor === false) {
        fwrite(STDERR, "  [!!] {$gset['after']} not found - cannot place {$gset['field_name']}\n");
        exit(1);
    }
    $new = array_fill(0, count($header), '');
    $new[C_FIELD]   = $gset['field_name'];
    $new[C_FORM]    = $rows[$anchor][C_FORM];
    $new[C_TYPE]    = $gset['element_type'];
    $new[C_LABEL]   = $gset['label'];
    $new[C_CHOICES] = $gset['equation'];
    // REDCap's download prefixes every value that starts with `@` with a space (its CSV-injection
    // guard) and stores it without one; match the file's own convention so the upload round-trips
    // exactly like the 40-odd action-tag annotations already in it.
    $new[C_ANNOT]   = (strpos($gset['annotation'], '@') === 0 ? ' ' : '') . $gset['annotation'];
    array_splice($rows, $anchor + 1, 0, [$new]);
    $addedField = true;
    printf("  field   %s added on `%s` after %s\n", $gset['field_name'], $new[C_FORM], $gset['after']);
}

// ---- integrity -------------------------------------------------------------------------------
$dead = 0;
foreach ($rows as $r) {
    if ($r[C_FORM] !== MICA_WEEKLY_SMS_FORM) continue;
    if (preg_match(mica_weekly_sms_dead_ref_pattern(), $r[C_BRANCH] . ' ' . $r[C_LABEL])) $dead++;
}

// Every row outside `sunday` must be untouched, apart from the one inserted field.
$byName = fn(array $set) => array_combine(array_column($set, C_FIELD), $set);
$before = $byName($original);
$after  = $byName($rows);
$collateral = [];
foreach ($after as $name => $r) {
    if ($name === $gset['field_name'] && $addedField) continue;
    if (!isset($before[$name])) { $collateral[] = "$name (new)"; continue; }
    foreach ($r as $c => $v) {
        if ($v === $before[$name][$c]) continue;
        $allowed = $r[C_FORM] === MICA_WEEKLY_SMS_FORM && in_array($c, [C_LABEL, C_BRANCH], true);
        if (!$allowed) $collateral[] = "$name col " . ($header[$c] ?? $c);
    }
}
$lost = array_diff(array_keys($before), array_keys($after));

// ---- write -----------------------------------------------------------------------------------
$fh = fopen($out, 'w');
if (!$fh) { fwrite(STDERR, "cannot write $out\n"); exit(1); }
if ($bom) fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, $header, ',', '"', '');
foreach ($rows as $r) fputcsv($fh, $r, ',', '"', '');
fclose($fh);

$ok = $dead === 0 && empty($missing) && empty($collateral) && empty($lost);

echo "\n--- summary ---\n";
echo "  branching rewritten     $changedLogic\n";
echo "  branching already ok    $alreadyOk\n";
echo "  labels rewritten        $changedLabel\n";
echo "  field added             " . ($addedField ? 1 : 0) . "\n";
echo "  dead-event refs left    $dead" . ($dead ? "   <-- SHOULD BE 0" : '') . "\n";
echo "  expected fields missing " . count($missing) . ($missing ? '   ' . implode(', ', $missing) : '') . "\n";
echo "  rows changed elsewhere  " . count($collateral) . ($collateral ? '   ' . implode('; ', array_slice($collateral, 0, 10)) : '') . "\n";
echo "  rows lost               " . count($lost) . "\n";
echo "  output  $out (" . count($rows) . " fields)\n";
echo $ok ? "\n*** OK ***\n" : "\n*** NOT OK - do not upload ***\n";
exit($ok ? 0 : 1);
