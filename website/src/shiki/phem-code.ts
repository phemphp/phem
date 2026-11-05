import type { ThemeRegistration } from 'shiki';

// The code theme of the design system: light or dark with the page theme, with the four syntax-* tokens.
// The colors are CSS variables, so tokens.css stays the one source of the values.
export const phemCode: ThemeRegistration = {
    name: 'phem-code',
    type: 'dark',
    colors: {
        'editor.background': 'var(--code-surface)',
        'editor.foreground': 'var(--code-ink)',
    },
    tokenColors: [
        {
            scope: ['comment', 'punctuation.definition.comment'],
            settings: { foreground: 'var(--code-muted)', fontStyle: 'italic' },
        },
        {
            scope: [
                'keyword',
                'storage',
                'constant.language',
                'support.function.construct',
                'punctuation.section.embedded',
                'punctuation.section.interpolation',
                'punctuation.definition.tag.begin.php',
            ],
            settings: { foreground: 'var(--syntax-keyword)' },
        },
        {
            scope: ['string', 'punctuation.definition.string'],
            settings: { foreground: 'var(--syntax-string)' },
        },
        {
            scope: ['entity.other.attribute-name', 'meta.attribute', 'support.class.component.phpm'],
            settings: { foreground: 'var(--syntax-attribute)' },
        },
        {
            scope: ['variable', 'variable.other', 'support.type.property-name'],
            settings: { foreground: 'var(--syntax-variable)' },
        },
    ],
};
