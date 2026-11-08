# Build a task list with Phem

In this tutorial, you build a small task list. Each step adds one Phem idea: a page, a component with props, a list, state and events, a form, and a test. It takes about 20 minutes.

## Before you begin

You need the app from [Create your first Phem app](getting-started.md), with `symfony serve` running. The `phemc` watcher compiles your `.phem` files each time you save them, so you can reload the browser after each step.

When you’re done, you’ll have this page at `/tasks`:

```text
My tasks

[ A new task          ] [Add]

[x] Read the tutorial
[ ] Build a page

1 left
```

## Step 1: Create the tasks page

Start with a page that shows two fixed tasks. A page is a component with the `#[Page]` attribute, and that attribute is also its Symfony route, so you don’t add a controller or a routes file. This page has no state, so it’s a function.

Create `src/Ui/Task/TasksPage.phem`:

```php title="src/Ui/Task/TasksPage.phem"
<?phem

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

Here’s what this code does:

- **The return value**: the function returns the markup. It follows two rules: close every tag (`<br />`) and return one root element. The parentheses around multi-line markup are optional, but they make it easier to read.
- **The file location**: page names end with `Page`. A page and its components share one directory per feature, here `src/Ui/Task/`.
- **`Document`**: it’s the HTML document of your app, from `src/Ui/Common/Document.phem`. `<Document:title>` fills its `title` slot.

Save the file and open `/tasks`. The page shows the title and the two tasks.

> [!TIP]
> Run `bin/console phem:render /tasks` to print the HTML of a page in the terminal, without a browser.

## Step 2: Create a component with props

Move the markup of one task into its own component. Only the tasks page uses it, so put it in the page’s directory. A function component gets its props as parameters.

Create `src/Ui/Task/TaskItem.phem`:

```php title="src/Ui/Task/TaskItem.phem"
<?phem

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

A few details in this component:

- **`{{ title }}`**: it prints the prop. The markup is Twig, so names inside `{{ }}` have no `$`.
- **`className`**: it sets the `class` attribute.
- **`$done = false`**: the default value makes the prop optional.

To use the component, write it as a tag: `<TaskItem title="Read the tutorial" done={{ true }} />`. A string prop can use quotes. Any other value goes inside `{{ }}`.

## Step 3: Show the tasks as a list

Put the tasks in an array and render one `TaskItem` for each task. In `src/Ui/Task/TasksPage.phem`, replace the `TasksPage()` function:

```php title="src/Ui/Task/TasksPage.phem"
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

`TaskItem` is in the same namespace as the page, so you don’t import it. The markup can read the function’s local variables: `tasks` is `$tasks`.

Give each item in a list a `key`. After an event, the browser uses the key to match each item with its new HTML. Reload the page, and the list renders as this HTML:

```html
<ul>
    <li data-phem-key="1" class="done">Read the tutorial</li>
    <li data-phem-key="2" class="">Build a page</li>
</ul>
```

## Step 4: Toggle a task with state and an event

Next, you’ll let the user check off a task. Data that changes is state, and a component with state is a class: its `#[State]` properties hold the state, and its methods change it.

### Add a checkbox to the task item

First, give `TaskItem` a checkbox and an `onToggle` prop. An event handler prop has the `Closure` type. Replace `src/Ui/Task/TaskItem.phem`:

```php title="src/Ui/Task/TaskItem.phem"
<?phem

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

### Create the task list component

Then create the `TaskList` class, which owns the tasks. Its `toggle()` method flips the `done` flag of one task. Create `src/Ui/Task/TaskList.phem`:

```php title="src/Ui/Task/TaskList.phem"
<?phem

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
}
```

Add this `render()` method to the `TaskList` class, after `toggle()`:

```php title="src/Ui/Task/TaskList.phem"
public function render(): Node
{
    $left = count(array_filter($this->tasks, fn (array $task): bool => !$task['done']));

    return (
        <section>
            <ul>
                {% for task in tasks %}
                    <TaskItem key={{ task.id }} title={{ task.title }} done={{ task.done }} onToggle={{ toggle(task.id) }} />
                {% endfor %}
            </ul>
            <p>{{ left }} left</p>
        </section>
    );
}
```

Here’s how `render()` works:

- **A handler call per task**: `toggle(task.id)` doesn’t run during the render. It gives `onToggle` the `toggle()` handler with the id of its task, and the click runs `toggle(2)`.
- **State in the markup**: the markup reads the `#[State]` properties by name, so `{% for task in tasks %}` loops over `$this->tasks`.
- **PHP computes, markup shows**: PHP computes `$left`, and the markup only prints it.

