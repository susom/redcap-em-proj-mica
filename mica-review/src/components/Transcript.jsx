import { useMemo } from 'react'
import { Empty, Notice } from './Notice.jsx'
import { SPEAKER, label } from '../labels.js'
import { segment, unlocatable } from '../evidence.js'

/**
 * One message, with any cited spans marked.
 *
 * The text is rendered as React children — never as markup. Participant text can contain anything,
 * and this dashboard's whole value is that a reviewer is looking at exactly what was said.
 */
function Message({ message, quotes, isCited }) {
  const parts = useMemo(() => segment(message.content, quotes), [message.content, quotes])
  const missing = useMemo(() => unlocatable(message.content, quotes), [message.content, quotes])

  return (
    <article
      id={`mica-msg-${message.message_id}`}
      className={`mica-msg mica-msg--${message.speaker_role}${isCited ? ' mica-msg--cited' : ''}`}
    >
      <header className="mica-msg-head">
        <span className="mica-msg-who">{label(SPEAKER, message.speaker_role)}</span>
        <span>#{message.sequence}</span>
        {message.timestamp ? <span>{new Date(message.timestamp).toLocaleTimeString()}</span> : null}
        {/* The message id is what the model cited and what the server verified against, so a
            reviewer checking a finding needs to be able to see it. */}
        <code className="mica-mono">{message.message_id}</code>
      </header>

      <p className="mica-msg-body">
        {parts.map((part, i) =>
          part.highlighted ? (
            <mark key={i} className="mica-mark">
              {part.text}
            </mark>
          ) : (
            <span key={i}>{part.text}</span>
          ),
        )}
      </p>

      {missing.length > 0 ? (
        // The server proved every quote was byte-exact before this finding existed, so reaching
        // here means something is wrong. Said out loud rather than shown as a missing highlight:
        // the reviewer is looking at a claim nobody verified.
        <Notice kind="error" title="A cited quote is not in this message">
          <p>
            The server verified every quote before this finding was created, so this should be
            impossible. Treat the finding as unverified and report it.
          </p>
        </Notice>
      ) : null}
    </article>
  )
}

/**
 * The finalized transcript.
 *
 * `integrity` comes from the server, which re-hashes the stored payload before returning it. When it
 * does not match, no messages are returned at all — showing text that does not match its own hash
 * would let a reviewer verify a quote against something nobody vouched for, which is worse than
 * showing nothing.
 */
export function Transcript({ session, quotesByMessage }) {
  const messages = session.messages || []

  if (session.integrity === 'hash_mismatch') {
    return (
      <Notice kind="error" title="This transcript does not match its own hash">
        <p>
          The stored conversation no longer matches the hash recorded when it was finalized, so it is
          not shown. Any finding about it is unverifiable until this is investigated — the transcript
          may have been altered after it was pinned.
        </p>
      </Notice>
    )
  }

  if (messages.length === 0) {
    return (
      <Empty title="No transcript is available for this session">
        <p>
          The scan job points at a transcript that could not be read. That is a data problem, not an
          empty conversation.
        </p>
      </Empty>
    )
  }

  return (
    <>
      <p className="mica-recs" style={{ marginTop: 0 }}>
        {messages.length} messages
        {session.started_at ? ` · started ${new Date(session.started_at).toLocaleString()}` : ''}
      </p>
      <div className="mica-transcript" tabIndex={0} role="region" aria-label="Session transcript">
        {messages.map((message) => (
          <Message
            key={message.message_id}
            message={message}
            quotes={quotesByMessage.get(message.message_id) || []}
            isCited={quotesByMessage.has(message.message_id)}
          />
        ))}
      </div>
    </>
  )
}
