// The order of the docs. The sidebar, the drawer, the breadcrumbs, the pager and the search read
// it. Each file of docs/ and CHANGELOG.md must be in it once (the build fails on a missing or an unknown file).
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
            { slug: 'installation', label: 'Install and Configure Phem' },
            { slug: 'project-structure', label: 'Organize Your Project' },
            { slug: 'components', label: 'Build Components' },
            { slug: 'pages', label: 'Build Pages and Routes' },
            { slug: 'events', label: 'Handle Events' },
            { slug: 'forms', label: 'Handle Forms' },
            { slug: 'client-side', label: 'Add Client-Side Behavior' },
            { slug: 'styling', label: 'Style Components' },
            { slug: 'server-features', label: 'Handle Slow and Failing Work' },
            { slug: 'security', label: 'Secure Events and State' },
            { slug: 'real-time-updates', label: 'Push Real-Time Updates' },
            { slug: 'error-pages', label: 'Show Custom Error Pages' },
            { slug: 'testing', label: 'Test Components and Pages' },
            { slug: 'packages', label: 'Make a Component Package' },
            { slug: 'using-packages', label: 'Use Component Packages' },
            { slug: 'deployment', label: 'Deploy a Phem App' },
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
        pages: [
            { slug: 'errors-and-limits', label: 'Failed Events and Limits' },
            { slug: 'debugging', label: 'Debug a Phem App' },
        ],
    },
    {
        title: 'Project',
        pages: [
            { slug: 'contributing', label: 'Contribute to Phem' },
            { slug: 'releasing', label: 'Release a Version' },
            { slug: 'changelog', label: 'Changelog' },
        ],
    },
];

export const pages: NavPage[] = navigation.flatMap((group) => group.pages);

export function groupOf(slug: string): NavGroup | undefined {
    return navigation.find((group) => group.pages.some((page) => page.slug === slug));
}
