import { Controller } from "/_rhp/assets/vendor/stimulus.js";
import { highlight, escapeHtml } from "../highlight.js";

// A live example: the real source of src/pages/examples/<Name>.rhp (highlighted)
// and a preview iframe of the page /examples/<Name>.
export default class extends Controller {
    static targets = ["code", "preview"];
    static values = { example: String };

    connect() {
        this.onLoad = this.onLoad.bind(this);
        this.previewTarget.addEventListener("load", this.onLoad);
        this.loadSource();
    }

    disconnect() {
        this.previewTarget.removeEventListener("load", this.onLoad);
        this.observer?.disconnect();
    }

    get file() {
        return `${this.exampleValue}.rhp`;
    }

    async loadSource() {
        try {
            const response = await fetch(`/examples-src/${this.file}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const source = await response.text();
            // The namespace line is the same in all examples: hide it, like an import in a sandbox.
            const shown = source.replace(/^<\?php\s+namespace\s+[^;]+;\s*/, "").trimEnd();
            this.codeTarget.innerHTML = `<code>${highlight(shown)}</code>`;
        } catch (error) {
            this.codeTarget.innerHTML = `<code class="sandbox-error">Could not load ${escapeHtml(this.file)} (${escapeHtml(error.message)}).</code>`;
        }
    }

    reset() {
        // A new load of the page drops the state that the rhp controller keeps in the page.
        this.previewTarget.src = `/examples/${this.exampleValue}`;
    }

    onLoad() {
        this.observer?.disconnect();

        const frameWindow = this.previewTarget.contentWindow;
        const doc = this.previewTarget.contentDocument;
        if (!frameWindow || !doc) return;

        this.resize();
        // Turbo morphs the preview after each event. Follow the size of its content.
        this.observer = new frameWindow.ResizeObserver(() => this.resize());
        this.observer.observe(doc.documentElement);
    }

    resize() {
        const doc = this.previewTarget.contentDocument;
        if (!doc) return;
        const height = Math.ceil(doc.documentElement.getBoundingClientRect().height);
        this.previewTarget.style.height = `${Math.max(height, 60)}px`;
    }
}
