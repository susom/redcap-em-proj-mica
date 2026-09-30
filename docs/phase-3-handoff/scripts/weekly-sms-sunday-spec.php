<?php
/**
 * The target state of the `sunday` instrument for the Arm-3 weekly SMS - one definition, two
 * consumers:
 *
 *   apply-weekly-sms-branching.php          writes it to redcap_metadata (dev projects, DB access)
 *   patch-weekly-sms-data-dictionary.php    writes it into a downloaded Data Dictionary CSV (prod,
 *                                           UI access only)
 *
 * Keeping it here is what makes "the CSV patch produces the same instrument the dev build did" a
 * checkable claim instead of a hope. The rationale for every rule is in the docblock of
 * apply-weekly-sms-branching.php and in docs/alerts/ARM3_WEEKLY_SMS_BUILD.md section 4.
 *
 * No REDCap dependency - plain PHP, so the CSV patcher can run anywhere.
 */

const MICA_WEEKLY_SMS_FORM        = 'sunday';
const MICA_WEEKLY_SMS_ENROL_EVENT = 'day_1_ed_arm_1';   // where contact_info / auditc / admin data lives

/** field_name => branching logic, for every gated field on `sunday`. */
function mica_weekly_sms_branching(string $enrolEvent = MICA_WEEKLY_SMS_ENROL_EVENT): array
{
    $threshold = '[' . $enrolEvent . '][calc_binge_threshold]';
    $target    = [];

    for ($n = 1; $n <= 12; $n++) {
        // alcohol-free week
        $target["ar_$n"] = "([dquant] <> '' and [dquant] = 0) and [current-instance] = $n";
        // drank, but stayed under the binge threshold
        $target["sd_$n"] = "([dquant] <> '' and [dquant] > 0 and [dquant] < $threshold) and [current-instance] = $n";
        // at or above the binge threshold
        $target["bd_$n"] = "([dquant] <> '' and [dquant] >= $threshold) and [current-instance] = $n";
        // declined to set a goal
        $target["gp_$n"] = "([gset] = '0') and [current-instance] = $n";
        // set a goal
        $target["gg_$n"] = "([gset] = '1') and [current-instance] = $n";
    }

    $target['no_plan']         = "[drink_fut] = '0'";
    $target['gset']            = "[drink_fut] = '1'";
    $target['sun_end_week_12'] = "[current-instance] = 12";

    return $target;
}

/** Substring rewrites applied to every `sunday` label: the only participant-visible cross-event ref. */
function mica_weekly_sms_label_rewrites(string $enrolEvent = MICA_WEEKLY_SMS_ENROL_EVENT): array
{
    return ['[baseline_arm_1]' => '[' . $enrolEvent . ']'];
}

/**
 * Whole-label replacements. `gset` piped `calc_dquant_threshold`, a field that exists in no
 * project; ASPIRE's single-threshold wording is restored verbatim.
 */
function mica_weekly_sms_full_labels(string $enrolEvent = MICA_WEEKLY_SMS_ENROL_EVENT): array
{
    return [
        'gset' => 'Would you be willing to commit to a goal to drink ['
                . $enrolEvent . '][calc_gset_threshold] or fewer drinks on any occasion?',
    ];
}

/** The one new field: the goal threshold `gset` asks the participant to commit to. */
function mica_weekly_sms_gset_field(): array
{
    return [
        'field_name'   => 'calc_gset_threshold',
        'after'        => 'calc_binge_threshold',          // placed directly after, on the same form
        'element_type' => 'calc',
        'label'        => 'Goal threshold (drinks per occasion)',
        'equation'     => "if([s_sex] = '1', 4, if([s_sex] = '2', 3, ''))",
        'annotation'   => '@HIDDEN-SURVEY',
    ];
}

/** Regex/LIKE fragments that must be gone from `sunday` afterwards. */
function mica_weekly_sms_dead_ref_pattern(): string
{
    return '/week_[0-9]+_sms_arm_1|baseline_arm_1/';
}
