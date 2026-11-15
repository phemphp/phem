// The layouts of the story stage (src/components/home/Stage.astro). A layout is one composition of
// the scenes for the space that the screen gives: the size of the canvas, the place of the button
// of the story in each scene, and the connectors between the elements. The CSS places the elements
// of each layout (src/styles/home.css), and the layout measures them and draws the lines.
//
// - `wide`: 920 × 760 design units. The elements of a scene sit side by side. A desktop, a laptop
//   and a tablet show it.
// - `compact`: 390 × 440 design units. The elements of a scene sit one below the other, and the
//   lines run in the margins. A phone shows it.
import type { Box } from './stroke';

export type Named = (name: string) => HTMLElement;

export interface Point {
    x: number;
    y: number;
}

// The center of the button of the story, and its scale.
export interface Spot extends Point {
    scale: number;
}

// What a layout draws on: the elements of the stage and their boxes, in design units.
export interface Sheet {
    readonly el: Named;
    // The size of the button of the story at scale 1.
    readonly button: { width: number; height: number };
    box(element: Element | Range): Box;
    // The box of a mark of the code of `scope`.
    mark(scope: string, name: string): Box;
    // The left edge of the first character of the code line of a mark.
    lineStart(scope: string, name: string): number;
    // The right end of the code line of a mark, with a small gap.
    lineEnd(scope: string, name: string): number;
    line(name: string, d: string): void;
    dot(name: string, x: number, y: number): void;
    place(name: string, left: number, top: number): void;
}

export interface Layout {
    readonly width: number;
    readonly height: number;
    // The place of the button of the story in the CSS (.stage__btn).
    readonly center: Point;
    // The scale of the button in the page of scene 1, in the browsers of scene 6 and in the
    // drawing of scene 8. The Like slots have the same size (home.css).
    readonly scale: { page: number; browser: number; sketch: number };
    // The button in scenes 2 and 3 (the code), 4 (the composition), 5 (the framework) and 7 (the
    // source file, where it fades out).
    readonly spots: { code: Spot; compose: Spot; framework: Spot; source: Spot };
    // Scene 1: the gap between the source notes and the page.
    readonly noteGap: number;
    // Draws the connectors of scenes 3 to 7 and places their tags.
    connect(sheet: Sheet): void;
}

export const middle = (b: Box): number => b.top + b.height / 2;
const right = (b: Box): number => b.left + b.width;
const bottom = (b: Box): number => b.top + b.height;

const framework = ['services', 'security', 'rate-limit'];

// Scene 6: the click goes down from the Like button of browser A to the code, and the HTML comes
// back up beside it. Returns the place of the two lines.
function click(sheet: Sheet): { x: number; from: number; to: number } {
    const { el } = sheet;
    const like = sheet.box(el('browser-a').querySelector('.like--slot') ?? el('browser-a'));
    const x = like.left + like.width / 2;
    const from = bottom(sheet.box(el('browser-a'))) + 2;
    const to = sheet.box(el('broadcast')).top - 2;

    sheet.line('click-out', `M ${x - 6} ${from} V ${to}`);
    sheet.line('click-back', `M ${x + 6} ${to} V ${from}`);

    return { x, from, to };
}

class Wide implements Layout {
    readonly width = 920;
    readonly height = 760;
    readonly center = { x: 460, y: 380 };
    readonly scale = { page: 1.1, browser: 0.7, sketch: 0.78 };
    readonly spots = {
        code: { x: 170, y: 380, scale: 1 },
        compose: { x: 175, y: 560, scale: 0.9 },
        framework: { x: 170, y: 310, scale: 1 },
        source: { x: 460, y: 88, scale: 0.45 },
    };
    readonly noteGap = 30;

    connect(sheet: Sheet): void {
        this.trip(sheet);
        this.leads(sheet);
        this.links(sheet);
        this.broadcast(sheet);
        this.build(sheet);
    }

    // Scene 3: the event goes from the button to the handler, and the HTML of render() comes back.
    // The lines end just before `public`, the first word of each line.
    private trip(sheet: Sheet): void {
        const { el } = sheet;
        const spot = this.spots.code;
        const handler = middle(sheet.mark('code', 'handler'));
        const render = middle(sheet.mark('code', 'render'));
        const button = { x: spot.x + sheet.button.width / 2 + 8, y: spot.y };
        const handlerStart = sheet.lineStart('code', 'handler') - 10;
        const renderStart = sheet.lineStart('code', 'render') - 10;

        sheet.line('trip-out', `M ${button.x} ${button.y - 12} C ${button.x + 70} ${button.y - 12}, ${handlerStart - 70} ${handler}, ${handlerStart} ${handler}`);
        sheet.line('trip-back', `M ${renderStart} ${render} C ${renderStart - 60} ${render}, ${button.x + 60} ${button.y + 12}, ${button.x} ${button.y + 12}`);

        // Each tag sits at the middle of its line, on the outer side: "onClick Event" before the
        // line that goes up to the handler, "HTML" under the flat line that comes back from render().
        const samplesOf = (path: string, count: number): DOMPoint[] => {
            const line = el(path) as unknown as SVGPathElement;
            const length = line.getTotalLength();

            return Array.from({ length: count + 1 }, (_, index) => line.getPointAtLength((length * index) / count));
        };
        const samples = samplesOf('trip-out', 40);
        const out = samples[20];
        const back = samplesOf('trip-back', 2)[1];

        // The line bends at its middle, so the tag moves to the left until it keeps a gap of 10 to
        // every point of the line.
        const event = el('event');
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

        sheet.place('event', eventLeft, eventTop);
        sheet.place('html', back.x - el('html').offsetWidth / 2, back.y + 8);
    }

