<?php
/**
 * Give the +98-day booster rungs (07, 08, 12, 13) their own wording.
 *
 *   php apply-booster-followup-copy.php [pid]            # dry run, prints the diff
 *   php apply-booster-followup-copy.php [pid] --apply    # commits, after writing a rollback file
 *
 * Idempotent. A second --apply reports "already ok" for every attribute.
 *
 * THE DEFECT. Found by actually receiving both texts (docs/alerts/PID271_ALERT_TEST_MATRIX.md
 * section 7c). The +92 and +98 rungs carry byte-identical bodies:
 *
 *     a9843172...  alerts 06, 08, 11, 13   SMS    "...booster session is ready..."
 *     ff35e0df...  alerts 05, 07, 10, 12   EMAIL  "It's time for your 3-month MICA booster session."
 *
 * and 05/07/10/12 additionally share the subject "Your MICA booster session is ready". So a
 * participant who ignores the day-92 message receives the same sentence again on day 98, with
 * nothing marking it as a follow-up. The alert *titles* say "1-week reminder"; the participant
 * never sees a title.
 *
 * The static matrix cannot catch this - it reports `message ... ok` because every token resolves.
 * They resolve to the same sentence.
 *
 * WHAT CHANGES. Only the four +98 alerts, and only their participant-facing copy: `alert_message`
 * on all four, plus `email_subject` on the two EMAIL ones. The +92 rungs (05, 06, 10, 11) are the
 * first contact and are left exactly as they are. No condition, recipient or anchor is touched.
 *
 * WHY THE WORDING IS ARM-AGNOSTIC. 07/08 are arm 2 and 12/13 are arm 3, but the booster session
 * itself is the same in both arms and the existing +92 copy is already arm-agnostic. Splitting the
 * text by arm here would invent a distinction the study does not make.
 *
 * SMS LENGTH IS ASSERTED, NOT ASSUMED. A GSM-7 message is one segment up to 160 characters; going
 * over silently doubles the cost and can split mid-URL in some clients. The script pipes the
 * longest `first_name` in the project plus a realistic production survey URL and refuses to write
 * if the result exceeds 160.
 */

$pid   = (int) ($argv[1] ?? 271);
$apply = in_array('--apply', array_slice($argv, 1), true);

$_GET['pid'] = $pid;
define('NOAUTH', true);
define('CRON', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        if (is_file("$dir/redcap_connect.php")) return "$dir/redcap_connect.php";
    }
    fwrite(STDERR, "cannot locate redcap_connect.php; set REDCAP_ROOT\n");
    exit(1);
}

const NAME = '[day_1_ed_arm_1][first_name]';
const LINK = '[survey-link:mica_booster_session:Open your booster session]';
const URL  = '[survey-url:mica_booster_session]';

/** A second nudge, one week after the first. Same tokens, same sign-off as the +92 email. */
$emailSubject = 'Reminder: your MICA booster session is still open';
$emailBody =
    '<p>Hi ' . NAME . ',</p>' .
    '<p>Just a reminder — we wrote last week about your 3-month MICA booster session, and our ' .
    'records show it isn\'t complete yet.</p>' .
    '<p>There\'s still time, and you can pick it up whenever works for you.</p>' .
    '<p>' . LINK . '</p>' .
    '<p>— The MICA Study Team</p>';

/** Plain text, no markup: alert 11 carries a stray <p> wrapper on an SMS and it reads as noise. */
// "3-month" is dropped deliberately: the +92 message already established which session this is,
// and the words buy back 8 characters of segment headroom for long names.
$smsBody = 'Hi ' . NAME . ', the MICA Team again. Your booster session is still open: '
         . URL . ' Reply STOP to opt out.';

/** alert_order => [field => new value] */
$changes = [
    7  => ['email_subject' => $emailSubject, 'alert_message' => $emailBody],
    8  => ['alert_message' => $smsBody],
    12 => ['email_subject' => $emailSubject, 'alert_message' => $emailBody],
    13 => ['alert_message' => $smsBody],
];

