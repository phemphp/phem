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
            { slug: 'introduction', label: 'Introduction' },
            { slug: 'quickstart', label: 'Quickstart' },
            { slug: 'installation', label: 'Installation' },
            { slug: 'tutorial', label: 'Tutorial' },
            { slug: 'principles', label: 'Principles' },
            { slug: 'architecture', label: 'Architecture' },
        ],
    },
    {
        title: 'Handbook',
        pages: [
            { slug: 'structure', label: 'Structure' },
            { slug: 'components', label: 'Components' },
            { slug: 'pages', label: 'Pages' },
            { slug: 'events', label: 'Events' },
            { slug: 'forms', label: 'Forms' },
            { slug: 'feeds', label: 'Feeds' },
            { slug: 'styling', label: 'Styling' },
            { slug: 'client-side', label: 'Client-Side' },
            { slug: 'controllers', label: 'Controllers' },
            { slug: 'real-time', label: 'Real-Time' },
            { slug: 'resilience', label: 'Resilience' },
            { slug: 'security', label: 'Security' },
            { slug: 'error-pages', label: 'Error Pages' },
            { slug: 'testing', label: 'Testing' },
            { slug: 'packages', label: 'Packages' },
            { slug: 'package-authoring', label: 'Package Authoring' },
            { slug: 'deployment', label: 'Deployment' },
        ],
    },
    {
        title: 'Reference',
        pages: [
            { slug: 'syntax', label: 'Syntax' },
            { slug: 'compiler', label: 'Compiler' },
            { slug: 'configuration', label: 'Configuration' },
        ],
    },
    {
        title: 'Troubleshooting',
        pages: [
            { slug: 'failed-events', label: 'Failed Events' },
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
