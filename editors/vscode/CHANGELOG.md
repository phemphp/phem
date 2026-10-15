# Changelog

## Unreleased

- The Twig delimiters `{{ }}` and `{% %}` are one bracket pair, and they always have the color of
  the first bracket level. Before, the two braces of a delimiter had different colors, and the
  color changed with the depth (yellow in a template, blue in a function body).

## 0.2.0

- Completion for component tags, with the required props and the import of the component.
- Completion for props, named slots (`<Card:footer>`), rephp events, and closing tags.
- Completion for `use`, `use function`, and `use const` statements, from the `.rhp` files and the
  Composer autoload maps.
- Completion for class and function names in PHP code, with the `use` statement.
- A new icon: the elePHPant with a Twig sprig. The extension has a 128x128 icon too.

## 0.1.0

- First release: syntax highlighting for `.rhp` files (PHP, markup literals, Twig), and comments
  that use `{# #}` inside markup.
