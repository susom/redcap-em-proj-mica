#!/usr/bin/env python3
"""
Generate the Alerts & Notifications import CSV for MICA (PID 257).

Output is consumed by REDCap's own importer:
    Project Setup -> Alerts & Notifications -> Upload alerts (CSV)
    (route=AlertsController:uploadAlerts&pid=257)

Column order and the allowed value vocabulary are taken from
redcap_v17.2.3/Classes/Alerts.php::getAlertsCSVAttributes() (line ~5160) and the
DB->CSV mapping at line ~5254. Do not reorder the header.

Vocabulary (from Alerts.php):
  alert-trigger            SUBMIT | SUBMIT-LOGIC | LOGIC
  saved-with-form-status   ANY | COMPLETE
  alert-stop-type          RECORD | RECORD_EVENT | RECORD_EVENT_INSTRUMENT
                           | RECORD_INSTRUMENT | RECORD_EVENT_INSTRUMENT_INSTANCE
  send-on                  NOW | DATE | TIME_LAG | NEXT_OCCURRENCE
  alert-send-how-many      ONCE | EVERY | SCHEDULE
  every-time-type          EVERY | EVERY-CHANGE | EVERY-CHANGE-CALCS
  alert-type               EMAIL | SMS | VOICE_CALL | SENDGRID_TEMPLATE
  Y/N flags                ensure-logic-still-true, do-not-clear-recurrences,
                           prevent-piping-identifiers, alert-deactivated

Every alert is emitted with alert-deactivated=Y. PID 257 has LIVE Twilio
credentials (SID ACe1be19..., from 6504075535); activate deliberately, one at a
time, after reviewing each rendered alert in the UI.
"""

import csv
import sys
from pathlib import Path

# ---------------------------------------------------------------- constants

FROM_ADDR = "ihab.zeedia@stanford.edu"          # as used by all 22 ASPIRE alerts
FROM_DISPLAY = "MICA Study"

# PLACEHOLDER -- no study address was supplied. Swap before go-live.
CRC = "micastudy@stanford.edu"

DAY1_EVENTS = ("day_1_ed_arm_1", "day_1_ed_arm_2", "day_1_ed_arm_3")
SESSION_ARMS_DAY1 = ("day_1_ed_arm_2", "day_1_ed_arm_3")     # arms 2 & 3 only
SESSION_ARMS_M3 = ("month_3_arm_2", "month_3_arm_3")

# Record link for CRC-facing alerts.
#
# ASPIRE hardcoded absolute URLs -- and they have already rotted: its alerts still
# point at redcap_v15.8.0 / redcap_v16.0.0 / redcap_v16.1.0 and pid=31710. We use
# [form-link:<form>:<label>] instead, a real smart variable (Piping.php:167) that
# REDCap resolves against the running version, so it survives upgrades and the
# localhost -> Stanford move.
#
# The named form must be designated at the event where the alert fires:
#   admin -> Day 1 events only;  close -> every event.
def record_link(form):
    return f'<p>[form-link:{form}:Open record [record-name] in REDCap]</p>'

# Ladder offsets in days from admin.randomization_date (the anchor chosen by the PI).
# Month 3 is randomization_date + 91 (per admin.calc_month_3's own label).
ED_NUDGE, ED_ESCALATE = 7, 14          # ED: one nudge at +1wk, CRC call at +2wk
BOOSTER_INITIAL, BOOSTER_NUDGE, BOOSTER_ESCALATE = 92, 98, 105


def any_event(events):
    """[event-name]='a' or [event-name]='b' ..."""
    return "(" + " or ".join(f"[event-name]='{e}'" for e in events) + ")"


