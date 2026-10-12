# Build the 2026-10-05 "TSR opens the chat" Survey Queue CSV on top of the FIX CSV from earlier today.
#
#   python3 make_handoff_queue_csv.py
#
# Reads  MICA-35968-SurveyQueue-FIX-20261005.csv      (prod's queue after today's eligibility fix;
#                                                       identical to local PID 283, 104/104 entries)
# Writes MICA-35968-SurveyQueue-HANDOFF-20261005.csv  (the same 104 entries, 3 of them changed)
#
# Why: TSR is answered at Day 1 (ED) Arm 1 by everyone, because randomization happens on that submit.
# The arm-2/3 entries "MICA ED session when TSR is completed" point at TSR on *their own* Day-1 event,
# which nobody ever completes, so they never fire. The queue therefore falls through to Close @ arm 1,
# an empty page, whose redirect is what finally opens the chat. See
# docs/topics/screening/2026-10-05-tsr-opens-chat.md.
import csv, os

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'MICA-35968-SurveyQueue-FIX-20261005.csv')
OUT = os.path.join(HERE, 'MICA-35968-SurveyQueue-HANDOFF-20261005.csv')

ARMS = ['1', '2', '3']
# The "no later timepoint started" guard every Day-1 entry already carries (FIX CSV).
DAY1_GUARD = ' AND '.join(f"[month_{m}_arm_{x}][auditc_complete] <> '2'" for m in (3, 6, 12) for x in ARMS)

CHANGES = {
    # The intervention arms' session opens straight from the arm-1 TSR submit, for that arm only.
    ('mica_ed_session', 'day_1_ed_arm_2'): {
        'condition_surveycomplete_form_name': 'tsr',
        'condition_surveycomplete_event_name': 'day_1_ed_arm_1',
        'condition_logic': f"[day_1_ed_arm_1][study_group] = '2' AND {DAY1_GUARD}",
    },
    ('mica_ed_session', 'day_1_ed_arm_3'): {
        'condition_surveycomplete_form_name': 'tsr',
        'condition_surveycomplete_event_name': 'day_1_ed_arm_1',
        'condition_logic': f"[day_1_ed_arm_1][study_group] = '3' AND {DAY1_GUARD}",
    },
    # Close @ arm 1 is Standard Care's closing page, and the safety net for a record randomization
    # failed on (blank group: its [ed_session_url] is the "pending" handoff page). Arms 2/3 close at
    # their own event, after the session and CEMI, as the PI's queue already says.
    ('close', 'day_1_ed_arm_1'): {
        'condition_logic': f"([day_1_ed_arm_1][study_group] = '1' OR [day_1_ed_arm_1][study_group] = '') AND {DAY1_GUARD}",
    },
}

with open(SRC, encoding='utf-8', newline='') as f:
    reader = csv.DictReader(f)
    cols = reader.fieldnames
    rows = list(reader)

seen = set()
for r in rows:
    key = (r['form_name'], r['event_name'])
    if key in CHANGES:
        r.update(CHANGES[key])
        seen.add(key)

missing = set(CHANGES) - seen
if missing:
    raise SystemExit(f'entries not found in {os.path.basename(SRC)}: {sorted(missing)}')

with open(OUT, 'w', encoding='utf-8', newline='') as f:
    w = csv.DictWriter(f, fieldnames=cols)
    w.writeheader()
    w.writerows(rows)

print(f'{len(rows)} rows, {len(seen)} changed -> {os.path.basename(OUT)}')
for k in sorted(seen):
    print(f'  {k[0]} @ {k[1]}')
