// The stage of the story (src/components/home/Stage.astro) and its timeline. StageLines measures
// the stage and builds its lines; the Director gives the timeline its verbs (draw, travel,
// reveal, conceal); scenesTimeline() tells the story with them.
//
// Every line draws as a path and fades: the connectors, the outlines of the code windows, of the
// article and of the browsers, and their inner lines. A dot that travels on a line is the head of
// that line, because one state sets both (src/scripts/stroke.ts).
import { gsap } from 'gsap';
import { outline, Stroke, type Box, type Outline } from './stroke';

// The design size of the canvas, in the units of Stage.astro, and the center of scene 1.
export const canvasWidth = 920;
export const canvasHeight = 760;
const center = { x: 460, y: 380 };

export type Named = (name: string) => HTMLElement;

export function named(canvas: HTMLElement): Named {
    return (name) => {
        const element = canvas.querySelector<HTMLElement>(`[data-s="${name}"]`);

        if (!element) {
            throw new Error(`The stage has no "${name}" element.`);
        }

        return element;
    };
}

// The box of an element or of a range of text in design units, whatever the scale of the canvas.
function measure(canvas: HTMLElement): (element: Element | Range) => Box {
    const frame = canvas.getBoundingClientRect();
    const scale = frame.width / canvasWidth;

    return (element) => {
        const rect = element.getBoundingClientRect();

        return {
            left: (rect.left - frame.left) / scale,
            top: (rect.top - frame.top) / scale,
            width: rect.width / scale,
            height: rect.height / scale,
        };
    };
}

const middle = (b: Box): number => b.top + b.height / 2;

// The lines of the stage: the geometry that depends on the text, the strokes of the connectors,
// and the outlines of the elements with `data-outline`.
export class StageLines {
    readonly el: Named;
    readonly strokes = new Map<string, Stroke>();
    readonly outlines = new Map<string, Outline>();
    sketch: Stroke[] = [];
    private readonly slots = new Map<string, Box>();

    constructor(private readonly canvas: HTMLElement) {
        this.el = named(canvas);
    }

    build(): void {
        const box = measure(this.canvas);

        this.connectors(box);
        this.source(box);
        this.sketchLike(box);

        for (const path of this.canvas.querySelectorAll<SVGPathElement>('path[data-s]')) {
            this.strokes.set(path.dataset.s ?? '', new Stroke(path));
        }

        const layer = this.canvas.querySelector<SVGSVGElement>('[data-stage-outlines]');

        if (layer) {
            for (const element of this.canvas.querySelectorAll<HTMLElement>('[data-outline]')) {
                const drawn = outline(element, layer, box);
                gsap.set(drawn.group, { autoAlpha: 0 });
                this.outlines.set(element.dataset.s ?? '', drawn);
            }
        }

        // Scene 1 (the page), scene 6 (browser A) and scene 8 (the drawing): the button of the
        // browser is the button of the story.
        for (const name of ['page', 'browser-a']) {
            this.slots.set(name, box(this.el(name).querySelector('.like--slot') ?? this.el(name)));
        }

        const sketch = this.el('sketch');
        this.slots.set('sketch', box(sketch.querySelector('[data-sketch-slot]') ?? sketch));
        // The Symfony mark is a filled shape: it fades in with the texts.
        this.sketch = [...sketch.querySelectorAll<SVGPathElement>('path:not(.sketch__logo)')].map((path) => new Stroke(path));

        // The paths draw the outlines now: the CSS hides the borders of the HTML (home.css).
        this.canvas.closest('[data-stage]')?.classList.add('is-drawn');
    }

    destroy(): void {
        this.outlines.forEach((drawn) => drawn.group.remove());
        this.canvas.closest('[data-stage]')?.classList.remove('is-drawn');
    }

    stroke(name: string): Stroke {
        const stroke = this.strokes.get(name);

        if (!stroke) {
            throw new Error(`The stage has no "${name}" line.`);
        }

        return stroke;
    }

    // The center of the button of the story at `scale` when it fills the Like slot of a browser:
    // its left edge is the left edge of the slot.
    slot(name: string, scale: number): { x: number; y: number } {
        const slot = this.slots.get(name);

        if (!slot) {
            throw new Error(`The stage has no "${name}" slot.`);
        }

        return { x: slot.left + (this.el('btn').offsetWidth * scale) / 2, y: middle(slot) };
    }

