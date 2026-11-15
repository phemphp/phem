// Checks the joins of the line drawing of the last home page scene (Friends.astro).
//
// A line end must touch another line, or stay clearly free. An end that stops a little short of
// a line (0.6 to 10 units) shows a gap at the round cap of the stroke. The script also finds the
// hooks that a trace leaves in a curve. Run it after a change to the drawing:
//
//     node scripts/check-sketch.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const file = process.argv[2] ?? fileURLToPath(new URL('../src/components/home/Friends.astro', import.meta.url));

// An end at a distance of less than `touch` from a line joins it. An end at a distance of more
// than `free` from all lines is free.
const touch = 0.6;
const free = 10;

// The free ends that stay near a line on purpose: [x, y, what].
const intended = [
    [396.8, 202.1, 'the right eyebrow of the elephant'],
    [411.6, 242.4, 'the right eye of the elephant'],
    [337.8, 376.4, 'a fold of the collar of the elephant'],
    [646.0, 333.6, 'a lash of the pony'],
];

// The paths of the drawing, with their group and their index in the group.
function paths(source) {
    const found = [];

    for (const group of source.matchAll(/<g class="sketch__(\w+)">([\s\S]*?)<\/g>/g)) {
        let index = 0;

        for (const path of group[2].matchAll(/<path d="([^"]+)"/g)) {
            found.push({ name: `${group[1]} ${index++}`, d: path[1] });
        }
    }

    return found;
}

// The segments of a path as cubic curves (lines and arcs become cubics), and if it is closed.
function segments(d) {
    const tokens = d.match(/[A-Za-z]|-?\d*\.?\d+/g);
    const list = [];
    let i = 0;
    let command = '';
    let current = [0, 0];
    let start = [0, 0];
    let closed = false;
    const number = () => parseFloat(tokens[i++]);

    while (i < tokens.length) {
        if (/[A-Za-z]/.test(tokens[i])) {
            command = tokens[i++];
        }

        if ('M' === command) {
            current = start = [number(), number()];
            command = 'L';
        } else if ('L' === command) {
            const to = [number(), number()];
            list.push([current, lerp(current, to, 1 / 3), lerp(current, to, 2 / 3), to]);
            current = to;
        } else if ('C' === command) {
            const c1 = [number(), number()];
            const c2 = [number(), number()];
            const to = [number(), number()];
            list.push([current, c1, c2, to]);
            current = to;
        } else if ('A' === command) {
            const radius = number();
            i += 4;
            const to = [number(), number()];
            list.push(...arc(current, to, radius));
            current = to;
        } else if ('Z' === command) {
            if (distance(current, start) > 0) {
                list.push([current, lerp(current, start, 1 / 3), lerp(current, start, 2 / 3), start]);
            }

            current = start;
            closed = true;
        } else {
            throw new Error(`The command "${command}" is not supported: use absolute M, L, C, A and Z.`);
        }
    }

    return { list, closed: closed || (list.length > 0 && distance(list[0][0], current) < 0.01) };
}

// A half circle from one point to the opposite point, as two cubics.
function arc(from, to, radius) {
    const center = lerp(from, to, 0.5);
    const a = Math.atan2(from[1] - center[1], from[0] - center[0]);
    const k = (4 / 3) * Math.tan(Math.PI / 8) * radius;
    const point = (angle) => [center[0] + radius * Math.cos(angle), center[1] + radius * Math.sin(angle)];
    const tangent = (angle) => [-Math.sin(angle) * k, Math.cos(angle) * k];
    const quarters = [];

    for (let q = 0; q < 2; q++) {
        const a0 = a + (q * Math.PI) / 2;
        const a1 = a0 + Math.PI / 2;
        const [p0, p1] = [point(a0), point(a1)];
        const [t0, t1] = [tangent(a0), tangent(a1)];
        quarters.push([p0, [p0[0] + t0[0], p0[1] + t0[1]], [p1[0] - t1[0], p1[1] - t1[1]], p1]);
    }

    return quarters;
}

function lerp(a, b, t) {
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
}

function distance(a, b) {
    return Math.hypot(a[0] - b[0], a[1] - b[1]);
}

function bezier([p0, p1, p2, p3], t) {
    const u = 1 - t;

    return [0, 1].map((k) => u * u * u * p0[k] + 3 * u * u * t * p1[k] + 3 * u * t * t * p2[k] + t * t * t * p3[k]);
}

function polyline(list) {
    const points = list.length > 0 ? [list[0][0]] : [];

    for (const segment of list) {
        for (let s = 1; s <= 32; s++) {
            points.push(bezier(segment, s / 32));
        }
    }

    return points;
}

function toSegment(point, a, b) {
    const dx = b[0] - a[0];
    const dy = b[1] - a[1];
    const length = dx * dx + dy * dy;
    const t = 0 === length ? 0 : Math.max(0, Math.min(1, ((point[0] - a[0]) * dx + (point[1] - a[1]) * dy) / length));

    return distance(point, [a[0] + dx * t, a[1] + dy * t]);
}

// The total turn of a curve, in degrees.
function turn(segment) {
    let total = 0;
    let last = null;

    for (let s = 1; s <= 64; s++) {
        const [a, b] = [bezier(segment, (s - 1) / 64), bezier(segment, s / 64)];

        if (distance(a, b) < 1e-6) {
            continue;
        }

        const angle = Math.atan2(b[1] - a[1], b[0] - a[0]);

        if (null !== last) {
            total += Math.abs(Math.atan2(Math.sin(angle - last), Math.cos(angle - last)));
        }

        last = angle;
    }

    return (total * 180) / Math.PI;
}

const drawing = paths(readFileSync(file, 'utf8')).map((path) => {
    const { list, closed } = segments(path.d);

    return { ...path, list, closed, points: polyline(list) };
});

const problems = [];
const counts = { joined: 0, free: 0, intended: 0 };

for (const path of drawing) {
    if (path.closed || 0 === path.list.length) {
        continue;
    }

    const ends = [['start', path.list[0][0]], ['end', path.list.at(-1)[3]]];

    for (const [which, point] of ends) {
        let gap = Infinity;
        let nearest = '';

        for (const other of drawing) {
            if (other === path) {
                continue;
            }

            for (let k = 1; k < other.points.length; k++) {
                const d = toSegment(point, other.points[k - 1], other.points[k]);

                if (d < gap) {
                    gap = d;
                    nearest = other.name;
                }
            }
        }

        const at = `(${point[0].toFixed(1)}, ${point[1].toFixed(1)})`;

        if (gap < touch) {
            counts.joined++;
        } else if (gap > free) {
            counts.free++;
        } else if (intended.some(([x, y]) => distance([x, y], point) < 1.5)) {
            counts.intended++;
        } else {
            problems.push(`gap   ${path.name} ${which} ${at}: ${gap.toFixed(2)} from ${nearest}`);
        }
    }
}

// A hook: one curve turns back on itself, or two curves meet at a sharp angle that is not a
// corner of a straight line.
for (const path of drawing) {
    path.list.forEach((segment, index) => {
        if (turn(segment) > 120) {
            problems.push(`hook  ${path.name} segment ${index} at (${segment[3][0].toFixed(1)}, ${segment[3][1].toFixed(1)})`);
        }
    });
}

console.log(`${counts.joined} ends join a line, ${counts.free} are free, ${counts.intended} are free on purpose.`);

if (problems.length > 0) {
    console.log(problems.join('\n'));
    process.exit(1);
}
