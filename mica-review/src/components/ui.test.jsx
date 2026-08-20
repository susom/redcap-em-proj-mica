import { describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Queue } from './Queue.jsx'
import { SessionReview } from './SessionReview.jsx'
import { DispositionForm } from './DispositionForm.jsx'

/**
 * These test the UI's safety-critical claims, not its looks.
 *
 * Every one of them is a variation on the same theme: a reviewer must never be able to read an
 * absence as a clean result, or a model suggestion as something that already happened.
 */

const finding = (over = {}) => ({
  instance: 1,
  finding_id: 'f-1',
  finding_concern_type: 'self_harm',
  finding_urgency: 'critical',
  finding_summary: 'passive suicidal ideation',
  finding_source_role: 'participant',
  finding_confidence: '0.91',
  finding_evidence: [
    { message_id: 'L1', speaker_role: 'participant', exact_quote: 'not waking up' },
  ],
  finding_rec_actions: ['alert_care_team'],
  finding_rec_targets: ['care_team'],
  review_status: 'pending',
  review_lock_version: 0,
  ...over,
})

const session = (over = {}) => ({
  record: '2',
  event_id: 1008,
  instance: 1,
  session_type: 'baseline',
  transcript_ref: 'T900',
  run_status: 'ok',
  job_status: 'ready_for_review',
  job_last_error: '',
  resolved_model: 'gemini-2.5-flash-002',
  attempts: 1,
  integrity: 'verified',
  messages: [
    {
      message_id: 'L1',
      sequence: 1,
      speaker_role: 'participant',
      content: 'I keep thinking about not waking up.',
      timestamp: null,
    },
  ],
  findings: [finding()],
  ...over,
})