    outline(name: string): Outline {
        const drawn = this.outlines.get(name);

        if (!drawn) {
            throw new Error(`The stage has no "${name}" outline.`);
        }

        return drawn;
    }

    // Scene 1: the source of the page. Each line of code sits on the left of the part of the page
    // that it renders, and its connector ends in the padding of the page. #[Page] sits above the
    // URL, and #[Component] under the page: its connector climbs beside the page to the button.
    private source(box: (element: Element | Range) => Box): void {
        const el = this.el;
        const page = el('page');
        const part = (selector: string): Box => box(page.querySelector(selector) ?? page);
        const frame = box(page);
        const column = frame.left - 30;
        const title = part('.browser__title');
        const text = [...page.querySelectorAll('.browser__body > .browser__line')].map(box);
        const like = part('.like--slot');
        const url = part('.browser__url');
        const note = (name: string, y: number, end: number): void => {
            el(name).style.left = `${column - el(name).offsetWidth}px`;
            el(name).style.top = `${y - el(name).offsetHeight / 2}px`;
            el(`${name}-ln`).setAttribute('d', `M ${column + 6} ${y} H ${end}`);
        };

        note('note-title', middle(title), title.left - 10);
        note('note-content', (text[0].top + text[text.length - 1].top + text[text.length - 1].height) / 2, text[0].left - 10);

        const pageNote = el('note-page');
        const pageNoteY = frame.top - 40;
        pageNote.style.left = `${url.left}px`;
        pageNote.style.top = `${pageNoteY - pageNote.offsetHeight / 2}px`;
        el('note-page-ln').setAttribute('d', `M ${url.left + 14} ${pageNoteY + pageNote.offsetHeight / 2 + 6} V ${url.top - 3}`);

        const likeNote = el('note-like');
        const climb = column + 10;
        const likeY = middle(like);
        const likeNoteTop = frame.top + frame.height + 30;
        const radius = 6;
        likeNote.style.left = `${climb - 8}px`;
        likeNote.style.top = `${likeNoteTop}px`;
        el('note-like-ln').setAttribute('d', `M ${climb} ${likeNoteTop - 4} V ${likeY + radius} Q ${climb} ${likeY}, ${climb + radius} ${likeY} H ${like.left - 10}`);
    }

    // Scene 8: the outline of the button of the story where it lands in the page that the elephant
    // holds: the box of the button at the scale of the drawing, turned with the page.
    private sketchLike(box: (element: Element | Range) => Box): void {
        const el = this.el;
        const btn = el('btn');
        const slot = box(el('sketch').querySelector('[data-sketch-slot]') ?? el('sketch'));
        const width = btn.offsetWidth * sketchScale;
        const height = btn.offsetHeight * sketchScale;
        const x = slot.left;
        const y = middle(slot) - height / 2;
        const r = Math.min((parseFloat(getComputedStyle(btn).borderTopLeftRadius) || 0) * sketchScale, height / 2);
        const path = el('sketch-like');

        path.setAttribute('d', `M ${x} ${y + r} A ${r} ${r} 0 0 1 ${x + r} ${y} H ${x + width - r} A ${r} ${r} 0 0 1 ${x + width} ${y + r} V ${y + height - r} A ${r} ${r} 0 0 1 ${x + width - r} ${y + height} H ${x + r} A ${r} ${r} 0 0 1 ${x} ${y + height - r} Z`);
        path.setAttribute('transform', `rotate(${sketchTilt} ${x + width / 2} ${y + height / 2})`);
    }

