import { describe, expect, it } from "vitest"
import { isDigit, toAsciiDigits } from "../dist/phem_number.js"

describe("phem_number: the digits of other scripts", () => {
  it("knows the digits of the numeral systems", () => {
    for (const char of ["0", "9", "٥", "۵", "５", "五", "〇", "零"]) expect(isDigit(char)).toBe(true)
    for (const char of ["a", ".", "-", " "]) expect(isDigit(char)).toBe(false)
  })

  it("gives the text with ASCII digits", () => {
    expect(toAsciiDigits("٤٢")).toBe("42")
    expect(toAsciiDigits("۱۲")).toBe("12")
    expect(toAsciiDigits("３７")).toBe("37")
    expect(toAsciiDigits("一二〇")).toBe("120")
    expect(toAsciiDigits("零")).toBe("0")
    expect(toAsciiDigits("1,5")).toBe("1,5")
  })
})
