import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { pageTheme } from "./phem_theme.js"

// The `phem-theme` controller: a switch for the light or the dark theme of the page. The choice
// of the user goes in `localStorage`, and `<html>` gets the theme:
//
//     <div data-controller="phem-theme" role="group" aria-label="Theme">
//         <button data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="light">Light</button>
//         <button data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="dark">Dark</button>
//         <button data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="system">System</button>
//     </div>
//
// The `choose` action stores its `theme` param (`light`, `dark` or `system`), or the value of the
// element of the event (a `<select>` or a radio button). The `toggle` action stores the opposite
// of the current theme, for one button.
//
// `<html>` gets `data-theme="light"` or `data-theme="dark"`, and the same `color-scheme`. With
// `system`, the theme follows the `prefers-color-scheme` of the system. The `theme_script()`
// Twig function sets the theme before the first paint, and its `default` (see phem_theme.js).
//
// The controller element gets `data-theme` (the current theme) and `data-preference` (the choice).
// An `option` target of the choice gets `aria-pressed="true"` (a button), `checked` (a radio
// button) or `selected` (an `<option>`). The switches of the page, and of the other tabs, share
// the choice. `<html>` dispatches one `phem-theme:change` for each change, with the `theme` and
// the `preference`.
export default class PhemThemeController extends Controller {
  static targets = ["option"]

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.renderTheme(pageTheme),
    })
    this.state.connect()

    pageTheme.subscribe(this)
  }

  disconnect() {
    pageTheme.unsubscribe(this)
    this.state.disconnect()
  }

  choose({ params, target }) {
    pageTheme.choose(params?.theme ?? target?.value)
  }

  toggle() {
    pageTheme.toggle()
  }

  optionTargetConnected() {
    if (pageTheme.theme !== null) this.renderTheme(pageTheme)
  }

  // Marks the options of the choice. The page theme calls it after each change.
  renderTheme({ theme, preference }) {
    this.element.dataset.theme = theme
    this.element.dataset.preference = preference

    for (const option of this.optionTargets) {
      const chosen = (option.dataset.phemThemeThemeParam ?? option.value) === preference
      if (option instanceof HTMLInputElement) option.checked = chosen
      else if (option instanceof HTMLOptionElement) option.selected = chosen
      else option.setAttribute("aria-pressed", String(chosen))
    }
  }

  #owns(el, name) {
    if (el === this.element) return name === "data-theme" || name === "data-preference"

    return this.optionTargets.includes(el) && (name === "aria-pressed" || name === "checked" || name === "selected")
  }
}