def channel(kind, prefix=""):
    """
    Channel split on the choice_fup_delivery checkbox.

    Rule chosen by the PI: SMS wins if both boxes are ticked, and SMS is also the
    fallback if neither is ticked. The two conditions below are mutually
    exclusive and jointly exhaustive, so exactly one channel always fires --
    unlike ASPIRE, which gated its two ASIs independently on (1)='1' and (2)='1'
    and therefore double-sent to anyone who ticked both and stayed silent for
    anyone who ticked neither.
    """
    one, two = f"{prefix}[choice_fup_delivery(1)]", f"{prefix}[choice_fup_delivery(2)]"
    if kind == "SMS":
        return f"({two}='1' or ({one}<>'1' and {two}<>'1'))"
    return f"({one}='1' and {two}<>'1')"


def suppress(prefix="", sms=False):
    """Withdrawal / opt-out gates. sms_stop only matters for the SMS channel."""
    parts = [f"{prefix}[study_withdrawn(1)]<>'1'"]
    if sms:
        parts.append(f"{prefix}[sms_stop(1)]<>'1'")
    return " and ".join(parts)


HEADER = [
    "alert-unique-id", "alert-title", "alert-trigger", "unique-form-name",
    "unique-event-name", "saved-with-form-status", "alert-condition",
    "ensure-logic-still-true", "do-not-clear-recurrences", "alert-stop-type",
    "send-on", "send-on-next-day-type", "send-on-next-time",
    "send-on-time-lag-days", "send-on-time-lag-hours", "send-on-time-lag-minutes",
    "send-on-field-after", "send-on-field", "send-on-date",
    "alert-send-how-many", "every-time-type", "repeat-for", "repeat-for-units",
    "repeat-for-max", "alert-expiration", "alert-type", "email-from-display",
    "email-from", "email-to", "email-cc", "email-bcc", "email-failed",
    "email-subject", "alert-message", "prevent-piping-identifiers",
    "file-upload-fields", "phone-number-to", "alert-deactivated",
    "sendgrid-template-id", "sendgrid-template-data",
    "sendgrid-mail-send-configuration",
]

DEFAULTS = {k: "" for k in HEADER}
DEFAULTS.update({
    "alert-unique-id": "",              # blank => REDCap creates a new alert
    "alert-trigger": "LOGIC",
    "saved-with-form-status": "COMPLETE",
    "ensure-logic-still-true": "N",
    "do-not-clear-recurrences": "N",
    "alert-stop-type": "RECORD_EVENT",
    "send-on": "NOW",
    "send-on-field-after": "after",
    "alert-send-how-many": "ONCE",
    "repeat-for-units": "DAYS",
    "alert-type": "EMAIL",
    "email-from-display": FROM_DISPLAY,
    "email-from": FROM_ADDR,
    "prevent-piping-identifiers": "Y",
    "alert-deactivated": "Y",           # SAFETY: never import live
})

rows = []


def alert(**kw):
    """
    Append one alert. The title is auto-numbered from the emission order, so
    adding or removing a rung renumbers everything with no manual edits -- pass
    the title WITHOUT a leading number.
    """
    row = dict(DEFAULTS)
    unknown = set(kw) - set(HEADER)
    if unknown:
        sys.exit(f"unknown column(s): {sorted(unknown)}")
    row.update(kw)
    row["alert-title"] = f"{len(rows) + 1:02d} {row['alert-title']}"
    rows.append(row)


def lag(days):
    """
    Schedule relative to admin.randomization_date.

    Unprefixed on purpose. At runtime Alerts.php:1174 wraps a bare field with
    LogicTester::logicPrependEventName(..., 'event-name', ...), so it resolves to
    the event the alert itself fires in. The ED ladder fires at the Day 1 event,
    which is exactly where randomization_date lives -- so one alert covers arms 2
    and 3 with no event prefix at all. The booster ladder cannot do this (it
    fires at Month 3) and overrides send-on-field with a literal event name.
    """
    return {
        "send-on": "TIME_LAG",
        "send-on-field": "[randomization_date]",
        "send-on-field-after": "after",
        "send-on-time-lag-days": str(days),
        "send-on-time-lag-hours": "0",
        "send-on-time-lag-minutes": "0",
    }


