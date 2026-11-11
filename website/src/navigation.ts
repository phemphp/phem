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
        title: 'Overview',
        pages: [
            { slug: 'what-is-phem', label: 'Introduction' },
            { slug: 'getting-started', label: 'Quickstart' },
            { slug: 'installation', label: 'Installation' },
            { slug: 'tutorial', label: 'Tutorial' },
            { slug: 'philosophy', label: 'Principles' },
            { slug: 'how-it-works', label: 'Architecture' },
        ],
    },
    {
        title: 'Handbook',
        pages: [
            { slug: 'project-structure', label: 'Structure' },
            { slug: 'components', label: 'Components' },
            { slug: 'pages', label: 'Pages' },
            { slug: 'events', label: 'Events' },
            { slug: 'forms', label: 'Forms' },
            { slug: 'long-lists', label: 'Feeds' },
            { slug: 'styling', label: 'Styling' },
            { slug: 'client-side', label: 'Client-Side' },
            { slug: 'ui-controllers', label: 'Controllers' },
            { slug: 'real-time-updates', label: 'Real-Time' },
            { slug: 'server-features', label: 'Resilience' },
            { slug: 'security', label: 'Security' },
            { slug: 'error-pages', label: 'Error Pages' },
            { slug: 'testing', label: 'Testing' },
            { slug: 'using-packages', label: 'Packages' },
            { slug: 'packages', label: 'Package Authoring' },
            { slug: 'deployment', label: 'Deployment' },
        ],
    },
    {
        title: 'Reference',
        pages: [
            { slug: 'phem-syntax', label: 'Syntax' },
            { slug: 'compiling', label: 'Compiler' },
            { slug: 'configuration', label: 'Configuration' },
        ],
    },
    {
        title: 'Troubleshooting',
        pages: [
            { slug: 'errors-and-limits', label: 'Failed Events' },
            { slug: 'debugging', label: 'Debugging' },
        ],
    },
    {
        title: 'Project',
        pages: [
            { slug: 'contributing', label: 'Contributing' },
            { slug: 'releasing', label: 'Releasing' },
            { slug: 'changelog', label: 'Changelog' },
        ],
    },
];

export const pages: NavPage[] = navigation.flatMap((group) => group.pages);

export function groupOf(slug: string): NavGroup | undefined {
    return navigation.find((group) => group.pages.some((page) => page.slug === slug));
}
