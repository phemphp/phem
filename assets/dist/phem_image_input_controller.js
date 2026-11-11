import { Controller } from "@hotwired/stimulus"

// The `phem-image-input` controller: shows a preview of the images of a file input before the
// upload, and makes the large images smaller in the browser. The submit sends the smaller files.
//
//     <div data-controller="phem-image-input" data-phem-image-input-max-width-value="1600"
//          data-phem-image-input-preview-class="thumb">
//         <input type="file" name="photos[]" multiple accept="image/*" data-phem-image-input-target="input" />
//         <div data-phem-image-input-target="preview"></div>
//     </div>
//
// A `preview` target that is an `<img>` shows the first image (an avatar). Another element gets an
// `<img>` for each image, with the `preview` class and the name of the file as its `alt`. The
// previews belong to the client: the morph of an event keeps them. When a handler calls
// `$e->clearFiles()`, the input is empty, and the previews go away.
//
// With the `maxWidth` or the `maxHeight` value (in pixels, 0 for no limit), a larger JPEG, PNG or
// WebP image gets the largest size in the limits, with the same ratio. The `type` value changes
// the media type of the images (for example "image/webp"), and the `quality` value (0.85 by
// default) is the quality of a JPEG or a WebP. An image that the browser cannot read stays as it
// is. The controller element has `data-resizing` while the browser resizes: a submit waits for
// the end.
//
// The controller dispatches `phem-image-input:resized` with the `files` in the detail, after it
// puts the new files in the input.
export default class PhemImageInputController extends Controller {
  static targets = ["input", "preview"]
  static classes = ["preview"]
  static values = {
    maxWidth: { type: Number, default: 0 },
    maxHeight: { type: Number, default: 0 },
    quality: { type: Number, default: 0.85 },
    type: { type: String, default: "" },
  }

  connect() {
    this.urls = []
    this.changes = 0

    this.change = (event) => {
      if (event.target === this.inputTarget) this.#prepare()
    }
    this.element.addEventListener("change", this.change)

    this.clear = (event) => {
      if (event.target === this.inputTarget) this.#showPreviews([])
    }
    this.element.addEventListener("phem:clear-files", this.clear)

    // The server renders the preview with no image: the morph keeps the images of the client.
    this.keepPreview = (event) => {
      if (this.previewTargets.includes(event.target) && this.urls.length) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-element", this.keepPreview)

    // A submit while the browser resizes waits for the new files.
    this.waitSubmit = (event) => {
      if (!this.resizing || event.target !== this.form) return

      event.preventDefault()
      event.stopImmediatePropagation()
      const submitter = event.submitter?.isConnected ? event.submitter : null
      this.resizing.then(() => (submitter ? this.form?.requestSubmit(submitter) : this.form?.requestSubmit()))
    }
    this.form = this.hasInputTarget ? this.inputTarget.form : null
    this.form?.addEventListener("submit", this.waitSubmit, { capture: true })
  }

  disconnect() {
    this.element.removeEventListener("change", this.change)
    this.element.removeEventListener("phem:clear-files", this.clear)
    this.element.removeEventListener("turbo:before-morph-element", this.keepPreview)
    this.form?.removeEventListener("submit", this.waitSubmit, { capture: true })
    this.changes++
    this.#revoke()
  }

  async #prepare() {
    const input = this.inputTarget
    const change = ++this.changes
    const files = [...input.files]

    if (this.maxWidthValue > 0 || this.maxHeightValue > 0 || this.typeValue) {
      let done
      this.resizing = new Promise((resolve) => (done = resolve))
      this.element.toggleAttribute("data-resizing", true)

      try {
        // An image that the browser cannot resize (a canvas too large for its memory) stays as it is.
        const resized = await Promise.all(files.map((file) => this.#resize(file).catch(() => file)))
        // The user chose other files while the browser resized these.
        if (change !== this.changes) return

        if (resized.some((file, i) => file !== files[i])) {
          const transfer = new DataTransfer()
          for (const file of resized) transfer.items.add(file)
          input.files = transfer.files
          files.splice(0, files.length, ...resized)
          this.dispatch("resized", { detail: { files: resized } })
        }
      } finally {
        if (change === this.changes) {
          this.resizing = null
          this.element.removeAttribute("data-resizing")
        }
        done()
      }
    }

    this.#showPreviews(files.filter((file) => file.type.startsWith("image/")))
  }

  // A smaller copy of an image, or the file itself.
  async #resize(file) {
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) return file

    let image
    try {
      image = await createImageBitmap(file, { imageOrientation: "from-image" })
    } catch {
      return file
    }

    const width = this.maxWidthValue > 0 ? this.maxWidthValue : Infinity
    const height = this.maxHeightValue > 0 ? this.maxHeightValue : Infinity
    const scale = Math.min(1, width / image.width, height / image.height)
    const type = this.typeValue || file.type
    if (scale === 1 && type === file.type) {
      image.close?.()
      return file
    }

    const canvas = document.createElement("canvas")
    canvas.width = Math.max(1, Math.round(image.width * scale))
    canvas.height = Math.max(1, Math.round(image.height * scale))
    canvas.getContext("2d").drawImage(image, 0, 0, canvas.width, canvas.height)
    image.close?.()

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, this.qualityValue))
    // A browser that cannot write the type gives a PNG: keep the file when it is not smaller.
    if (!blob || (blob.type !== type && blob.size >= file.size)) return file

    const name = blob.type === file.type ? file.name : renamed(file.name, blob.type)

    return new File([blob], name, { type: blob.type, lastModified: file.lastModified })
  }

  #showPreviews(files) {
    this.#revoke()
    this.urls = files.map((file) => URL.createObjectURL(file))

    for (const target of this.previewTargets) {
      if (target instanceof HTMLImageElement) {
        if (!("phemSrc" in target.dataset)) target.dataset.phemSrc = target.getAttribute("src") ?? ""
        if (this.urls.length) target.src = this.urls[0]
        else if (target.dataset.phemSrc) target.src = target.dataset.phemSrc
        else target.removeAttribute("src")
        continue
      }

      target.replaceChildren(...files.map((file, i) => {
        const img = document.createElement("img")
        img.src = this.urls[i]
        img.alt = file.name
        img.classList.add(...this.previewClasses)
        return img
      }))
    }
  }

  #revoke() {
    for (const url of this.urls ?? []) URL.revokeObjectURL(url)
    this.urls = []
  }
}

// The name of a file with the extension of its new media type.
function renamed(name, type) {
  const extension = { "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp" }[type]
  if (!extension) return name

  const base = name.replace(/\.[^.]*$/, "")

  return `${base}.${extension}`
}
