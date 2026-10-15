# Changelog

## Unreleased

- Bracket pair colorization treats `{{ }}` and `{% %}` as one pair, so both delimiters show in
  the same color.

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
