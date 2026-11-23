import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { pagePalette } from "./phem_palette.js"

// The `phem-palette` controller: a picker of a named palette of the page (a set of colors, next to
// the light and the dark theme of `phem-theme`). The choice of the user goes in `localStorage`, and
// `<html>` gets the palette:
//
//     <select data-controller="phem-palette" data-action="phem-palette#choose" aria-label="Palette">
//         <option data-phem-palette-target="option" value="base">Base</option>
//         <option data-phem-palette-target="option" value="shadcn">shadcn</option>
//     </select>
//
// The `choose` action stores its `palette` param, or the value of the element of the event (a
// `<select>` or a radio button). Only a name of `palette_script()` is a palette: the Twig function
// gives `<html>` the names, the default, and the stored palette before the first paint.
//
// `<html>` gets `data-palette`. The controller element gets `data-palette` too. An `option` target
// of the choice gets `aria-pressed="true"` (a button), `checked` (a radio button) or `selected` (an
// `<option>`). The pickers of the page, and of the other tabs, share the choice. `<html>`
// dispatches one `phem-palette:change` for each change, with the `palette`.
export default class PhemPaletteController extends Controller {
  static targets = ["option"]

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.renderPalette(pagePalette),
    })
    this.state.connect()

    pagePalette.subscribe(this)
  }

  disconnect() {
    pagePalette.unsubscribe(this)
    this.state.disconnect()
  }

  choose({ params, target }) {
    pagePalette.choose(params?.palette ?? target?.value)
  }

  optionTargetConnected() {
    if (pagePalette.palette !== null) this.renderPalette(pagePalette)
  }

  // Marks the options of the choice. The page palette calls it after each change.
  renderPalette({ palette }) {
    if (palette === null) delete this.element.dataset.palette
    else this.element.dataset.palette = palette

    for (const option of this.optionTargets) {
      const chosen = (option.dataset.phemPalettePaletteParam ?? option.value) === palette
      if (option instanceof HTMLInputElement) option.checked = chosen
      else if (option instanceof HTMLOptionElement) option.selected = chosen
      else option.setAttribute("aria-pressed", String(chosen))
    }
  }

  #owns(el, name) {
    if (el === this.element) return name === "data-palette"

    return this.optionTargets.includes(el) && (name === "aria-pressed" || name === "checked" || name === "selected")
  }
}
