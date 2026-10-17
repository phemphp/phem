import { Controller } from "/vendor/@hotwired/stimulus/stimulus.index.js";
import { highlight, escapeHtml } from "../highlight.js";

// A live example: the real source of src/Pages/Examples/<Name>.phpm (highlighted)
// and a preview iframe of the page /examples/<Name>.
// A multi-file example has one tab for each file: <button data-sandbox-target="tab" data-src="/url">.
export default class extends Controller {
    static targets = ["code", "preview", "tab"];
    static values = { example: String };

    connect() {
        this.sources = new Map();
        this.onLoad = this.onLoad.bind(this);
        this.previewTarget.addEventListener("load", this.onLoad);
        this.loadSource();
    }

    disconnect() {
        this.previewTarget.removeEventListener("load", this.onLoad);
        this.observer?.disconnect();
    }

    get selectedTab() {
        return this.tabTargets.find((tab) => tab.getAttribute("aria-selected") === "true") ?? this.tabTargets[0];
    }

    get url() {
        return this.hasTabTarget ? this.selectedTab.dataset.src : `/examples-src/${this.exampleValue}.phpm`;
    }

    select(event) {
        for (const tab of this.tabTargets) {
            tab.setAttribute("aria-selected", String(tab === event.currentTarget));
        }
        this.loadSource();
    }

    async loadSource() {
        const url = this.url;
        try {
            if (!this.sources.has(url)) {
                const response = await fetch(url);
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                this.sources.set(url, await response.text());
            }
            const source = this.sources.get(url);
            // The namespace line is the same in all single-file examples: hide it, like an import in a sandbox.
            // A multi-file example shows it, because the imports between the files use it.
            const header = this.hasTabTarget ? /^<\?php\s*/ : /^<\?php\s+namespace\s+[^;]+;\s*/;
            const shown = source.replace(header, "").trimEnd();
            if (url === this.url) this.codeTarget.innerHTML = `<code>${highlight(shown)}</code>`;
        } catch (error) {
            this.codeTarget.innerHTML = `<code class="sandbox-error">Could not load ${escapeHtml(url)} (${escapeHtml(error.message)}).</code>`;
        }
    }

    reset() {
        // A new load of the page drops the state that the mamu controller keeps in the page.
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
