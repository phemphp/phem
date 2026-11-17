import { afterEach, beforeAll, describe, expect, it } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemDrawerProviderController from "../dist/phem_drawer_provider_controller.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem-drawer-provider", PhemDrawerProviderController)
})

afterEach(() => {
  document.body.innerHTML = ""
})

const $ = (id) => document.getElementById(id)

async function mount(open = false) {
  document.body.innerHTML = `
    <div data-controller="phem-drawer-provider">
      <div id="background" data-phem-drawer-provider-target="background"></div>
      <div id="indent" data-phem-drawer-provider-target="indent">
        <div id="drawer" data-controller="phem-dialog" data-phem-dialog-swipe-direction-value="down" ${open ? 'data-phem-dialog-open-value="true"' : ""}></div>
        <div id="dialog" data-controller="phem-dialog"></div>
      </div>
    </div>`
  await new Promise((resolve) => setTimeout(resolve, 0))
}

const send = (id, name, detail = {}) => $(id).dispatchEvent(new CustomEvent(`phem-dialog:${name}`, { bubbles: true, detail }))

describe("phem-drawer-provider", () => {
  it("makes the indent active while a drawer is open", async () => {
    await mount()
    expect($("indent").hasAttribute("data-inactive")).toBe(true)
    expect($("indent").style.getPropertyValue("--drawer-swipe-progress")).toBe("0")

    send("drawer", "open")
    expect($("indent").hasAttribute("data-active")).toBe(true)
    expect($("background").hasAttribute("data-active")).toBe(true)

    send("drawer", "swipe", { progress: 0.5 })
    expect($("indent").style.getPropertyValue("--drawer-swipe-progress")).toBe("0.5")

    send("drawer", "close")
    expect($("indent").hasAttribute("data-active")).toBe(false)
    expect($("indent").hasAttribute("data-inactive")).toBe(true)
    expect($("indent").style.getPropertyValue("--drawer-swipe-progress")).toBe("0")
  })

  it("ignores a dialog that is not a drawer", async () => {
    await mount()

    send("dialog", "open")
    expect($("indent").hasAttribute("data-active")).toBe(false)
  })

  it("is active at the connect when a drawer is open", async () => {
    await mount(true)

    expect($("indent").hasAttribute("data-active")).toBe(true)
  })
})