    // The connectors that start or end at a mark of the code.
    private connectors(box: (element: Element | Range) => Box): void {
        const el = this.el;
        const mark = (scope: string, name: string): Box => box(el(scope).querySelector(`[data-mark="${name}"]`) ?? el(scope));

        // Scene 2: a bracket on the right of the code links the line of the handler and the line
        // of onClick. Each arm starts at the end of its line. It draws from onClick up to the
        // handler.
        const handler = middle(mark('code', 'handler'));
        const render = middle(mark('code', 'render'));
        const onclick = middle(mark('code', 'onclick'));
        const lineOf = (name: string): Element => el('code').querySelector(`[data-mark="${name}"]`)?.closest('.line') ?? el('code');
        const lineEnd = (name: string): number => {
            const line = box(lineOf(name));

            return line.left + line.width + 8;
        };

        // The left edge of the first character of a line that is not a space: the indent is in
        // the text of the first token.
        const lineStart = (name: string): number => {
            const line = lineOf(name);
            const walker = document.createTreeWalker(line, NodeFilter.SHOW_TEXT);

            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                const offset = (node.textContent ?? '').search(/\S/);

                if (-1 === offset) {
                    continue;
                }

                const range = document.createRange();
                range.setStart(node, offset);
                range.setEnd(node, offset + 1);

                return box(range).left;
            }

            return box(line).left;
        };
        const handlerEnd = lineEnd('handler');
        const onclickEnd = lineEnd('onclick');
        const side = Math.max(handlerEnd, onclickEnd) + 12;
        el('bracket').setAttribute('d', `M ${onclickEnd} ${onclick} H ${side} V ${handler} H ${handlerEnd}`);

        // Scene 3: the event goes from the button to the handler, and the HTML of render() comes
        // back. The lines end just before `public`, the first word of each line.
        const button = { x: 170 + el('btn').offsetWidth / 2 + 8, y: center.y };
        const handlerStart = lineStart('handler') - 10;
        const renderStart = lineStart('render') - 10;
        el('trip-out').setAttribute('d', `M ${button.x} ${button.y - 12} C ${button.x + 70} ${button.y - 12}, ${handlerStart - 70} ${handler}, ${handlerStart} ${handler}`);
        el('trip-back').setAttribute('d', `M ${renderStart} ${render} C ${renderStart - 60} ${render}, ${button.x + 60} ${button.y + 12}, ${button.x} ${button.y + 12}`);

        // Each tag sits at the middle of its line, on the outer side: "onClick Event" before the
        // line that goes up to the handler, "HTML" under the flat line that comes back from render().
        const middleOf = (path: string): DOMPoint => {
            const line = el(path) as unknown as SVGPathElement;

            return line.getPointAtLength(line.getTotalLength() / 2);
        };
        const out = middleOf('trip-out');
        const back = middleOf('trip-back');
        // The line bends at its middle, so the tag moves to the left until it keeps a gap of 10 to
        // every point of the line.
        const event = el('event');
        const tripOut = el('trip-out') as unknown as SVGPathElement;
        const tripLength = tripOut.getTotalLength();
        const samples = Array.from({ length: 41 }, (_, index) => tripOut.getPointAtLength((tripLength * index) / 40));
        const eventTop = out.y - event.offsetHeight / 2;
        const gapTo = (left: number): number => Math.min(...samples.map((point) => {
            const dx = Math.max(left - point.x, 0, point.x - (left + event.offsetWidth));
            const dy = Math.max(eventTop - point.y, 0, point.y - (eventTop + event.offsetHeight));

            return Math.hypot(dx, dy);
        }));
        let eventLeft = out.x - 12 - event.offsetWidth;

        while (gapTo(eventLeft) < 10) {
            eventLeft -= 2;
        }

        event.style.left = `${eventLeft}px`;
        event.style.top = `${eventTop}px`;
        el('html').style.left = `${back.x - el('html').offsetWidth / 2}px`;
        el('html').style.top = `${back.y + 8}px`;

        // Scene 6: the click goes down from the Like button of browser A to like(), and the HTML
        // comes back up beside it. The broadcast leaves from the line of #[Broadcast] and climbs
        // beside the code window to Mercure.
        const like = box(el('browser-a').querySelector('.like--slot') ?? el('browser-a'));
        const desktop = box(el('browser-a'));
        const likeButton = box(el('broadcast'));
        const x = like.left + like.width / 2;
        const from = desktop.top + desktop.height + 2;
        const to = likeButton.top - 2;
        el('click-out').setAttribute('d', `M ${x - 6} ${from} V ${to}`);
        el('click-back').setAttribute('d', `M ${x + 6} ${to} V ${from}`);
        el('click-event').style.left = `${x - 18 - el('click-event').offsetWidth}px`;
        el('click-event').style.top = `${(from + to) / 2 - el('click-event').offsetHeight / 2}px`;
        el('click-html').style.left = `${x + 18}px`;
        el('click-html').style.top = `${(from + to) / 2 - el('click-html').offsetHeight / 2}px`;

