import type { Element, ElementContent } from 'hast';
import type { ShikiTransformer } from 'shiki';
import { hast } from '../icons';

// Wraps each code block in the code window of the design system (CodeBlock). A `title` in the
// fence gives the bar with the file path and the copy button: ```php title="src/Ui/HomePage.phpm"
export const codeBlock: ShikiTransformer = {
    name: 'phem-code-block',
    root(root) {
        const title = /\btitle="([^"]*)"/.exec(this.options.meta?.__raw ?? '')?.[1];
        const pre = root.children.find((child): child is Element => child.type === 'element');

        if (!pre) {
            return;
        }

        const children: ElementContent[] = [pre];

        if (title) {
            children.unshift({
                type: 'element',
                tagName: 'figcaption',
                properties: { className: ['ph-code__bar'] },
                children: [
                    { type: 'element', tagName: 'span', properties: {}, children: [{ type: 'text', value: title }] },
                    {
                        type: 'element',
                        tagName: 'button',
                        properties: { className: ['ph-code__copy'], type: 'button', ariaLabel: 'Copy code' },
                        children: [hast('clipboard-document')],
                    },
                ],
            });
        }

        root.children = [{ type: 'element', tagName: 'figure', properties: { className: ['ph-code'] }, children }];
    },
};