# =========================================================================
# REQUIREMENT 1 -- confirm the phone number with a passcode
# =========================================================================
# Fires as soon as consent AND baseline1 (Enrollment/demographics) are Complete.
# Trigger is `baseline1` in ANY event, pinned to Day 1 by the condition, so one
# alert covers all three arms.
#
# CHANGED 2026-08-27 (decision recorded as O4 in README.md). This originally
# triggered on `close` and also required [close_complete]='2' -- i.e. the whole
# baseline battery -- which is the literal reading of the requirement. It was
# moved earlier on purpose: verifying the phone BEFORE the participant sits
# through the battery catches a wrong number while they are still reachable,
# whereas the `close` version only discovers it at the very end. Validated
# end-to-end on 2026-08-27 (Test 1, record 3).
alert(**{
    "alert-title": "Phone check - passcode by SMS",
    "alert-trigger": "SUBMIT-LOGIC",
    "unique-form-name": "baseline1",
    "unique-event-name": "",                       # any event; pinned in logic
    "alert-condition": (
        f"{any_event(DAY1_EVENTS)}"
        " and [consent_complete]='2'"
        " and [baseline1_complete]='2'"
        " and [phonen]<>''"
        " and [calcrnd]<>''"
        f" and {suppress(sms=True)}"
    ),
    "alert-stop-type": "RECORD",
    "send-on": "NOW",
    "alert-send-how-many": "ONCE",
    "alert-type": "SMS",
    "phone-number-to": "[phonen]",
    "email-subject": "MICA passcode",
    "alert-message": (
        "MICA: your verification passcode is [calcrnd]. "
        "Enter it on the Check Code form to confirm this is the right phone number."
    ),
})

# =========================================================================
# REQUIREMENTS 2/3/4 -- MICA ED session ladder (Day 1, arms 2 & 3)
# =========================================================================
# All referenced fields (randomization_date, mica_ed_session_complete,
# choice_fup_delivery, email, phonen, sms_stop, study_withdrawn) live in the same
# Day 1 event, so references stay unprefixed and one alert covers both arms.
ED_BASE = (
    f"{any_event(SESSION_ARMS_DAY1)}"
    " and [randomization_date]<>''"
    " and [mica_ed_session_complete]<>'2'"
)

# The +1-day rung was REMOVED on 2026-08-27 at the PI's direction. The ED session
# happens minutes after randomization, in the department, with a CRC present, so a
# next-day "your session is ready" reminder had no distinct job -- both it and the
# +7 rung were really "walked out mid-session" recovery. What remains matches the
# original requirements exactly: one reminder at +1 week (req 3), then the CRC
# call at +2 weeks (req 4).
for days, label in ((ED_NUDGE, "1-week reminder"),):
    alert(**{
        "alert-title": f"MICA ED session {label} (email)",
        "alert-condition": f"{ED_BASE} and [email]<>'' and {channel('EMAIL')} and {suppress()}",
        "ensure-logic-still-true": "Y",
        **lag(days),
        "email-to": "[email]",
        "email-subject": "Your MICA session is waiting for you",
        "alert-message": (
            "<p>Hi [first_name],</p>"
            "<p>Your MICA session is ready and takes about 20 minutes.</p>"
            "<p>[survey-link:mica_ed_session:Open your MICA session]</p>"
            "<p>If you have questions, reply to this email.</p>"
            "<p>&mdash; The MICA Study Team</p>"
        ),
    })
    alert(**{
        "alert-title": f"MICA ED session {label} (SMS)",
        "alert-condition": (
            f"{ED_BASE} and [phonen]<>'' and {channel('SMS')} and {suppress(sms=True)}"
        ),
        "ensure-logic-still-true": "Y",
        **lag(days),
        "alert-type": "SMS",
        "phone-number-to": "[phonen]",
        "email-subject": "MICA session",
        "alert-message": (
            "Hi [first_name], it's the MICA Team. Your MICA session is ready "
            "(about 20 min): [survey-url:mica_ed_session] Reply STOP to opt out."
        ),
    })

