# Phem cheatsheet

This page has the `.phem` syntax on one page, and the rules that are easy to break. It is short, so a coding agent can read it before each task (`phemc docs cheatsheet`). For the full syntax, read [The `.phem` syntax](syntax.md). For the reasons of the rules, read [Design components that fit how Phem works](principles.md).

## Write a file

A `.phem` file opens with `<?phem` and is PHP with markup literals. Pick the smallest form that does the job:

```php
<?phem
namespace App\Ui;

use Phem\Attribute\{Component, Page, Prop, State};
use Phem\{Html, Node};

// Markup and props: a function component.
#[Component]
function Badge(string $label, ?Html $children = null): Node
{
    return <span className="badge">{{ label }} {{ children }}</span>;
}

// State, handlers or services: a class component.
#[Component]
final class Counter
{
    #[Prop] public string $label = 'Clicks';
    #[State] public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return <button onClick={{ increment }}>{{ label }}: {{ count }}</button>;
    }
}

// A URL: a page. A page needs no #[Component].
#[Page('/counter')]
function CounterPage(): Node
{
    return <Document title="Counter"><Counter label="Clicks" /></Document>;
}
```

## Write markup

| You write | It means |
|---|---|
| `<div>`, `<Card />`, `<Ui\Card />` | A lowercase tag is HTML. A capitalized or qualified tag is a component |
| `<br />`, `<img ... />` | Close every tag, also a void element |
| `<>...</>` | A fragment: markup returns one root |
| `return (<div>...</div>);` | Put parentheses around markup on more than one line |
| `className`, `htmlFor` | The `class` and `for` attributes |
| `{{ user.name }}` | Twig. `$user` in PHP is `user` in markup |
| `attr={{ expr }}` | The value. `null` and `false` remove the attribute |
| `attr="text {{ expr }}"` | A string with the value in it |
| `{{ ...rest }}` | Spread a hash of attributes. A later attribute replaces an earlier one |
| `{% if x %}...{% endif %}`, `{{ x and <A /> }}`, `{{ x ? <A /> : <B /> }}` | Conditions |
| `{% for i in items %}<li key={{ i.id }}>...</li>{% endfor %}` | A list. Give each item a `key` |
| `<Card.footer>...</Card.footer>` or `footer={{ <em>...</em> }}` | A named slot: an `Html` prop |
| `onClick={{ save }}`, `onClick={{ remove(item) }}` | A server handler. A call runs on the click, not during the render |
| `phem:bind={{ query }}` | Link a field to a `#[State]` property or to a `Bound` prop |
| `phem:loading={{ save }}`, `phem:debounce={{ 300 }}`, `phem:lock` | Directives of the client |
| `{{ path(AboutPage) }}`, `{{ OrderStatus.Paid }}` | A class name in markup: import it with `use` |

## Follow the rules

Each rule has the diagnostic code that `phemc check` gives when you break it, when the compiler can see it.

1. **Open tag**: open the file with `<?phem`, not `<?php` (E006).
2. **Import each component**: a function component with `use function`, a class with `use` (E010). A tag of a function without `#[Component]` is also E010.
3. **Import each attribute**: `use Phem\Attribute\{Component, Page, Prop, State};` (W004).
4. **Give the props that the component declares**: no unknown prop, and each required prop (E011). Read the props with `phemc components <name>`.
5. **Put a part in its root**: a part with a required context needs its provider above it (E015). The anatomy of `phemc components <root>` shows where each part goes.
6. **Close the tags, return one root**: close each tag and each void element, and wrap two roots in `<>...</>` (E001–E005).
7. **Use only the variables of the scope**: markup reads the PHP variables of its function, and the props, state, properties and methods of its class (E030). Compute the values in PHP before the `return`, not in Twig.
8. **Keep `render()` pure**: no service, clock, random value, static property or superglobal in `render()` or in a function component (W008). Load data in `mount()` or in a handler.
9. **Use a class for state**: `#[State]` goes on a public property of a `#[Component]` class. Hook functions such as `useState` don’t exist (E020). A `#[State]` property can’t be readonly (E041).
10. **Pass a handler, don't call it**: `onClick={{ save }}` or `onClick={{ remove(item) }}`, never `mount` or `render` (E046). The arguments must fit the method (E047).
11. **Give each list item a `key`** (W002).
12. **Use the events that the browser sends**: `onClick`, `onChange`, `onInput`, `onSubmit`, `onKeyDown`… not `onMouseEnter` (W003).
13. **Use `#[Page]` for a URL**: not `export default` (E050), and not the Symfony `#[Route]` on a function (E052). A page is not a tag (E056).
14. **Give a backed enum prop the value of a case**: `variant="outline"` (E018).
15. **Keep security in the database**: the user can replay an older state, so a limit or a permission never lives in `#[State]`. Put filters, tabs and page numbers in the URL with `#[Url]`.

## Find and check

| Task | Command | MCP tool |
|---|---|---|
| Find a doc, a component or an example for a task | `phemc search "<task>"` | `phem_search` |
| Read a doc or a section | `phemc docs <key>` (`--toc` for all the keys) | `phem_doc` |
| See the components of the app and of the packages | `phemc components` | `phem_components` |
| Read the props, the context and the anatomy of a component | `phemc components <name>` | `phem_component` |
| Read an example as a complete file | `phemc examples <name>` | `phem_examples` |
| Check a file after a change | `phemc check <file>` | `phem_check` |

Run the commands from the project root, with `vendor/bin/phemc`. See [Use Phem with coding agents](ai-agents.md).
