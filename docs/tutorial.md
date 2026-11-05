# Tutorial: a task list

In this tutorial, you build a small task list. Each step adds one idea of Phem: a page, a
component with props, a list, state and events, a form, and a test. It takes about 20 minutes.

Do [Getting started](getting-started.md) first. You need its application, with `symfony serve`
on. The `phemc` worker builds the `.phpm` files each time you save them.

At the end, you have this page at `/tasks`:

```
My tasks

[ A new task          ] [Add]

[x] Read the tutorial
[ ] Build a page

1 left
```

## Step 1: Make a page

A page is a component with the `#[Page]` attribute. It is a Symfony route, so you do not add a
controller or a routes file. This page has no state, so it is a function.

```php title="src/Ui/Task/TasksPage.phpm"
<?phpm

namespace App\Ui\Task;

use Phem\Attribute\Page;
use Phem\Node;

use function App\Ui\Common\Document;

#[Page('/tasks')]
function TasksPage(): Node
{
    return (
        <Document>
            <Document:title>Tasks</Document:title>
            <h1>My tasks</h1>
            <ul>
                <li>Read the tutorial</li>
                <li>Build a page</li>
            </ul>
        </Document>
    );
}
```

- The markup is the return value of the function. The rules are the rules of JSX: close all
  tags (`<br />`), and return one root element. The parentheses around markup with more than
  one line are not necessary, but they make it easier to read.
- The name of a page ends with `Page`. The page and its components are in one directory for
  the context of the page: here `src/Ui/Task/`.
- `Document` is the HTML document of the app (`src/Ui/Common/Document.phpm`). All the pages use
  it, so it is in `src/Ui/Common/`. `<Document:title>` gives a value to its `title` slot.

Save the file, and open `/tasks`. The page shows the title and the two tasks.

> [!TIP]
> Run `bin/console phem:render /tasks` to see the HTML of a page in the terminal, with no
> browser.

## Step 2: Make a component with props

Move the markup of one task to a component. Only the tasks page uses it, so put it in the
directory of the page. A function component gets its props as parameters.

```php title="src/Ui/Task/TaskItem.phpm"
<?phpm

namespace App\Ui\Task;

use Phem\Attribute\Component;
use Phem\Node;

#[Component]
function TaskItem(string $title, bool $done = false): Node
{
    return (
        <li className={{ done ? 'done' }}>
            {{ title }}
        </li>
    );
}
```

- `{{ title }}` prints the prop. The markup is Twig, so a name in `{{ }}` has no `$`.
- `className` is the `class` attribute, as in React.
- `done = false` makes the prop optional.

Use it in the page with a tag: `<TaskItem title="Read the tutorial" done={{ true }} />`. A string
prop can use quotes. Another value uses `{{ }}`.

## Step 3: Show a list

Put the tasks in an array, and show one `TaskItem` for each task. Change the page:

```php title="src/Ui/Task/TasksPage.phpm"
<?phpm

namespace App\Ui\Task;

use Phem\Attribute\Page;
use Phem\Node;

use function App\Ui\Common\Document;

#[Page('/tasks')]
function TasksPage(): Node
{
    $tasks = [
        ['id' => 1, 'title' => 'Read the tutorial', 'done' => true],
        ['id' => 2, 'title' => 'Build a page', 'done' => false],
    ];

    return (
        <Document>
            <Document:title>Tasks</Document:title>
            <h1>My tasks</h1>
            <ul>
                {% for task in tasks %}
                    <TaskItem key={{ task.id }} title={{ task.title }} done={{ task.done }} />
                {% endfor %}
            </ul>
        </Document>
    );
}
```

`TaskItem` is in the namespace of the page, so the page does not import it. The markup can use
the local variables of the function: `tasks` is `$tasks`. Give a `key` to
each item of a list. After an event, the browser uses the key to find each item again.

The HTML of the list:

```html
<ul>
    <li data-phem-key="1" class="done">Read the tutorial</li>
    <li data-phem-key="2" class="">Build a page</li>
</ul>
```

## Step 4: Add state and an event

The tasks must change when the user clicks a checkbox. A component with state is a class. Its
`#[State]` properties keep the state, and its methods change it.

First, give `TaskItem` a checkbox and an `onToggle` prop. A handler is a `Closure` prop:

```php title="src/Ui/Task/TaskItem.phpm"
<?phpm

namespace App\Ui\Task;

use Closure;
use Phem\Attribute\Component;
use Phem\Node;

#[Component]
function TaskItem(string $title, bool $done, Closure $onToggle): Node
{
    return (
        <li>
            <label>
                <input type="checkbox" checked={{ done }} onChange={{ onToggle }} />
                {{ title }}
            </label>
        </li>
    );
}
```

Then make the `TaskList` class. It owns the tasks:

```php title="src/Ui/Task/TaskList.phpm"
<?phpm

namespace App\Ui\Task;

use Phem\Attribute\{Component, State};
use Phem\Node;

#[Component]
final class TaskList
{
    #[State]
    public array $tasks = [
        ['id' => 1, 'title' => 'Read the tutorial', 'done' => true],
        ['id' => 2, 'title' => 'Build a page', 'done' => false],
    ];

    public function toggle(int $id): void
    {
        foreach ($this->tasks as $i => $task) {
            if ($task['id'] === $id) {
                $this->tasks[$i]['done'] = !$task['done'];
            }
        }
    }

    public function render(): Node
    {
        $items = array_map(function (array $task): Node {
            $toggle = fn () => $this->toggle($task['id']);

            return <TaskItem key={{ task.id }} title={{ task.title }} done={{ task.done }} onToggle={{ toggle }} />;
        }, $this->tasks);

        $left = count(array_filter($this->tasks, fn (array $task): bool => !$task['done']));

        return (
            <section>
                <ul>{{ items }}</ul>
                <p>{{ left }} left</p>
            </section>
        );
    }
}
```

