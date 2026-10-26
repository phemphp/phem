// The helpers of the controller tests. Each test file stubs `fetch` and registers its controllers.

// A page with one component (`c1`) and the markup of its server render.
export async function mount(markup, rootAttributes = "") {
  document.body.innerHTML = `
    <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch"
         data-phem-state-value="{}" data-phem-signature-value="s1" data-phem-loading-delay-value="0" ${rootAttributes}>
      <phem-c id="c1">${markup}</phem-c>
    </div>`
  // Stimulus connects the controller in a mutation observer callback.
  await new Promise((resolve) => setTimeout(resolve, 0))
}

// The answer of the server to the next event: a morph of `c1` to `markup`, with no change of the
// state, or the `changes` of the state.
export function respond(markup, changes = {}, extra = {}) {
  const body = {
    streams: `<turbo-stream action="update" method="morph" target="c1"><template>${markup}</template></turbo-stream>`,
    changes,
    signature: "s2",
    ...extra,
  }
  fetch.mockResolvedValueOnce(new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } }))
}

// Resolves with the `phem:done` event of the next event.
export function nextDone() {
  return new Promise((resolve) => window.addEventListener("phem:done", resolve, { once: true }))
}

export function click(selector) {
  document.querySelector(selector).click()
}
