import { useMemo } from 'react'
import { UrgencyBadge, ReviewChip } from './Badges.jsx'
import { Empty, Notice, Skeleton } from './Notice.jsx'
import { countSessions, groupBySession } from '../queue.js'
import {
  CONCERN,
  JOB_STATUS,
  SESSION_TYPE,
  URGENCY,
  REVIEW_STATUS,
  ago,
  band,
  label,
} from '../labels.js'

/**
 * The counts strip. Ordered by what a reviewer acts on first.
 *
 * `Total` used to show the server's row count, which after grouping is findings plus zero-finding
 * sessions added together — a number that invites being read as either. The last two tiles now name
 * the two things separately, counted from the grouped rows on screen, so each tile means one thing.
 */
function Summary({ summary, counts }) {
  const stats = [
    { key: 'unscreened', label: 'Not screened', value: summary.unscreened, cls: 'mica-stat--alarm' },
    { key: 'critical', label: 'Critical', value: summary.critical, cls: 'mica-stat--critical' },
    { key: 'high', label: 'High', value: summary.high },
    { key: 'awaiting_review', label: 'Awaiting review', value: summary.awaiting_review },
    { key: 'confirmed', label: 'Confirmed', value: summary.confirmed },
    { key: 'findings', label: 'Findings', value: counts.findings },
    { key: 'sessions', label: 'Sessions', value: counts.sessions },
  ]

  return (
    <div className="mica-summary">
      {stats.map((stat) => (
        <div key={stat.key} className={`mica-stat ${stat.cls || ''}`}>
          <div className="mica-stat-value">{stat.value ?? 0}</div>
          <div className="mica-stat-label">{stat.label}</div>
        </div>
      ))}
    </div>
  )
}

function Select({ id, label: text, value, onChange, options, allLabel }) {
  return (
    <div className="mica-field">
      <label htmlFor={id}>{text}</label>
      <select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">{allLabel}</option>
        {Object.entries(options).map(([key, name]) => (
          <option key={key} value={key}>
            {name}
          </option>
        ))}
      </select>
    </div>
  )
}

function Filters({ filters, onChange, disabled }) {
  const set = (key) => (value) => onChange({ ...filters, [key]: value })

  return (
    <div className="mica-filters" role="group" aria-label="Filter the queue">
      <Select
        id="mica-f-urgency"
        label="Urgency"
        value={filters.urgency || ''}
        onChange={set('urgency')}
        options={URGENCY}
        allLabel="Any urgency"
      />
      <Select
        id="mica-f-concern"
        label="Concern"
        value={filters.concern_type || ''}
        onChange={set('concern_type')}
        options={CONCERN}
        allLabel="Any concern"
      />
      <Select
        id="mica-f-review"
        label="Review"
        value={filters.review_status || ''}
        onChange={set('review_status')}
        options={REVIEW_STATUS}
        allLabel="Any status"
      />
      <Select
        id="mica-f-session"
        label="Session"
        value={filters.session_type || ''}
        onChange={set('session_type')}
        options={SESSION_TYPE}
        allLabel="Both sessions"
      />
      <div className="mica-field">
        <label htmlFor="mica-f-from">From</label>
        <input
          id="mica-f-from"
          type="date"
          value={filters.from || ''}
          onChange={(e) => set('from')(e.target.value)}
        />
      </div>
      <div className="mica-field">
        <label htmlFor="mica-f-to">To</label>
        <input
          id="mica-f-to"
          type="date"
          value={filters.to || ''}
          onChange={(e) => set('to')(e.target.value)}
        />
      </div>
      <div className="mica-field">
        <label className="mica-radio" htmlFor="mica-f-unreviewed">
          <input
            id="mica-f-unreviewed"
            type="checkbox"
            checked={Boolean(filters.unreviewed_only)}
            onChange={(e) => set('unreviewed_only')(e.target.checked)}
          />
          Needs attention only
        </label>
      </div>
      <button
        type="button"
        className="mica-btn"
        onClick={() => onChange({})}
        disabled={disabled || Object.keys(filters).length === 0}
      >
        Clear filters
      </button>
    </div>
  )
}

/**
 * What this session amounts to, in one sentence a reviewer can triage on.
 *
 * Never a bare count. "3 findings" does not say whether any of it is work, and the two states a
 * count cannot express — screened-and-clean, and not-screened-at-all — are the two a reviewer must
 * never confuse (see Notice.jsx).
 */
function sessionLine(group) {
  if (group.band === 'unscreened') return 'This session was not screened'

  const { findings, awaiting, settled } = group

  if (findings.length === 0) {
    return group.head.job_status === 'ready_for_review'
      ? 'Screened — no supported concern found'
      : label(JOB_STATUS, group.head.job_status)
  }

  const total = `${findings.length} finding${findings.length === 1 ? '' : 's'}`

  if (awaiting === 0) return `${total} · all reviewed`
  if (settled === 0) return `${total} · ${awaiting} awaiting review`
  return `${total} · ${awaiting} awaiting review, ${settled} reviewed`
}

