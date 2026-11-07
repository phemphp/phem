// The order of the docs. The sidebar, the drawer, the breadcrumbs, the pager and the search read
// it. Each file of docs/ must be in it once (the build fails on a missing or an unknown file).
export interface NavPage {
    slug: string;
    label: string;
}

export interface NavGroup {
    title: string;
    pages: NavPage[];
}

export const navigation: NavGroup[] = [
    {
        title: 'Learn',
        pages: [
            { slug: 'what-is-phem', label: 'Understand Phem' },
            { slug: 'getting-started', label: 'Create Your First App' },
            { slug: 'tutorial', label: 'Build a Task List' },
            { slug: 'philosophy', label: 'Follow the Design Principles' },
            { slug: 'how-it-works', label: 'Understand How Phem Works' },
        ],
    },
    {
        title: 'Guides',
        pages: [
            { slug: 'components', label: 'Build Components' },
            { slug: 'events-and-forms', label: 'Handle Events and Forms' },
            { slug: 'client-side', label: 'Add Client-Side Behavior' },
            { slug: 'server-features', label: 'Handle Slow and Failing Work' },
            { slug: 'real-time-updates', label: 'Push Real-Time Updates' },
            { slug: 'error-pages', label: 'Show Custom Error Pages' },
            { slug: 'testing', label: 'Test Components and Pages' },
            { slug: 'packages', label: 'Make a Component Package' },
            { slug: 'using-packages', label: 'Use Component Packages' },
            { slug: 'deployment', label: 'Deploy with Docker' },
        ],
    },
    {
        title: 'Reference',
        pages: [
            { slug: 'phem-syntax', label: 'Phem Syntax' },
            { slug: 'compiling', label: 'Compiler' },
        ],
    },
    {
        title: 'Troubleshooting',
        pages: [{ slug: 'errors-and-limits', label: 'Failed Events and Limits' }],
    },
    {
        title: 'Project',
        pages: [{ slug: 'contributing', label: 'Contribute to Phem' }],
    },
];

export const pages: NavPage[] = navigation.flatMap((group) => group.pages);

export function groupOf(slug: string): NavGroup | undefined {
    return navigation.find((group) => group.pages.some((page) => page.slug === slug));
}
