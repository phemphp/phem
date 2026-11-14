// The lines of the story stage. A stroke is an SVG path that draws from its start, retracts from
// its start, and carries a dot. One state, `from` and `to` (the visible part, 0 to 1 of the
// length), sets the dash of the path and the place of the dot, so the dot and the line cannot
// drift apart.
export class Stroke {
    readonly state = { from: 0, to: 0 };
    private readonly length: number;

    constructor(readonly path: SVGPathElement) {
        this.length = path.getTotalLength();
        this.render();
    }

    render = (): void => {
        const { from, to } = this.state;
        const visible = Math.max(0, to - from) * this.length;

        // A zero dash with butt caps paints nothing. The gap is longer than the path.
        this.path.style.strokeDasharray = `${visible} ${this.length + 1}`;
        this.path.style.strokeDashoffset = `${-from * this.length}`;
    };

    // The point at the head of the line.
    head(): DOMPoint {
        return this.path.getPointAtLength(this.state.to * this.length);
    }
}

export interface Box {
    left: number;
    top: number;
    width: number;
    height: number;
}

// The outline of a stage element, drawn as paths: its frame and its inner lines. The HTML
// element keeps its text, and the CSS hides its borders while the outline exists.
export interface Outline {
    group: SVGGElement;
    frame: Stroke[];
    lines: Stroke[];
    likes: SVGPathElement[];
}

const svg = 'http://www.w3.org/2000/svg';
let masks = 0;

function node<K extends keyof SVGElementTagNameMap>(name: K, attributes: Record<string, string>): SVGElementTagNameMap[K] {
    const element = document.createElementNS(svg, name);

    for (const [key, value] of Object.entries(attributes)) {
        element.setAttribute(key, value);
    }

    return element;
}

// The two halves of a rounded rectangle, from the top left corner to the bottom right corner:
// the top and the right side, and the left and the bottom side. They draw at the same time.
function halves(b: Box, radius: number): [string, string] {
    const x = b.left + 0.6;
    const y = b.top + 0.6;
    const w = b.width - 1.2;
    const h = b.height - 1.2;
    const r = Math.min(radius, w / 2, h / 2);

    return [
        `M ${x} ${y + r} A ${r} ${r} 0 0 1 ${x + r} ${y} H ${x + w - r} A ${r} ${r} 0 0 1 ${x + w} ${y + r} V ${y + h - r}`,
        `M ${x} ${y + r} V ${y + h - r} A ${r} ${r} 0 0 0 ${x + r} ${y + h} H ${x + w - r} A ${r} ${r} 0 0 0 ${x + w} ${y + h - r}`,
    ];
}

// The closed rounded rectangle, for a dashed frame.
function rectangle(b: Box, radius: number): string {
    const x = b.left + 0.6;
    const y = b.top + 0.6;
    const w = b.width - 1.2;
    const h = b.height - 1.2;
    const r = Math.min(radius, w / 2, h / 2);

    return `M ${x + r} ${y} H ${x + w - r} A ${r} ${r} 0 0 1 ${x + w} ${y + r} V ${y + h - r} A ${r} ${r} 0 0 1 ${x + w - r} ${y + h} H ${x + r} A ${r} ${r} 0 0 1 ${x} ${y + h - r} V ${y + r} A ${r} ${r} 0 0 1 ${x + r} ${y} Z`;
}

// Builds the outline of `element` in `layer`. `box` gives the boxes in design units.
export function outline(element: HTMLElement, layer: SVGSVGElement, box: (element: Element) => Box): Outline {
    const group = node('g', { class: 'outline' });
    const frameElement = element.querySelector<HTMLElement>('.ph-code') ?? element;
    const style = getComputedStyle(frameElement);
    const frameBox = box(frameElement);
    const radius = parseFloat(style.borderTopLeftRadius) || 0;
    const soft = frameElement.classList.contains('ph-code');
    const result: Outline = { group, frame: [], lines: [], likes: [] };

    const line = (d: string, className: string): Stroke => {
        const path = node('path', { d, class: className });
        group.append(path);

        return new Stroke(path);
    };

    // The frame. A dashed frame draws through a mask, so the dashes stay still.
    if ('dashed' === style.borderTopStyle) {
        const id = `stage-mask-${++masks}`;
        const mask = node('mask', { id, maskUnits: 'userSpaceOnUse', x: '-20', y: '-20', width: '960', height: '800' });
        group.append(mask, node('path', { d: rectangle(frameBox, radius), class: 'ln ln--dashed', mask: `url(#${id})` }));

        for (const d of halves(frameBox, radius)) {
            const path = node('path', { d, class: 'ln-mask' });
            mask.append(path);
            result.frame.push(new Stroke(path));
        }
    } else {
        result.frame = halves(frameBox, radius).map((d) => line(d, soft ? 'ln ln--soft' : 'ln'));
    }

    // The bar of a code window or of a browser.
    for (const bar of element.querySelectorAll('.ph-code__bar, .browser__bar')) {
        const b = box(bar);
        result.lines.push(line(`M ${frameBox.left + 0.6} ${b.top + b.height - 0.5} H ${frameBox.left + frameBox.width - 0.6}`, 'ln ln--soft'));
    }

    // The text of the article, and the home indicator of a tablet or a phone, as lines.
    for (const text of element.querySelectorAll('.article__line, .browser__line, .browser__home')) {
        const b = box(text);
        result.lines.push(line(`M ${b.left} ${b.top + b.height / 2} H ${b.left + b.width}`, 'ln'));
    }

    for (const comments of element.querySelectorAll('.browser__comments:not([hidden])')) {
        const b = box(comments);
        result.lines.push(line(`M ${b.left + 0.5} ${b.top} V ${b.top + b.height}`, 'ln ln--soft'));
    }

    // The Like buttons of the other browsers.
    for (const like of element.querySelectorAll<HTMLElement>('.like:not(.like--slot)')) {
        const [top, bottom] = halves(box(like), parseFloat(getComputedStyle(like).borderTopLeftRadius) || 0);
        const strokes = [line(top, 'ln ln--like'), line(bottom, 'ln ln--like')];
        result.lines.push(...strokes);
        result.likes.push(...strokes.map((stroke) => stroke.path));
    }

    layer.append(group);

    return result;
}
