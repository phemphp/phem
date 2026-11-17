// The match of a text and a query of a client filter (`phem-combobox`, `phem-menu`): it ignores
// the case and the accents, as `Intl.Collator` with `sensitivity: "base"` compares them, in the
// `locale` (or the language of the page).
//
//     const matches = textMatcher({ locale: "fr", mode: "contains" })
//     matches("Évreux", "evr") // true

// The collators, by locale.
const collators = new Map()

// A function `(text, query) => boolean`. `mode` is `contains` (the default) or `starts-with`.
export function textMatcher({ locale = "", mode = "contains" } = {}) {
  const lang = locale || document.documentElement.lang || ""
  if (!collators.has(lang)) collators.set(lang, new Intl.Collator(lang || undefined, { usage: "search", sensitivity: "base" }))
  const collator = collators.get(lang)
  const same = (a, b) => collator.compare(a, b) === 0

  if (mode === "starts-with") return (text, query) => same(text.slice(0, query.length), query)

  return (text, query) => {
    for (let at = 0; at + query.length <= text.length; at++) if (same(text.slice(at, at + query.length), query)) return true

    return false
  }
}