/** One finding inside a session card. A sibling button, never nested inside the header button. */
function FindingLine({ row, onOpen }) {
  const level = band(row)

  return (
    <button type="button" className="mica-card-finding" onClick={() => onOpen(row)}>
      <span className={`mica-card-finding-mark mica-card-finding-mark--${level}`} aria-hidden="true" />
      <span className="mica-card-finding-name">
        {row.finding_concern_type ? label(CONCERN, row.finding_concern_type) : 'Finding'}
      </span>
      <span className="mica-card-finding-tags">
        {/*
          Only when there is an urgency. A scan_failure has none by design — it is an unscreened
          session, not a rated one — and rendering the tag anyway produced an empty box holding an
          em dash, which reads as a value that failed to load rather than one that does not apply.
        */}
        {row.finding_urgency ? (
          <span className={`mica-tag mica-tag--${level}`}>{label(URGENCY, row.finding_urgency)}</span>
        ) : null}
        <ReviewChip status={row.review_status} />
        {row.review_corrected_urgency ? (
          <span className="mica-card-finding-note">
            corrected to {label(URGENCY, row.review_corrected_urgency)}
          </span>
        ) : null}
      </span>
    </button>
  )
}

/**
 * One chat session, with everything the scanner said about it.
 *
 * Findings are always visible rather than behind a disclosure. A queue whose job is to surface
 * safety findings must not make a reviewer open something to discover a critical one — the count in
 * the header is a summary of what is already on screen, not a substitute for it.
 */
function SessionCard({ group, onOpen }) {
  const { head, findings, band: level } = group
  const allSettled = findings.length > 0 && group.awaiting === 0

  return (
    <div
      className={`mica-card mica-card--${level}${allSettled ? ' mica-card--settled' : ''}`}
    >
      <button type="button" className="mica-card-head" onClick={() => onOpen(head)}>
        <UrgencyBadge band={level} />

        {/*
          The separator is followed by a non-breaking space, so the dot can never end a wrapped
          line — "Record 1 ·" then a break, which is what a phone-width card actually did — while
          the label itself still wraps at its own spaces. A nowrap span was tried first and cut the
          label off at 320px instead.
        */}
        <span className="mica-card-id">
          <strong>
            Record <code className="mica-mono">{head.record}</code>
          </strong>
          <span className="mica-card-part">
            <span className="mica-card-sep" aria-hidden="true">
              {'· '}
            </span>
            {label(SESSION_TYPE, head.session_type)}
          </span>
          {head.instance > 1 ? (
            <span className="mica-card-part">
              <span className="mica-card-sep" aria-hidden="true">
                ·{' '}
              </span>
              Session {head.instance}
            </span>
          ) : null}
        </span>

        <span className="mica-card-line">{sessionLine(group)}</span>

        <span className="mica-card-aside">
          {ago(head.created)}
          {head.review_reviewer ? (
            <>
              <br />
              by {head.review_reviewer}
            </>
          ) : null}
        </span>
      </button>

      {findings.length > 0 ? (
        <div className="mica-card-findings">
          {findings.map((row) => (
            <FindingLine key={row.finding_id} row={row} onOpen={onOpen} />
          ))}
        </div>
      ) : null}
    </div>
  )
}

export function Queue({ state, filters, onFilters, onOpen, onRefresh }) {
  const { loading, error, queue, summary } = state
  const groups = useMemo(() => groupBySession(queue), [queue])
  const counts = useMemo(() => countSessions(groups), [groups])

  return (
    <>
      {error ? (
        <Notice kind="error" title="The queue could not be loaded">
          <p>{error}</p>
          <p style={{ marginTop: '0.5rem' }}>
            <button type="button" className="mica-btn" onClick={onRefresh}>
              Try again
            </button>
          </p>
        </Notice>
      ) : null}

      {summary ? <Summary summary={summary} counts={counts} /> : null}

      <Filters filters={filters} onChange={onFilters} disabled={loading} />

      {loading ? <Skeleton rows={4} /> : null}

      {!loading && !error && queue.length === 0 ? (
        // The wording distinguishes the two cases the reviewer cannot otherwise tell apart.
        Object.keys(filters).length > 0 ? (
          <Empty title="No sessions match these filters">
            <p>
              That is a filter result, not an empty queue. Clear the filters to see everything in the
              project.
            </p>
          </Empty>
        ) : (
          <Empty title="No sessions have been scanned yet">
            <p>
              Nothing has reached the review queue for this project. This is an empty queue — not a
              set of sessions that were screened and found clean.
            </p>
          </Empty>
        )
      ) : null}

      {groups.length > 0 ? (
        <>
          <div className="mica-sr-only" aria-live="polite">
            {counts.sessions} session{counts.sessions === 1 ? '' : 's'} listed, most urgent first,
            carrying {counts.findings} finding{counts.findings === 1 ? '' : 's'}.
          </div>
          <div className="mica-queue">
            {groups.map((group) => (
              <SessionCard key={group.key} group={group} onOpen={onOpen} />
            ))}
          </div>
        </>
      ) : null}
    </>
  )
}
