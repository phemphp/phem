// The keys of `phem:key` and `phem:shortcut`: a list of keys separated by spaces, each with its
// modifiers (`Enter`, `ctrl+s`, `shift+Enter`, `mod+k`, `Space`). A key is the `key` of the browser
// event. The names have no case.
//
// `mod` is the Command key (`meta`) on a Mac, an iPhone and an iPad, and `ctrl` on the other
// systems. `mod` does not go with `ctrl` or `meta` in one key. The modifiers must match: `Enter`
// does not match Shift+Enter. Shift is free for a character that is not a letter, such as `?`,
// which needs Shift on some keyboards.

const MODIFIERS = ["ctrl", "shift", "alt", "meta"]

// The modifier of `mod`: the Command key when the system uses it for its shortcuts. The system
// does not change, so the module reads it once.
const MOD = /mac|iphone|ipad|ipod/i.test(navigator.userAgentData?.platform || navigator.platform || "") ? "meta" : "ctrl"

// Whether a key event matches one of the keys.
export function matchesKey(keys, event) {
  const pressed = (event.key ?? "").toLowerCase()

  return keys.trim().split(/\s+/).some((combo) => {
    const parts = combo.toLowerCase().split("+")
    let key = parts.pop()
    // `+` or `ctrl++`: the key is `+`.
    if (key === "" && parts.at(-1) === "") {
      parts.pop()
      key = "+"
    }

    if (key === "space") key = " "
    if (key !== pressed) return false

    const modifiers = parts.map((m) => (m === "mod" ? MOD : m))
    const freeShift = key.length === 1 && key.toLowerCase() === key.toUpperCase()

    return MODIFIERS.every((m) => (m === "shift" && freeShift && !modifiers.includes(m)) || event[`${m}Key`] === modifiers.includes(m))
  })
}
