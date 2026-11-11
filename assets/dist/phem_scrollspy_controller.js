import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { atEnd, scrolls } from "./phem_scroll.js"

// The input of the user that scrolls the page: after it, the last clicked link is not current.
const SCROLL_INPUT = ["wheel", "touchmove", "keydown"]

// The `phem-scrollspy` controller: marks the link of the section in view, for a table of contents.
//
//     <nav data-controller="phem-scrollspy" aria-label="On this page">
//         <a href="#install" data-phem-scrollspy-target="link">Install</a>
//         <a href="#configure" data-phem-scrollspy-target="link">Configure</a>
//     </nav>
//
// The section of a link is the element of the page with the id of its hash. The current section
// is the last section whose top is at its `scroll-margin-top` or above it: a click on its link
// scrolls it to this place, so a click marks the clicked link. A sticky header sets the
// `scroll-margin-top` of the sections. In an element that scrolls, the line starts at the top of
// the element. At the end of the scroll, the last section is current: a short last section does
// not get to the line. A clicked section in view stays current at the end of the scroll, until
// the user scrolls. Above the first section, no link is current.
//
// All the links of the current section get `aria-current="true"` and `data-active`. When the
// current section changes, the controller dispatches `phem-scrollspy:change`, with the `link` and
// the `section` (`null` above the first section). The marks belong to the client: they stay after
// the morph of an event.
export default class PhemScrollspyController extends Controller {
  static targets = ["link"]

  #frame = 0
  #current = null
  #clicked = null

  // The measures of the layout: the sections, the element that scrolls and the scroll margins.
  // They change with a resize, a morph and the links, not with a scroll.
  #sections = []
  #scroller = null
  #margins = new Map()
  #resize = null
  #observed = new Set()
  #measure = false

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.linkTargets.includes(el) && (name === "aria-current" || name === "data-active"),
      morphed: () => this.#update(),
    })
    this.state.connect()

    // The capture gets the scroll of the page and of each element that scrolls.
    document.addEventListener("scroll", this.onScroll, { capture: true, passive: true })
    window.addEventListener("resize", this.onResize, { passive: true })
    for (const type of SCROLL_INPUT) window.addEventListener(type, this.onInput, { passive: true })
    this.element.addEventListener("click", this.onClick)

    // A change of the height of the content moves the sections, with no scroll.
    if (typeof ResizeObserver === "function") this.#resize = new ResizeObserver(this.onResize)
    this.#update()
  }

  disconnect() {
    this.state.disconnect()
    document.removeEventListener("scroll", this.onScroll, { capture: true })
    window.removeEventListener("resize", this.onResize)
    for (const type of SCROLL_INPUT) window.removeEventListener(type, this.onInput)
    this.element.removeEventListener("click", this.onClick)
    this.#resize?.disconnect()
    this.#resize = null
    this.#observed.clear()
    cancelAnimationFrame(this.#frame)
    this.#frame = 0
  }

  linkTargetConnected() {
    if (this.state) this.#update()
  }

  linkTargetDisconnected() {
    if (this.state) this.#update()
  }

  // A scroll of the page, or of the element around the sections. The scroll of another element
  // (a list in a popup) does not move the sections.
  onScroll = (event) => {
    if (event.target !== document && event.target !== this.#scroller) return

    this.#schedule(false)
  }

  onResize = () => {
    this.#schedule(true)
  }

  onInput = () => {
    this.#clicked = null
  }

  onClick = (event) => {
    const link = this.linkTargets.find((el) => el.contains(event.target))
    this.#clicked = this.#sections.find((entry) => entry.link === link)?.section ?? null
  }

  // One render in each frame, also for many events. With `measure`, the frame also measures the
  // layout again.
  #schedule(measure) {
    this.#measure ||= measure
    if (this.#frame) return

    this.#frame = requestAnimationFrame(() => {
      this.#frame = 0
      const measure = this.#measure
      this.#measure = false

      if (measure) {
        this.#update()
      } else {
        this.#render()
      }
    })
  }

  // Measures the layout again, and renders.
  #update() {
    this.#sections = this.#find()
    this.#scroller = this.#sections.length > 0 ? scrollParent(this.#sections[0].section) : null
    this.#margins.clear()
    for (const { section } of this.#sections) this.#margins.set(section, parseFloat(getComputedStyle(section).scrollMarginTop) || 0)

    if (this.#resize) this.#observe([document.body, ...this.#sections.map(({ section }) => section.parentElement)])

    this.#render()
  }

  // Observes the containers of the sections. A new element only: the observer calls back at once
  // for each new element, and this measures the layout again.
  #observe(elements) {
    const next = new Set(elements.filter(Boolean))

    for (const el of this.#observed) {
      if (!next.has(el)) this.#resize.unobserve(el)
    }

    for (const el of next) {
      if (!this.#observed.has(el)) this.#resize.observe(el)
    }

    this.#observed = next
  }

  #render() {
    const section = this.#currentSection()

    for (const link of this.linkTargets) {
      const active = section !== null && this.#sections.some((entry) => entry.link === link && entry.section === section)
      if (active && link.getAttribute("aria-current") !== "true") {
        link.setAttribute("aria-current", "true")
      } else if (!active && link.getAttribute("aria-current") === "true") {
        link.removeAttribute("aria-current")
      }

      link.toggleAttribute("data-active", active)
    }

    if (section === this.#current) return

    this.#current = section
    const link = this.#sections.find((entry) => entry.section === section)?.link ?? null
    this.dispatch("change", { detail: { link, section } })
  }

  // The links with a section of this page, in the order of the sections in the page.
  #find() {
    const sections = []
    for (const link of this.linkTargets) {
      const section = sectionOf(link)
      if (section) sections.push({ link, section })
    }

    return sections.sort((a, b) => (a.section === b.section ? 0 : a.section.compareDocumentPosition(b.section) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
  }

  #currentSection() {
    if (this.#sections.length === 0) return null

    const el = this.#scroller ?? document.scrollingElement ?? document.documentElement
    const top = this.#scroller ? this.#scroller.getBoundingClientRect().top + this.#scroller.clientTop : 0

    // At the end of the scroll: the clicked section when it is in view, or the last section.
    if (el.scrollHeight > el.clientHeight && el.scrollTop > 0 && atEnd(el)) {
      const clicked = this.#clicked && this.#sections.find((entry) => entry.section === this.#clicked)
      if (clicked && clicked.section.getBoundingClientRect().top >= top) return clicked.section

      return this.#sections[this.#sections.length - 1].section
    }

    let current = null
    for (const { section } of this.#sections) {
      if (section.getBoundingClientRect().top - top - this.#margins.get(section) <= 1) current = section
    }

    return current
  }
}

// The section of a link to this page: the element with the id of its hash.
function sectionOf(link) {
  // A link with `<base href>` has the path of the base: `#install` is on this page.
  const href = link.getAttribute("href") ?? ""
  const local = href.startsWith("#") || (link.origin === window.location.origin && link.pathname === window.location.pathname)
  if (!local || !link.hash) return null

  // A hash with a `%` that is not an escape is the id as it is.
  let id = link.hash.slice(1)
  try {
    id = decodeURIComponent(id)
  } catch {}

  return document.getElementById(id)
}

// The nearest ancestor that scrolls, or null for the page.
function scrollParent(el) {
  for (let parent = el.parentElement; parent && parent !== document.body && parent !== document.documentElement; parent = parent.parentElement) {
    if (scrolls(parent) && parent.scrollHeight > parent.clientHeight) return parent
  }

  return null
}