        const flag = middle(mark('broadcast', 'broadcast'));
        const gap = likeButton.left + likeButton.width + 10;
        el('hub').setAttribute('d', `M ${likeButton.left + likeButton.width} ${flag} H ${gap - 8} Q ${gap} ${flag}, ${gap} ${flag - 8} V 351`);

        // Scene 4: each boundary leads to its tag in the composition. The lines nest like the
        // boxes: the line of the outer box turns last, so no two lines cross.
        const leads: [string, string, number, number, number][] = [
            ['lead-document', 'document', 330, 130, 382],
            ['lead-article', 'article', 305, 172, 364],
            ['lead-like', 'like-button', 271, 560, 346],
        ];

        for (const [path, name, x, y, bend] of leads) {
            const target = mark('compose', name);
            const end = { x: target.left - 6, y: middle(target) };
            el(path).setAttribute('d', `M ${x} ${y} C ${bend} ${y}, ${bend} ${end.y}, ${end.x} ${end.y}`);
        }
    }
}

// The verbs of the timeline. Each one returns the time when it ends, so the next step can start
// when a dot arrives. The CSS hides each stage element first, so a tween sets its "to" state only
// when the scroll reaches it (immediateRender: false).
class Director {
    constructor(
        private readonly lines: StageLines,
        readonly tl: gsap.core.Timeline = gsap.timeline({ paused: true }),
    ) {}

    el(name: string): HTMLElement {
        return this.lines.el(name);
    }

    // The button of the story moves to (x, y) at a scale. `--btn-scale` keeps its border as wide as
    // the lines at any scale (home.css).
    move(x: number, y: number, scale: number, time: number, duration = 0.4, vars: gsap.TweenVars = {}): number {
        this.tl.to(this.el('btn'), { x: x - center.x, y: y - center.y, scale, '--btn-scale': scale, duration, ease: 'power2.inOut', ...vars }, time);

        return time + duration;
    }

    fadeIn(names: string[], time: number, duration = 0.06): number {
        this.tl.fromTo(names.map((name) => this.el(name)), { autoAlpha: 0 }, { autoAlpha: 1, duration, ease: 'power1.out', immediateRender: false }, time);

        return time + duration;
    }

    fadeOut(names: string[], time: number, duration = 0.1): number {
        this.tl.fromTo(names.map((name) => this.el(name)), { autoAlpha: 1 }, { autoAlpha: 0, duration, ease: 'power1.in', immediateRender: false }, time);

        return time + duration;
    }

    // A connector draws from its start and fades in.
    draw(name: string, time: number, duration: number, ease = 'power2.inOut'): number {
        const stroke = this.lines.stroke(name);

        this.tl.fromTo(stroke.path, { autoAlpha: 0 }, { autoAlpha: 1, duration: duration * 0.3, ease: 'none', immediateRender: false }, time);
        this.tl.fromTo(stroke.state, { to: 0 }, { to: 1, duration, ease, immediateRender: false, onUpdate: stroke.render }, time);

        return time + duration;
    }

    // A dot travels on a connector, and is the head of the line that draws behind it.
    travel(name: string, dot: string, time: number, duration: number): number {
        const stroke = this.lines.stroke(name);
        const circle = this.el(dot);
        const follow = (): void => {
            stroke.render();

            const head = stroke.head();
            circle.setAttribute('cx', String(head.x));
            circle.setAttribute('cy', String(head.y));
        };

        this.tl.fromTo(stroke.path, { autoAlpha: 0 }, { autoAlpha: 1, duration: duration * 0.3, ease: 'none', immediateRender: false }, time);
        this.tl.set(circle, { autoAlpha: 1 }, time);
        this.tl.fromTo(stroke.state, { to: 0 }, { to: 1, duration, ease: 'none', immediateRender: false, onUpdate: follow }, time);
        this.tl.set(circle, { autoAlpha: 0 }, time + duration);

        return time + duration;
    }

