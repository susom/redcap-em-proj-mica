import { useCallback, useEffect, useState } from 'react'
import { api } from './api.js'
import { Queue } from './components/Queue.jsx'
import { SessionReview } from './components/SessionReview.jsx'
import { Notice, Skeleton, Empty } from './components/Notice.jsx'
import { AuditTrail } from './components/AuditTrail.jsx'
import { LaunchGates } from './components/LaunchGates.jsx'

/**
 * The dashboard shell.
 *
 * Deliberately no router. This is a two-screen tool inside a REDCap page, and pulling in a router
 * would mean either fighting REDCap's own URL or maintaining a hash scheme nobody links to. State is
 * the screen.
 */
export function App() {
  const boot = api.bootstrap() || {}

  // Which tabs exist. Asked of the server rather than derived from `roles` here, so RoleService's
  // matrix stays the single answer to "who may do what".
  const canSeeQueue = boot.canSeeQueue !== false
  const canSeeAudit = Boolean(boot.canSeeAudit)
  const canSeeGates = Boolean(boot.canSeeLaunchGates)

  // A super user holds `sysadmin` and nothing else, so they reach this page but `reviewQueue` refuses
  // them. Landing them on a tab that 403s would read as a broken dashboard, when in fact the one
  // thing they are entitled to - the configuration checklist - is right there.
  const [view, setView] = useState(canSeeQueue ? 'queue' : canSeeGates ? 'gates' : 'queue')

  const [filters, setFilters] = useState({})
  const [queueState, setQueueState] = useState({ loading: true, error: null, queue: [], summary: null })
  const [sessionState, setSessionState] = useState({ loading: false, error: null, session: null })
  const [auditState, setAuditState] = useState({ loading: false, error: null, events: [] })
  const [saving, setSaving] = useState(false)
  const [flash, setFlash] = useState(null)

  /**
   * Change screen, and drop whatever the last screen was saying.
   *
   * The flash is about something that just happened on the screen you were on. Leaving it up across
   * a tab change put "Decision recorded: dismissed" at the top of the launch checklist, which reads
   * as a statement about the thing you are now looking at.
   */
  const goTo = useCallback((next) => {
    setView(next)
    setFlash(null)
  }, [])

  const loadQueue = useCallback(async (activeFilters) => {
    setQueueState((s) => ({ ...s, loading: true, error: null }))
    try {
      const body = await api.queue(activeFilters)
      setQueueState({ loading: false, error: null, queue: body.queue || [], summary: body.summary })
    } catch (err) {
      // The queue keeps whatever it had: a transient failure should not blank a list the reviewer
      // was working down.
      setQueueState((s) => ({ ...s, loading: false, error: err.message }))
    }
  }, [])

  useEffect(() => {
    if (view === 'queue') loadQueue(filters)
  }, [view, filters, loadQueue])

  const openSession = useCallback(async (row) => {
    setView('session')
    setSessionState({ loading: true, error: null, session: null })
    try {
      const body = await api.session(row.record, row.event_id, row.instance)
      setSessionState({ loading: false, error: null, session: body.session })
    } catch (err) {
      setSessionState({ loading: false, error: err.message, session: null })
    }
  }, [])

  const reloadSession = useCallback(async () => {
    const current = sessionState.session
    if (!current) return
    try {
      const body = await api.session(current.record, current.event_id, current.instance)
      setSessionState({ loading: false, error: null, session: body.session })
    } catch (err) {
      setSessionState((s) => ({ ...s, error: err.message }))
    }
  }, [sessionState.session])

  const submitDisposition = useCallback(
    async (finding, review) => {
      const current = sessionState.session
      if (!current) return

      setSaving(true)
      try {
        const body = await api.disposition(
          current.record,
          current.event_id,
          finding.instance,
          review,
        )
        setFlash(`Decision recorded: ${body.disposition.review_status.replace(/_/g, ' ')}.`)
        // Reloaded rather than patched in place, so what is on screen is what is stored - including
        // the new lock version, which the next save depends on.
        await reloadSession()
      } finally {
        // Released even on failure, or a rejected save leaves the form permanently disabled.
        setSaving(false)
      }
    },
    [sessionState.session, reloadSession],
  )

  const loadAudit = useCallback(async () => {
    setAuditState({ loading: true, error: null, events: [] })
    try {
      const body = await api.audit(200)
      setAuditState({ loading: false, error: null, events: body.events || [] })
    } catch (err) {
      setAuditState({ loading: false, error: err.message, events: [] })
    }
  }, [])

  useEffect(() => {
    if (view === 'audit') loadAudit()
  }, [view, loadAudit])

  const [gatesState, setGatesState] = useState({ loading: false, error: null, gates: [] })

  const loadGates = useCallback(async () => {
    setGatesState((s) => ({ ...s, loading: true, error: null }))
    try {
      const body = await api.launchGates()
      setGatesState({
        loading: false,
        error: null,
        gates: body.gates || [],
        ready: Boolean(body.ready),
        mayStartSessions: Boolean(body.may_start_sessions),
      })
    } catch (err) {
      setGatesState({ loading: false, error: err.message, gates: [] })
    }
  }, [])

  useEffect(() => {
    if (view === 'gates') loadGates()
  }, [view, loadGates])

  return (
    <div className="mica-shell">
      <header className="mica-header">
        <h1 className="mica-title">MICA safety review</h1>
        <p className="mica-whoami">
          Signed in as <code>{boot.username || 'unknown'}</code>
          {(boot.roles || []).length > 0 ? ` · ${boot.roles.join(', ')}` : ' · no MICA role'}
        </p>
      </header>

      {boot.deidentified ? (
        <Notice kind="info" title="You are seeing aggregates only">
          <p>
            Your role is auditor, so transcripts and participant-level findings are not shown. Counts
            and the audit trail are.
          </p>
        </Notice>
      ) : null}

      <nav className="mica-tabs" aria-label="Views">
        {canSeeQueue ? (
          <button
            type="button"
            className="mica-tab"
            aria-current={view === 'queue' ? 'page' : undefined}
            onClick={() => goTo('queue')}
          >
            Queue
          </button>
        ) : null}
        {canSeeGates ? (
          <button
            type="button"
            className="mica-tab"
            aria-current={view === 'gates' ? 'page' : undefined}
            onClick={() => goTo('gates')}
          >
            {/* Not "Settings": getPolicy/savePolicy are unimplemented, and a Settings tab holding
                one read-only checklist promises something that is not there. */}
            Launch readiness
          </button>
        ) : null}
        {canSeeAudit ? (
          <button
            type="button"
            className="mica-tab"
            aria-current={view === 'audit' ? 'page' : undefined}
            onClick={() => goTo('audit')}
          >
            Audit trail
          </button>
        ) : null}
        {view === 'queue' ? (
          <button
            type="button"
            className="mica-tab mica-tab--refresh"
            onClick={() => loadQueue(filters)}
            disabled={queueState.loading}
          >
            {queueState.loading ? 'Refreshing…' : 'Refresh'}
          </button>
        ) : null}
        {view === 'gates' ? (
          // Same place as Refresh rather than a second control inside the card: gates change when
          // settings do, so re-checking is the same kind of act as reloading the queue.
          <button
            type="button"
            className="mica-tab mica-tab--refresh"
            onClick={loadGates}
            disabled={gatesState.loading}
          >
            {gatesState.loading ? 'Re-checking…' : 'Re-check'}
          </button>
        ) : null}
      </nav>

      {flash ? (
        <Notice kind="ok" title={flash}>
          <p className="mica-recs">
            The decision is on the record with your username and the time. Actions that notify anyone
            are a separate step.
          </p>
        </Notice>
      ) : null}

      {view === 'queue' ? (
        <Queue
          state={queueState}
          filters={filters}
          onFilters={(next) => {
            // Blank values are dropped so the server sees an absent filter rather than an empty one.
            const cleaned = Object.fromEntries(
              Object.entries(next).filter(([, v]) => v !== '' && v !== false && v != null),
            )
            setFilters(cleaned)
            setFlash(null)
          }}
          onOpen={(row) => {
            setFlash(null)
            openSession(row)
          }}
          onRefresh={() => loadQueue(filters)}
        />
      ) : null}

      {view === 'session' ? (
        <SessionReview
          state={sessionState}
          canDisposition={Boolean(boot.canDisposition)}
          onDisposition={submitDisposition}
          onBack={() => {
            setView('queue')
            setSessionState({ loading: false, error: null, session: null })
          }}
          busy={saving}
        />
      ) : null}

      {view === 'gates' ? <LaunchGates state={gatesState} /> : null}

      {view === 'audit' ? (
        auditState.loading ? (
          <Skeleton rows={6} />
        ) : auditState.error ? (
          <Notice kind="error" title="The audit trail could not be loaded">
            <p>{auditState.error}</p>
          </Notice>
        ) : auditState.events.length === 0 ? (
          <Empty title="No audit events recorded yet">
            <p>Every queue view, session read and decision appears here once it happens.</p>
          </Empty>
        ) : (
          <AuditTrail events={auditState.events} />
        )
      ) : null}
    </div>
  )
}
