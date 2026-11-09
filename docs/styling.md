# Style components

Phem renders plain HTML, so you style it with any CSS. This guide shows you how to let a caller add classes to a component, and how to merge Tailwind classes of the same group.

## Merge the classes of the caller

With a spread, a caller’s `className` replaces the classes of the component. Mark the default classes with `classes()` to merge the caller’s classes into them instead:

```php
#[Component]
function Button(?Html $children = null, mixed ...$rest): Node
{
    return (
        <button className={{ classes('px-4 py-2 bg-blue-500') }} {{ ...rest }}>
            {{ children }}
        </button>
    );
}

<Button className="bg-red-500">Delete</Button>
// class="px-4 py-2 bg-red-500" with the Tailwind merger
```

Position decides who wins. `classes()` before the spread sets defaults that the caller can change, and `classes()` after the spread sets classes that the caller can’t remove. `classes()` takes the same inputs as clsx: strings, lists and condition maps (`{'active': isActive}`). A `class` attribute takes a list too: `className={{ ['btn', {'active': isActive}] }}`. In PHP code, call `Phem\classes()`.

`style` doesn’t merge: the last value wins.

## Merge Tailwind classes

The default merger of `classes()` only removes duplicates. The Tailwind merger (Tailwind CSS v4) also removes earlier classes of the same group, so `px-2` then `px-4` gives `px-4`. Install it:

```sh
composer require tales-from-a-dev/tailwind-merge-php
```

Then turn it on and point it to your input CSS:

```yaml
# config/packages/phem.yaml
phem:
    class_merger:
        tailwind:
            css: '%kernel.project_dir%/assets/styles/app.css'
```

The merger only knows the default values of the Tailwind theme. A custom value from your `@theme` isn’t in its group. For example, it puts `text-caption` with the text colors, so a later color removes the size. The `css` option fixes this: Phem reads the `@theme` blocks of the file and its relative imports when the container builds. In production, clear the cache after you change the theme.

The merger accepts these other options:

- **`prefix`**: the prefix of your Tailwind build (`@import "tailwindcss" prefix(tw);`), without the colon, for example `tw`
- **`cache`**: the id of a PSR-16 cache service for the merged classes, for example `app.psr16_cache`
- **`theme`**: theme names by hand, by theme key, for example `{ text: [caption, price-lg] }`

To use your own merger, set `class_merger.service` to the id of a `Phem\Dom\ClassMerger` service instead of `tailwind`, for example `App\Ui\MyClassMerger`.
