// The motion of the home page story (src/components/home/Story.astro). It is a progressive
// enhancement: the page is complete with no JS and with reduced motion.
//
// - A large screen with motion gets the sticky stage, smooth scroll (Lenis) and one timeline that
//   the scroll scrubs. Each scene has a readable resting state (src/scripts/stage-timeline.ts),
//   and the scroll back plays the same states in reverse.
// - A small screen with motion gets the heading reveals and a fade-up of the static figures.
// - With reduced motion, nothing moves.
//
// gsap.matchMedia() builds the mode that matches and reverts it when the screen or the motion
// setting changes. A page that the browser keeps in its back-forward cache comes back as it was.
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import 'lenis/dist/lenis.css';
import { field } from './field';
import { canvasHeight, canvasWidth, introTimeline, named, scenesTimeline, StageLines, StoryTime } from './stage-timeline';

gsap.registerPlugin(ScrollTrigger);

// 2D transforms only. A 3D transform puts each moving word on its own GPU layer, and Chrome on
// macOS can paint that layer in a slightly different color than the page: a faint box shows
// behind the text while it moves, mostly on the dark background. The tilt of scene 1 is the one
// 3D transform: it turns the stage, not the words of the copy.
gsap.config({ force3D: false });

export function story(root: HTMLElement): void {
    // The inline script of Story.astro shows the static page when this script is late. The first
    // scene is then on the screen already, so it does not play its reveal.
    let intro = root.classList.contains('story--pending');

    // The reveals read the end state of the first scene, so it must show first. They set their
    // first state in the same task: no frame shows the scene between the two.
    root.classList.remove('story--pending');

    // Story.astro gives the size of the stage. Below it, the scale of the stage makes its text
    // too small to read: the small screens get the static figures.
    const conditions = { motion: '(prefers-reduced-motion: no-preference)', stage: root.dataset.stageQuery ?? 'not all' };

    gsap.matchMedia().add(conditions, (context) => {
        const { motion, stage: large } = (context.conditions ?? {}) as { motion?: boolean; stage?: boolean };

        if (!motion) {
            return;
        }

        // The first scene plays its reveal on load only, not after a change of the screen.
        const first = intro;
        intro = false;

        return large ? stage(root, context, first) : reveal(root, first);
    });
}

// ---------- Small screens ----------

function reveal(root: HTMLElement, intro: boolean): void {
    scenes(root).forEach((scene, index) => {
        if (0 === index && !intro) {
            return;
        }

        revealCopy(scene, 0 === index);

        const figure = scene.querySelector('[data-scene-figure]');

        if (figure) {
            gsap.from(figure, {
                y: 24,
                opacity: 0,
                duration: 0.8,
                ease: 'power3.out',
                scrollTrigger: { trigger: figure, start: 'top 88%', end: 'max', once: true },
            });
        }
    });
}

// ---------- Large screens: the stage ----------