alert(**{
    "alert-title": "CRC - call participant, MICA ED session still not done",
    "alert-condition": f"{ED_BASE} and {suppress()}",
    "ensure-logic-still-true": "Y",
    **lag(ED_ESCALATE),
    "email-to": CRC,
    "email-subject": "MICA [record-name]: ED session not completed after 2 weeks - please call",
    "alert-message": (
        record_link("admin") +
        "<p>Study group: [study_group] &middot; Randomized: [randomization_date]</p>"
        "<ul>"
        "<li>The participant has not completed the MICA ED session "
        f"{ED_ESCALATE} days after randomization.</li>"
        f"<li>An automated reminder was sent on day {ED_NUDGE}.</li>"
        "<li><b>Action: phone the participant.</b> Contact: [phonen] / [email]</li>"
        "</ul>"
    ),
    "prevent-piping-identifiers": "N",   # CRC needs the phone/email to make the call
})

# =========================================================================
# REQUIREMENTS 2/3/4 -- MICA booster session ladder (Month 3, arms 2 & 3)
# =========================================================================
# These fire at the Month 3 event (so [survey-url:mica_booster_session] resolves
# unprefixed), but randomization_date / choice_fup_delivery / email / phonen /
# sms_stop / study_withdrawn all live at Day 1, so those references need an event
# prefix.
#
# We must use a LITERAL event name, which is why this ladder is duplicated per
# arm while the ED ladder above is not. [first-event-name] resolves correctly at
# RUNTIME (Piping.php:725, LogicLexer.php:143) but the alert IMPORTER is stricter
# than the runtime: Alerts::validateDateTimeFields / getPhoneFieldsList /
# getEmailsList each build their whitelist as [field] plus [literal_event][field]
# only, so a smart-variable prefix is rejected on send-on-field, phone-number-to
# and email-to. Verified empirically -- the [first-event-name] version failed
# import with "invalid date time field value for send-on-field" plus invalid
# phone/email errors on exactly these rows.
for _arm in (2, 3):
    ev_m3 = f"month_3_arm_{_arm}"
    ev_d1 = f"day_1_ed_arm_{_arm}"
    P = f"[{ev_d1}]"
    boost_base = (
        f"[event-name]='{ev_m3}'"
        " and [mica_booster_session_complete]<>'2'"
        f" and {P}[randomization_date]<>''"
    )
    boost_lag = dict(lag(0))
    boost_lag["send-on-field"] = f"{P}[randomization_date]"

    def blag(days, _bl=boost_lag):
        d = dict(_bl)
        d["send-on-time-lag-days"] = str(days)
        return d

    for days, label in ((BOOSTER_INITIAL, "reminder"), (BOOSTER_NUDGE, "1-week reminder")):
        alert(**{
            "alert-title": f"MICA booster session {label} (email, arm {_arm})",
            "alert-condition": (
                f"{boost_base} and {P}[email]<>'' and {channel('EMAIL', P)} "
                f"and {suppress(P)}"
            ),
            "ensure-logic-still-true": "Y",
            **blag(days),
            "email-to": f"{P}[email]",
            "email-subject": "Your MICA booster session is ready",
            "alert-message": (
                f"<p>Hi {P}[first_name],</p>"
                "<p>It's time for your 3-month MICA booster session.</p>"
                "<p>[survey-link:mica_booster_session:Open your booster session]</p>"
                "<p>&mdash; The MICA Study Team</p>"
            ),
        })
        alert(**{
            "alert-title": f"MICA booster session {label} (SMS, arm {_arm})",
            "alert-condition": (
                f"{boost_base} and {P}[phonen]<>'' and {channel('SMS', P)} "
                f"and {suppress(P, sms=True)}"
            ),
            "ensure-logic-still-true": "Y",
            **blag(days),
            "alert-type": "SMS",
            "phone-number-to": f"{P}[phonen]",
            "email-subject": "MICA booster",
            "alert-message": (
                f"Hi {P}[first_name], it's the MICA Team. Your 3-month booster "
                "session is ready: [survey-url:mica_booster_session] Reply STOP to opt out."
            ),
        })

    alert(**{
        "alert-title": f"CRC - call participant, MICA booster not done (arm {_arm})",
        "alert-condition": f"{boost_base} and {suppress(P)}",
        "ensure-logic-still-true": "Y",
        **blag(BOOSTER_ESCALATE),
        "email-to": CRC,
        "email-subject": "MICA [record-name]: booster session not completed - please call",
        "alert-message": (
            record_link("close") +
            f"<p>Study group: {P}[study_group] &middot; Randomized: {P}[randomization_date]</p>"
            "<ul>"
            "<li>The participant has not completed the MICA booster session, now "
            f"{BOOSTER_ESCALATE - 91} days past the Month 3 target.</li>"
            f"<li>Automated reminders were sent on day {BOOSTER_INITIAL} and day {BOOSTER_NUDGE}.</li>"
            f"<li><b>Action: phone the participant.</b> Contact: {P}[phonen] / {P}[email]</li>"
            "</ul>"
        ),
        "prevent-piping-identifiers": "N",
    })


