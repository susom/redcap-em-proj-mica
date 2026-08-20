import { URGENCY, REVIEW_STATUS, label } from '../labels.js'

/**
 * The urgency badge.
 *
 * `unscreened` is not an urgency and does not render as one — it says "Not screened" in words,
 * because a reviewer must be able to tell "the scanner rated this critical" from "the scanner never
 * read this" without relying on colour or position.
 */
export function UrgencyBadge({ band }) {
  const text =
    band === 'unscreened' ? 'Not screened' : band === 'none' ? 'No finding' : URGENCY[band] || band

  return (
    <span className={`mica-badge mica-badge--${band}`}>
      {/* Announced with its meaning, not just its word: "Critical" alone in a list of badges does
          not tell a screen-reader user what it is critical about. */}
      <span className="mica-sr-only">Urgency: </span>
      {text}
    </span>
  )
}

export function ReviewChip({ status }) {
  const value = status || 'pending'

  return (
    <span className={`mica-chip mica-chip--${value}`}>
      <span className="mica-sr-only">Review status: </span>
      {label(REVIEW_STATUS, value)}
    </span>
  )
}

export function Chip({ children }) {
  return <span className="mica-chip">{children}</span>
}