function stage(root: HTMLElement, context: gsap.Context, intro: boolean): () => void {
    const stageBox = root.querySelector<HTMLElement>('[data-stage]');
    const canvas = root.querySelector<HTMLElement>('[data-stage-canvas]');
    const tiltBox = root.querySelector<HTMLElement>('[data-stage-tilt]');
    const list = root.querySelector<HTMLElement>('.story__scenes');

    // No stage: the static figures show. The inline script of Story.astro may have set the layout
    // of the stage already.
    if (!stageBox || !canvas || !tiltBox || !list) {
        root.classList.remove('story--enhanced');

        return () => {};
    }

    root.classList.add('story--enhanced');

    // The figures stay for screen readers, but their copy buttons and code leave the Tab order.
    const focusable = [...root.querySelectorAll<HTMLElement>('[data-scene-figure] :is(a, button, [tabindex="0"])')];
    focusable.forEach((element) => element.setAttribute('tabindex', '-1'));

    // The canvas fits the sticky column.
    const fit = (): void => {
        const scale = Math.min(stageBox.clientWidth / canvasWidth, stageBox.clientHeight / canvasHeight) * 0.94;
        canvas.style.setProperty('--stage-scale', scale.toFixed(4));
    };
    const resize = new ResizeObserver(fit);
    resize.observe(stageBox);
    fit();

    // Smooth wheel scroll, also for the links to a scene (Lenis reads their scroll-margin). The
    // dialogs (search, navigation) keep their native scroll.
    const lenis = new Lenis({
        lerp: 0.1,
        smoothWheel: true,
        anchors: true,
        prevent: (node: HTMLElement) => null !== node.closest('dialog'),
    });
    const raf = (time: number): void => lenis.raf(time * 1000);
    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add(raf);
    gsap.ticker.lagSmoothing(0);

    const all = scenes(root);
    all.forEach((scene, index) => {
        if (0 !== index || intro) {
            revealCopy(scene, 0 === index);
        }
    });

    // The scroll hint of scene 1 fades out with the first pixels of the scroll down, and comes back
    // when the scroll goes up to the top.
    // A click on it scrolls slowly to scene 2, so the stage plays the move between the two scenes.
    const hint = root.querySelector<HTMLAnchorElement>('.scene__hint');
    const slowScroll = (event: MouseEvent): void => {
        const target = document.querySelector<HTMLElement>(hint?.hash ?? '');

        if (!target) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        const top = target.getBoundingClientRect().top + scrollY - (parseFloat(getComputedStyle(target).scrollMarginTop) || 0);
        lenis.scrollTo(top, { duration: 2.4, easing: (t: number) => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2) });
    };

    if (hint) {
        gsap.to(hint, { autoAlpha: 0, y: 8, ease: 'none', scrollTrigger: { start: 0, end: 160, scrub: 0.3 } });
        hint.addEventListener('click', slowScroll, true);
    }

    gsap.set(named(canvas)('btn'), { xPercent: -50, yPercent: -50 });

    // The lines follow the code, so the timeline waits for the fonts.
    const time = new StoryTime(all.filter((scene) => scene.classList.contains('scene--long')).map((scene) => Number(scene.dataset.scene)));
    let alive = true;
    let lines: StageLines | undefined;
    let untilt = (): void => {};
    let unfield = (): void => {};

    const draw = (): void => {
        copyFigures(root);
        lines = new StageLines(canvas);
        lines.build();

        const timeline = scenesTimeline(lines, time);
        const top = (): number => document.querySelector<HTMLElement>('.site-top')?.offsetHeight ?? 0;
        let opening: gsap.core.Timeline | undefined;

        // A scroll ends the intro and removes it, so it cannot draw the page again after the
        // timeline hides it. Lenis already smooths the wheel: a short scrub only smooths the keys
        // and the scroll bar, so the stage stays with the copy.
        const trigger = ScrollTrigger.create({
            trigger: list,
            start: () => `top ${top()}px`,
            end: 'bottom bottom',
            scrub: 0.3,
            animation: timeline,
            onUpdate: () => {
                if (!opening) {
                    return;
                }

                opening.progress(1).kill();
                opening = undefined;

                // The scrub calls onUpdate after it renders the timeline. After a jump, the
                // timeline can be past scene 1 already, and the end of the intro shows the page
                // again. Render the timeline again from its start: each element takes the state
                // of the scroll.
                const now = timeline.time();
                timeline.time(0, true).time(now, true);
            },
        });
        ScrollTrigger.refresh();

        // The intro plays only when the page opens at scene 1.
        if (0 === trigger.progress) {
            opening = introTimeline(lines).play();
        }

        untilt = tilt(tiltBox, () => timeline.time() / time.into(2));

        // The background of the story. The page of scene 1 takes events after the intro. The trail
        // stays on the stage, where no element covers it. In scene 1, the grid answers the pointer
        // on the stage only: the copy of the first scene stays still.
        const background = root.querySelector<HTMLCanvasElement>('[data-story-field]');
        const scene1 = (): boolean => timeline.time() < time.into(2);

        if (background) {
            unfield = field(background, lines.el('page'), {
                trail: () => !opening?.isActive() && scene1(),
                answers: (x, y) => {
                    const box = stageBox.getBoundingClientRect();

                    return !scene1() || (x >= box.left && x <= box.right && y >= box.top && y <= box.bottom);
                },
                zone: (x, y) => {
                    const top = document.elementFromPoint(x, y);

                    return null !== top && stageBox.contains(top) && null === top.closest('.stage__item, .stage__btn');
                },
                obstacles: () => [...stageBox.querySelectorAll<HTMLElement>('.stage__item, .stage__btn')]
                    .filter((element) => 'hidden' !== getComputedStyle(element).visibility)
                    .map((element) => element.getBoundingClientRect()),
            });
        }
    };

    document.fonts.ready.then(() => {
        let failure: { error: unknown } | undefined;

        // An error must not leave the add() of the context open, so it is caught inside.
        context.add(() => {
            try {
                if (alive) {
                    draw();
                }
            } catch (error) {
                failure = { error };
            }
        });

        // The stage cannot draw: show the static figures.
        if (failure) {
            context.revert();

            throw failure.error;
        }
    });

    return () => {
        alive = false;
        untilt();
        unfield();
        lines?.destroy();
        resize.disconnect();
        gsap.ticker.remove(raf);
        gsap.ticker.lagSmoothing(500, 33);
        hint?.removeEventListener('click', slowScroll, true);
        lenis.destroy();
        focusable.forEach((element) => (element.matches('pre') ? element.setAttribute('tabindex', '0') : element.removeAttribute('tabindex')));
        root.classList.remove('story--enhanced');
        canvas.style.removeProperty('--stage-scale');
    };
}

