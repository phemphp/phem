// The background of the story shows how Phem uses the network, and answers the mouse.
//
// - The grid: a faint dot grid behind the whole story. Only the cells that the pointer passes over
//   brighten, then they go back: the server sends a morph only for the components that changed.
// - The trail, in scene 1: the pointer leaves events on the points of the grid. They wait in the
//   queue, and a new event takes the place of the one that waits, so only the last one stays. When
//   the pointer rests, the last event goes to the frame of the page, and the cells around the
//   place where it lands update.
//
// It is one canvas that stays on the screen while the story scrolls (Story.astro). The text and
// the stage cover it. It answers the mouse only, like the tilt of the stage. `trail` tells when the
// page of scene 1 takes events. `zone` tells whether a point of the screen is free for the trail:
// an event never shows behind an element. `obstacles` gives the boxes of the elements on the way of
// an event to the page. `answers` tells where the grid answers the pointer.
import { gsap } from 'gsap';

interface Options {
    trail: () => boolean;
    zone: (x: number, y: number) => boolean;
    obstacles: () => DOMRect[];
    answers: (x: number, y: number) => boolean;
}

interface Point {
    x: number;
    y: number;
}

interface Fading extends Point {
    born: number;
}

interface Send {
    from: Point;
    start: number;
    duration: number;
    landed: boolean;
}

// The grid, in CSS pixels.
const gap = 28;
const dot = 1;
const rest = 0.1;
// The cells that the pointer passes over, and how long they stay bright (seconds).
const reach = 70;
const fade = 0.45;
// The trail: the rest before the last event goes (seconds), and the speed of the event on its way
// to the page (pixels per second).
const debounce = 0.35;
const speed = 900;
const replaced = 0.3;
// The ripple where the event lands, and the cells that update around it.
const ripple = 0.5;
const update = 60;
// No event starts on the page or close to it.
const margin = 16;

