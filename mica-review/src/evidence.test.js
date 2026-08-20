import { describe, it, expect } from 'vitest'
import { segment, unlocatable, quotesByMessage, quotesForFindings, evidenceOf } from './evidence.js'

/**
 * The rule these tests defend: a highlight is presentation, never verification. If a quote cannot be
 * located exactly, the answer is no highlight — never an approximate one. An RA highlighting the
 * wrong sentence and confirming a critical finding against it is worse than no highlight at all.
 */
describe('segment', () => {
  const SAID = 'I have been drinking more and some nights I think about not waking up.'

  it('marks an exact quote and leaves the rest alone', () => {
    const parts = segment(SAID, ['some nights I think about not waking up'])

    expect(parts).toEqual([
      { text: 'I have been drinking more and ', highlighted: false },
      { text: 'some nights I think about not waking up', highlighted: true },
      { text: '.', highlighted: false },
    ])
  })

  it('reassembles to exactly the original text', () => {
    // The property that matters most: highlighting must never add, drop or reorder a character of
    // what the participant said.
    for (const quotes of [[], ['drinking more'], ['I have been', 'not waking up'], ['nope']]) {
      expect(segment(SAID, quotes).map((s) => s.text).join('')).toBe(SAID)
    }
  })

  it('does not match case-insensitively', () => {
    // The server verified byte-exact. Being lenient here would show a highlight the verifier would
    // have rejected.
    expect(segment(SAID, ['SOME NIGHTS'])).toEqual([{ text: SAID, highlighted: false }])
  })

  it('does not match across collapsed whitespace', () => {
    expect(segment('a\nb', ['a b'])).toEqual([{ text: 'a\nb', highlighted: false }])
  })

  it('does not fuzzy-match a paraphrase', () => {
    expect(segment(SAID, ['some nights I consider ending my life'])).toEqual([
      { text: SAID, highlighted: false },
    ])
  })

  it('marks every occurrence of a repeated quote', () => {
    const parts = segment('no no no', ['no'])

    expect(parts.filter((p) => p.highlighted)).toHaveLength(3)
    expect(parts.map((p) => p.text).join('')).toBe('no no no')
  })

  it('finds an occurrence nested inside another', () => {
    // Advancing by the quote length instead of by one would skip this.
    const parts = segment('aaaa', ['aa'])

    expect(parts).toEqual([{ text: 'aaaa', highlighted: true }])
  })

  it('merges overlapping quotes into one span', () => {
    // Two findings citing nested spans of the same sentence is normal, and rendering them as
    // separate highlights produces visibly broken nesting.
    const parts = segment('the quick brown fox', ['quick brown', 'brown fox'])

    expect(parts).toEqual([
      { text: 'the ', highlighted: false },
      { text: 'quick brown fox', highlighted: true },
    ])
  })

  it('merges adjacent quotes', () => {
    const parts = segment('abcdef', ['abc', 'def'])

    expect(parts).toEqual([{ text: 'abcdef', highlighted: true }])
  })

  it('keeps a gap between non-adjacent quotes', () => {
    const parts = segment('abXYcd', ['ab', 'cd'])

    expect(parts).toEqual([
      { text: 'ab', highlighted: true },
      { text: 'XY', highlighted: false },
      { text: 'cd', highlighted: true },
    ])
  })

  it('handles quotes given out of order', () => {
    expect(segment('abXYcd', ['cd', 'ab'])).toEqual([
      { text: 'ab', highlighted: true },
      { text: 'XY', highlighted: false },
      { text: 'cd', highlighted: true },
    ])
  })

  it('handles multi-byte text without splitting characters', () => {
    const said = 'caña 日本語 🙂 and more'
    const parts = segment(said, ['日本語 🙂'])

    expect(parts.map((p) => p.text).join('')).toBe(said)
    expect(parts.find((p) => p.highlighted).text).toBe('日本語 🙂')
  })

  it('treats an empty or missing quote as nothing to highlight', () => {
    // The server rejects an empty exact_quote, but str_contains(x, '') is true in PHP and
    // ''.includes('') is true in JS — so both sides guard it rather than assume the other did.
    expect(segment('abc', [''])).toEqual([{ text: 'abc', highlighted: false }])
    expect(segment('abc', [null, undefined])).toEqual([{ text: 'abc', highlighted: false }])
  })

  it('handles an empty message', () => {
    expect(segment('', ['x'])).toEqual([])
  })

  it('survives non-string input rather than throwing mid-render', () => {
    expect(segment(null, ['x'])).toEqual([])
    expect(segment(undefined, null)).toEqual([])
  })

  it('does not treat the quote as a pattern', () => {
    // indexOf, not a regex. A quote containing regex metacharacters is ordinary participant text.
    const said = 'costs $5.00 (approx.) [see note] a+b'
    for (const quote of ['$5.00', '(approx.)', '[see note]', 'a+b']) {
      const parts = segment(said, [quote])
      expect(parts.find((p) => p.highlighted)?.text).toBe(quote)
    }
  })
})

