import { when } from '../labels.js'

const EVENT_LABEL = {
  queue_view: 'Viewed the queue',
  session_view: 'Read a session',
  disposition: 'Recorded a decision',
  action_sent: 'Sent an action',
  policy_change: 'Changed the policy',
  history_view: 'Viewed history',
  audit_view: 'Viewed the audit trail',
  access_denied: 'Was refused access',
  scan_gave_up: 'A scan gave up',
}

/**
 * The audit trail.
 *
 * A table, unlike the queue — this is scanned for patterns across rows ("who read what, when"), and
 * aligned columns are what make that possible. The queue is the opposite: one decision per row.
 *
 * `details` is rendered as key/value pairs rather than raw JSON. The server allowlists what can go in
 * there precisely so it never contains participant text, and printing a JSON blob would invite a
 * reader to assume it might.
 */
export function AuditTrail({ events }) {
  return (
    <section className="mica-panel">
      <h3 className="mica-panel-title">Audit trail</h3>
      <p className="mica-recs" style={{ marginTop: 0 }}>
        Most recent first. Reading this page is itself recorded.
      </p>

      <div style={{ overflowX: 'auto' }}>
        <table className="mica-audit-table">
          <caption className="mica-sr-only">
            Audit events: who acted, what they did, and which finding or session it concerned.
          </caption>
          <thead>
            <tr>
              <th scope="col">When</th>
              <th scope="col">Who</th>
              <th scope="col">Did what</th>
              <th scope="col">To what</th>
              <th scope="col">Details</th>
            </tr>
          </thead>
          <tbody>
            {events.map((event) => (
              <tr key={event.id} className={event.event_type === 'access_denied' ? 'mica-audit-denied' : undefined}>
                <td>{when(event.created)}</td>
                <td>
                  <code className="mica-mono">{event.actor}</code>
                  {event.actor_role ? <div className="mica-recs">{event.actor_role}</div> : null}
                </td>
                <td>{EVENT_LABEL[event.event_type] || event.event_type}</td>
                <td>
                  {event.target_kind ? (
                    <>
                      {event.target_kind}
                      {event.target_id ? (
                        <>
                          {' '}
                          <code className="mica-mono">{event.target_id}</code>
                        </>
                      ) : null}
                    </>
                  ) : (
                    '—'
                  )}
                </td>
                <td>
                  {Object.keys(event.details || {}).length === 0
                    ? '—'
                    : Object.entries(event.details).map(([key, value]) => (
                        <div key={key} className="mica-recs">
                          {key}: {Array.isArray(value) ? value.join(', ') : String(value)}
                        </div>
                      ))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