export function field(canvas: HTMLCanvasElement, page: HTMLElement, options: Options): () => void {
    const context = canvas.getContext('2d');

    if (!context) {
        return () => {};
    }

    // The grid at rest, drawn once: a frame copies it and draws only the warm cells on top.
    const base = document.createElement('canvas');
    const baseContext = base.getContext('2d');

    if (!baseContext) {
        return () => {};
    }

    const colors = { ink: '', accent: '' };

    let width = 0;
    let height = 0;
    let columns = 0;
    let rows = 0;
    let heat = new Float32Array(0);
    let dirty = true;

    const drawBase = (): void => {
        baseContext.clearRect(0, 0, width, height);
        baseContext.fillStyle = colors.ink;
        baseContext.globalAlpha = rest;
        baseContext.beginPath();

        for (let row = 0; row < rows; row++) {
            for (let column = 0; column < columns; column++) {
                baseContext.moveTo(column * gap + dot, row * gap);
                baseContext.arc(column * gap, row * gap, dot, 0, Math.PI * 2);
            }
        }

        baseContext.fill();
        dirty = true;
    };

    const readColors = (): void => {
        const style = getComputedStyle(canvas);
        colors.ink = style.getPropertyValue('--story-ink').trim();
        colors.accent = style.getPropertyValue('--story-accent').trim();
        drawBase();
    };

    const fit = (): void => {
        const ratio = devicePixelRatio || 1;
        width = canvas.clientWidth;
        height = canvas.clientHeight;

        for (const [target, targetContext] of [[canvas, context], [base, baseContext]] as const) {
            target.width = Math.round(width * ratio);
            target.height = Math.round(height * ratio);
            targetContext.setTransform(ratio, 0, 0, ratio, 0, 0);
        }

        columns = Math.ceil(width / gap) + 1;
        rows = Math.ceil(height / gap) + 1;
        heat = new Float32Array(columns * rows);
        drawBase();
    };

    // A point of the screen in the coordinates of the canvas.
    const local = (x: number, y: number): Point => {
        const frame = canvas.getBoundingClientRect();

        return { x: x - frame.left, y: y - frame.top };
    };

    // A point of the canvas that no element covers.
    const free = (point: Point): boolean => {
        const frame = canvas.getBoundingClientRect();

        return options.zone(point.x + frame.left, point.y + frame.top);
    };

    // The corners of the frame of the page. The stage turns toward the pointer, so the frame on the
    // screen is not the box of the page: a probe in each corner gives its place.
    const probes = ['0 auto auto 0', '0 0 auto auto', 'auto 0 0 auto', 'auto auto 0 0'].map((inset) => {
        const probe = document.createElement('span');
        probe.style.cssText = `position: absolute; inset: ${inset}; width: 0; height: 0; pointer-events: none;`;
        page.append(probe);

        return probe;
    });
    const frameCorners = (): Point[] => probes.map((probe) => {
        const box = probe.getBoundingClientRect();

        return local(box.left, box.top);
    });

    // The box of the page in the coordinates of the canvas, with its margin.
    const nearPage = (point: Point): boolean => {
        const box = page.getBoundingClientRect();
        const corner = local(box.left, box.top);

        return point.x >= corner.x - margin && point.x <= corner.x + box.width + margin && point.y >= corner.y - margin && point.y <= corner.y + box.height + margin;
    };

    // The cells near a point (or near the line from the last point) take some heat.
    const warm = (from: Point, to: Point, radius: number, strength: number): void => {
        const left = Math.max(0, Math.floor((Math.min(from.x, to.x) - radius) / gap));
        const right = Math.min(columns - 1, Math.ceil((Math.max(from.x, to.x) + radius) / gap));
        const top = Math.max(0, Math.floor((Math.min(from.y, to.y) - radius) / gap));
        const bottom = Math.min(rows - 1, Math.ceil((Math.max(from.y, to.y) + radius) / gap));
        const dx = to.x - from.x;
        const dy = to.y - from.y;
        const length = dx * dx + dy * dy;

        for (let row = top; row <= bottom; row++) {
            for (let column = left; column <= right; column++) {
                const x = column * gap;
                const y = row * gap;
                const t = 0 === length ? 0 : Math.max(0, Math.min(1, ((x - from.x) * dx + (y - from.y) * dy) / length));
                const distance = Math.hypot(x - (from.x + t * dx), y - (from.y + t * dy));
                const value = strength * (1 - distance / radius);
                const index = row * columns + column;

                if (value > heat[index]) {
                    heat[index] = value;
                }
            }
        }
    };

    // The trail.
    let pointer: Point | undefined;
    let waiting: Point | undefined;
    let moved = 0;
    const gone: Fading[] = [];
    const sends: Send[] = [];
    const clock = (): number => performance.now() / 1000;

    const move = (event: PointerEvent): void => {
        if ('mouse' !== event.pointerType) {
            return;
        }

        const next = local(event.clientX, event.clientY);

        if (next.x < 0 || next.y < 0 || next.x > width || next.y > height) {
            pointer = undefined;

            return;
        }

        if (!options.answers(event.clientX, event.clientY)) {
            pointer = undefined;

            return;
        }

        warm(pointer ?? next, next, reach, 1);
        pointer = next;

        if (!options.trail() || !options.zone(event.clientX, event.clientY)) {
            return;
        }

        // The last event goes when the pointer rests. It sits on the nearest point of the grid, and
        // a new event takes the place of the one that waits.
        moved = clock();

        const point = { x: Math.round(next.x / gap) * gap, y: Math.round(next.y / gap) * gap };

        if (nearPage(point) || !free(point) || (waiting && waiting.x === point.x && waiting.y === point.y)) {
            return;
        }

        if (waiting) {
            gone.push({ ...waiting, born: moved });
        }

        waiting = point;
    };
    const leave = (): void => {
        pointer = undefined;
    };

    // The nearest point of the frame of the page. The frame turns with the stage, so an event on its
    // way asks again on each frame.
    const wall = (from: Point, corners: Point[]): Point => {
        let to = corners[0];
        let distance = Infinity;

        corners.forEach((a, index) => {
            const b = corners[(index + 1) % corners.length];
            const dx = b.x - a.x;
            const dy = b.y - a.y;
            const t = Math.max(0, Math.min(1, ((from.x - a.x) * dx + (from.y - a.y) * dy) / (dx * dx + dy * dy || 1)));
            const point = { x: a.x + t * dx, y: a.y + t * dy };
            const length = Math.hypot(point.x - from.x, point.y - from.y);

            if (length < distance) {
                to = point;
                distance = length;
            }
        });

        return to;
    };

    // The event goes to the frame of the page, when no element covers its way. Else it fades where
    // it is. The way is tested against the boxes of the elements, read once.
    const send = (from: Point, now: number): void => {
        const to = wall(from, frameCorners());
        const distance = Math.hypot(to.x - from.x, to.y - from.y);
        const frame = canvas.getBoundingClientRect();
        const boxes = options.obstacles().map((box) => ({ left: box.left - frame.left, top: box.top - frame.top, right: box.right - frame.left, bottom: box.bottom - frame.top }));

        for (let along = 0; along < distance - 6; along += 4) {
            const t = along / distance;
            const x = from.x + (to.x - from.x) * t;
            const y = from.y + (to.y - from.y) * t;

            if (boxes.some((box) => x >= box.left && x <= box.right && y >= box.top && y <= box.bottom)) {
                gone.push({ ...from, born: now });

                return;
            }
        }

        sends.push({ from, start: now, duration: Math.max(0.25, distance / speed), landed: false });
    };

    const appear = { value: 0 };
    const intro = gsap.to(appear, { value: 1, duration: 1.2, delay: 0.6, ease: 'power1.out', onUpdate: () => (dirty = true) });
    let last = clock();

    const render = (): void => {
        const now = clock();
        const dt = Math.min(0.1, now - last);
        last = now;

        // Out of scene 1, the trail goes at once.
        if (!options.trail() && (waiting || gone.length || sends.length)) {
            waiting = undefined;
            gone.length = 0;
            sends.length = 0;
            dirty = true;
        }

        if (waiting && now - moved >= debounce) {
            send(waiting, now);
            waiting = undefined;
        }

        // The cells cool down.
        let warmCells = false;
        const cool = Math.exp(-dt / fade);

        for (let index = 0; index < heat.length; index++) {
            if (heat[index] > 0.002) {
                heat[index] *= cool;
                warmCells = true;
            } else if (heat[index] > 0) {
                heat[index] = 0;
                dirty = true;
            }
        }

        while (gone.length && now - gone[0].born > replaced) {
            gone.shift();
        }

        while (sends.length && now - sends[0].start > sends[0].duration + ripple) {
            sends.shift();
            dirty = true;
        }

        // Draw only a change: a still pointer costs nothing.
        if (!warmCells && !waiting && !gone.length && !sends.length && !dirty) {
            return;
        }

        dirty = false;
        context.clearRect(0, 0, width, height);

        const amount = appear.value;

        // The grid: the copy of the grid at rest, and the warm cells in place of their dots.
        context.globalAlpha = amount;
        context.drawImage(base, 0, 0, width, height);
        context.fillStyle = colors.ink;

        for (let index = 0; index < heat.length; index++) {
            const value = heat[index];

            if (0 === value) {
                continue;
            }

            const x = (index % columns) * gap;
            const y = Math.floor(index / columns) * gap;
            const size = dot + value + 1;

            context.clearRect(x - size, y - size, size * 2, size * 2);
            context.globalAlpha = (rest + value * 0.5) * amount;
            context.beginPath();
            context.arc(x, y, dot + value, 0, Math.PI * 2);
            context.fill();
        }

        // The events that a newer one replaced.
        context.fillStyle = colors.accent;

        for (const fading of gone) {
            const t = (now - fading.born) / replaced;
            context.globalAlpha = 0.5 * (1 - t) * amount;
            context.beginPath();
            context.arc(fading.x, fading.y, 2.5 - 1.5 * t, 0, Math.PI * 2);
            context.fill();
        }

        // The event that waits.
        if (waiting) {
            context.globalAlpha = 0.9 * amount;
            context.beginPath();
            context.arc(waiting.x, waiting.y, 2.5, 0, Math.PI * 2);
            context.fill();
        }

        // The events on their way, and the ripples where they land.
        context.strokeStyle = colors.accent;
        context.lineWidth = 1;

        const corners = sends.length ? frameCorners() : [];

        for (const event of sends) {
            const to = wall(event.from, corners);
            const t = Math.min(1, (now - event.start) / event.duration);
            const head = { x: event.from.x + (to.x - event.from.x) * t, y: event.from.y + (to.y - event.from.y) * t };

            if (t < 1) {
                context.globalAlpha = 0.5 * amount;
                context.beginPath();
                context.moveTo(event.from.x, event.from.y);
                context.lineTo(head.x, head.y);
                context.stroke();
                context.globalAlpha = 0.9 * amount;
                context.beginPath();
                context.arc(head.x, head.y, 2.5, 0, Math.PI * 2);
                context.fill();
                continue;
            }

            // It lands: the cells around the place update once.
            if (!event.landed) {
                event.landed = true;
                warm(to, to, update, 1);
            }

            const r = (now - event.start - event.duration) / ripple;
            context.globalAlpha = 0.6 * (1 - r) * amount;
            context.beginPath();
            context.arc(to.x, to.y, 3 + 18 * r, 0, Math.PI * 2);
            context.stroke();
        }

        context.globalAlpha = 1;
    };

    const resize = new ResizeObserver(fit);
    const theme = new MutationObserver(readColors);

    fit();
    readColors();
    resize.observe(canvas);
    theme.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
    addEventListener('pointermove', move);
    document.documentElement.addEventListener('mouseleave', leave);
    gsap.ticker.add(render);

    return () => {
        gsap.ticker.remove(render);
        removeEventListener('pointermove', move);
        document.documentElement.removeEventListener('mouseleave', leave);
        resize.disconnect();
        theme.disconnect();
        intro.kill();
        probes.forEach((probe) => probe.remove());
        context.clearRect(0, 0, width, height);
    };
}