describe('Queue — an empty list must say which kind of empty', () => {
  const base = { loading: false, error: null, queue: [], summary: null }

  it('distinguishes "nothing scanned" from "filtered to nothing"', async () => {
    const { unmount } = render(
      <Queue state={base} filters={{}} onFilters={() => {}} onOpen={() => {}} onRefresh={() => {}} />,
    )

    expect(screen.getByText(/No sessions have been scanned yet/i)).toBeInTheDocument()
    // The sentence that matters: an empty queue is not a set of clean screens.
    expect(screen.getByText(/not a set of sessions that were screened and found clean/i)).toBeInTheDocument()
    unmount()

    render(
      <Queue
        state={base}
        filters={{ urgency: 'critical' }}
        onFilters={() => {}}
        onOpen={() => {}}
        onRefresh={() => {}}
      />,
    )

    expect(screen.getByText(/No sessions match these filters/i)).toBeInTheDocument()
    expect(screen.getByText(/That is a filter result, not an empty queue/i)).toBeInTheDocument()
  })

  it('shows a load failure instead of an empty list', async () => {
    // A failed load rendering as "no sessions" is the worst possible outcome on this screen.
    render(
      <Queue
        state={{ ...base, error: 'the server did not answer' }}
        filters={{}}
        onFilters={() => {}}
        onOpen={() => {}}
        onRefresh={() => {}}
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent(/could not be loaded/i)
    expect(screen.queryByText(/No sessions have been scanned yet/i)).not.toBeInTheDocument()
  })
})

describe('Queue — an unscreened session does not look like an urgency', () => {
  it('says "Not screened" in words rather than showing a severity', () => {
    const rows = [
      {
        job_id: 1,
        record: '2',
        instance: 1,
        event_id: 1008,
        session_type: 'baseline',
        job_status: 'manual_review_required',
        finding_concern_type: 'scan_failure',
        finding_urgency: null,
        review_status: 'pending',
        created: 1_700_000_000,
      },
    ]

    render(
      <Queue
        state={{ loading: false, error: null, queue: rows, summary: null }}
        filters={{}}
        onFilters={() => {}}
        onOpen={() => {}}
        onRefresh={() => {}}
      />,
    )

    // Scoped to the row: "Critical" also exists as a filter <option>, and asserting against the
    // whole document would have failed for a reason that has nothing to do with the row.
    const row = screen.getByRole('button', { name: /not screened/i })

    expect(within(row).getByText('Not screened')).toBeInTheDocument()
    expect(within(row).getByText(/This session was not screened/i)).toBeInTheDocument()
    expect(within(row).queryByText('Critical')).not.toBeInTheDocument()
  })

  it('opens the session when the row is activated', async () => {
    const onOpen = vi.fn()
    const row = {
      job_id: 1,
      record: '2',
      instance: 1,
      event_id: 1008,
      session_type: 'baseline',
      job_status: 'ready_for_review',
      finding_id: 'f-1',
      finding_concern_type: 'self_harm',
      finding_urgency: 'critical',
      review_status: 'pending',
      created: 1_700_000_000,
    }

    render(
      <Queue
        state={{ loading: false, error: null, queue: [row], summary: null }}
        filters={{}}
        onFilters={() => {}}
        onOpen={onOpen}
        onRefresh={() => {}}
      />,
    )

    // A button, so keyboard works without any extra handling.
    await userEvent.click(screen.getByRole('button', { name: /Self-harm/i }))

    expect(onOpen).toHaveBeenCalledWith(row)
  })
})

describe('SessionReview — a clean screen is not an empty list', () => {
  const props = {
    canDisposition: true,
    onDisposition: () => {},
    onBack: () => {},
    busy: false,
  }

  it('says the scanner looked and supported nothing', () => {
    render(
      <SessionReview
        {...props}
        state={{ loading: false, error: null, session: session({ findings: [], run_status: 'ok' }) }}
      />,
    )

    expect(screen.getByText(/Screened — no concern supported/i)).toBeInTheDocument()
    expect(screen.getByText(/completed screen, not an absent one/i)).toBeInTheDocument()
  })

  it('says the opposite when the scan did not complete', () => {
    render(
      <SessionReview
        {...props}
        state={{
          loading: false,
          error: null,
          session: session({
            findings: [],
            run_status: 'content_filter',
            job_status: 'manual_review_required',
            job_last_error: 'blocked by the provider',
          }),
        }}
      />,
    )

    // Said in BOTH places by design - the scan panel and the findings panel - so a reviewer who
    // scrolled past one still reads the other. Asserting a single match would fail on the design.
    expect(screen.getAllByText(/has not been screened/i).length).toBeGreaterThanOrEqual(1)
    expect(screen.queryByText(/no concern supported/i)).not.toBeInTheDocument()
    // The job's own error is surfaced, not just its status.
    expect(screen.getByText(/blocked by the provider/i)).toBeInTheDocument()
  })

  it('shows the job status beside the run status when they disagree', () => {
    // The one place run_status alone misleads: an `ok` run under a job that released nothing.
    render(
      <SessionReview
        {...props}
        state={{
          loading: false,
          error: null,
          session: session({
            findings: [],
            run_status: 'ok',
            job_status: 'manual_review_required',
            job_last_error: 'the review instrument does not exist',
          }),
        }}
      />,
    )

    // Both statuses on screen at once: the model call completed, and nothing was released.
    expect(screen.getAllByText(/NOT SCREENED — needs a human read/i).length).toBeGreaterThanOrEqual(1)
    expect(screen.getByText(/the review instrument does not exist/i)).toBeInTheDocument()
    expect(screen.getByText('Completed')).toBeInTheDocument()
  })

  it('refuses to show a transcript that fails its own hash', () => {
    render(
      <SessionReview
        {...props}
        state={{
          loading: false,
          error: null,
          session: session({ integrity: 'hash_mismatch', messages: [] }),
        }}
      />,
    )

    expect(screen.getByText(/does not match its own hash/i)).toBeInTheDocument()
    expect(screen.getByText(/may have been altered after it was pinned/i)).toBeInTheDocument()
  })

  it('highlights the cited quote inside the message', () => {
    render(
      <SessionReview {...props} state={{ loading: false, error: null, session: session() }} />,
    )

    const marks = screen.getAllByText('not waking up', { selector: 'mark' })
    expect(marks.length).toBeGreaterThan(0)
  })

  it('states that a model recommendation has notified nobody', () => {
    // A reviewer must never be able to read a suggestion as something that already happened.
    render(
      <SessionReview {...props} state={{ loading: false, error: null, session: session() }} />,
    )

    expect(screen.getByText(/A suggestion only\. Nobody has been notified\./i)).toBeInTheDocument()
    expect(screen.getByText(/Alert care team/i)).toBeInTheDocument()
  })

  it('warns when a released finding cites no evidence', () => {
    render(
      <SessionReview
        {...props}
        state={{
          loading: false,
          error: null,
          session: session({ findings: [finding({ finding_evidence: [] })] }),
        }}
      />,
    )

    expect(screen.getByText(/cites no evidence/i)).toBeInTheDocument()
  })
})

describe('DispositionForm — the decision gate', () => {
  it('will not submit an ending decision without a rationale', async () => {
    const onSubmit = vi.fn()
    render(<DispositionForm finding={finding()} canDisposition onSubmit={onSubmit} busy={false} />)

    await userEvent.click(screen.getByRole('radio', { name: /Confirmed/i }))

    const submit = screen.getByRole('button', { name: /Record decision/i })
    expect(submit).toBeDisabled()
    expect(screen.getByText(/A rationale is required to confirm/i)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/Rationale/i), 'Verified against the transcript.')
    expect(submit).toBeEnabled()

    await userEvent.click(submit)
    expect(onSubmit).toHaveBeenCalledOnce()
    expect(onSubmit.mock.calls[0][0]).toMatchObject({
      review_status: 'confirmed',
      review_rationale: 'Verified against the transcript.',
      review_lock_version: '0',
    })
  })

  it('lets a second review go without a rationale', async () => {
    const onSubmit = vi.fn()
    render(<DispositionForm finding={finding()} canDisposition onSubmit={onSubmit} busy={false} />)

    await userEvent.click(screen.getByRole('radio', { name: /Needs second review/i }))

    expect(screen.getByRole('button', { name: /Record decision/i })).toBeEnabled()
  })

  it('always sends the correction fields, so a withdrawal can clear them', async () => {
    // An omitted key means "leave it alone" on the server and cannot clear anything - so a reviewer
    // who withdraws a correction has to have an empty value actually transmitted.
    const onSubmit = vi.fn()
    render(
      <DispositionForm
        finding={finding({ review_corrected_urgency: 'moderate' })}
        canDisposition
        onSubmit={onSubmit}
        busy={false}
      />,
    )

    await userEvent.click(screen.getByRole('radio', { name: /Confirmed/i }))
    await userEvent.type(screen.getByLabelText(/Rationale/i), 'The model was right after all.')

    // Withdraw the correction.
    await userEvent.selectOptions(screen.getByLabelText(/Urgency/i), '')
    await userEvent.click(screen.getByRole('button', { name: /Record decision/i }))

    expect(onSubmit.mock.calls[0][0]).toHaveProperty('review_corrected_urgency', '')
  })

  it('keeps what the reviewer typed when somebody else saved first', async () => {
    // A reviewer who loses a careful paragraph to a colleague's save learns to write short ones.
    const onSubmit = vi.fn().mockRejectedValue(
      Object.assign(new Error('Somebody else saved this finding while you were reviewing it'), {
        status: 409,
      }),
    )

    render(<DispositionForm finding={finding()} canDisposition onSubmit={onSubmit} busy={false} />)

    await userEvent.click(screen.getByRole('radio', { name: /Confirmed/i }))
    const rationale = screen.getByLabelText(/Rationale/i)
    await userEvent.type(rationale, 'A careful paragraph I do not want to lose.')
    await userEvent.click(screen.getByRole('button', { name: /Record decision/i }))

    expect(await screen.findByText(/Somebody else saved this finding first/i)).toBeInTheDocument()
    expect(screen.getByText(/What you typed is still here/i)).toBeInTheDocument()
    expect(rationale).toHaveValue('A careful paragraph I do not want to lose.')
  })

  it('reports a plain failure without claiming a conflict', async () => {
    const onSubmit = vi
      .fn()
      .mockRejectedValue(Object.assign(new Error('saveData refused'), { status: 400 }))

    render(<DispositionForm finding={finding()} canDisposition onSubmit={onSubmit} busy={false} />)

    await userEvent.click(screen.getByRole('radio', { name: /Dismissed/i }))
    await userEvent.type(screen.getByLabelText(/Rationale/i), 'not a concern')
    await userEvent.click(screen.getByRole('button', { name: /Record decision/i }))

    expect(await screen.findByText(/The decision was not saved/i)).toBeInTheDocument()
    expect(screen.queryByText(/Somebody else saved/i)).not.toBeInTheDocument()
  })

  it('offers no form at all to someone who cannot decide', () => {
    render(
      <DispositionForm finding={finding()} canDisposition={false} onSubmit={() => {}} busy={false} />,
    )

    expect(screen.getByText(/You cannot record a decision/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Record decision/i })).not.toBeInTheDocument()
  })

  it('disables the button while saving, so a decision cannot be double-submitted', async () => {
    render(<DispositionForm finding={finding()} canDisposition onSubmit={() => {}} busy />)

    await userEvent.click(screen.getByRole('radio', { name: /Confirmed/i }))
    await userEvent.type(screen.getByLabelText(/Rationale/i), 'x')

    const submit = screen.getByRole('button', { name: /Saving/i })
    expect(submit).toBeDisabled()
  })
})

