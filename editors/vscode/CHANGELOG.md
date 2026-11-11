# Changelog

## 0.5.0

- The extension id is now `yceruto.phem` (before: `phem.phem`). Uninstall the old
  extension: `code --uninstall-extension phem.phem`.
- Completion for the Phem attributes `phem:scroll-into-view` and `phem:keep-scroll`.
- Completion for the Phem attributes `phem:key`, `phem:stop`, `phem:prevent` and `phem:poll`,
  and for the events `onVisible` and `onPoll`.
- The files are now `.phem` files that open with `<?phem` (before: `.phpm` and `<?phpm`). The
  language id is now `phem`.
- Find references on a component, a function, or a method: the tags of a component, the calls
  of a function, and the calls and the Twig handlers of a method, in the `.phem` files of the
  project. `Cmd`+click on a declaration also shows them.
- A usage count (`2 usages`) above each component, function, and method. Click it to see the
  usages, then click one to go to it.
- Completion for the Phem attribute `phem:error`: on an HTML element, and on the tag of a
  component with an `id` prop. The diagnostics show the new E061.
- Class names in markup: completion of the cases and the constants after `OrderStatus.` (and
  `cases()` of an enum), and of the pages in `path(` and `url(`, with their import. Go to
  definition and hover on the class and on the case. The diagnostics show the new E062, E063 and
  E064.
- The extension runs the Phem language server (the `phemc-lsp` crate of the compiler). The
  completion, the definition, the hover, and the outline come from the server. The JavaScript
  copy of the parser is removed. The PhpStorm plugin uses the same server.
- The extension contains the server, compiled to WebAssembly, and runs it with the Node of
  VS Code. You do not need a `phemc` binary.
- The diagnostics of the compiler show as you type, also for unsaved changes. Before, they
  showed only on save, and only with a `phem.phemc.path` setting.
- `phem.phemc.path` now runs a native `phemc lsp` as the server. Empty: the bundled server.
- Completion for the Phem attributes `phem:bind`, `phem:live` and `phem:progress`.
- The **Phem: Restart Language Server** command starts the server again. Use it after you
  install a new server build.

## 0.4.0

- Completion for the Phem attributes of an HTML element: `phem:loading`, `phem:debounce`, and
  `phem:throttle`. In the Twig value of `phem:debounce`, completion also suggests `'blur'`.
- A `#[Prop(rest: true)]` property of a class component is its rest prop. Completion and hover
  do not show it as a named prop, and the outline shows it as "rest props".
- A property of a class component is a prop only with `#[Prop]` (or `#[Path]` on a page).
  Completion, hover, and the outline do not show the other public properties as props.
- A function is a component only with `#[Component]`, as a class. The other functions of a
  `.phpm` file are plain PHP functions: completion does not suggest them as component tags.
- The project is now Phem. The extension id is `phem.phem`, the language id is `phpm`, and
  the files are `.phpm` files that open with `<?phpm`. The compiler is `phemc`, and the settings
  are `phem.phemc.path` and `phem.phemc.sourceDir`.
- Completion for class members after `->`, `?->`, and `::`, with the parents, the traits, the
  interfaces, and the docblock types. Completion for variables after `$`.
- Twig completion: variables (PHP scope, class properties and methods, `{% for %}`,
  `{% set %}`, `loop`), attributes after a dot (properties, methods, getters, array keys),
  filters, tests, functions (Twig and Symfony), tags with the end tags of the open blocks, and
  the route names in `path('...')` and `url('...')`.
- Twig tags that the extension does not know (new Twig tags, or tags of extensions like
  `{% component %}`) open a block when their end tag follows. Completion gives their end tag,
  and hover shows the block of an end tag.
- HTML completion: element names, attributes per element, global and ARIA attributes, and the
  values of the enumerated attributes.
- Go to definition and hover for components, slots, props, classes, functions, members, PHP
  variables, Twig variables and attributes, handlers, and route names. Hover for the Twig
  filters, functions, tests, tags, and the HTML elements and attributes.
- An outline: the namespace, the components with their props and slots, the pages with their
  paths, and the classes with their members.
- phemc diagnostics on save, with the new `phem.phemc.path` and `phem.phemc.sourceDir` settings. It
  uses `phemc check --format json`.
- The `.`, `>`, `|`, `$`, `%`, `'`, and `"` characters start the completion where it applies.
- Completion and hover for the built-in `<Root>` component (`Phem\Root`): its `tag` prop and
  its `children`. Completion adds the `use Phem\Root;` import.

- The Twig delimiters `{{ }}` and `{% %}` are one bracket pair, and they always have the color of
  the first bracket level. Before, the two braces of a delimiter had different colors, and the
  color changed with the depth (yellow in a template, blue in a function body).

## 0.2.0

- Completion for component tags, with the required props and the import of the component.
- Completion for props, named slots (`<Card:footer>`), Phem events, and closing tags.
- Completion for `use`, `use function`, and `use const` statements, from the `.phpm` files and the
  Composer autoload maps.
- Completion for class and function names in PHP code, with the `use` statement.
- A new icon: the elePHPant with a Twig sprig. The extension has a 128x128 icon too.

## 0.1.0

- First release: syntax highlighting for `.phpm` files (PHP, markup literals, Twig), and comments
  that use `{# #}` inside markup.
