import { Notice, Skeleton } from './Notice.jsx'

/**
 * The launch checklist.
 *
 * Shows **every** gate, passing and failing, in the order the server returns them. A list that only
 * showed failures could not be read as a checklist, and a checklist is what somebody needs in order
 * to see what is left. The order is stable and meaningful - the deliberate blocker is first - so it
 * is not re-sorted here: a list whose rows move around is one people re-read from the top every time.
 *
 * ## The headline is the hard part
 *
 * A development project may start sessions even with every gate failing, so `may_start_sessions` is
 * `true` there. Printing that on its own above a red checklist reads as reassurance, and a PI would
 * reasonably conclude they are ready to launch - the exact ambiguity this whole gate system exists to
 * prevent. So the headline always says *why* sessions are running, and on a development project it
 * says they would be refused in production.
 */
export function LaunchGates({ state, onRefresh }) {
  if (state.loading) return <Skeleton rows={7} />

  if (state.error) {
    return (
      <Notice kind="error" title="The launch checklist could not be loaded">
        <p>{state.error}</p>
      </Notice>
    )
  }

  const gates = state.gates || []
  if (gates.length === 0) {
    return (
      <Notice kind="error" title="No gates were reported">
        <p>
          The server returned an empty checklist. That is not the same as everything passing — treat
          it as unknown and report it.
        </p>
      </Notice>
    )
  }

  const failing = gates.filter((g) => !g.passed)
  const decisions = failing.filter((g) => g.deliberate)
  const broken = failing.filter((g) => !g.deliberate)

  return (
    <section className="mica-gates" aria-labelledby="mica-gates-heading">
      <div className="mica-gates-head">
        <h2 id="mica-gates-heading" className="mica-gates-title">
          Launch readiness
        </h2>
        <button type="button" className="mica-tab mica-tab--refresh" onClick={onRefresh}>
          Re-check
        </button>
      </div>

      <Summary
        ready={state.ready}
        mayStart={state.mayStartSessions}
        total={gates.length}
        failing={failing.length}
        decisions={decisions.length}
        broken={broken.length}
      />

      <ol className="mica-gate-list">
        {gates.map((gate) => (
          <Gate key={gate.id} gate={gate} />
        ))}
      </ol>
    </section>
  )
}

/**
 * What the state of the checklist means, in one place, before any of the rows.
 *
 * Four distinct situations, and they must not be collapsible into each other: ready; blocked and
 * refusing sessions; blocked but running anyway because this is a development project; and blocked
 * only by a decision nobody has made yet.
 */
function Summary({ ready, mayStart, total, failing, decisions, broken }) {
  if (ready) {
    return (
      <Notice kind="ok" title={`All ${total} launch gates pass`}>
        <p>Sessions can start, transcripts are screened, and findings reach the queue.</p>
      </Notice>
    )
  }

  // Failing but sessions still run: only ever a development project. Saying "sessions are running"
  // without that reason is how an unready study reads as a ready one.
  if (mayStart) {
    return (
      <Notice kind="warn" title={`${failing} of ${total} launch gates fail — development only`}>
        <p>
          Sessions are still starting <strong>because this is a development project</strong>. In a
          production project every one of them would be refused until the list below is clear.
        </p>
        {decisions > 0 && broken === 0 ? (
          <p className="mica-recs">
            Nothing here is broken. What is left is {decisions === 1 ? 'a decision' : 'decisions'} for
            study leadership to make.
          </p>
        ) : null}
      </Notice>
    )
  }

  return (
    <Notice kind="error" title={`${failing} of ${total} launch gates fail — sessions are refused`}>
      <p>
        This project is in production status, so MICA is not starting participant sessions. Anyone
        opening the chat link sees the study&rsquo;s technical-fallback wording. Scanning, review and
        notification of sessions that already happened are unaffected.
      </p>
    </Notice>
  )
}

/**
 * One gate.
 *
 * A failing gate that is `deliberate` is labelled as awaiting a decision rather than as a fault. The
 * only one is the critical-finding acknowledgment target, which the handoff ships unset on purpose so
 * that launching requires study leadership to decide, in writing, how quickly a critical finding must
 * be acknowledged. Rendering it as an error would send somebody looking for a bug.
 */
function Gate({ gate }) {
  const status = gate.passed ? 'pass' : gate.deliberate ? 'decision' : 'fail'
  const label = { pass: 'Passing', decision: 'Awaiting a decision', fail: 'Blocking' }[status]

  return (
    <li className={`mica-gate mica-gate--${status}`}>
      <div className="mica-gate-row">
        {/* The mark is decorative; `label` carries the state for a screen reader, and colour is
            never the only signal. */}
        <span className="mica-gate-mark" aria-hidden="true">
          {gate.passed ? '✓' : gate.deliberate ? '?' : '✕'}
        </span>
        <div className="mica-gate-body">
          <p className="mica-gate-title">
            {gate.title}
            <span className={`mica-gate-state mica-gate-state--${status}`}>{label}</span>
          </p>
          <p className="mica-gate-detail">{gate.detail}</p>
          {gate.how_to_fix ? (
            <p className="mica-gate-fix">
              <strong>To clear it:</strong> {gate.how_to_fix}
            </p>
          ) : null}
        </div>
      </div>
    </li>
  )
}
