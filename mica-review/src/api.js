/**
 * The one place that talks to the module.
 *
 * Every call goes through REDCap's JavaScript Module Object, which signs the request and carries the
 * CSRF token - so this file never builds a URL or handles a token itself. It also never retries: a
 * disposition is not idempotent, and a silent retry on a timeout could apply one reviewer's decision
 * twice or race their own second attempt.
 */

/** The bootstrap the module page writes. Read lazily so tests can install a fake first. */
function bootstrap() {
  return typeof window !== 'undefined' ? window.mica_review : undefined
}

/**
 * REDCap's JSMO for this module.
 *
 * Read from the global the page assigns, not discovered. The framework names the object after the
 * module's namespace - `ExternalModules.Stanford.MICA` - and an earlier version of this function
 * tried to find it by scanning `Object.keys(window.ExternalModules)` for a key containing "MICA".
 * That never matched, because the top-level key is `Stanford` and MICA is nested inside it. The
 * result was a dashboard that rendered fine and then said "could not reach REDCap" for a reason
 * nothing on screen could explain. The page knows the name; it passes it.
 */
function jsmo() {
  if (typeof window === 'undefined') return undefined
  return window.mica_review_jsmo
}

export class ApiError extends Error {
  constructor(message, status, extra = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    Object.assign(this, extra)
  }
}

/**
 * Call one module action.
 *
 * The module's own response shape is `{ok, ...}` with a `status`, and it is nested inside the
 * framework's `{success, payload}` envelope. Both layers can fail for different reasons - a CSRF
 * rejection versus a role refusal - and collapsing them would report an expired session as a
 * permissions problem.
 */
export async function call(action, payload = {}) {
  const module = jsmo()

  if (!module || typeof module.ajax !== 'function') {
    throw new ApiError(
      'This page could not reach REDCap. Reload it; if that does not help, the module is not ' +
        'loaded correctly on this project.',
      0,
    )
  }

  let envelope
  try {
    envelope = await module.ajax(action, payload)
  } catch (cause) {
    // A network or framework-level failure. Distinguished from a refusal because the reviewer's
    // next step is different: retry versus ask for access.
    throw new ApiError(
      'The request did not reach the server. Check your connection and try again — nothing was saved.',
      0,
      { cause },
    )
  }

  // The framework wraps a successful hook call; an unsuccessful one carries its own error.
  const body = envelope && typeof envelope === 'object' && 'payload' in envelope
    ? envelope.payload
    : envelope

  if (envelope && envelope.success === false) {
    throw new ApiError(
      envelope.error || 'REDCap rejected the request. Your session may have expired — reload the page.',
      401,
    )
  }

  if (!body || typeof body !== 'object') {
    throw new ApiError('The server sent a response this page could not read.', 500)
  }

  if (body.ok === false) {
    throw new ApiError(body.error || 'The request was refused.', body.status || 400, {
      currentVersion: body.current_version,
    })
  }

  return body
}

export const api = {
  bootstrap,
  queue: (filters) => call('reviewQueue', { filters }),
  session: (record, eventId, instance) =>
    call('reviewSession', { record, event_id: eventId, instance }),
  disposition: (record, eventId, instance, review) =>
    call('submitDisposition', { record, event_id: eventId, instance, review }),
  history: () => call('reviewHistory', {}),
  audit: (limit) => call('auditTrail', { limit }),
  launchGates: () => call('launchReadiness', {}),
}
