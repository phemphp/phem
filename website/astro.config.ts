import { satteri } from '@astrojs/markdown-satteri';
import { defineConfig } from 'astro/config';
import { anchors } from './src/markdown/anchors';
import { callouts } from './src/markdown/callouts';
import { lead } from './src/markdown/lead';
import { links } from './src/markdown/links';
import { phpmCode } from './src/markdown/phpm-code';
import { codeBlock } from './src/shiki/code-block';
import { phemCode } from './src/shiki/phem-code';
import { phpm } from './src/shiki/phpm';
import { repository } from './src/site';

export default defineConfig({
    site: 'https://phem.dev',
    trailingSlash: 'always',
    markdown: {
        processor: satteri({
            features: { smartPunctuation: false },
            mdastPlugins: [phpmCode],
            hastPlugins: [links(repository), lead, anchors, callouts],
        }),
        shikiConfig: {
            theme: phemCode,
            langs: ['php', 'css', 'javascript', phpm],
            transformers: [codeBlock],
        },
    },
});
