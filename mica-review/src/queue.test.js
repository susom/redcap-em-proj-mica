import { describe, expect, it } from 'vitest'
import { countSessions, groupBySession, sessionKey } from './queue.js'

const finding = (over = {}) => ({
  job_id: 312,
  record: '1',
  event_id: 1008,
  instance: 1,
  session_type: 'baseline',
  job_status: 'ready_for_review',
  finding_id: 'f-1',
  finding_concern_type: 'self_harm',
  finding_urgency: 'critical',
  review_status: 'pending',
  created: 1_700_000_000,
  ...over,
})

describe('groupBySession', () => {
  it('collapses several findings of one session into one entry', () => {
    // The reported bug: three findings from one scan read as three separate queue items pointing at
    // the same conversation.
    const groups = groupBySession([
      finding({ finding_id: 'f-1', finding_concern_type: 'self_harm', finding_urgency: 'critical' }),
      finding({ finding_id: 'f-2', finding_concern_type: 'prompt_injection', finding_urgency: 'high' }),
      finding({ finding_id: 'f-3', finding_concern_type: 'protocol_or_quality', finding_urgency: 'high' }),
    ])

    expect(groups).toHaveLength(1)
    expect(groups[0].findings).toHaveLength(3)
    expect(groups[0].awaiting).toBe(3)
    expect(groups[0].band).toBe('critical')
  })

  it('keeps different sessions of the same record apart', () => {
    const groups = groupBySession([
      finding({ event_id: 1008 }),
      finding({ event_id: 1009, session_type: 'booster', finding_id: 'f-b' }),
      finding({ event_id: 1008, instance: 2, finding_id: 'f-2nd' }),
    ])

    expect(groups).toHaveLength(3)
  })

  it('preserves the order the server sent, which is the safety ordering', () => {
    // ReviewQueue::sort() pins manual_review_required above critical. Grouping must not re-rank.
    const groups = groupBySession([
      finding({ record: '9', finding_id: null, job_status: 'manual_review_required' }),
      finding({ record: '1' }),
    ])

    expect(groups.map((g) => g.head.record)).toEqual(['9', '1'])
    expect(groups[0].band).toBe('unscreened')
  })

  /**
   * The understatement this guards against: settled findings sort last, so a session holding a
   * confirmed critical and a pending high arrives high-first. Reading the badge off the first row
   * would label that session "High" while it contains a critical finding.
   */
  it('badges the group by its worst finding, not its first row', () => {
    const groups = groupBySession([
      finding({ finding_id: 'f-high', finding_urgency: 'high', review_status: 'pending' }),
      finding({ finding_id: 'f-crit', finding_urgency: 'critical', review_status: 'confirmed' }),
    ])

    expect(groups).toHaveLength(1)
    expect(groups[0].band).toBe('critical')
    expect(groups[0].awaiting).toBe(1)
    expect(groups[0].settled).toBe(1)
  })

  it('treats a session with no findings as a session, not as a finding', () => {
    const groups = groupBySession([finding({ finding_id: null, finding_urgency: null })])

    expect(groups).toHaveLength(1)
    expect(groups[0].findings).toEqual([])
    expect(groups[0].awaiting).toBe(0)
  })

  it('is empty for no rows', () => {
    expect(groupBySession([])).toEqual([])
  })
})

describe('sessionKey', () => {
  it('defaults a missing instance to 1 so it cannot split a session in two', () => {
    expect(sessionKey({ record: '1', event_id: 1008 })).toBe(sessionKey(finding()))
  })
})

describe('countSessions', () => {
  it('counts sessions and findings separately', () => {
    const groups = groupBySession([
      finding({ finding_id: 'f-1' }),
      finding({ finding_id: 'f-2' }),
      finding({ record: '3', event_id: 1012, finding_id: null }),
    ])

    expect(countSessions(groups)).toEqual({ sessions: 2, findings: 2 })
  })
})
