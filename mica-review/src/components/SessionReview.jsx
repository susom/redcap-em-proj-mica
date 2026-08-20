import { useMemo, useState } from 'react'
import { Chip, ReviewChip, UrgencyBadge } from './Badges.jsx'
import { DispositionForm } from './DispositionForm.jsx'
import { Empty, Notice, Skeleton } from './Notice.jsx'
import { Transcript } from './Transcript.jsx'
import { quotesForFindings, evidenceOf } from '../evidence.js'
import {
  CONCERN,
  JOB_STATUS,
  NOTIFICATION_TARGET,
  RECOMMENDED_ACTION,
  RUN_STATUS,
  SESSION_TYPE,
  SPEAKER,
  URGENCY,
  label,
} from '../labels.js'

/**
 * The scan's own metadata.
 *
 * Shows the job status and its error ALONGSIDE the run status, deliberately. They answer different
 * questions: the run status describes the model call, and whether anything was released is the job's
 * business. An `ok` run under a job in `manual_review_required` is a scan that worked and a release
 * that did not — and showing the run row alone would read as a completed review.
 */
function ScanMeta({ session }) {
  const released = session.job_status === 'ready_for_review' || session.job_status === 'review_complete'

  return (
    <section className="mica-panel">
      <h3 className="mica-panel-title">Scan</h3>

      {!released ? (
        <Notice
          kind={session.job_status === 'manual_review_required' ? 'error' : 'warn'}
          title={label(JOB_STATUS, session.job_status)}
        >
          <p>
            {session.job_status === 'manual_review_required'
              ? 'Nothing was released for this session. It has not been screened — read the transcript yourself.'
              : 'This session is still moving through the scan queue.'}
          </p>
          {session.job_last_error ? (
            <p style={{ marginTop: '0.35rem' }} className="mica-recs">
              {session.job_last_error}
            </p>
          ) : null}
        </Notice>
      ) : null}

      <dl className="mica-meta-grid">
        <dt>Session</dt>
        <dd>
          {label(SESSION_TYPE, session.session_type)}
          {session.instance > 1 ? ` · session ${session.instance}` : ''}
        </dd>

        <dt>Record</dt>
        <dd>
          <code className="mica-mono">{session.record}</code>
        </dd>

        <dt>Transcript</dt>
        <dd>
          <code className="mica-mono">{session.transcript_ref}</code>
        </dd>

        <dt>Queue status</dt>
        <dd>{label(JOB_STATUS, session.job_status)}</dd>

        <dt>Model call</dt>
        <dd>
          {label(RUN_STATUS, session.run_status)}
          {session.attempts > 1 ? ` · after ${session.attempts} attempts` : ''}
        </dd>

        <dt>Deployment</dt>
        <dd>
          <code className="mica-mono">{session.resolved_model || '—'}</code>
        </dd>

        {session.ended_at ? (
          <>
            <dt>Ended</dt>
            <dd>{new Date(session.ended_at).toLocaleString()}</dd>
          </>
        ) : null}
      </dl>
    </section>
  )
}