# =========================================================================
# PORTED FROM ASPIRE -- see docs/ALERTS_AUDIT_257_vs_262.md section 4a
# =========================================================================

# ASPIRE 5604. The only ASPIRE alert addressed to the participant.
alert(**{
    "alert-title": "Participant - signed consent PDF",
    "alert-trigger": "SUBMIT-LOGIC",
    "unique-form-name": "person_obtaining_consent",
    "unique-event-name": "",
    "alert-condition": "[consent_pdf]<>'' and [email]<>''",
    "ensure-logic-still-true": "Y",
    "alert-stop-type": "RECORD_EVENT_INSTRUMENT_INSTANCE",
    "alert-send-how-many": "EVERY",
    "every-time-type": "EVERY",
    # Must be an explicit "0", not blank. The importer only defaults repeat-for
    # when alert-send-how-many is SCHEDULE (Alerts.php:~5486); for EVERY it
    # passes the blank straight through and redcap_alerts.cron_repeat_for is
    # NOT NULL, so the row is rejected with
    #   "Alert could not be created! Column 'cron_repeat_for' cannot be null".
    "repeat-for": "0",
    "email-to": "[email]",
    "email-subject": "Stanford Research Contact Information and Consent Form",
    "alert-message": (
        "<p>Welcome to Stanford's MICA Research Study.</p>"
        "<p>We have attached a copy of the consent form you signed. "
        "Please keep it for your records.</p>"
        "<p>If you have any questions, reply to this email.</p>"
        "<p>&mdash; The MICA Study Team</p>"
    ),
    "file-upload-fields": "[consent_pdf]",
})

# ASPIRE 5605.
alert(**{
    "alert-title": "CRC - participant consented",
    "alert-trigger": "SUBMIT-LOGIC",
    "unique-form-name": "consent",
    "unique-event-name": "",
    "alert-condition": f"{any_event(DAY1_EVENTS)} and [consent_complete]='2'",
    "alert-stop-type": "RECORD",
    "email-to": CRC,
    "email-subject": "MICA [record-name]: Consented",
    "alert-message": (
        record_link("admin") +
        "<ul><li>Consent is complete. Sign Person Obtaining Consent and upload the "
        "signed PDF.</li></ul>"
    ),
})