describe('unlocatable', () => {
  it('names a quote that is not in the message', () => {
    // The server should have made this impossible, so if it happens the reviewer needs to know they
    // are looking at an unverified claim — not to see a highlight quietly missing.
    expect(unlocatable('hello there', ['hello', 'goodbye'])).toEqual(['goodbye'])
  })

  it('is empty when everything matches', () => {
    expect(unlocatable('hello there', ['hello', 'there'])).toEqual([])
  })

  it('ignores empty quotes rather than reporting them as missing', () => {
    expect(unlocatable('hello', ['', null])).toEqual([])
  })
})

describe('quotesByMessage', () => {
  it('groups evidence by the message it cites', () => {
    const grouped = quotesByMessage([
      { message_id: 'L1', speaker_role: 'participant', exact_quote: 'first' },
      { message_id: 'L1', speaker_role: 'participant', exact_quote: 'second' },
      { message_id: 'L2', speaker_role: 'mica', exact_quote: 'third' },
    ])

    expect(grouped.get('L1')).toEqual(['first', 'second'])
    expect(grouped.get('L2')).toEqual(['third'])
  })

  it('skips malformed evidence instead of throwing', () => {
    expect(quotesByMessage([null, {}, { exact_quote: 'orphan' }]).size).toBe(0)
    expect(quotesByMessage(undefined).size).toBe(0)
  })
})

describe('quotesForFindings', () => {
  it('combines every finding’s citations for the same message', () => {
    // A reviewer scanning the transcript for context wants every cited span at once, not only the
    // finding they have open.
    const all = quotesForFindings([
      { evidence: [{ message_id: 'L1', exact_quote: 'a' }] },
      { evidence: [{ message_id: 'L1', exact_quote: 'b' }, { message_id: 'L2', exact_quote: 'c' }] },
    ])

    expect(all.get('L1')).toEqual(['a', 'b'])
    expect(all.get('L2')).toEqual(['c'])
  })

  it('handles a finding with no evidence', () => {
    // A scan_failure placeholder has none by design.
    expect(quotesForFindings([{ evidence: [] }, {}]).size).toBe(0)
  })
})

describe('evidenceOf', () => {
  it('reads the name the server actually sends', () => {
    // The bug this exists for: the server sends `finding_evidence` (the REDCap field is
    // `finding_evidence_json` and the store strips the suffix) while the model's own output calls it
    // `evidence`. Reading only one meant highlighting silently never happened — nothing errored.
    expect(evidenceOf({ finding_evidence: [{ message_id: 'L1', exact_quote: 'a' }] })).toHaveLength(1)
    expect(evidenceOf({ evidence: [{ message_id: 'L1', exact_quote: 'a' }] })).toHaveLength(1)
    expect(evidenceOf({})).toEqual([])
    expect(evidenceOf(null)).toEqual([])
  })

  it('feeds quotesForFindings from the server’s shape', () => {
    const all = quotesForFindings([
      { finding_evidence: [{ message_id: 'L1', exact_quote: 'not waking up' }] },
    ])

    expect(all.get('L1')).toEqual(['not waking up'])
  })
})