    // Connectors retract from their start and fade out. Each tween starts from the drawn state:
    // the intro of scene 1 draws the source of the page, and the scroll back must show it.
    retract(names: string[], time: number, duration = 0.15): number {
        const shown = { immediateRender: false };

        for (const name of names) {
            const stroke = this.lines.stroke(name);

            this.tl.fromTo(stroke.state, { from: 0, to: 1 }, { from: 1, to: 1, duration, ease: 'power2.in', onUpdate: stroke.render, ...shown }, time);
            this.tl.fromTo(stroke.path, { autoAlpha: 1 }, { autoAlpha: 0, duration: duration * 0.5, ease: 'none', ...shown }, time + duration * 0.5);
        }

        return time + duration;
    }

    // An outline draws from its top left corner, then its inner lines draw, then its text fades in.
    reveal(name: string, time: number, duration = 0.25): number {
        const drawn = this.lines.outline(name);
        const step = drawn.lines.length > 1 ? (duration * 0.3) / (drawn.lines.length - 1) : 0;

        this.tl.fromTo(drawn.group, { autoAlpha: 0 }, { autoAlpha: 1, duration: duration * 0.25, ease: 'none', immediateRender: false }, time);
        drawn.frame.forEach((stroke) => this.stretch(stroke, time, duration, 'power2.inOut'));
        drawn.lines.forEach((stroke, index) => this.stretch(stroke, time + duration * 0.3 + index * step, duration * 0.4, 'power2.out'));
        this.tl.fromTo(this.el(name), { autoAlpha: 0 }, { autoAlpha: 1, duration: duration * 0.45, ease: 'power1.out', immediateRender: false }, time + duration * 0.5);

        return time + duration;
    }

    // Outlines retract toward their bottom right corner and fade out, with their text. Each tween
    // starts from the drawn state, not from the state it finds: the intro of scene 1 also moves
    // the page, and the scroll back must show the outline complete.
    conceal(names: string[], time: number, duration = 0.2): number {
        const shown = { immediateRender: false };

        for (const name of names) {
            const drawn = this.lines.outline(name);

            [...drawn.frame, ...drawn.lines].forEach((stroke) => this.tl.fromTo(stroke.state, { from: 0, to: 1 }, { from: 1, to: 1, duration, ease: 'power2.in', onUpdate: stroke.render, ...shown }, time));
            this.tl.fromTo(drawn.group, { autoAlpha: 1 }, { autoAlpha: 0, duration: duration * 0.5, ease: 'none', ...shown }, time + duration * 0.5);
            this.tl.fromTo(this.el(name), { autoAlpha: 1 }, { autoAlpha: 0, duration: duration * 0.5, ease: 'power1.in', ...shown }, time);
        }

        return time + duration;
    }

    // The drawing draws in, path by path, in the order of the document.
    sketch(time: number, duration: number): number {
        const strokes = this.lines.sketch;
        const each = duration * 0.2;
        const step = strokes.length > 1 ? (duration - each) / (strokes.length - 1) : 0;

        strokes.forEach((stroke, index) => this.stretch(stroke, time + index * step, each, 'power1.inOut'));

        return time + duration;
    }

    // A node dot grows from its center.
    grow(name: string, time: number, radius: number, duration = 0.04): number {
        this.tl.fromTo(this.el(name), { autoAlpha: 1, attr: { r: 0 } }, { autoAlpha: 1, attr: { r: radius }, duration, ease: 'power2.out', immediateRender: false }, time);

        return time + duration;
    }

    shrink(names: string[], time: number, duration = 0.08): number {
        this.tl.to(names.map((name) => this.el(name)), { attr: { r: 0 }, autoAlpha: 0, duration, ease: 'power2.in' }, time);

        return time + duration;
    }

    // Marks of the code take the accent.
    mark(marks: Element[], time: number, duration = 0.06, stagger = 0): number {
        this.tl.fromTo(marks, { '--on': 0 }, { '--on': 1, duration, stagger, immediateRender: false }, time);

        return time + duration + stagger * Math.max(0, marks.length - 1);
    }

    marks(scope: string, ...names: string[]): Element[] {
        return [...this.el(scope).querySelectorAll('[data-mark]')].filter((mark) => 0 === names.length || names.includes(mark.getAttribute('data-mark') ?? ''));
    }

