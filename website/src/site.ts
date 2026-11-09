import { execFileSync } from 'node:child_process';

// The values that the pages and the Markdown plugins share.
export const repository = 'https://github.com/phemphp/phem';
export const author = 'yceruto';

// The latest stable release at build time (v0.3.15): the highest git tag with no pre-release
// part, or undefined with no tags. The header asks GitHub for a newer release in the browser.
export const version: string | undefined = latestRelease();

function latestRelease(): string | undefined {
    try {
        const tags = execFileSync('git', ['tag', '--list', 'v*', '--sort=-v:refname'], { cwd: '..', encoding: 'utf8' });

        return tags.split('\n').find((tag) => /^v\d+\.\d+\.\d+$/.test(tag));
    } catch {
        return undefined;
    }
}
