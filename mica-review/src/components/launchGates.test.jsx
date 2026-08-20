import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { LaunchGates } from './LaunchGates.jsx'

/**
 * The checklist's one job is that an unready project cannot read as a ready one.
 *
 * A development project starts sessions even with every gate failing, so `may_start_sessions` is
 * true there. Almost every test below is a variation on: that fact must never be shown without the
 * reason for it.
 */

const gate = (over = {}) => ({
  id: 'models',
  title: 'Model aliases resolve',
  passed: true,
  detail: 'gpt-5-4, gemini-2.5-flash registered.',
  how_to_fix: '',
  deliberate: false,
  ...over,
})

const ackGate = (passed) =>
  gate({
    id: 'critical_acknowledgment_minutes',
    title: 'Critical-finding acknowledgment target',
    passed,
    detail: passed ? 'Set to 60 minutes.' : 'Not set.',
    how_to_fix: passed ? '' : 'Study leadership decides the target.',
    deliberate: true,
  })

const state = (over = {}) => ({
  loading: false,
  error: null,
  ready: true,
  mayStartSessions: true,
  gates: [gate()],
  ...over,
})

describe('the summary', () => {
  it('says all gates pass when they do', () => {
    render(<LaunchGates state={state()} />)

    expect(screen.getByText(/All 1 launch gates pass/i)).toBeTruthy()
  })

  it('never says sessions are running without saying it is only because this is development', () => {
    // The whole point. "Sessions are still starting" above a red checklist reads as reassurance, and
    // a PI would reasonably conclude they are ready to launch.
    render(
      <LaunchGates
        state={state({ ready: false, mayStartSessions: true, gates: [gate({ passed: false })] })}
      />,
    )

    expect(screen.getByText(/development only/i)).toBeTruthy()
    expect(screen.getByText(/because this is a development project/i)).toBeTruthy()
    expect(screen.getByText(/would be refused/i)).toBeTruthy()
  })

  it('says sessions are refused when a production project is blocked', () => {
    render(
      <LaunchGates
        state={state({ ready: false, mayStartSessions: false, gates: [gate({ passed: false })] })}
      />,
    )

    expect(screen.getByText(/sessions are refused/i)).toBeTruthy()
    expect(screen.getByText(/technical-fallback wording/i)).toBeTruthy()
    // Reviewing sessions that already happened is never blocked, and a PI needs to know that.
    expect(screen.getByText(/Scanning, review and\s+notification.*are unaffected/i)).toBeTruthy()
  })

  it('says nothing is broken when only a decision is outstanding', () => {
    render(
      <LaunchGates
        state={state({ ready: false, mayStartSessions: true, gates: [ackGate(false), gate()] })}
      />,
    )

    expect(screen.getByText(/Nothing here is broken/i)).toBeTruthy()
    expect(screen.getByText(/a decision.*for\s+study leadership/i)).toBeTruthy()
  })

  it('does not claim nothing is broken when something is', () => {
    render(
      <LaunchGates
        state={state({
          ready: false,
          mayStartSessions: true,
          gates: [ackGate(false), gate({ passed: false })],
        })}
      />,
    )

    expect(screen.queryByText(/Nothing here is broken/i)).toBeNull()
  })

  it('counts failures against the total', () => {
    render(
      <LaunchGates
        state={state({
          ready: false,
          mayStartSessions: false,
          gates: [gate({ passed: false }), gate({ id: 'a' }), gate({ id: 'b', passed: false })],
        })}
      />,
    )

    expect(screen.getByText(/2 of 3 launch gates fail/i)).toBeTruthy()
  })
})

