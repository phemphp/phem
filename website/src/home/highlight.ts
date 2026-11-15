import type { Element } from 'hast';
import { createHighlighter, type DecorationItem, type ShikiTransformer } from 'shiki';
import { codeBlock } from '../shiki/code-block';
import { phemCode } from '../shiki/phem-code';
import { phem } from '../shiki/phem';
import type { Mark, Snippet } from './snippets';

// The highlighter of the home page. The <Code> component cannot load the languages that the
// phem grammar embeds, so the page highlights its code itself, once for all the snippets.
const highlighter = await createHighlighter({ themes: [phemCode], langs: ['php', 'css', 'javascript', 'twig', phem] });

// The code window gets `data-copy` with the name of its snippet: the stage shows a copy of it
// (src/scripts/story.ts). It runs after codeBlock, which wraps the code in the window.
function copyName(name: string): ShikiTransformer {
    return {
        name: 'phem-home-copy-name',
        root(root) {
            const frame = root.children.find((child): child is Element => 'element' === child.type);

            if (frame) {
                frame.properties['data-copy'] = name;
            }
        },
    };
}

export function highlight(snippet: Snippet, name: string): string {
    return highlighter.codeToHtml(snippet.code, {
        lang: snippet.lang,
        theme: 'phem-code',
        decorations: (snippet.marks ?? []).map((mark) => decoration(snippet.code, mark)),
        transformers: [codeBlock, copyName(name)],
        meta: { __raw: snippet.title ? `title="${snippet.title}"` : '' },
    });
}

// The n-th match of the mark: a whole word when the text starts and ends with a word character,
// so `like` does not match `$likes`.
function decoration(code: string, mark: Mark): DecorationItem {
    const escaped = mark.text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp(`${/^\w/.test(mark.text) ? '\\b' : ''}${escaped}${/\w$/.test(mark.text) ? '\\b' : ''}`, 'g');
    const matches = [...code.matchAll(pattern)];
    const match = matches[(mark.nth ?? 1) - 1];

    if (undefined === match?.index) {
        throw new Error(`The mark "${mark.name}" finds no match ${mark.nth ?? 1} of "${mark.text}".`);
    }

    return {
        start: match.index,
        end: match.index + mark.text.length,
        properties: { 'data-mark': mark.name },
    };
}
