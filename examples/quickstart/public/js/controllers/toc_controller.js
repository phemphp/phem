import { Controller } from "/vendor/@hotwired/stimulus/stimulus.index.js";

// Marks the "On this page" link of the section that is at the top of the screen.
export default class extends Controller {
    static targets = ["link"];

    connect() {
        this.sections = this.linkTargets
            .map((link) => document.getElementById(link.hash.slice(1)))
            .filter(Boolean);

        this.update = this.update.bind(this);
        window.addEventListener("scroll", this.update, { passive: true });
        this.update();
    }

    disconnect() {
        window.removeEventListener("scroll", this.update);
    }

    update() {
        let current = this.sections[0];
        for (const section of this.sections) {
            if (section.getBoundingClientRect().top < 120) current = section;
        }
        for (const link of this.linkTargets) {
            link.classList.toggle("active", current !== undefined && link.hash === `#${current.id}`);
        }
    }
}