    // The count changes with a short cross-fade.
    swap(values: Element[], from: number, to: number, time: number): void {
        this.tl.to(values[from], { autoAlpha: 0, duration: 0.04 }, time);
        this.tl.fromTo(values[to], { autoAlpha: 0 }, { autoAlpha: 1, duration: 0.04, immediateRender: false }, time);
    }

    // The updated component shows the accent for a moment.
    flash(targets: Element[], time: number): void {
        this.tl.to(targets, { '--active': 1, duration: 0.04 }, time);
        this.tl.to(targets, { '--active': 0, duration: 0.2 }, time + 0.3);
    }

    private stretch(stroke: Stroke, time: number, duration: number, ease: string): void {
        this.tl.fromTo(stroke.state, { to: 0 }, { to: 1, duration, ease, immediateRender: false, onUpdate: stroke.render }, time);
    }
}

// The time of the story. One unit is one screen of scroll. A long scene (Scene.astro `long`) has
// two screens: it rests one unit more, and plays its steps in that unit. `long` gives the index of
// each long scene, and story.ts reads them from the page.
export class StoryTime {
    constructor(readonly long: number[]) {}

    // Scene n rests at `rest(n)`: n - 1, plus one unit for each long scene before it.
    readonly rest = (scene: number): number => scene - 1 + this.long.filter((long) => long < scene).length;

    // The move into scene n starts at `into(n)`, 0.7 before its rest.
    readonly into = (scene: number, offset = 0): number => this.rest(scene) - 0.7 + offset;
}

// The scale of the button of the story in the page of scene 1 and in the browsers of scene 6. The
// Like slots of these browsers have the same size (home.css).
const pageScale = 1.1;
const browserScale = 0.7;
const sketchScale = 0.78;
// The page that the elephant holds leans to the left (Friends.astro).
const sketchTilt = -8;

// Scene 1: the lines of the source of the page, and their connectors.
const notes = ['note-page', 'note-title', 'note-content', 'note-like'];
const noteLines = notes.map((note) => `${note}-ln`);

// Scene 1 at rest: the page, its Like button and its source. The timeline starts from this state,
// so the scroll back to scene 1 shows the page again, with or without the intro.
function restScene1(lines: StageLines): void {
    const drawn = lines.outline('page');
    const slot = lines.slot('page', pageScale);

    [...drawn.frame, ...drawn.lines, ...noteLines.map((name) => lines.stroke(name))].forEach((stroke) => {
        stroke.state.to = 1;
        stroke.render();
    });
    gsap.set([drawn.group, lines.el('page'), ...notes.map((name) => lines.el(name)), ...noteLines.map((name) => lines.el(name))], { autoAlpha: 1 });
    gsap.set(lines.el('btn'), { x: slot.x - center.x, y: slot.y - center.y, scale: pageScale, '--btn-scale': pageScale, autoAlpha: 1, '--active': 0 });
}

// Scene 1 on load: the page draws in, then a dot extends into the outline of its Like button, and
// the source of the page draws in around it. The links of the scene show at once.
export function introTimeline(lines: StageLines): gsap.core.Timeline {
    const d = new Director(lines, gsap.timeline({ paused: true, delay: 0.15 }));
    const btn = d.el('btn');
    const dot = d.el('intro-dot');
    const slot = lines.slot('page', pageScale);
    const x = btn.offsetWidth / 2 - 4;
    const y = btn.offsetHeight / 2 - 4;

    dot.setAttribute('cx', String(slot.x));
    dot.setAttribute('cy', String(slot.y));

    d.reveal('page', 0, 1.1);
    d.tl.set(dot, { autoAlpha: 1 }, 0.65)
        .fromTo(btn, { clipPath: `inset(${y}px ${x}px round 8px)` }, { clipPath: 'inset(0px 0px round 8px)', duration: 0.8, ease: 'power3.inOut', immediateRender: false }, 0.9)
        .to(dot, { autoAlpha: 0, duration: 0.2 }, 1)
        .fromTo(btn.querySelectorAll('.like__label, .like__sep, .like__count'), { autoAlpha: 0, y: 4 }, { autoAlpha: 1, y: 0, duration: 0.5, ease: 'power3.out', stagger: 0.06, immediateRender: false }, 1.4)
        .set(btn, { clearProps: 'clipPath' });

    notes.forEach((note, index) => {
        const time = 1.5 + index * 0.12;

        d.fadeIn([note], time, 0.4);
        d.draw(`${note}-ln`, time + 0.1, 0.5);
    });

    // Render the end, then the start: each element takes its first state before the intro plays.
    return d.tl.progress(1).progress(0);
}