function Finding({ finding, canDisposition, onDisposition, onJumpTo, busy }) {
  const unscreened = finding.finding_concern_type === 'scan_failure'
  const level = unscreened ? 'unscreened' : finding.finding_urgency || 'quality'
  const evidence = evidenceOf(finding)

  return (
    <article className={`mica-finding mica-finding--${level}`}>
      <header className="mica-finding-head">
        <UrgencyBadge band={level} />
        <strong>{label(CONCERN, finding.finding_concern_type)}</strong>
        <ReviewChip status={finding.review_status} />
        {finding.finding_source_role ? (
          <Chip>{label(SPEAKER, finding.finding_source_role)}</Chip>
        ) : null}
        {finding.finding_confidence ? (
          <Chip>confidence {Number(finding.finding_confidence).toFixed(2)}</Chip>
        ) : null}
      </header>

      <p className="mica-finding-summary">{finding.finding_summary}</p>

      {evidence.length > 0 ? (
        <>
          <p className="mica-stat-label">Evidence</p>
          <ul className="mica-evidence">
            {evidence.map((item, i) => (
              <li key={i} className="mica-quote">
                <div>&ldquo;{item.exact_quote}&rdquo;</div>
                <button type="button" className="mica-quote-src" onClick={() => onJumpTo(item.message_id)}>
                  {label(SPEAKER, item.speaker_role)} · {item.message_id} — show in transcript
                </button>
              </li>
            ))}
          </ul>
        </>
      ) : unscreened ? null : (
        <Notice kind="warn" title="This finding cites no evidence">
          <p>Every released finding should carry at least one verified quote. Treat it as unverified.</p>
        </Notice>
      )}

      {(finding.finding_rec_actions || []).length > 0 ? (
        <p className="mica-recs">
          <strong>Model suggests:</strong>{' '}
          {finding.finding_rec_actions.map((a) => label(RECOMMENDED_ACTION, a)).join(', ')}
          {(finding.finding_rec_targets || []).length > 0 ? (
            <>
              {' · '}
              <strong>notify:</strong>{' '}
              {finding.finding_rec_targets.map((t) => label(NOTIFICATION_TARGET, t)).join(', ')}
            </>
          ) : null}
          {/* Stated plainly, every time. A reviewer must never be able to read a model
              recommendation as something that already happened. */}
          <br />
          <em>A suggestion only. Nobody has been notified.</em>
        </p>
      ) : null}

      {finding.review_status && finding.review_status !== 'pending' ? (
        <dl className="mica-meta-grid" style={{ marginTop: '0.5rem' }}>
          <dt>Decided by</dt>
          <dd>
            {finding.review_reviewer || '—'}
            {finding.review_reviewed_at ? ` · ${finding.review_reviewed_at}` : ''}
          </dd>
          {finding.review_rationale ? (
            <>
              <dt>Rationale</dt>
              <dd>{finding.review_rationale}</dd>
            </>
          ) : null}
          {finding.review_corrected_urgency ? (
            <>
              <dt>Corrected urgency</dt>
              <dd>{label(URGENCY, finding.review_corrected_urgency)}</dd>
            </>
          ) : null}
          {finding.review_corrected_concern_type ? (
            <>
              <dt>Corrected concern</dt>
              <dd>{label(CONCERN, finding.review_corrected_concern_type)}</dd>
            </>
          ) : null}
        </dl>
      ) : null}

      <DispositionForm
        finding={finding}
        canDisposition={canDisposition}
        onSubmit={(review) => onDisposition(finding, review)}
        busy={busy}
      />
    </article>
  )
}

export function SessionReview({ state, canDisposition, onDisposition, onBack, busy }) {
  const { loading, error, session } = state
  const [focusedMessage, setFocusedMessage] = useState(null)

  const allQuotes = useMemo(
    () => (session ? quotesForFindings(session.findings) : new Map()),
    [session],
  )

  function jumpTo(messageId) {
    setFocusedMessage(messageId)
    const el = document.getElementById(`mica-msg-${messageId}`)
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' })
      // Focus as well as scroll, so a keyboard or screen-reader user actually arrives there rather
      // than having the viewport move underneath them.
      el.setAttribute('tabindex', '-1')
      el.focus({ preventScroll: true })
    }
  }

  return (
    <>
      <button type="button" className="mica-back" onClick={onBack}>
        &larr; Back to the queue
      </button>

      {error ? (
        <Notice kind="error" title="This session could not be loaded">
          <p>{error}</p>
        </Notice>
      ) : null}

      {loading ? <Skeleton rows={5} /> : null}

      {session ? (
        <div className="mica-session">
          <div>
            <ScanMeta session={session} />
            <section className="mica-panel" style={{ marginTop: '1rem' }}>
              <h3 className="mica-panel-title">Transcript</h3>
              <Transcript session={session} quotesByMessage={allQuotes} />
            </section>
          </div>

          <section className="mica-panel">
            <h3 className="mica-panel-title">
              Findings{session.findings?.length ? ` (${session.findings.length})` : ''}
            </h3>

            {(session.findings || []).length === 0 ? (
              // The distinction this whole pipeline exists for, said in the one place a reviewer
              // will read it.
              session.run_status === 'ok' ? (
                <Notice kind="ok" title="Screened — no concern supported">
                  <p>
                    The scanner read this conversation and supported no safety, quality or privacy
                    concern. This is a completed screen, not an absent one.
                  </p>
                </Notice>
              ) : (
                <Empty title="No findings were released">
                  <p>
                    The scan did not complete, so this session has not been screened. Read the
                    transcript.
                  </p>
                </Empty>
              )
            ) : null}

            {(session.findings || []).map((finding) => (
              <Finding
                key={finding.finding_id || finding.instance}
                finding={finding}
                canDisposition={canDisposition}
                onDisposition={onDisposition}
                onJumpTo={jumpTo}
                busy={busy}
              />
            ))}

            {focusedMessage ? (
              <p className="mica-sr-only" aria-live="polite">
                Showing message {focusedMessage} in the transcript.
              </p>
            ) : null}
          </section>
        </div>
      ) : null}
    </>
  )
}
