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
            { slug: 'what-is-phem', label: 'What is Phem' },
            { slug: 'getting-started', label: 'Getting started' },
            { slug: 'tutorial', label: 'Tutorial: a task list' },
            { slug: 'deployment', label: 'Deployment' },
        ],
    },
    {
        title: 'Guides',
        pages: [
            { slug: 'philosophy', label: 'Philosophy and conventions' },
            { slug: 'testing', label: 'Testing' },
            { slug: 'packages', label: 'Component packages' },
        ],
    },
    {
        title: 'Reference',
        pages: [
            { slug: 'phpm-syntax', label: 'The .phpm syntax' },
            { slug: 'compiling', label: 'Compiling' },
            { slug: 'how-it-works', label: 'How it works' },
        ],
    },
    {
        title: 'Project',
        pages: [{ slug: 'contributing', label: 'Contributing' }],
    },
];

export const pages: NavPage[] = navigation.flatMap((group) => group.pages);

export function groupOf(slug: string): NavGroup | undefined {
    return navigation.find((group) => group.pages.some((page) => page.slug === slug));
}
