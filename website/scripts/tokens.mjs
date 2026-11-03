// Writes src/styles/tokens.css from design/tokens.json (the "Phem Docs" design system).
// bundle.css reads each token as a CSS variable with the name of the token: var(--primary).
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const tokens = JSON.parse(readFileSync(new URL('design/tokens.json', root), 'utf8'));
const themes = tokens.color.themes.map((theme) => theme.id);
const [first] = themes;

// A plain value is the value of all the themes. A theme with no value takes the first theme.
const valueIn = (value, theme) => (typeof value === 'object' ? (value[theme] ?? value[first]) : value);

const families = Object.values(tokens).filter((family) => family?.tokens && family !== tokens.color);
const lines = { [first]: [] };

for (const theme of themes.slice(1)) {
    lines[theme] = [];
}

for (const token of [...tokens.color.tokens, ...families.flatMap((family) => family.tokens)]) {
    lines[first].push(`  --${token.name}: ${valueIn(token.value, first)};`);

    for (const theme of themes.slice(1)) {
        if (typeof token.value === 'object' && theme in token.value) {
            lines[theme].push(`  --${token.name}: ${token.value[theme]};`);
        }
    }
}

for (const [name, stack] of Object.entries(tokens.type.families)) {
    lines[first].push(`  --font-${name}: ${stack};`);
}

const blocks = [
    `/* ${tokens.name}: generated from design/tokens.json by scripts/tokens.mjs. Do not edit. */`,
    `:root, [data-theme="${first}"] {\n  color-scheme: ${first};\n${lines[first].join('\n')}\n}`,
    ...themes.slice(1).map((theme) => `[data-theme="${theme}"] {\n  color-scheme: ${theme};\n${lines[theme].join('\n')}\n}`),
];

mkdirSync(new URL('src/styles/', root), { recursive: true });
writeFileSync(new URL('src/styles/tokens.css', root), blocks.join('\n\n') + '\n');