    // Scene 4: each boundary leads to its tag in the composition. The lines nest like the boxes:
    // the line of the outer box turns last, so no two lines cross.
    private leads(sheet: Sheet): void {
        const leads: [string, string, number, number, number][] = [
            ['lead-document', 'document', 330, 130, 382],
            ['lead-article', 'article', 305, 172, 364],
            ['lead-like', 'like-button', 271, 560, 346],
        ];

        for (const [path, name, x, y, bend] of leads) {
            const target = sheet.mark('compose', name);
            const end = { x: target.left - 6, y: middle(target) };

            sheet.line(path, `M ${x} ${y} C ${bend} ${y}, ${bend} ${end.y}, ${end.x} ${end.y}`);
        }
    }

    // Scene 5: the component to the framework.
    private links(sheet: Sheet): void {
        sheet.line('link-services', 'M 232 310 C 300 310, 300 70, 364 70');
        sheet.line('link-security', 'M 232 310 H 364');
        sheet.line('link-rate-limit', 'M 232 310 C 300 310, 300 460, 364 460');
        sheet.dot('dot-services', 370, 70);
        sheet.dot('dot-security', 370, 310);
        sheet.dot('dot-rate-limit', 370, 460);
    }

    // Scene 6: a tag on each side of the click. The broadcast leaves from the line of #[Broadcast]
    // and climbs beside the code window to Mercure, then goes on to the other devices.
    private broadcast(sheet: Sheet): void {
        const { el } = sheet;
        const { x, from, to } = click(sheet);

        sheet.place('click-event', x - 18 - el('click-event').offsetWidth, (from + to) / 2 - el('click-event').offsetHeight / 2);
        sheet.place('click-html', x + 18, (from + to) / 2 - el('click-html').offsetHeight / 2);

        const code = sheet.box(el('broadcast'));
        const flag = middle(sheet.mark('broadcast', 'broadcast'));
        const gap = right(code) + 10;

        sheet.line('hub', `M ${right(code)} ${flag} H ${gap - 8} Q ${gap} ${flag}, ${gap} ${flag - 8} V 351`);
        sheet.dot('mercure-dot', 470, 345);
        sheet.line('push-b', 'M 470 339 V 292');
        sheet.line('push-c', 'M 476 345 C 610 345, 690 332, 690 292');
    }

    // Scene 7: the build.
    private build(sheet: Sheet): void {
        sheet.line('build-in', 'M 460 110 V 198');
        sheet.line('build-php', 'M 460 242 C 460 290, 240 286, 240 336');
        sheet.line('build-twig', 'M 460 242 C 460 290, 680 286, 680 336');
    }
}

// The corner of a line that turns in a margin.
const radius = 6;

class Compact implements Layout {
    readonly width = 390;
    readonly height = 440;
    readonly center = { x: 195, y: 220 };
    readonly scale = { page: 0.75, browser: 0.55, sketch: 0.78 * (390 / 920) };
    readonly spots = {
        code: { x: 195, y: 30, scale: 0.8 },
        compose: { x: 79, y: 154, scale: 0.6 },
        framework: { x: 195, y: 30, scale: 0.8 },
        source: { x: 195, y: 26, scale: 0.4 },
    };
    readonly noteGap = 18;

    connect(sheet: Sheet): void {
        this.trip(sheet);
        this.leads(sheet);
        this.links(sheet);
        this.broadcast(sheet);
        this.build(sheet);
    }

    // Scene 3: the button sits above the code. The event leaves it to the left, goes down in the
    // margin of the code and turns to the handler. The HTML of render() comes back in the margin,
    // outside the line of the event, so the two lines do not cross.
    private trip(sheet: Sheet): void {
        const { el } = sheet;
        const spot = this.spots.code;
        const left = spot.x - (sheet.button.width * spot.scale) / 2 - 6;
        const code = sheet.box(el('code'));
        const handler = middle(sheet.mark('code', 'handler'));
        const render = middle(sheet.mark('code', 'render'));
        const out = { x: code.left - 10, y: spot.y + 8 };
        const back = { x: code.left - 20, y: spot.y - 8 };

        sheet.line('trip-out', `M ${left} ${out.y} H ${out.x + radius} Q ${out.x} ${out.y}, ${out.x} ${out.y + radius} V ${handler - radius} Q ${out.x} ${handler}, ${out.x + radius} ${handler} H ${sheet.lineStart('code', 'handler') - 6}`);
        sheet.line('trip-back', `M ${sheet.lineStart('code', 'render') - 6} ${render} H ${back.x + radius} Q ${back.x} ${render}, ${back.x} ${render - radius} V ${back.y + radius} Q ${back.x} ${back.y}, ${back.x + radius} ${back.y} H ${left}`);

        // "onClick Event" under the line that leaves the button, "HTML" above the line that
        // comes back to it.
        sheet.place('event', out.x + 22, out.y + 4);
        sheet.place('html', back.x + 32, back.y - 4 - el('html').offsetHeight);
    }

