// The new texts of `phem:announce` after the morph of an event of the user.
//
//     <p phem:announce>{{ products|length }} results</p>
//
// `data-phem-announce`: the phem controller calls watchAnnounce() before it renders the streams
// of an answer, and announce() after the render. Each stream of the answer compares the texts of
// the `data-phem-announce` elements of its target before and after its render. A push or the
// answer of another event that renders at the same time is not in these streams: it announces
// nothing. The browser announces each text that the target did not show before: "3 results" →
// "12 results" announces "12 results", and the same text again announces nothing. The texts, not
// the elements: the morph can replace an element and keep its text. A push, a refresh and the
// events of the browser (`load`, `poll`, `visible`) do not announce.
//
// The text is the text that a screen reader reads: with no `hidden` or `aria-hidden="true"`
// content, and with no `<template>`, `<script>` or `<style>`. A `data-phem-announce` element in
// another one is part of the text of the outer element: it does not announce again.
//
// The message goes to the live region of the page (phem_announcer.js), with the messages of
// `$e->announce()` of the same answer.

const ANNOUNCE = "[data-phem-announce]"

const HIDDEN = '[hidden], [aria-hidden="true"]'

const SILENT_TAGS = new Set(["TEMPLATE", "SCRIPT", "STYLE"])

// Watches the streams that Turbo takes from now until stop(). Turbo dispatches
// `turbo:before-stream-render` while renderStreamMessage() runs, and renders the streams later.
export function watchAnnounce() {
  const added = []
  const wrap = (event) => {
    const targets = targetsOf(event.detail.newStream)
    const render = event.detail.render
    event.detail.render = async (element) => {
      const before = announceTexts(targets())
      await render(element)
      added.push(...newTexts(before, announceTexts(targets())))
    }
  }

  document.addEventListener("turbo:before-stream-render", wrap)

  return {
    stop: () => document.removeEventListener("turbo:before-stream-render", wrap),
    announce: () => {
      for (const text of added) window.dispatchEvent(new CustomEvent("phem:announce", { detail: text }))
    },
  }
}

// The texts of the `data-phem-announce` elements in these elements (also the elements), in their
// order, with no empty text.
export function announceTexts(elements) {
  const texts = []
  for (const el of elements) {
    for (const found of [...(el.matches(ANNOUNCE) ? [el] : []), ...el.querySelectorAll(ANNOUNCE)]) {
      if (found.parentElement?.closest(ANNOUNCE) || found.closest(HIDDEN)) continue

      const text = spokenText(found).replace(/\s+/g, " ").trim()
      if (text !== "") texts.push(text)
    }
  }

  return texts
}

// The texts of after that were not in before. A text that shows two times is new when it showed
// only one time before.
export function newTexts(before, after) {
  const old = [...before]

  return after.filter((text) => {
    const index = old.indexOf(text)
    if (index === -1) return true

    old.splice(index, 1)
    return false
  })
}

// A function that finds the target elements of the stream again: a render can replace them.
function targetsOf(stream) {
  const id = stream.getAttribute("target")
  const selector = stream.getAttribute("targets")
  if (id) return () => [document.getElementById(id)].filter(Boolean)
  if (selector) return () => [...document.querySelectorAll(selector)]

  return () => []
}

// The text of the element with no hidden content.
function spokenText(el) {
  let text = ""
  for (const node of el.childNodes) {
    if (node.nodeType === Node.TEXT_NODE) text += node.data
    else if (node.nodeType === Node.ELEMENT_NODE && !node.matches(HIDDEN) && !SILENT_TAGS.has(node.tagName)) text += spokenText(node)
  }

  return text
}