describe('accessibility basics', () => {
  it('gives the disposition radios one group per finding', () => {
    // Two findings on one page with a shared radio name would make choosing for one clear the other.
    const { container } = render(
      <SessionReview
        canDisposition
        onDisposition={() => {}}
        onBack={() => {}}
        busy={false}
        state={{
          loading: false,
          error: null,
          session: session({
            findings: [finding({ instance: 1 }), finding({ instance: 2, finding_id: 'f-2' })],
          }),
        }}
      />,
    )

    const names = new Set(
      [...container.querySelectorAll('input[type=radio]')].map((el) => el.getAttribute('name')),
    )

    expect(names.size).toBe(2)
  })

  it('labels the transcript region and every form control', () => {
    render(
      <SessionReview
        canDisposition
        onDisposition={() => {}}
        onBack={() => {}}
        busy={false}
        state={{ loading: false, error: null, session: session() }}
      />,
    )

    expect(screen.getByRole('region', { name: /transcript/i })).toBeInTheDocument()
    // Every textarea and select reachable by its label, not by DOM order.
    expect(screen.getByLabelText(/Rationale/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/Reviewer notes/i)).toBeInTheDocument()
  })

  it('announces the queue length for screen readers', () => {
    const row = {
      job_id: 1,
      record: '2',
      instance: 1,
      event_id: 1008,
      session_type: 'baseline',
      job_status: 'ready_for_review',
      finding_id: 'f-1',
      finding_concern_type: 'self_harm',
      finding_urgency: 'critical',
      review_status: 'pending',
      created: 1_700_000_000,
    }

    const { container } = render(
      <Queue
        state={{ loading: false, error: null, queue: [row], summary: null }}
        filters={{}}
        onFilters={() => {}}
        onOpen={() => {}}
        onRefresh={() => {}}
      />,
    )

    const live = container.querySelector('[aria-live="polite"]')
    expect(within(live).getByText(/1 session listed, most urgent first/i)).toBeInTheDocument()
  })
})