    // Scene 4: the boxes sit above the composition. Each boundary leads down in the right margin
    // of the code, and turns to the end of the line of its tag. The line of the inner box runs
    // outside and turns last, so no two lines cross.
    private leads(sheet: Sheet): void {
        const { el } = sheet;
        const edge = right(sheet.box(el('compose')));
        const column = (index: number): number => edge - 34 + index * 9;
        const end = (name: string): Point => ({ x: sheet.lineEnd('compose', name), y: middle(sheet.mark('compose', name)) });
        const down = (path: string, x: number, from: number, to: Point): void => {
            sheet.line(path, `M ${x} ${from} V ${to.y - radius} Q ${x} ${to.y}, ${x - radius} ${to.y} H ${to.x}`);
        };

        down('lead-document', column(0), bottom(sheet.box(el('document'))), end('document'));
        down('lead-article', column(1), bottom(sheet.box(el('article'))), end('article'));

        const like = sheet.box(el('like-bound'));
        const to = end('like-button');
        const x = column(2) + 6;

        sheet.line('lead-like', `M ${right(like)} ${middle(like)} H ${x - radius} Q ${x} ${middle(like)}, ${x} ${middle(like) + radius} V ${to.y - radius} Q ${x} ${to.y}, ${x - radius} ${to.y} H ${to.x}`);
    }

    // Scene 5: one line goes down in the left margin, from the button to the node of each part of
    // the framework.
    private links(sheet: Sheet): void {
        const spot = this.spots.framework;
        const left = spot.x - (sheet.button.width * spot.scale) / 2 - 6;
        const x = 10;

        for (const name of framework) {
            const y = middle(sheet.box(sheet.el(`label-${name}`)));

            sheet.line(`link-${name}`, `M ${left} ${spot.y} H ${x + radius} Q ${x} ${spot.y}, ${x} ${spot.y + radius} V ${y - 4}`);
            sheet.dot(`dot-${name}`, x, y);
        }
    }

    // Scene 6: the two tags sit on the right of the click. The broadcast leaves from the end of
    // the line of #[Broadcast] and climbs to Mercure, between the tablet and the phone, then goes
    // on to the two devices.
    private broadcast(sheet: Sheet): void {
        const { el } = sheet;
        const { x, from, to } = click(sheet);

        sheet.place('click-event', x + 16, from + 2);
        sheet.place('click-html', x + 16, to - 2 - el('click-html').offsetHeight);

        const tablet = sheet.box(el('browser-b'));
        const phone = sheet.box(el('browser-c'));
        const flag = middle(sheet.mark('broadcast', 'broadcast'));
        const hub = { x: (right(tablet) + phone.left) / 2, y: sheet.box(el('broadcast')).top - 22 };
        const push = (path: string, device: Box, side: number): void => {
            const target = device.left + device.width / 2;

            sheet.line(path, `M ${hub.x + side * 6} ${hub.y} Q ${target} ${hub.y}, ${target} ${bottom(device) + 2}`);
        };

        sheet.line('hub', `M ${sheet.lineEnd('broadcast', 'broadcast')} ${flag} H ${hub.x - 8} Q ${hub.x} ${flag}, ${hub.x} ${flag - 8} V ${hub.y + 6}`);
        sheet.dot('mercure-dot', hub.x, hub.y);
        sheet.place('mercure', hub.x + 12, hub.y + 5);
        push('push-b', tablet, -1);
        push('push-c', phone, 1);
    }

    // Scene 7: the source goes down through phemc to the PHP. The line of the Twig goes around
    // the PHP in the right margin.
    private build(sheet: Sheet): void {
        const { el } = sheet;
        const source = sheet.box(el('source'));
        const phemc = sheet.box(el('phemc'));
        const php = sheet.box(el('php'));
        const twig = sheet.box(el('twig'));
        const x = source.left + source.width / 2;
        const side = right(twig) + 8;
        const from = middle(phemc);
        const to = middle(twig);

        sheet.line('build-in', `M ${x} ${bottom(source) + 2} V ${phemc.top - 2}`);
        sheet.line('build-php', `M ${x} ${bottom(phemc) + 2} V ${php.top - 2}`);
        sheet.line('build-twig', `M ${right(phemc) + 2} ${from} H ${side - radius} Q ${side} ${from}, ${side} ${from + radius} V ${to - radius} Q ${side} ${to}, ${side - radius} ${to} H ${right(twig) + 2}`);
    }
}

export const wide: Layout = new Wide();
export const compact: Layout = new Compact();
