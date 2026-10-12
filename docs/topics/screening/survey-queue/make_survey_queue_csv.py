# Build the prod Survey Queue Setup CSV from the PI's 15:42 export, with the 2026-10-05 fixes.
import re, html, csv, sys
SRC = '/Users/ihabz/Downloads/MICAEDR01_2026-10-05_1542.REDCap.xml'
s = open(SRC, encoding='utf-8').read()
rows = []
for t in re.findall(r'<redcap:SurveysQueue [^>]*/?>', s):
    a = {k: html.unescape(v) for k, v in re.findall(r'(\w+)="([^"]*)"', t)}
    rows.append({
        'form_name': a['survey_id'], 'event_name': a['event_id'], 'active': a['active'],
        'condition_surveycomplete_form_name': a.get('condition_surveycomplete_survey_id', ''),
        'condition_surveycomplete_event_name': a.get('condition_surveycomplete_event_id', ''),
        'condition_andor': a.get('condition_andor', 'AND') or 'AND',
        'condition_logic': a.get('condition_logic', ''), 'auto_start': a['auto_start'],
    })
orig = [dict(r) for r in rows]

NEG = "(([s_sex] = '2' AND [audit_c_score] < 3) OR ([s_sex] = '1' AND [audit_c_score] < 4) OR [days_dr] = '0')"
POS = "(([s_sex] = '2' AND [audit_c_score] >= 3) OR ([s_sex] = '1' AND [audit_c_score] >= 4))"
INELIG = "([phone] = '0' OR ([s_sex] = '2' AND [preg] = '1') OR [sc_age] < 18 OR [sc_age] > 65)"
GATE = {  # Day 1 (ED) Arm 1 only
    'auditc': "[english] = '1' AND [prison] = '0' AND [goodcand] = '1' AND [s_interest] = '1'",
    'screen_eligibility': f"{NEG} OR ([screen2_complete] = '2' AND {POS} AND {INELIG})",
    'consent': f"{POS} AND [phone] = '1' AND ([s_sex] = '1' OR [preg] = '0') AND [sc_age] >= 18 AND [sc_age] <= 65",
}
def later(arm_events):
    return ' AND '.join(f"[{e}][auditc_complete] <> '2'" for e in arm_events)
ARMS = ['1', '2', '3']
GUARD = {}  # event -> "no later timepoint started" clause
for a in ARMS:
    GUARD[f'day_1_ed_arm_{a}'] = later([f'month_{m}_arm_{x}' for m in (3, 6, 12) for x in ARMS])
    GUARD[f'month_3_arm_{a}'] = later([f'month_{m}_arm_{a}' for m in (6, 12)])
    GUARD[f'month_6_arm_{a}'] = later([f'month_12_arm_{a}'])

changes = []
for r in rows:
    ev, form = r['event_name'], r['form_name']
    logic = r['condition_logic'].strip()
    if ev == 'day_1_ed_arm_1' and form in GATE:
        logic = GATE[form]
    if ev == 'day_1_ed_arm_3' and form == 'bscq' and r['condition_surveycomplete_form_name'] == 'bscq':
        r['condition_surveycomplete_form_name'] = 'sip2r'
        changes.append('arm 3 Day 1 bscq: waits on SIP-2R, not on itself')
    if ev in GUARD:
        logic = f"({logic}) AND {GUARD[ev]}" if logic else GUARD[ev]
    r['condition_logic'] = logic

cols = ['form_name', 'event_name', 'active', 'condition_surveycomplete_form_name',
        'condition_surveycomplete_event_name', 'condition_andor', 'condition_logic', 'auto_start']
import os
OUT = os.path.dirname(os.path.abspath(__file__))
for name, data in ((os.path.join(OUT, 'MICA-35968-SurveyQueue-ORIGINAL-1542.csv'), orig), (os.path.join(OUT, 'MICA-35968-SurveyQueue-FIX-20261005.csv'), rows)):
    with open(name, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=cols); w.writeheader(); w.writerows(data)
changed = sum(1 for a, b in zip(orig, rows) if a != b)
print(f'{len(rows)} rows, {changed} changed; guards on {sum(1 for r in rows if r["event_name"] in GUARD)} entries')
print('\n'.join(changes))
