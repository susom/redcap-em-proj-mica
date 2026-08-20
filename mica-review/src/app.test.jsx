import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { App } from './App.jsx'

/**
 * Which tabs exist, and which one you land on.
 *
 * Tab visibility follows the server's answer (`canSee*` on the bootstrap), never a role string
 * re-interpreted here — RoleService's matrix is the single answer to "who may do what", and a second
 * copy of it in JavaScript is a second thing to keep in step. These tests pin that the client asks
 * rather than decides.
 */

const ok = (payload) => Promise.resolve({ success: true, payload: { ok: true, ...payload } })

function boot(over = {}) {
  window.mica_review = {
    username: 'someone',
    roles: ['ra'],
    canDisposition: true,
    deidentified: false,
    canSeeQueue: true,
    canSeeAudit: false,
    canSeeLaunchGates: false,
    ...over,
  }

  window.mica_review_jsmo = {
    ajax: vi.fn((action) => {
      if (action === 'reviewQueue') return ok({ queue: [], summary: null })
      if (action === 'auditTrail') return ok({ events: [] })
      if (action === 'launchReadiness') {
        return ok({
          ready: false,
          may_start_sessions: true,
          gates: [
            {
              id: 'critical_acknowledgment_minutes',
              title: 'Critical-finding acknowledgment target',
              passed: false,
              detail: 'Not set.',
              how_to_fix: 'Study leadership decides the target.',
              deliberate: true,
            },
          ],
          explanation: '',
        })
      }
      return ok({})
    }),
  }
}

beforeEach(() => boot())
afterEach(() => {
  delete window.mica_review
  delete window.mica_review_jsmo
})

const tab = (name) => screen.queryByRole('button', { name })

describe('tab visibility', () => {
  it('hides the launch checklist from a reviewer who may not see it', () => {
    // An RA reviews findings; the configuration checklist belongs to the PI and the sysadmin.
    render(<App />)

    expect(tab('Queue')).toBeTruthy()
    expect(tab('Launch readiness')).toBeNull()
  })

  it('shows it to someone who may', () => {
    boot({ canSeeLaunchGates: true, roles: ['ra', 'pi'] })
    render(<App />)

    expect(tab('Launch readiness')).toBeTruthy()
  })

  it('calls it "Launch readiness", not "Settings"', () => {
    // getPolicy/savePolicy are unimplemented. A Settings tab holding one read-only checklist promises
    // something that is not there.
    boot({ canSeeLaunchGates: true })
    render(<App />)

    expect(tab('Settings')).toBeNull()
    expect(tab('Launch readiness')).toBeTruthy()
  })
})

describe('where a sysadmin lands', () => {
  it('opens the checklist rather than a queue that would refuse them', async () => {
    // A super user holds `sysadmin` and nothing else, so they reach this page but reviewQueue refuses
    // them. Landing on a tab that 403s reads as a broken dashboard, when the one thing they are
    // entitled to — the configuration checklist they own — is right there.
    boot({ roles: ['sysadmin'], canSeeQueue: false, canSeeAudit: false, canSeeLaunchGates: true })
    render(<App />)

    expect(tab('Queue')).toBeNull()
    await waitFor(() => expect(screen.getByText(/Launch readiness/)).toBeTruthy())
    await waitFor(() =>
      expect(screen.getByText(/Critical-finding acknowledgment target/)).toBeTruthy(),
    )
  })

  it('never asks for the queue it cannot have', async () => {
    boot({ roles: ['sysadmin'], canSeeQueue: false, canSeeLaunchGates: true })
    render(<App />)

    await waitFor(() => expect(window.mica_review_jsmo.ajax).toHaveBeenCalled())
    const actions = window.mica_review_jsmo.ajax.mock.calls.map(([a]) => a)
    expect(actions).not.toContain('reviewQueue')
    expect(actions).toContain('launchReadiness')
  })

  it('still opens the queue for a reviewer', async () => {
    render(<App />)

    await waitFor(() => expect(window.mica_review_jsmo.ajax).toHaveBeenCalled())
    expect(window.mica_review_jsmo.ajax.mock.calls.map(([a]) => a)).toContain('reviewQueue')
  })
})

describe('re-checking the gates', () => {
  it('offers Re-check in the tab row, where Refresh already lives', async () => {
    // Gates change when settings do. Same control, same place as the queue's Refresh, rather than a
    // second convention inside the card.
    boot({ canSeeLaunchGates: true })
    render(<App />)

    await userEvent.click(screen.getByRole('button', { name: 'Launch readiness' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Re-check' })).toBeTruthy())

    const before = window.mica_review_jsmo.ajax.mock.calls.filter(([a]) => a === 'launchReadiness').length
    await userEvent.click(screen.getByRole('button', { name: 'Re-check' }))

    await waitFor(() =>
      expect(
        window.mica_review_jsmo.ajax.mock.calls.filter(([a]) => a === 'launchReadiness').length,
      ).toBe(before + 1),
    )
  })
})

