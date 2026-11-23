import { PhemStorage } from "./phem_storage.js"

const storage = new PhemStorage("palette")

// The palette of the page, one for all the `phem-palette` controllers: one listener for the
// storage, one write of `<html>`, and one `phem-palette:change` event for each change.
//
// The `palette_script()` Twig function (Phem\Twig\PaletteScript) gives `<html>` the names of the
// palettes (`data-palettes`) and the default (`data-palette-default`). With no names, the page has
// no palette, and a choice does nothing.
class PagePalette {
  #switches = new Set()
  #listening = null
  // A choice that `localStorage` could not keep (a private window): it lives until the reload.
  #chosen = null

  palette = null

  get palettes() {
    return (document.documentElement.dataset.palettes ?? "").split(" ").filter(Boolean)
  }

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

  choose(palette) {
    if (!this.palettes.includes(palette)) return

    storage.set(palette)
    this.#chosen = storage.get() === palette ? null : palette
    this.update()
  }

  // Gives `<html>` the palette of the choice, and renders the switches.
  update({ dispatch = true } = {}) {
    const old = this.palette
    this.palette = this.#palette()

    const root = document.documentElement
    if (this.palette === null) delete root.dataset.palette
    else root.dataset.palette = this.palette

    for (const controller of this.#switches) controller.renderPalette(this)

    if (!dispatch || old === this.palette) return

    root.dispatchEvent(new CustomEvent("phem-palette:change", { bubbles: true, detail: { palette: this.palette } }))
  }

  // The stored choice, or the choice that storage could not keep, or the default.
  #palette() {
    const palettes = this.palettes
    const stored = storage.get()
    if (palettes.includes(stored)) return stored
    if (this.#chosen !== null && palettes.includes(this.#chosen)) return this.#chosen

    const fallback = document.documentElement.dataset.paletteDefault

    return palettes.includes(fallback) ? fallback : (palettes[0] ?? null)
  }

  #listen() {
    this.#listening = new AbortController()
    storage.listen(() => this.update(), this.#listening.signal)

    // Another tab removed the choice, or cleared the storage: PhemStorage.listen() does not
    // hear it.
    window.addEventListener("storage", (event) => {
      if (event.newValue !== null || (event.key !== null && event.key !== storage.key)) return

      this.#chosen = null
      this.update()
    }, { signal: this.#listening.signal })
  }
}

export const pagePalette = new PagePalette()
