import { describe, expect, it } from "vitest"
import { textMatcher, textParts } from "../dist/phem_match.js"

describe("textMatcher", () => {
  it("finds the query in the text, with no case and no accents", () => {
    const matches = textMatcher()

    expect(matches("Évreux", "EVR")).toBe(true)
    expect(matches("Pineapple", "apple")).toBe(true)
    expect(matches("Pineapple", "pa")).toBe(false)
  })

  it("matches the start of the text with starts-with", () => {
    const matches = textMatcher({ mode: "starts-with" })

    expect(matches("Éclair", "ecl")).toBe(true)
    expect(matches("Pineapple", "apple")).toBe(false)
  })

  it("matches the characters of the query in the same order with fuzzy", () => {
    const matches = textMatcher({ mode: "fuzzy" })

    expect(matches("New York", "nwy")).toBe(true)
    expect(matches("New York", "NEW YK")).toBe(true)
    expect(matches("Évreux", "evx")).toBe(true)
    expect(matches("New York", "ywn")).toBe(false)
    // The space of the query is a character too.
    expect(matches("Newark", "new a")).toBe(false)
    expect(matches("New", "news")).toBe(false)
  })

  it("uses contains for an unknown mode", () => {
    expect(textMatcher({ mode: "other" })("Pineapple", "apple")).toBe(true)
  })
})

describe("textParts", () => {
  it("gives the parts that are the query", () => {
    expect(textParts("Pineapple apple", "APP")).toEqual([
      { text: "Pine", mark: false },
      { text: "app", mark: true },
      { text: "le ", mark: false },
      { text: "app", mark: true },
      { text: "le", mark: false },
    ])
  })

  it("ignores the accents, and trims the query", () => {
    expect(textParts("Éclair", "  ecl ")).toEqual([
      { text: "Écl", mark: true },
      { text: "air", mark: false },
    ])
  })

  it("gives the text for an empty query or no match", () => {
    expect(textParts("Apple", " ")).toEqual([{ text: "Apple", mark: false }])
    expect(textParts("Apple", "zz")).toEqual([{ text: "Apple", mark: false }])
  })
})
