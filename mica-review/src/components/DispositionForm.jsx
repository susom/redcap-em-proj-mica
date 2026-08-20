import { useState } from 'react'
import { Notice } from './Notice.jsx'
import { CONCERN, URGENCY, REVIEW_STATUS } from '../labels.js'

const ENDING = ['confirmed', 'dismissed']

/**
 * The disposition form: the moment a human decides.
 *
 * Everything about it is shaped by one fact — **nothing reaches a care team, PI or protocol lead
 * until this is confirmed.** So:
 *
 * - The rationale is required for confirm and dismiss, and the button stays disabled until it is
 *   there. The server enforces it too; this is so the reviewer finds out before they lose their
 *   place rather than after.
 * - A conflict does not discard what they typed. Their text stays in the form and the message says
 *   so, because a reviewer who loses a careful paragraph to somebody else's save learns to write
 *   short ones.
 * - Corrections can be *withdrawn*, not only set. An empty value is submitted deliberately so the
 *   server clears it — a confirmed finding carrying a correction its own reviewer withdrew is worse
 *   than no correction.
 */
export function DispositionForm({ finding, canDisposition, onSubmit, busy }) {
  const [status, setStatus] = useState('')
  const [rationale, setRationale] = useState(finding.review_rationale || '')
  const [notes, setNotes] = useState(finding.review_notes || '')
  const [concern, setConcern] = useState(finding.review_corrected_concern_type || '')
  const [urgency, setUrgency] = useState(finding.review_corrected_urgency || '')
  const [error, setError] = useState(null)
  const [conflict, setConflict] = useState(null)

  if (!canDisposition) {
    return (
      <Notice kind="info" title="You cannot record a decision">
        <p>
          Your role gives you read access to this session. Confirming or dismissing a finding is
          limited to reviewers and the PI.
        </p>
      </Notice>
    )
  }

  const needsRationale = ENDING.includes(status)
  const rationaleMissing = needsRationale && rationale.trim() === ''
  const canSubmit = status !== '' && !rationaleMissing && !busy

  async function submit(event) {
    event.preventDefault()
    setError(null)
    setConflict(null)

    try {
      await onSubmit({
        review_status: status,
        review_rationale: rationale,
        review_notes: notes,
        // Always sent, even when empty: that is how a withdrawal is expressed. An omitted key means
        // "leave it alone", which cannot clear anything.
        review_corrected_concern_type: concern,
        review_corrected_urgency: urgency,
        review_lock_version: String(finding.review_lock_version ?? 0),
      })
      setStatus('')
    } catch (err) {
      if (err.status === 409) {
        // Their text is deliberately left in place — see the component comment.
        setConflict(err.message)
      } else {
        setError(err.message)
      }
    }
  }

  return (
    <form className="mica-disposition" onSubmit={submit}>
      {conflict ? (
        <Notice kind="warn" title="Somebody else saved this finding first">
          <p>{conflict}</p>
          <p style={{ marginTop: '0.35rem' }}>
            <strong>What you typed is still here.</strong> Reload the session to see their decision,
            then re-apply yours if you still disagree.
          </p>
        </Notice>
      ) : null}

      {error ? (
        <Notice kind="error" title="The decision was not saved">
          <p>{error}</p>
        </Notice>
      ) : null}

      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="mica-stat-label" style={{ marginBottom: '0.35rem' }}>
          Decision
        </legend>
        <div className="mica-radio-set">
          {['confirmed', 'dismissed', 'needs_second_review'].map((value) => (
            <label key={value} className="mica-radio">
              <input
                type="radio"
                name={`disposition-${finding.instance}`}
                value={value}
                checked={status === value}
                onChange={() => setStatus(value)}
              />
              {REVIEW_STATUS[value]}
            </label>
          ))}
        </div>
      </fieldset>

      <div className="mica-field">
        <label htmlFor={`rationale-${finding.instance}`}>
          Rationale{needsRationale ? ' (required)' : ' (optional)'}
        </label>
        <textarea
          id={`rationale-${finding.instance}`}
          value={rationale}
          onChange={(e) => setRationale(e.target.value)}
          aria-describedby={`rationale-help-${finding.instance}`}
          aria-invalid={rationaleMissing ? 'true' : undefined}
        />
        <p id={`rationale-help-${finding.instance}`} className="mica-recs">
          {needsRationale
            ? 'Required to confirm or dismiss. Write it for the next person reading this — including you, in three months.'
            : 'Optional when sending for a second review.'}
        </p>
      </div>

      <details>
        <summary style={{ cursor: 'pointer' }}>Correct the classification (optional)</summary>
        <p className="mica-recs" style={{ marginTop: '0.35rem' }}>
          A correction is stored separately. The model&rsquo;s own classification stays intact, so the
          disagreement itself is on the record. Leave a field blank to withdraw a correction you set
          earlier.
        </p>
        <div className="mica-filters" style={{ marginTop: '0.35rem' }}>
          <div className="mica-field">
            <label htmlFor={`concern-${finding.instance}`}>Concern type</label>
            <select
              id={`concern-${finding.instance}`}
              value={concern}
              onChange={(e) => setConcern(e.target.value)}
            >
              <option value="">No correction</option>
              {Object.entries(CONCERN)
                .filter(([key]) => key !== 'scan_failure')
                .map(([key, name]) => (
                  <option key={key} value={key}>
                    {name}
                  </option>
                ))}
            </select>
          </div>
          <div className="mica-field">
            <label htmlFor={`urgency-${finding.instance}`}>Urgency</label>
            <select
              id={`urgency-${finding.instance}`}
              value={urgency}
              onChange={(e) => setUrgency(e.target.value)}
            >
              <option value="">No correction</option>
              {Object.entries(URGENCY).map(([key, name]) => (
                <option key={key} value={key}>
                  {name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </details>

      <div className="mica-field">
        <label htmlFor={`notes-${finding.instance}`}>Reviewer notes (optional)</label>
        <textarea
          id={`notes-${finding.instance}`}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
        />
      </div>

      <div className="mica-actions">
        <button type="submit" className="mica-btn mica-btn--primary" disabled={!canSubmit}>
          {busy ? 'Saving…' : 'Record decision'}
        </button>
        {status === '' ? (
          <span className="mica-recs">Choose a decision to continue.</span>
        ) : rationaleMissing ? (
          <span className="mica-recs">A rationale is required to {status.replace('_', ' ')}.</span>
        ) : null}
      </div>
    </form>
  )
}