// --- SMS segment check, against the worst case this project can actually produce ----------------
$q = db_query("SELECT value FROM " . Records::getDataTable($pid) . "
               WHERE project_id = ? AND field_name = 'first_name' AND value <> ''
               ORDER BY CHAR_LENGTH(value) DESC LIMIT 1", [$pid]);
$row = db_fetch_assoc($q);
// Stress the check with a long realistic name, not merely the longest this test project happens
// to hold - the assertion has to mean something on prod.
$longestName = $row['value'] ?? 'Ihab';
if (mb_strlen($longestName) < 20) $longestName = 'Konstantinopoulos';
// A production survey URL, not localhost's short one.
$prodUrl = 'https://redcap.stanford.edu/surveys/?s=XXXXXXXXXX';
$piped = str_replace([NAME, URL], [$longestName, $prodUrl], $smsBody);
$len = mb_strlen($piped);

printf("pid %d   mode: %s\n\n", $pid, $apply ? 'APPLY' : 'DRY RUN (pass --apply to commit)');
echo "SMS length check\n";
printf("  stress name: \"%s\" (%d chars)\n", $longestName, mb_strlen($longestName));
printf("  piped with a production URL:   %d chars  -> %s\n", $len,
       $len <= 160 ? 'OK, single GSM-7 segment' : 'TOO LONG - would split into 2 segments');
printf("  \"%s\"\n\n", $piped);
if ($len > 160) { fwrite(STDERR, "refusing to write an SMS body that exceeds one segment\n"); exit(1); }

// --- the four alerts ----------------------------------------------------------------------------
$orders = implode(',', array_keys($changes));
$q = db_query("SELECT * FROM redcap_alerts WHERE project_id = ? AND alert_order IN ($orders) ORDER BY alert_order", [$pid]);
$alerts = [];
while ($r = db_fetch_assoc($q)) $alerts[] = $r;
if (count($alerts) !== count($changes)) {
    fwrite(STDERR, sprintf("expected %d alerts, found %d - refusing to run\n", count($changes), count($alerts)));
    exit(1);
}

$rollback = ["-- rollback for apply-booster-followup-copy.php on pid $pid, generated " . date('Y-m-d H:i:s')];
$changed = 0; $ok = 0;

foreach ($alerts as $a) {
    $id  = (int) $a['alert_id'];
    $ord = (int) $a['alert_order'];
    printf("=== %02d  [%s]  %s\n", $ord, $a['alert_type'], $a['alert_title']);

    $sets = [];
    foreach ($changes[$ord] as $field => $new) {
        $old = (string) ($a[$field] ?? '');
        if ($old === $new) { printf("    %-14s already ok\n", $field); $ok++; continue; }
        // Never drop a piping token on the way through.
        foreach ([NAME] as $tok) {
            if (strpos($old, $tok) !== false && strpos($new, $tok) === false) {
                fwrite(STDERR, "new $field for alert $ord drops the token $tok - refusing\n");
                exit(1);
            }
        }
        $sets[$field] = $new;
        printf("    %-14s -  %s\n    %-14s +  %s\n", $field, mb_substr($old, 0, 150), '', mb_substr($new, 0, 150));
        $changed++;
    }
    if (!$sets) { echo "\n"; continue; }

    if ($apply) {
        $pairs = [];
        foreach ($sets as $f => $v) {
            $pairs[] = "`$f` = " . checkNull($v);
            $rollback[] = sprintf("UPDATE redcap_alerts SET `%s` = %s WHERE alert_id = %d;  -- %02d",
                                  $f, checkNull((string) ($a[$f] ?? '')), $id, $ord);
        }
        if (!db_query("UPDATE redcap_alerts SET " . implode(', ', $pairs) . " WHERE alert_id = $id")) {
            fwrite(STDERR, "UPDATE failed on alert_id $id: " . db_error() . "\n");
            exit(1);
        }
        echo "    -> written\n";
    }
    echo "\n";
}

printf("fields rewritten %d / already ok %d\n", $changed, $ok);

if ($apply && $changed) {
    $path = __DIR__ . "/rollback-booster-followup-copy-$pid-" . date('Ymd-His') . '.sql';
    file_put_contents($path, implode("\n", $rollback) . "\n");
    echo "rollback written to $path\n";

    // --- prove the duplication is gone --------------------------------------------------------
    echo "\nbody-hash check across the booster ladder (<p> stripped)\n";
    $q = db_query("SELECT alert_order, alert_type, cron_send_email_on_time_lag_days AS lag_days,
                          MD5(REPLACE(REPLACE(TRIM(alert_message),'<p>',''),'</p>','')) AS h
                   FROM redcap_alerts WHERE project_id = ? AND alert_order BETWEEN 5 AND 13
                   ORDER BY alert_order", [$pid]);
    $seen = [];
    while ($r = db_fetch_assoc($q)) {
        $dup = isset($seen[$r['h']]) ? ('  <- same body as ' . $seen[$r['h']]) : '';
        if (!isset($seen[$r['h']])) $seen[$r['h']] = sprintf('%02d', $r['alert_order']);
        printf("  %02d %-6s +%-4s %s%s\n", $r['alert_order'], $r['alert_type'], $r["lag_days"],
               substr($r['h'], 0, 8), $dup);
    }
} elseif (!$apply) {
    echo "\nnothing written. re-run with --apply to commit.\n";
}