// The stage shows the code and the drawing of the static figures: each `data-copy-of` element of
// the stage gets a copy of the `data-copy` element of a figure, with nothing to focus or click.
function copyFigures(root: HTMLElement): void {
    for (const target of root.querySelectorAll<HTMLElement>('[data-stage] [data-copy-of]')) {
        if (target.firstElementChild) {
            continue;
        }

        const source = root.querySelector(`[data-scene-figure] [data-copy="${target.dataset.copyOf}"]`);

        if (!source) {
            throw new Error(`The story has no figure "${target.dataset.copyOf}" for the stage.`);
        }

        const copy = source.cloneNode(true) as Element;
        copy.removeAttribute('data-copy');
        copy.querySelectorAll('.ph-code__copy, .sketch__like').forEach((node) => node.remove());
        copy.querySelectorAll('[tabindex]').forEach((node) => node.removeAttribute('tabindex'));

        target.append(copy);
    }
}

// Scene 1: the page turns a little toward the mouse pointer. The turn fades out while the story
// leaves scene 1 (`left` goes from 0 to 1), so the next scenes stay flat. The lines are measured
// before the first turn.
const turn = { y: 8, x: 4 };

function tilt(box: HTMLElement, left: () => number): () => void {
    const pointer = { x: 0, y: 0 };
    const toX = gsap.quickTo(pointer, 'x', { duration: 0.9, ease: 'power3.out' });
    const toY = gsap.quickTo(pointer, 'y', { duration: 0.9, ease: 'power3.out' });
    const angle = { x: 0, y: 0 };

    const move = (event: PointerEvent): void => {
        if ('mouse' !== event.pointerType) {
            return;
        }

        toX((event.clientX / innerWidth) * 2 - 1);
        toY((event.clientY / innerHeight) * 2 - 1);
    };
    const render = (): void => {
        const amount = Math.max(0, 1 - left());
        const y = Math.round(pointer.x * turn.y * amount * 100) / 100;
        const x = Math.round(-pointer.y * turn.x * amount * 100) / 100;

        // Write only a change: a still pointer and the flat scenes cost nothing.
        if (x === angle.x && y === angle.y) {
            return;
        }

        angle.x = x;
        angle.y = y;

        if (0 === x && 0 === y) {
            gsap.set(box, { clearProps: 'transform' });
        } else {
            gsap.set(box, { rotationY: y, rotationX: x });
        }
    };

    addEventListener('pointermove', move);
    gsap.ticker.add(render);

    return () => {
        removeEventListener('pointermove', move);
        gsap.ticker.remove(render);
        gsap.killTweensOf(pointer);
        gsap.set(box, { clearProps: 'transform' });
    };
}

// ---------- The copy ----------

function scenes(root: HTMLElement): HTMLElement[] {
    return [...root.querySelectorAll<HTMLElement>('[data-scene]')];
}

// The heading rises out of the masks of its words (Scene.astro), then the rest of the copy fades
// up. The first scene shows its links at once. The fades use opacity, not visibility, so the
// links stay in the Tab order.
function revealCopy(scene: HTMLElement, first: boolean): void {
    const words = scene.querySelectorAll('.word__inner');
    const rest = scene.querySelectorAll('.scene__index, [data-story-reveal]');
    const tl = gsap.timeline({ paused: !first, delay: first ? 0.1 : 0 });

    tl.from(words, { yPercent: 110, duration: 0.9, ease: 'power4.out', stagger: 0.06 });

    if (!first) {
        tl.from(rest, { y: 16, opacity: 0, duration: 0.8, ease: 'power3.out', stagger: 0.08 }, 0.2);
        ScrollTrigger.create({ trigger: scene, start: 'top 70%', end: 'max', once: true, onEnter: () => tl.play() });
    }
}
