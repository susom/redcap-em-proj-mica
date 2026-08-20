import { UrgencyBadge, ReviewChip } from './Badges.jsx'
import { Empty, Notice, Skeleton } from './Notice.jsx'
import {
  CONCERN,
  JOB_STATUS,
  SESSION_TYPE,
  URGENCY,
  REVIEW_STATUS,
  ago,
  band,
  isSettled,
  label,
} from '../labels.js'

/** The counts strip. Ordered by what a reviewer acts on first. */
function Summary({ summary }) {
  const stats = [
    { key: 'unscreened', label: 'Not screened', value: summary.unscreened, cls: 'mica-stat--alarm' },
    { key: 'critical', label: 'Critical', value: summary.critical, cls: 'mica-stat--critical' },
    { key: 'high', label: 'High', value: summary.high },
    { key: 'awaiting_review', label: 'Awaiting review', value: summary.awaiting_review },
    { key: 'confirmed', label: 'Confirmed', value: summary.confirmed },
    { key: 'total', label: 'Total', value: summary.total },
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

function Row({ row, onOpen }) {
  const level = band(row)
  const settled = isSettled(row.review_status)
  const unscreened = level === 'unscreened'

  // A row's headline is the concern, or — when there is no finding — what the job says happened.
  // Never blank: a row with no words on it is a row a reviewer skips.
  const headline = unscreened
    ? 'This session was not screened'
    : row.finding_concern_type
      ? label(CONCERN, row.finding_concern_type)
      : label(JOB_STATUS, row.job_status)

  return (
    <button
      type="button"
      className={`mica-row mica-row--${level}${settled ? ' mica-row--settled' : ''}`}
      onClick={() => onOpen(row)}
    >
      <UrgencyBadge band={level} />

      <span className="mica-row-main">
        <strong className="mica-row-summary">{headline}</strong>
      </span>

      <span className="mica-row-meta">
        <span>
          Record <code className="mica-mono">{row.record}</code>
        </span>
        <span>{label(SESSION_TYPE, row.session_type)}</span>
        {row.instance > 1 ? <span>Session {row.instance}</span> : null}
        {row.finding_id ? <ReviewChip status={row.review_status} /> : null}
        {row.review_corrected_urgency ? (
          <span>Corrected to {label(URGENCY, row.review_corrected_urgency)}</span>
        ) : null}
      </span>

      <span className="mica-row-aside">
        {ago(row.created)}
        {row.review_reviewer ? (
          <>
            <br />
            by {row.review_reviewer}
          </>
        ) : null}
      </span>
    </button>
  )
}

export function Queue({ state, filters, onFilters, onOpen, onRefresh }) {
  const { loading, error, queue, summary } = state

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

      {summary ? <Summary summary={summary} /> : null}

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

      {queue.length > 0 ? (
        <>
          <div className="mica-sr-only" aria-live="polite">
            {queue.length} session{queue.length === 1 ? '' : 's'} listed, most urgent first.
          </div>
          <div className="mica-queue">
            {queue.map((row) => (
              <Row
                key={`${row.job_id}:${row.finding_id || 'none'}`}
                row={row}
                onOpen={onOpen}
              />
            ))}
          </div>
        </>
      ) : null}
    </>
  )
}