# ASPIRE 5606.
alert(**{
    "alert-title": "CRC - baseline complete",
    "alert-trigger": "SUBMIT-LOGIC",
    "unique-form-name": "close",
    "unique-event-name": "",
    "alert-condition": (
        f"{any_event(DAY1_EVENTS)} and [close_complete]='2' and [baseline1_complete]='2'"
    ),
    "alert-stop-type": "RECORD",
    "email-to": CRC,
    "email-subject": "MICA [record-name]: Baseline done",
    "alert-message": (
        record_link("admin") +
        "<ul>"
        "<li>Enrollment and the baseline battery are complete.</li>"
        "<li>Confirm the phone check passed (Check Code), then set "
        "<i>Is the participant ready to be randomized?</i> on the Admin form.</li>"
        "</ul>"
    ),
})

# ASPIRE 5607 / 5608 / 5623.
for label, events in (
    ("Month 3", ("month_3_arm_1", "month_3_arm_2", "month_3_arm_3")),
    ("Month 6", ("month_6_arm_1", "month_6_arm_2", "month_6_arm_3")),
    ("Month 12", ("month_12_arm_1", "month_12_arm_2", "month_12_arm_3")),
):
    # LOGIC, not SUBMIT-LOGIC, and deliberately no form.
    #
    # CHANGED 2026-08-27 (O7 in README.md). These originally triggered on the
    # `close` form. Form-triggered alerts are INVISIBLE to API / data-import saves:
    # Alerts.php:193 forces the instrument to "" when $isDataImport is true, and
    # getAlertsForInstrumentSave can then only match alerts with form_name IS NULL.
    # Month 3/6/12 follow-up data is exactly the kind that gets bulk-imported, so
    # the CRC would silently never be told. The condition already pins the event and
    # requires close_complete='2', so the form trigger was redundant anyway -- it
    # only cost import compatibility.
    alert(**{
        "alert-title": f"CRC - {label} follow-up done",
        "alert-trigger": "LOGIC",
        "unique-form-name": "",
        "unique-event-name": "",
        "alert-condition": f"{any_event(events)} and [close_complete]='2'",
        "alert-stop-type": "RECORD_EVENT",
        "email-to": CRC,
        "email-subject": f"MICA [record-name]: {label} follow-up done",
        "alert-message": (
            record_link("close") +
            f"<ul><li>Participant has finished the {label} follow-up.</li>"
            "<li>Process compensation.</li></ul>"
        ),
    })

# ASPIRE 5625. Duplicate [sms_opt_out_complete]=1 clause in the original dropped.
alert(**{
    "alert-title": "CRC - SMS opt-out received (Twilio STOP)",
    "alert-condition": (
        f"{any_event(DAY1_EVENTS)} and ("
        "[sms_stop(1)]='1'"
        " or [weeks_112_arm_3][sms_opt_out_complete]='1'"
        " or [weeks_112_arm_3][sms_opt_out_complete]='2')"
    ),
    "alert-stop-type": "RECORD",
    "email-to": CRC,
    "email-subject": "MICA [record-name]: Twilio STOP received (opt out)",
    "alert-message": (
        record_link("admin") +
        "<ul>"
        "<li>Participant has texted STOP to Twilio, or completed the SMS opt-out survey.</li>"
        "<li><b>All SMS alerts now self-suppress for this record</b> via the "
        "<code>[sms_stop(1)]&lt;&gt;'1'</code> gate. Confirm they still receive "
        "email, or switch their delivery preference.</li>"
        "</ul>"
    ),
})

# ---------------------------------------------------------------- write

out = Path(__file__).with_name("MICA_257_alerts_import.csv")
with out.open("w", newline="", encoding="utf-8") as fh:
    w = csv.DictWriter(fh, fieldnames=HEADER, quoting=csv.QUOTE_MINIMAL)
    w.writeheader()
    w.writerows(rows)

print(f"wrote {len(rows)} alerts -> {out}")
for r in rows:
    print(f"  [{r['alert-type']:5}] {r['alert-title']}")
