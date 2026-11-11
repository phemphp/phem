import { PhemStorage } from "./phem_storage.js"

// The choices of the user. The `theme_script()` Twig function (Phem\Twig\ThemeScript) has the
// same list and the same `localStorage` key: ThemeScriptTest checks them.
export const THEMES = ["light", "dark", "system"]

const storage = new PhemStorage("theme")

// The theme of the page, one for all the `phem-theme` controllers: one listener for the system
// and the storage, one write of `<html>`, and one `phem-theme:change` event for each change.
//
// The default is the `data-theme-default` of `<html>`, which `theme_script()` sets, or `system`.
class PageTheme {
  #switches = new Set()
  #listening = null
  #media = null
  // A choice that `localStorage` could not keep (a private window): it lives until the reload.
  #chosen = null

  preference = null
  theme = null

  subscribe(controller) {
    this.#switches.add(controller)
    if (this.#switches.size === 1) this.#listen()

    this.update({ dispatch: false })
  }

  unsubscribe(controller) {
    this.#switches.delete(controller)
    if (this.#switches.size > 0) return

    this.#listening?.abort()
    this.#listening = null
  }

  choose(preference) {
    if (!THEMES.includes(preference)) return

    storage.set(preference)
    this.#chosen = storage.get() === preference ? null : preference
    this.update()
  }

  toggle() {
    this.choose(this.theme === "dark" ? "light" : "dark")
  }

  // Gives `<html>` the theme of the choice, and renders the switches.
  update({ dispatch = true } = {}) {
    const { preference: oldPreference, theme: oldTheme } = this
    this.preference = this.#preference()
    this.theme = this.preference === "system" ? (this.#media.matches ? "dark" : "light") : this.preference

    const root = document.documentElement
    root.dataset.theme = this.theme
    root.style.colorScheme = this.theme
    for (const controller of this.#switches) controller.renderTheme(this)

    if (!dispatch || (oldPreference === this.preference && oldTheme === this.theme)) return

    root.dispatchEvent(new CustomEvent("phem-theme:change", {
      bubbles: true,
      detail: { theme: this.theme, preference: this.preference },
    }))
  }

  // The stored choice, or the choice that storage could not keep, or the default.
  #preference() {
    const stored = storage.get()
    if (THEMES.includes(stored)) return stored
    if (this.#chosen !== null) return this.#chosen

    const fallback = document.documentElement.dataset.themeDefault

    return THEMES.includes(fallback) ? fallback : "system"
  }

  #listen() {
    this.#listening = new AbortController()
    this.#media = window.matchMedia("(prefers-color-scheme: dark)")
    const options = { signal: this.#listening.signal }

    this.#media.addEventListener("change", () => this.update(), options)
    storage.listen(() => this.update(), this.#listening.signal)

    // Another tab removed the choice, or cleared the storage: PhemStorage.listen() does not
    // hear it.
    window.addEventListener("storage", (event) => {
      if (event.newValue !== null || (event.key !== null && event.key !== storage.key)) return

      this.#chosen = null
      this.update()
    }, options)
  }
}

export const pageTheme = new PageTheme()
