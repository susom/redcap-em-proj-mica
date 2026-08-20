/**
 * Locating an evidence quote inside the message it cites, so the dashboard can highlight it.
 *
 * The server has already proved every quote is a byte-exact substring of the cited message before
 * the finding was ever created (QuoteVerifier). So this is not verification - it is presentation,
 * and it must not quietly disagree with the verifier: if a quote cannot be located here, the honest
 * answer is "no highlight" and a visible note, never a fuzzy match that shows the reviewer
 * approximately-the-right sentence.
 *
 * That distinction is the whole point of the feature. An RA highlighting the wrong sentence and
 * confirming a critical finding against it is worse than an RA reading the message themselves.
 */

/**
 * Split a message's text into segments, marking the ones a quote covers.
 *
 * Returns segments rather than HTML so the caller renders with React and nothing is ever
 * interpolated into markup - participant text can contain anything.
 *
 * @param {string} content the message as stored
 * @param {string[]} quotes exact quotes to mark
 * @returns {{text: string, highlighted: boolean}[]}
 */
export function segment(content, quotes) {
  const text = typeof content === 'string' ? content : ''
  const wanted = (quotes || []).filter((q) => typeof q === 'string' && q.length > 0)

  if (text === '' || wanted.length === 0) {
    return text === '' ? [] : [{ text, highlighted: false }]
  }

  // Collect every occurrence of every quote, then merge. Overlapping quotes are normal - two
  // findings can cite nested spans of the same sentence - and rendering them as separate
  // highlights would produce visibly broken nesting.
  const ranges = []
  for (const quote of wanted) {
    let from = 0
    for (;;) {
      const at = text.indexOf(quote, from)
      if (at === -1) break
      ranges.push([at, at + quote.length])
      // Advance by one, not by the quote length: a repeated phrase inside itself would otherwise
      // skip its own second occurrence.
      from = at + 1
    }
  }

  if (ranges.length === 0) {
    return [{ text, highlighted: false }]
  }

  ranges.sort((a, b) => a[0] - b[0] || a[1] - b[1])

  const merged = [ranges[0].slice()]
  for (const [start, end] of ranges.slice(1)) {
    const last = merged[merged.length - 1]
    if (start <= last[1]) {
      last[1] = Math.max(last[1], end)
    } else {
      merged.push([start, end])
    }
  }

  const segments = []
  let cursor = 0
  for (const [start, end] of merged) {
    if (start > cursor) segments.push({ text: text.slice(cursor, start), highlighted: false })
    segments.push({ text: text.slice(start, end), highlighted: true })
    cursor = end
  }
  if (cursor < text.length) segments.push({ text: text.slice(cursor), highlighted: false })

  return segments
}

/**
 * Which quotes could not be found in the message they cite.
 *
 * Surfaced in the UI rather than swallowed. The server should have made this impossible, so if it
 * ever happens the reviewer needs to know they are looking at an unverified claim - not to see a
 * highlight quietly missing.
 *
 * @returns {string[]}
 */
export function unlocatable(content, quotes) {
  const text = typeof content === 'string' ? content : ''
  return (quotes || []).filter((q) => typeof q === 'string' && q.length > 0 && !text.includes(q))
}

/**
 * Group a finding's evidence by the message it cites.
 *
 * @param {{message_id: string, speaker_role: string, exact_quote: string}[]} evidence
 * @returns {Map<string, string[]>} message_id -> quotes
 */
export function quotesByMessage(evidence) {
  const map = new Map()
  for (const item of evidence || []) {
    if (!item || typeof item.message_id !== 'string') continue
    const list = map.get(item.message_id) || []
    list.push(item.exact_quote)
    map.set(item.message_id, list)
  }
  return map
}

/**
 * All quotes cited across a set of findings, keyed by message.
 *
 * Used when the whole transcript is shown: a reviewer scanning for context wants to see every cited
 * span at once, not only the one finding they have open.
 */
export function quotesForFindings(findings) {
  const map = new Map()
  for (const finding of findings || []) {
    for (const [messageId, quotes] of quotesByMessage(evidenceOf(finding))) {
      map.set(messageId, [...(map.get(messageId) || []), ...quotes])
    }
  }
  return map
}

/**
 * A finding's evidence, whichever name it arrives under.
 *
 * The server sends `finding_evidence` — the REDCap field is `finding_evidence_json` and the store
 * strips the suffix — while the model's own output calls it `evidence`. Reading only one of them is
 * how highlighting silently stopped working: the component rendered, the transcript rendered, and
 * not a single quote was ever marked. Nothing errored, which is exactly why it needed a test.
 */
export function evidenceOf(finding) {
  if (!finding) return []
  if (Array.isArray(finding.finding_evidence)) return finding.finding_evidence
  if (Array.isArray(finding.evidence)) return finding.evidence
  return []
}