describe('the gate rows', () => {
  it('shows passing gates too, so the list reads as a checklist', () => {
    // A list that only showed failures could not be read as a checklist, and a checklist is what
    // somebody needs in order to see what is left.
    render(
      <LaunchGates
        state={state({
          ready: false,
          mayStartSessions: false,
          gates: [gate({ id: 'p', title: 'Passing thing' }), gate({ id: 'f', title: 'Failing thing', passed: false })],
        })}
      />,
    )

    expect(screen.getByText('Passing thing')).toBeTruthy()
    expect(screen.getByText('Failing thing')).toBeTruthy()
    expect(screen.getAllByText('Passing').length).toBe(1)
    expect(screen.getAllByText('Blocking').length).toBe(1)
  })

  it('keeps the server order rather than sorting failures first', () => {
    // The order is stable and meaningful - the deliberate blocker is first - and a list whose rows
    // move around is one people re-read from the top every time.
    render(
      <LaunchGates
        state={state({
          ready: false,
          mayStartSessions: false,
          gates: [gate({ id: 'a', title: 'First' }), gate({ id: 'b', title: 'Second', passed: false })],
        })}
      />,
    )

    const titles = screen.getAllByRole('listitem').map((li) => li.textContent)
    expect(titles[0]).toContain('First')
    expect(titles[1]).toContain('Second')
  })

  it('labels the deliberate blocker as a decision, not a fault', () => {
    // The handoff ships critical_acknowledgment_minutes unset on purpose. Rendering it as an error
    // would send somebody looking for a bug.
    render(
      <LaunchGates
        state={state({ ready: false, mayStartSessions: true, gates: [ackGate(false)] })}
      />,
    )

    expect(screen.getByText('Awaiting a decision')).toBeTruthy()
    expect(screen.queryByText('Blocking')).toBeNull()
  })

  it('never signals state by colour alone', () => {
    // Roughly one in twelve men cannot rely on the red/green pair, and this is a list whose entire
    // purpose is telling those two apart.
    render(
      <LaunchGates
        state={state({
          ready: false,
          mayStartSessions: false,
          gates: [gate(), gate({ id: 'f', passed: false }), ackGate(false)],
        })}
      />,
    )

    for (const label of ['Passing', 'Blocking', 'Awaiting a decision']) {
      expect(screen.getByText(label)).toBeTruthy()
    }
  })

  it('shows how to clear a failing gate but not a passing one', () => {
    render(
      <LaunchGates
        state={state({ ready: false, mayStartSessions: true, gates: [ackGate(false), ackGate(true)] })}
      />,
    )

    // Advice on a green line is what makes people stop reading a checklist.
    expect(screen.getAllByText(/To clear it:/).length).toBe(1)
  })
})

describe('loading and failure', () => {
  it('shows a skeleton while loading rather than an empty checklist', () => {
    render(<LaunchGates state={state({ loading: true, gates: [] })} />)

    expect(screen.getByText('Loading…')).toBeTruthy()
    expect(screen.queryByRole('listitem')).toBeNull()
  })

  it('reports a load failure instead of showing zero gates', () => {
    render(<LaunchGates state={state({ error: 'nope', gates: [] })} />)

    expect(screen.getByText(/could not be loaded/i)).toBeTruthy()
    expect(screen.getByText('nope')).toBeTruthy()
  })

  it('treats an empty checklist as unknown, not as everything passing', () => {
    // An empty list rendering as a green "all pass" is the single worst outcome available here.
    render(<LaunchGates state={state({ ready: true, gates: [] })} />)

    expect(screen.getByText(/No gates were reported/i)).toBeTruthy()
    expect(screen.getByText(/not the same as everything passing/i)).toBeTruthy()
    expect(screen.queryByText(/launch gates pass/i)).toBeNull()
  })
})

describe('prose', () => {
  it('renders backticked setting names as code, not as literal backticks', () => {
    // The server writes them in backticks. Rendered raw, a reader saw
    // "set `ra_review_policy.critical_acknowledgment_minutes` in the notification policy".
    render(
      <LaunchGates
        state={state({
          ready: false,
          mayStartSessions: true,
          gates: [
            gate({
              passed: false,
              deliberate: true,
              how_to_fix: 'Set `ra_review_policy.critical_acknowledgment_minutes` in the policy.',
            }),
          ],
        })}
      />,
    )

    const fix = screen.getByText(/To clear it:/).closest('p')
    expect(fix.textContent).not.toContain('`')
    expect(fix.querySelector('code')?.textContent).toBe(
      'ra_review_policy.critical_acknowledgment_minutes',
    )
  })
})

