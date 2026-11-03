import { defineHastPlugin } from 'satteri';
import { type IconName, svg } from '../icons';

// GitHub alerts (`> [!TIP]`) become callouts (design system: Callout). GitHub shows the same
// Markdown as an alert, and an LLM reads it as plain text.
const tones: Record<string, { tone: string; label: string; icon: IconName }> = {
    NOTE: { tone: 'info', label: 'Note', icon: 'information-circle' },
    TIP: { tone: 'tip', label: 'Tip', icon: 'light-bulb' },
    WARNING: { tone: 'warning', label: 'Warning', icon: 'exclamation-triangle' },
};

export const callouts = defineHastPlugin({
    name: 'phem-callouts',
    element: {
        filter: ['blockquote'],
        visit(node, ctx) {
            const paragraph = node.children.find((child) => child.type === 'element');

            if (!paragraph || paragraph.type !== 'element' || 'p' !== paragraph.tagName) {
                return;
            }

            const [first, ...rest] = paragraph.children;
            const marker = first?.type === 'text' ? /^\[!(\w+)\]\s*/.exec(first.value) : null;
            const tone = marker ? tones[marker[1]] : undefined;

            if (!marker || !tone || first.type !== 'text') {
                return;
            }

            const text = first.value.slice(marker[0].length);
            const others = node.children.filter((child) => child.type === 'element' && child !== paragraph);

            return {
                type: 'element',
                tagName: 'div',
                properties: { className: ['lw-callout', `lw-callout--${tone.tone}`], role: 'note' },
                children: [
                    { type: 'raw', value: svg(tone.icon) },
                    {
                        type: 'element',
                        tagName: 'p',
                        properties: {},
                        children: [
                            { type: 'element', tagName: 'strong', properties: {}, children: [{ type: 'text', value: tone.label }] },
                            { type: 'text', value: ' — ' },
                            ...(text ? [{ type: 'text' as const, value: text }] : []),
                            ...rest,
                        ],
                    },
                    ...others,
                ],
            };
        },
    },
});
