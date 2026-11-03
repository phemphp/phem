import type { APIRoute } from 'astro';
import { docs } from '../docs';
import { pages } from '../navigation';
import { url } from '../site';

// The items of the search dialog: the pages, and their h2 and h3 headings.
export const GET: APIRoute = async () => {
    const all = await docs();
    const label = (slug: string) => pages.find((page) => page.slug === slug)!.label;

    return Response.json({
        pages: all.map((doc) => ({ title: label(doc.slug), path: doc.title, href: url(`/${doc.slug}/`) })),
        sections: all.flatMap((doc) =>
            doc.headings.map((heading) => ({
                title: heading.text,
                path: `${label(doc.slug)} › ${heading.text}`,
                href: url(`/${doc.slug}/#${heading.slug}`),
            })),
        ),
    });
};
