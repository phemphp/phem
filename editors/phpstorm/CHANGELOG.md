# Changelog

## 0.4.1

- The language server reads the docblocks with the same code as the component catalog of
  `phemc components`. The hover and the docblock types do not change.

## 0.4.0

- A handler call in the markup that gives an argument to a `Phem\Reply` parameter shows the
  E047 error, as for a `Phem\Event` parameter. The hover of an `on*` attribute names
  `Phem\Reply`, and the help of an impure `render()` names `$reply->refresh()`.

## 0.3.0

- Completion for `phem:lock`, which disables the element of a handler while its
  event runs. A `phem:lock` on an element with no handler shows the W006 warning.

## 0.2.1

- A variable named after a Twig word operator (`matches`, `in`, `and`…) in the place of an
  operand, such as `{% for id in matches %}`, is a variable: no false error for an unknown
  variable.

## 0.2.0

- A field with an optional `Bound` prop can have its own `value` or `checked` next to
  `phem:bind`, with no E033.
- E068 for a class or a function with a reserved name of PHP (`Switch`, `List`).
- A call of a method in the markup of a class component (`{{ text() }}`) calls the method of
  the class. A call of a private method gives E067.
- A named slot tag is now `<Card.footer>`, not `<Card:footer>`: highlighting, completion after
  `<Card.`, hover and go-to-definition. The old form gives an error with a hint, and the
  completion after `<Card:` writes the new name.
- A named slot or the children can be a prop with markup: `<Card footer={{ <em>Saved</em> }} />`.
  Completion, hover and go-to-definition for it.
- Completion for the Phem attribute `phem:announce`, and a diagnostic for a text value.

## 0.1.0

- The first version on the JetBrains Marketplace.
- Syntax highlighting for `.phem` files: PHP, markup literals, Twig, and the JavaScript and CSS
  of `<script>` and `<style>`.
- Diagnostics as you type, from the Phem language server.
- Completion of components, props, slots, HTML and Phem attributes, Twig, routes, and PHP
  symbols.
- Go to Declaration, Quick Documentation, Find Usages, and a usage count above each
  declaration.
- The structure view and the breadcrumbs.
- Settings for the source directory, the Node.js path, and the `phemc` path.
