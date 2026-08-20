/**
 * Human labels for the enum values the server sends, in one place.
 *
 * Not a convenience: the strings a reviewer reads when deciding whether a session was screened are
 * part of the safety design, and scattering them across components is how "manual review required"
 * quietly becomes "failed" somewhere and "pending" somewhere else.
 *
 * An unknown value falls back to itself rather than to a friendly guess. A reviewer seeing a raw
 * `some_new_status` knows something changed; one seeing "Unknown" does not.
 */

export const URGENCY = {
  critical: 'Critical',
  high: 'High',
  moderate: 'Moderate',
  quality: 'Quality',
}

export const CONCERN = {
  self_harm: 'Self-harm',
  violence: 'Violence',
  severe_withdrawal: 'Severe withdrawal',
  medical_emergency: 'Medical emergency',
  impaired_capacity: 'Impaired capacity',
  dangerous_alcohol_use: 'Dangerous alcohol use',
  abuse_or_environmental_danger: 'Abuse or environmental danger',
  unsupported_treatment: 'Unsupported treatment',
  medical_inaccuracy: 'Medical inaccuracy',
  invented_history: 'Invented history',
  discrimination_or_shaming: 'Discrimination or shaming',
  dangerous_endorsement: 'Dangerous endorsement',
  privacy: 'Privacy',
  prompt_injection: 'Prompt injection',
  protocol_or_quality: 'Protocol or quality',
  other: 'Other',
  scan_failure: 'Scan did not complete',
}

export const REVIEW_STATUS = {
  pending: 'Pending review',
  confirmed: 'Confirmed',
  dismissed: 'Dismissed',
  needs_second_review: 'Needs second review',
}

/**
 * Job statuses, worded so the difference that matters is unmissable: `ready_for_review` means
 * something looked, `manual_review_required` means nothing did.
 */
export const JOB_STATUS = {
  queued: 'Queued for scanning',
  scanning: 'Scanning now',
  ready_for_review: 'Scanned — awaiting review',
  under_review: 'Under review',
  review_complete: 'Review complete',
  scan_failed: 'Scan failed',
  manual_review_required: 'NOT SCREENED — needs a human read',
}

export const RUN_STATUS = {
  ok: 'Completed',
  timeout: 'Timed out',
  refusal: 'Model declined',
  invalid_json: 'Unreadable output',
  schema_invalid: 'Output did not match the schema',
  citation_mismatch: 'Evidence did not match the transcript',
  content_filter: 'Blocked by a content filter',
  service_error: 'Service error',
}

export const SPEAKER = {
  participant: 'Participant',
  mica: 'MICA',
  other: 'Other',
}

export const RECOMMENDED_ACTION = {
  ra_review: 'RA review',
  second_reviewer: 'Second reviewer',
  alert_care_team: 'Alert care team',
  alert_pi_or_protocol_lead: 'Alert PI or protocol lead',
  document_protocol_issue: 'Document protocol issue',
  model_quality_review: 'Model quality review',
  privacy_review: 'Privacy review',
  no_specific_action: 'No specific action',
}

export const NOTIFICATION_TARGET = {
  research_assistant: 'Research assistant',
  care_team: 'Care team',
  principal_investigator: 'Principal investigator',
  protocol_lead: 'Protocol lead',
  privacy_or_compliance: 'Privacy / compliance',
}

export const SESSION_TYPE = {
  baseline: 'Baseline (Day 1, ED)',
  booster: 'Booster (Month 3)',
}

/** Look up a label, falling back to the raw value — see the header comment on why. */
export function label(dictionary, value) {
  if (value === null || value === undefined || value === '') return '—'
  return dictionary[value] ?? String(value)
}

/**
 * The urgency band a queue row belongs to, which is also its visual weight.
 *
 * An unscreened session is its own band above `critical`, not an urgency: it has no model urgency to
 * report, and treating the absence as "low" is precisely the mistake that would bury it.
 */
export function band(row) {
  if (row.job_status === 'manual_review_required' || row.finding_concern_type === 'scan_failure') {
    return 'unscreened'
  }
  return row.finding_urgency && URGENCY[row.finding_urgency] ? row.finding_urgency : 'none'
}

export function isSettled(reviewStatus) {
  return reviewStatus === 'confirmed' || reviewStatus === 'dismissed'
}

/** A date a reviewer can scan, from an epoch-second value. */
export function when(epochSeconds) {
  const value = Number(epochSeconds)
  if (!Number.isFinite(value) || value <= 0) return '—'
  return new Date(value * 1000).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/** How long ago, for the queue's "waiting since" column. */
export function ago(epochSeconds, now = Date.now()) {
  const value = Number(epochSeconds)
  if (!Number.isFinite(value) || value <= 0) return ''

  const days = Math.floor((now / 1000 - value) / 86400)
  if (days < 1) return 'today'
  if (days === 1) return 'yesterday'
  if (days < 14) return `${days} days ago`
  const weeks = Math.floor(days / 7)
  return weeks < 9 ? `${weeks} weeks ago` : `${Math.floor(days / 30)} months ago`
}