export function scenesTimeline(lines: StageLines, time: StoryTime): gsap.core.Timeline {
    // Scene 6 plays its click and its broadcast in its second screen.
    if (!time.long.includes(6)) {
        throw new Error('Scene 6 of the story must be long (Scene.astro).');
    }

    const { rest, into } = time;
    const d = new Director(lines);
    const btn = d.el('btn');
    const values = [...btn.querySelectorAll('.like__value')];
    let t: number;

    restScene1(lines);

    // 1 → 2: the page and its source go, the button shifts aside, and its code draws in beside it.
    d.retract(noteLines, into(2), 0.12);
    d.fadeOut(notes, into(2), 0.08);
    d.conceal(['page'], into(2), 0.2);
    d.move(170, center.y, 1, into(2, 0.05));
    t = d.reveal('code', into(2, 0.15), 0.3);
    t = d.mark(d.marks('code', 'handler', 'onclick'), t);
    d.draw('bracket', t, 0.15);

    // 2 → 3: the event travels to the handler, the HTML travels back, the count changes.
    d.fadeIn(['event'], into(3));
    t = d.travel('trip-out', 'travel-1', into(3), 0.24);
    t = d.mark(d.marks('code', 'update'), t, 0.04);
    d.mark(d.marks('code', 'render'), t, 0.04);
    d.fadeIn(['html'], t + 0.04);
    t = d.travel('trip-back', 'travel-1', t + 0.04, 0.24);
    d.swap(values, 0, 1, t);
    d.flash([btn], t);

    // 3 → 4: pull back to the article, its boundaries and the composition.
    d.retract(['trip-out', 'trip-back', 'bracket'], into(4), 0.14);
    d.fadeOut(['event', 'html'], into(4));
    d.conceal(['code'], into(4), 0.2);
    d.move(175, 560, 0.9, into(4, 0.05));
    d.reveal('article', into(4, 0.15), 0.3);
    d.reveal('document', into(4, 0.2), 0.3);
    d.reveal('compose', into(4, 0.2), 0.3);
    t = d.reveal('like-bound', into(4, 0.28), 0.2);
    ['lead-document', 'lead-article', 'lead-like'].forEach((lead, index) => {
        const end = d.draw(lead, t + index * 0.04, 0.12);
        d.mark(d.marks('compose', ['document', 'article', 'like-button'][index]), end - 0.02, 0.04);
    });

    // 4 → 5: the component stays, and lines reach the framework, one at a time.
    d.retract(['lead-document', 'lead-article', 'lead-like'], into(5), 0.12);
    d.conceal(['compose', 'like-bound', 'document', 'article'], into(5), 0.2);
    d.move(170, 310, 1, into(5, 0.05), 0.3);
    const framework = ['services', 'security', 'rate-limit'];
    framework.forEach((name, index) => {
        const arrival = d.travel(`link-${name}`, `travel-${index + 1}`, into(5, 0.35 + index * 0.1), 0.07);
        d.grow(`dot-${name}`, arrival, 4);
        d.fadeIn([`label-${name}`], arrival, 0.05);
        const end = d.reveal(`excerpt-${name}`, arrival, 0.08);
        d.mark(d.marks(`excerpt-${name}`), end - 0.03, 0.04);
    });

    // 5 → 6: the button lands in a desktop browser, beside a tablet and a phone that show the
    // same post, and the code of the LikeButton draws in.
    const slot = lines.slot('browser-a', browserScale);
    d.retract(framework.map((name) => `link-${name}`), into(6, -0.1), 0.12);
    d.shrink(framework.map((name) => `dot-${name}`), into(6, -0.1));
    d.fadeOut(framework.map((name) => `label-${name}`), into(6, -0.1));
    d.conceal(framework.map((name) => `excerpt-${name}`), into(6, -0.1), 0.15);
    d.move(slot.x, slot.y, browserScale, into(6, -0.05), 0.3);
    d.reveal('browser-a', into(6, 0.05), 0.2);
    d.reveal('browser-b', into(6, 0.09), 0.2);
    d.reveal('browser-c', into(6, 0.13), 0.2);
    d.reveal('broadcast', into(6, 0.15), 0.18);
    d.fadeIn(['legend'], into(6, 0.28), 0.06);

    // Scene 6 is long: the click and the broadcast play in the unit of scroll after its rest, while
    // its copy stays on the screen. The click: the event goes down to like(), which saves the
    // like, and the HTML comes back.
    t = rest(6) + 0.05;
    d.fadeIn(['click-event'], t);
    t = d.travel('click-out', 'travel-1', t, 0.2);
    t = d.mark(d.marks('broadcast', 'handler', 'shared'), t, 0.08);
    d.fadeIn(['click-html'], t);
    t = d.travel('click-back', 'travel-1', t, 0.2);
    d.swap(values, 1, 2, t);
    d.flash([btn], t);

    // Then #[Broadcast]: the change goes up to Mercure, and on to the other devices. The tablet
    // keeps its comments open: UI state stays in each browser.
    t = d.mark(d.marks('broadcast', 'broadcast'), t, 0.08);
    t = d.travel('hub', 'travel-2', t, 0.15);
    d.grow('mercure-dot', t, 5, 0.06);
    d.fadeIn(['mercure'], t, 0.06);
    d.travel('push-b', 'travel-2', t, 0.15);
    t = d.travel('push-c', 'travel-3', t, 0.15);

    for (const name of ['browser-b', 'browser-c']) {
        const like = d.el(name).querySelector<HTMLElement>('.like');

        if (like) {
            gsap.set([like, ...lines.outline(name).likes], { '--active': 0 });
            d.swap([...like.querySelectorAll('.like__value')], 0, 1, t);
            d.flash([like, ...lines.outline(name).likes], t);
        }
    }

    // 6 → 7: the source passes through phemc and branches into PHP and Twig.
    d.retract(['click-out', 'click-back', 'hub', 'push-b', 'push-c'], into(7), 0.12);
    d.shrink(['mercure-dot'], into(7));
    d.fadeOut(['mercure', 'click-event', 'click-html', 'legend'], into(7));
    d.conceal(['broadcast', 'browser-c', 'browser-b'], into(7), 0.2);
    d.conceal(['browser-a'], into(7, 0.05), 0.2);
    d.move(center.x, 88, 0.45, into(7, 0.05), 0.3, { autoAlpha: 0 });
    t = d.reveal('source', into(7, 0.2), 0.14);
    t = d.travel('build-in', 'travel-1', t, 0.1);
    d.reveal('phemc', t, 0.08);
    d.travel('build-php', 'travel-2', t + 0.04, 0.1);
    t = d.travel('build-twig', 'travel-3', t + 0.04, 0.1);
    d.fadeIn(['tag-php', 'tag-twig'], t);
    d.reveal('php', t, 0.12);
    d.reveal('twig', t, 0.12);

    // 7 → 8: the drawing draws in. The button, with the likes of the story, takes its place in the
    // page that the elephant holds while it is hidden, in place of the likes of the drawing. Then
    // its outline draws in, and it shows: the drawn outline is its border.
    d.retract(['build-in', 'build-php', 'build-twig'], into(8), 0.12);
    d.fadeOut(['tag-php', 'tag-twig'], into(8));
    d.conceal(['php', 'twig', 'phemc', 'source'], into(8), 0.18);
    d.fadeIn(['sketch'], into(8, 0.1), 0.02);
    t = d.sketch(into(8, 0.1), 0.45);
    d.tl.fromTo(d.el('sketch').querySelectorAll('text, .sketch__logo'), { opacity: 0 }, { opacity: 1, duration: 0.06, stagger: 0.02, immediateRender: false }, t - 0.15);
    const page = lines.slot('sketch', sketchScale);
    d.tl.set(btn, { x: page.x - center.x, y: page.y - center.y, scale: sketchScale, '--btn-scale': sketchScale, rotation: sketchTilt, borderColor: 'transparent' }, into(8));
    t = d.draw('sketch-like', t - 0.1, 0.15);
    d.fadeIn(['btn'], t - 0.05, 0.08);

    // Scene 8 rests at the end of the scroll.
    d.tl.set({}, {}, rest(8));

    return d.tl;
}
