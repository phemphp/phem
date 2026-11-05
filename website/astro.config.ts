import { satteri } from '@astrojs/markdown-satteri';
import { defineConfig } from 'astro/config';
import { anchors } from './src/markdown/anchors';
import { callouts } from './src/markdown/callouts';
import { lead } from './src/markdown/lead';
import { links } from './src/markdown/links';
import { phemFences } from './src/markdown/phem-fences';
import { tables } from './src/markdown/tables';
import { codeBlock } from './src/shiki/code-block';
import { phemCode } from './src/shiki/phem-code';
import { phem } from './src/shiki/phem';
import { repository } from './src/site';

export default defineConfig({
    site: 'https://phem.dev',
    trailingSlash: 'always',
    markdown: {
        processor: satteri({
            features: { smartPunctuation: false },
            mdastPlugins: [phemFences],
            hastPlugins: [links(repository), lead, anchors, callouts, tables],
        }),
        shikiConfig: {
            theme: phemCode,
            langs: ['php', 'css', 'javascript', phem],
            transformers: [codeBlock],
        },
    },
});