> [!NOTE]
> Phem calls `render()` for you, so you never call it yourself. A class component can also have a constructor, which gets the services, and a `mount()` method, which sets the first values before the first render. `TaskList` doesn’t need them, because its tasks have default values. Don’t name a handler `mount` or `render`: Phem reserves these names, and the compiler reports an error. Read [Methods that Phem calls for you](what-is-phem.md#methods-that-phem-calls-for-you) for the details.

### Show the task list on the page

Finally, render `<TaskList />` on the page. The page still has no state of its own, so it stays a function. Replace the `TasksPage()` function:

```php title="src/Ui/Task/TasksPage.phem"
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

Reload the page and click the second checkbox. The text changes to “0 left”.

### What happens when you click

The click runs `toggle()` on the server. The browser sends the component’s state with the event, and the server runs the handler, renders the component again, and returns the HTML that changed. Then the browser morphs the page to match.

Phem links each element to its handler with a `data-phem-on-*` attribute. You can see it in the HTML of the list:

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
> The browser keeps the state, and the server signs it so nobody can change it. Keep it for UI state, and store data that you must trust in your database.

## Step 5: Add tasks with a form

Now you’ll add a field to create new tasks. `phem:bind` links a field to a `#[State]` property. Typing in the field sends no request: the next event, here the form submit, sends the field’s value, and the server sets the property before it runs the handler.

Add the `title` state and the `add()` handler to the `TaskList` class:

```php title="src/Ui/Task/TaskList.phem"
#[State]
public string $title = '';

public function add(): void
{
    $title = trim($this->title);

    if ('' === $title) {
        return;
    }

    $id = max(array_column($this->tasks, 'id')) + 1;
    $this->tasks[] = ['id' => $id, 'title' => $title, 'done' => false];
    $this->title = '';
}
```

In `render()`, add the form at the top of the `<section>`:

```php title="src/Ui/Task/TaskList.phem"
return (
    <section>
        <form onSubmit={{ add }}>
            <input name="title" phem:bind={{ title }} placeholder="A new task" />
            <button type="submit">Add</button>
        </form>
        <ul>
            {% for task in tasks %}
                <TaskItem key={{ task.id }} title={{ task.title }} done={{ task.done }} onToggle={{ toggle(task.id) }} />
            {% endfor %}
        </ul>
        <p>{{ left }} left</p>
    </section>
);
```

Type a title and press Enter. The new task shows up in the list, and the field is empty again, because `add()` sets `$this->title` back to `''`.

> [!TIP]
> To save the tasks, inject a Doctrine repository in the `TaskList` constructor and use it in the handlers. Every component is a Symfony service. See [Use services in a component](components.md#use-services-in-a-component).

## Step 6: Test the task list

Phem tests components without a browser: a test clicks, types, and submits, then checks the HTML and the state. If your app doesn’t have PHPUnit yet, install it:

```sh
composer require --dev phpunit/phpunit
```

### Test the checkbox

Create `tests/Ui/Task/TaskListTest.php` with a test that checks the second task:

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
}
```

`mount()` renders one component, and `change()` sends an `onChange` event to the element that matches the selector.

### Test the form and the page

Add two more methods to the `TaskListTest` class. The first one submits the form, and the second one renders the whole page:

```php title="tests/Ui/Task/TaskListTest.php"
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
```

`fill()` sets a bound field, and the next event sends its value. `visit()` renders a page through the Symfony kernel, with its security rules.

### Run the tests

The tests use the compiled files, so compile them first, then run PHPUnit:

```sh
vendor/bin/phemc build src
vendor/bin/phpunit tests/Ui
```

All three tests pass:

```text
OK (3 tests, 10 assertions)
```

## Next steps

You’ve used the main ideas of Phem. Learn more about each one:

- [Philosophy and conventions](philosophy.md): the rules to keep in mind when you design with Phem
- [The `.phem` syntax](phem-syntax.md): markup, props, slots, lists, directives, and attributes
- [Components and state](components.md): the anatomy of a component, state, `mount()`, context, and URL state
- [Events and forms](events-and-forms.md): handlers, bound fields, forms, validation, and uploads
- [Client-side behavior](client-side.md): Stimulus and the built-in controllers
- [Server features](server-features.md): transactions, Suspense, real-time updates, and async work
- [Errors and limits](errors-and-limits.md): failed events, retries, and the limits of an event
- [Testing components and pages](testing.md): all the test helpers
- [Deployment](deployment.md): build a production image with FrankenPHP
