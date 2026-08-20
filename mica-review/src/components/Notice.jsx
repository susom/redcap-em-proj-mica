/**
 * The one component for everything the reviewer needs told.
 *
 * `role="alert"` only for errors: an assertive live region interrupting a screen reader for an
 * informational note is worse than no announcement at all, and this dashboard shows notes routinely.
 */
export function Notice({ kind = 'info', title, children }) {
  return (
    <div className={`mica-notice mica-notice--${kind}`} role={kind === 'error' ? 'alert' : undefined}>
      {title ? <p className="mica-notice-title">{title}</p> : null}
      {children}
    </div>
  )
}

/**
 * An empty state that says WHY it is empty.
 *
 * The single most important piece of copy in this app. "Nothing to review" and "nothing loaded" look
 * identical if you let them, and this entire pipeline exists so that a clean screen is
 * distinguishable from an absent one.
 */
export function Empty({ title, children }) {
  return (
    <div className="mica-empty">
      <p className="mica-notice-title">
        <strong>{title}</strong>
      </p>
      {children}
    </div>
  )
}

export function Skeleton({ rows = 3 }) {
  return (
    <div aria-busy="true" aria-live="polite">
      <span className="mica-sr-only">Loading…</span>
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="mica-skeleton" />
      ))}
    </div>
  )
}
