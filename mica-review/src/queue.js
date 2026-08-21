import { band, isSettled } from './labels.js'

/**
 * Turning the server's flat finding rows into one entry per chat session.
 *
 * The endpoint returns one row per finding, which is right for the API — a finding is what gets a
 * disposition, and the notification store reads the same rows. It is wrong for the queue, where a
 * session with three findings read as three separate items pointing at the same conversation. A
 * reviewer picks a *session* to work on and then reads what is in it.
 *
 * Two rules that look like details and are not:
 *
 * 1. **Encounter order is preserved.** `ReviewQueue::sort()` decides what a reviewer sees first, and
 *    that decision is safety-critical (manual_review_required above critical, then urgency, then
 *    oldest first — see its class comment). Grouping must not become a second, client-side ordering
 *    with its own opinion, so a session takes the position of its highest-ranked row and nothing is
 *    re-sorted here.
 * 2. **The badge is the group's worst finding, computed explicitly.** Not the first row's. Settled
 *    findings sort last, so a session holding a confirmed `critical` and a pending `high` has the
 *    high one first — and labelling that session "High" while it contains a critical finding is
 *    exactly the kind of quiet understatement this pipeline exists to avoid.
 */

/** Worst first. Mirrors ReviewQueue::URGENCY_RANK, plus `unscreened` above all of it. */
const BAND_RANK = { unscreened: 100, critical: 40, high: 30, moderate: 20, quality: 10, none: 0 }

export function sessionKey(row) {
  return `${row.record}|${Number(row.event_id) || 0}|${Number(row.instance) || 1}`
}

/**
 * @param {Array<object>} rows queue rows, already ordered by the server
 * @returns {Array<{key: string, head: object, findings: Array<object>, band: string,
 *   awaiting: number, settled: number}>}
 */
export function groupBySession(rows) {
  const groups = new Map()

  for (const row of rows) {
    const key = sessionKey(row)

    if (!groups.has(key)) {
      groups.set(key, { key, head: row, findings: [], band: 'none', awaiting: 0, settled: 0 })
    }

    const group = groups.get(key)

    // Rows with no finding are the session itself: a clean screen, or a job that could not be
    // screened. They set the group's band but are never listed as findings.
    if (row.finding_id) {
      group.findings.push(row)
      if (isSettled(row.review_status)) group.settled++
      else group.awaiting++
    }

    const level = band(row)
    if (BAND_RANK[level] > BAND_RANK[group.band]) group.band = level
  }

  return [...groups.values()]
}

/**
 * Counts the grouped view can state without ambiguity.
 *
 * The server's `total` is a row count, which after grouping is neither a session count nor a finding
 * count — it is findings plus zero-finding sessions added together, and a tile labelled "Total"
 * over that number invites the reader to interpret it as either. These two are computed from the
 * grouped rows so each tile means one thing.
 */
export function countSessions(groups) {
  return {
    sessions: groups.length,
    findings: groups.reduce((n, group) => n + group.findings.length, 0),
  }
}
