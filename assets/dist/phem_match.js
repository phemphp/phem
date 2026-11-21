// The match of a text and a query of a client filter (`phem-combobox`, `phem-menu`): it ignores
// the case and the accents, as `Intl.Collator` with `sensitivity: "base"` compares them, in the
// `locale` (or the language of the page).
//
//     const matches = textMatcher({ locale: "fr", mode: "contains" })
//     matches("Évreux", "evr") // true
//
//     textMatcher({ mode: "fuzzy" })("New York", "nwy") // true

// The collators, by locale.
const collators = new Map()

// The modes: a function that gets the comparison of two texts and gives the matcher.
const modes = {
  // The text contains the query.
  contains: (same) => (text, query) => {
    for (let at = 0; at + query.length <= text.length; at++) if (same(text.slice(at, at + query.length), query)) return true

    return false
  },

  // The text starts with the query.
  "starts-with": (same) => (text, query) => same(text.slice(0, query.length), query),

  // The characters of the query (the spaces too) are in the text, in the same order, as the
  // `MATCHES` rank of match-sorter.
  fuzzy: (same) => (text, query) => {
    const letters = [...query]
    let next = 0

    for (const letter of text) {
      if (next === letters.length) break
      if (same(letter, letters[next])) next++
    }

    return next === letters.length
  },
}

// A function `(text, query) => boolean`. `mode` is `contains` (the default), `starts-with` or
// `fuzzy`.
export function textMatcher({ locale = "", mode = "contains" } = {}) {
  const collator = collatorOf(locale)
  const same = (a, b) => collator.compare(a, b) === 0

  return (modes[mode] ?? modes.contains)(same)
}

// The parts of a text for its marks, as the `highlightText` of the Base UI demos: the parts that
// are the trimmed query (at most 100 characters) get `mark: true`. An empty query gives the text.
//
//     textParts("Pineapple", "app") // [{ text: "Pine", mark: false }, { text: "app", mark: true }, { text: "le", mark: false }]
export function textParts(text, query, { locale = "" } = {}) {
  const needle = query.trim().slice(0, 100)
  if (needle === "") return [{ text, mark: false }]

  const collator = collatorOf(locale)
  const parts = []
  let start = 0
  let at = 0

  while (at + needle.length <= text.length) {
    if (collator.compare(text.slice(at, at + needle.length), needle) !== 0) {
      at++
      continue
    }

    if (at > start) parts.push({ text: text.slice(start, at), mark: false })
    parts.push({ text: text.slice(at, at + needle.length), mark: true })
    at += needle.length
    start = at
  }

  if (start < text.length) parts.push({ text: text.slice(start), mark: false })

  return parts
}

function collatorOf(locale) {
  const lang = locale || document.documentElement.lang || ""
  if (!collators.has(lang)) collators.set(lang, new Intl.Collator(lang || undefined, { usage: "search", sensitivity: "base" }))

  return collators.get(lang)
}
