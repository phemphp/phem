import type { Element, ElementContent } from 'hast';
import type { ShikiTransformer } from 'shiki';
import { hast } from '../icons';

// Wraps each code block in the code window of the design system (CodeBlock), with a copy button.
// A `title` in the fence gives the bar with the file path, and the button goes in the bar:
// ```php title="src/Ui/HomePage.phem". With no title, the button floats in the top right corner.
export const codeBlock: ShikiTransformer = {
    name: 'phem-code-block',
    root(root) {
        const title = /\btitle="([^"]*)"/.exec(this.options.meta?.__raw ?? '')?.[1];
        const pre = root.children.find((child): child is Element => child.type === 'element');

        if (!pre) {
            return;
        }

        const copy: Element = {
            type: 'element',
            tagName: 'button',
            properties: { className: ['ph-code__copy'], type: 'button', ariaLabel: 'Copy code' },
            children: [hast('clipboard-document')],
        };
        const children: ElementContent[] = title
            ? [
                  {
                      type: 'element',
                      tagName: 'figcaption',
                      properties: { className: ['ph-code__bar'] },
                      children: [{ type: 'element', tagName: 'span', properties: {}, children: [{ type: 'text', value: title }] }, copy],
                  },
                  pre,
              ]
            : [pre, copy];
        const className = title ? ['ph-code'] : ['ph-code', 'ph-code--bare'];

        root.children = [{ type: 'element', tagName: 'figure', properties: { className }, children }];
    },
};
