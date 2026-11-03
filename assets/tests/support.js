// The helpers of the controller tests. Each test file stubs `fetch` and registers its controllers.

// A page with one component (`c1`) and the markup of its server render.
export async function mount(markup, rootAttributes = "") {
  document.body.innerHTML = `
    <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch submit->phem#dispatch change->phem#dispatch"
         data-phem-state-value="{}" data-phem-signature-value="s1" data-phem-loading-delay-value="0" ${rootAttributes}>
      <phem-c data-phem-c="c1">${markup}</phem-c>
    </div>`
  // Stimulus connects the controller in a mutation observer callback.
  await new Promise((resolve) => setTimeout(resolve, 0))
}

// The JSON of an answer of the server: a morph of `c1` to `markup` (in its `<phem-c>`), with no
// change of the state, or the `changes` of the state.
export function answer(markup, changes = {}, extra = {}) {
  return {
    streams: `<turbo-stream action="replace" method="morph" targets="[data-phem-c=c1]"><template><phem-c data-phem-c="c1">${markup}</phem-c></template></turbo-stream>`,
    changes,
    signature: "s2",
    ...extra,
  }
}

// The answer of the server to the next event (see answer()).
export function respond(markup, changes = {}, extra = {}) {
  fetch.mockResolvedValueOnce(new Response(JSON.stringify(answer(markup, changes, extra)), { status: 200, headers: { "Content-Type": "application/json" } }))
}

// Resolves with the `phem:done` event of the next event.
export function nextDone() {
  return new Promise((resolve) => window.addEventListener("phem:done", resolve, { once: true }))
}

export function click(selector) {
  document.querySelector(selector).click()
}