- Each task gets its own closure: `fn () => $this->toggle($task['id'])`. The closure keeps the id
  of its task. Pass the handler. Do not call it.
- PHP computes the values (`$items`, `$left`). The markup only shows them.
- An array of markup prints as a list: `{{ items }}`.

At last, the page shows `<TaskList />`. It has no state of its own, so it stays a function:

```php title="src/Ui/Task/TasksPage.phpm"
<?phpm

namespace App\Ui\Task;

use Phem\Attribute\Page;
use Phem\Node;

use function App\Ui\Common\Document;

#[Page('/tasks')]
function TasksPage(): Node
{
    return (
        <Document>
            <Document:title>Tasks</Document:title>
            <h1>My tasks</h1>
            <TaskList />
        </Document>
    );
}
```

Reload the page, and click the second checkbox. The text changes to "0 left".

The click runs `toggle()` on the server. The browser sends the state of the component with the
event. The server runs the handler, renders the component again, and sends back the HTML that
changed. The browser morphs the page. The HTML shows how Phem links the elements to the handlers:

```html
<section data-phem-c="phem56bfbb1c3162">
    <ul>
        <li data-phem-key="1">
            <label>
                <input type="checkbox" checked data-phem-on-change="phem56bfbb1c3162.e89cdc920485">
                Read the tutorial
            </label>
        </li>
        <li data-phem-key="2">
            <label>
                <input type="checkbox" data-phem-on-change="phem56bfbb1c3162.05c8739bee8d">
                Build a page
            </label>
        </li>
    </ul>
    <p>1 left</p>
</section>
```

> [!NOTE]
> The browser keeps the state, and the server signs it. The state is UI state: keep the data
> that you must trust in the database.

## Step 5: Add a form

Add a field for the title of a new task. `phem:bind` links a field to a `#[State]` property. The
field sends no request when the user types. The next event (here, the submit) sends its value,
and the server sets the property before it runs the handler.

Add the `title` state and the `add()` handler to `TaskList`:

```php title="src/Ui/Task/TaskList.phpm"
#[State]
public string $title = '';

public function add(): void
{
    $title = trim($this->title);

    if ('' === $title) {
        return;
    }

    $this->tasks[] = ['id' => max(array_column($this->tasks, 'id')) + 1, 'title' => $title, 'done' => false];
    $this->title = '';
}
```

Add the form at the top of the `<section>` in `render()`:

```php title="src/Ui/Task/TaskList.phpm"
return (
    <section>
        <form onSubmit={{ add }}>
            <input name="title" phem:bind={{ title }} placeholder="A new task" />
            <button type="submit">Add</button>
        </form>
        <ul>{{ items }}</ul>
        <p>{{ left }} left</p>
    </section>
);
```

Type a title, and press Enter. The new task shows in the list, and the field is empty again,
because `add()` sets `$this->title` to `''`.

> [!TIP]
> To save the tasks, give `TaskList` a Doctrine repository in its constructor, and use it in the
> handlers. A component is a Symfony service. See [Services and `mount()`](components.md#services-and-mount).

## Step 6: Test the component

Phem tests a component with no browser. A test clicks, types and submits, then it checks the
HTML and the state. Install PHPUnit first, if your app does not have it:
`composer require --dev phpunit/phpunit`.

```php title="tests/Ui/Task/TaskListTest.php"
<?php

namespace App\Tests\Ui\Task;

use App\Ui\Task\TaskList;
use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskListTest extends KernelTestCase
{
    use InteractsWithPhem;

    public function testToggle(): void
    {
        $this->mount(TaskList::class)
            ->assertSee('1 left')
            ->change('[data-phem-key="2"] input', true)
            ->assertSee('0 left')
            ->assertState('tasks', [
                ['id' => 1, 'title' => 'Read the tutorial', 'done' => true],
                ['id' => 2, 'title' => 'Build a page', 'done' => true],
            ]);
    }

    public function testAdd(): void
    {
        $this->mount(TaskList::class)
            ->fill('[name=title]', 'Write a test')
            ->submit('form')
            ->assertSee('Write a test')
            ->assertSee('2 left')
            ->assertState('title', '');
    }

    public function testTasksPage(): void
    {
        $this->visit('/tasks')
            ->assertSee('My tasks')
            ->assertSee('Build a page');
    }
}
```

- `mount()` renders one component. `visit()` renders a page through the kernel, with its
  security rules.
- `change()` sends an `onChange` event. `fill()` sets a bound field, and the next event sends it.

The tests use the compiled files, so build them first:

```sh
vendor/bin/phemc build src
vendor/bin/phpunit tests/Ui
```

```
OK (3 tests, 10 assertions)
```

## Next steps

You used the main ideas of Phem. Read more about each one:

- [Philosophy and conventions](philosophy.md): the rules to keep in mind when you design with Phem
- [The `.phpm` syntax](phpm-syntax.md): markup, props, slots, lists, directives and attributes
- [Components and state](components.md): the anatomy of a component, state, `mount()`, context and URL state
- [Events and forms](events-and-forms.md): handlers, bound fields, forms, validation and uploads
- [Client-side behavior](client-side.md): Stimulus and the built-in controllers
- [Server features](server-features.md): transactions, Suspense, real-time updates and async work
- [Errors and limits](errors-and-limits.md): failed events, retries and the limits of an event
- [Testing components and pages](testing.md): all the test helpers
- [Deployment](deployment.md): the production image with FrankenPHP
