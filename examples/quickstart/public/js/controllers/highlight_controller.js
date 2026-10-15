import { Controller } from "/_rhp/assets/vendor/stimulus.js";
import { highlight } from "../highlight.js";

// Highlights a short static code fragment of the docs: <pre data-controller="highlight">.
export default class extends Controller {
    connect() {
        const code = this.element.querySelector("code") || this.element;
        code.innerHTML = highlight(code.textContent.trim());
    }
}
