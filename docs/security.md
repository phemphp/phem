# Secure events and state

An event is a request to your app, and the state of a page comes back from the browser. This guide shows you how to protect events with your access rules, protect one handler, what Phem does with the handler of a disabled element, keep security decisions out of the state, limit how a state can be reused, and rate limit events.

## Protect events with your access rules

An event is a `POST` request to the URL of its page, so the page’s access rules apply to its events too: the firewall, `access_control` and `#[IsGranted]`. An event also has the route name of the page (`_route`), so a rule on a route name covers it:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { route: '^admin_', roles: ROLE_ADMIN } # admin pages and their events
```

This is also true for the pages of an import with a prefix, a host or a name prefix, localized or not, and for the pages in your route files (see [Route a page from a route file](pages.md#route-a-page-from-a-route-file)). Phem adds the event routes after all the imports, with the final route names.

Phem accepts events only from the same origin, so another site can’t send one.

## Protect one handler with `#[IsGranted]`

The `#[IsGranted]` of a page protects the page and all its events. To protect only one handler, put the Symfony `#[IsGranted]` attribute on the handler method, as on a controller method:

```php
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Page('/posts/{id:post}')]
final class PostPage
{
    #[Path] public Post $post;

    public function like(): void
    {
        // Every user who can open the page.
    }

    #[IsGranted('ROLE_EDITOR')]
    public function delete(): RedirectTo
    {
        // Only the editors.
    }

    #[IsGranted('POST_EDIT', subject: 'post')]
    public function rename(string $title): void
    {
        // Only the users that the voter of POST_EDIT accepts for $this->post.
    }
}
```

When the access is denied, the handler doesn’t run, and Phem throws an `AccessDeniedException`. The firewall then sends a 403 response, or sends the user to the login. With `statusCode: 404`, the event gets a 404 response, with the `message` of the attribute.

The attribute works on these handlers:

- **A handler method** of a page or of a component.
- **A closure** in `render()`: `#[IsGranted('ROLE_ADMIN')] fn () => ...`.
- **The class of a component**: all the handlers of the component need the role, also a static closure and a handler method of a parent class. As for a controller, Phem reads the attribute of the class of the component, not of its parent classes. On the class of a page, the attribute is a rule of the page: it protects the page and its events, as above. A component with a route that you render as a tag in another page still checks its class attribute for its handlers.

The attribute on the class and the attribute on the method both apply, as on a controller. The user needs both:

```php
#[IsGranted('ROLE_USER')]
#[Page('/posts/{id:post}')]
final class PostPage
{
    #[IsGranted('ROLE_EDITOR')]
    public function delete(): RedirectTo
    {
        // Only the users with ROLE_USER and ROLE_EDITOR.
    }
}
```

On a page, Symfony checks the class attribute before Phem reads the event, so a user without `ROLE_USER` gets no handler. Phem then checks the method attribute before `delete()` runs. On a component that isn't a page, Phem checks the class attribute first, then the method attribute.

> [!WARNING]
> On a component that isn't a page, `#[IsGranted]` on the class protects only the events of its handlers, not its HTML. A user without the role still sees the component, and gets a 403 response when they use it. To hide the component, check the role where you render it, `{% if is_granted('ROLE_ADMIN') %}`, or put the attribute on the page.

The subject comes from the arguments of the call in the markup, as it comes from the arguments of a controller. The other arguments, such as the services or a `#[MapForm]` object, come after the access check. So a subject that names another parameter of the handler is an error, and a subject that names no parameter is the property of the component with this name:

```php
#[Component]
final class TodoList
{
    /** @var list<Todo> */
    public array $todos = [];

    #[IsGranted('TODO_DELETE', subject: 'todo')]
    public function delete(Todo $todo): void
    {
        // onClick={{ delete(todo) }}: the voter gets the todo of the button.
    }
}
```

| `subject` | The subject that the voter gets |
|---|---|
| `'todo'` | The argument `$todo` of the call. When the handler has no `$todo` parameter, the `todo` property of the component (also a private one) |
| `['todo', 'list' => 'owner']` | A list: `todo` and `list` (the `owner` argument or property) |
| `new Expression('args["todo"].list')` | The value of the expression. It has `args` (the arguments of the call), `request` and `this` (the component) |
| A closure | The value of `fn (array $args, Request $request, object $component)` |

The check needs `symfony/security-bundle`. `#[IsGranted]` runs first, before `#[RateLimit]`, so a denied event uses no rate limit.

## A disabled element has no handler

The browser sends no click to a disabled button, so a click on it can come only from a request that a user writes by hand. Phem does not register the handler of a disabled form control or of an inert element. The element keeps its handler id, but an event with this id runs no handler, and the page stays as it is:

```php
<button onClick={{ publish }} disabled={{ not post.ready }}>Publish</button>
```

phemc evaluates `disabled` once, and gives its value to the handler:

```twig
{% set _phem_off_0 = (not post.ready) %}<button {{ phem_on('click', publish, _phem_off_0) }} {{ phem_attr('disabled', _phem_off_0) }}>Publish</button>
```

While `post.ready` is false, the server render is `<button data-phem-on-click="phem1a2b.c3d4" disabled>`, and an event for `phem1a2b.c3d4` doesn’t run `publish`. When `post.ready` is true, the next render registers the handler with the same id.

The same rule protects a double click. When the first click disables the button (`disabled={{ sent }}`), the second click waits in the queue of the browser, and it goes after the morph: it runs no handler, and the page doesn’t load again.

The rule applies to:

- **`disabled`** on a `<button>`, an `<input>`, a `<select>` or a `<textarea>`, also from a spread (`{{ ...attrs }}`)
- **`inert`** on any element, also from a spread
- the events that the browser never sends to these elements: `onClick`, `onKeyDown`, `onKeyUp`, `onFocus` and `onPaste`

The other handlers stay. A disabled control still gets the pointer events (`onPointerDown`), an `onBlur` when it becomes disabled, `onVisible`, `onPoll`, the `onCall` of a script, and the `onInput` and `onChange` that a controller sends to a field when it sets its value. The rule does not cover these cases, because the server can't know them:

- an element that is hidden with CSS (`hidden`, `display: none`), or that is in a closed dialog
- a control in a `<fieldset disabled>`, or in an element with `inert`
- `aria-disabled="true"`: the browser still sends the click
- `disabled` on the element of a `<Dynamic>`: phemc doesn't know its tag, so only `inert` applies

A control that the server renders disabled and that a script enables later (for example a script that enables the button when the user fills a field) does nothing on a click. Render it enabled, or enable it with an event, so that the server renders it again.

This rule doesn’t replace a check in the handler. A user can still send an older state in which the button was enabled (see [Keep security decisions out of the state](#keep-security-decisions-out-of-the-state)). Check the condition again when the handler runs, with data from the database:

```php
#[IsGranted('ROLE_EDITOR')]
public function publish(): void
{
    // From the database, not from the state.
    if (!$this->posts->isReady($this->post->id)) {
        throw new BadRequestHttpException();
    }

    // ...
}
```

## Keep security decisions out of the state

State is UI state, not trusted data: the browser can send back any older state that the server signed. Keep limits and security decisions, such as tries, votes and roles, in the database or the session. Two options limit how a state can be reused:

- **`state_max_age`**: the number of seconds a state stays valid; the default is `3600` (one hour), and `0` turns the limit off
- **`state_bind_session`**: binds each state to the user’s session; the default is `true`

```yaml
# config/packages/phem.yaml
phem:
    state_max_age: 3600
    state_bind_session: true
```

With these defaults, a state that leaks, for example in a log or in a HAR file that a user shares, is valid only for one hour, and only in the session of its user. They don’t stop users from sending one of their own older states again: only a check in the handler stops that.

`state_bind_session` doesn’t start a session. A visitor with no session gets states that aren’t bound, so the option protects only the users with a session. A new session id, for example after a login or a logout, makes the older states of the user invalid: the open pages load again on their next event. Set it to `false` when the states must stay valid in all sessions.

A page that stays open for longer than `state_max_age` loads again on its next event. A page that gets real-time updates loads again when its hub token expires, also with no event (see [Set up the Mercure hub in FrankenPHP](real-time.md#set-up-the-mercure-hub-in-frankenphp)). Raise `state_max_age` for pages that users leave open.

## Rate limit events

Use the Symfony `#[RateLimit]` attribute to limit the events of a page. The attribute needs Symfony 8.1 or later: on an older version, it does nothing. Install `symfony/rate-limiter` and configure the limiters, one for the page and one for its search handler:

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        phem_events:
            policy: sliding_window
            limit: 30
            interval: '1 minute'
        phem_search:
            policy: sliding_window
            limit: 10
            interval: '10 seconds'
```

Then add the attribute to the page or to one handler:

```php
use Symfony\Component\HttpKernel\Attribute\RateLimit;

#[RateLimit('phem_events', methods: 'POST')]
#[Page('/search')]
final class SearchPage
{
    #[RateLimit('phem_search')]
    public function search(Event $e): void
    {
        // ...
    }
}
```

An event is a `POST` request, so `methods: 'POST'` counts the events and not the page views. On a method, the limit applies to that handler only. A closure in `render()` has no limit of its own: only the limit of the page applies to it. Over the limit, the server returns `429` and the browser dispatches `phem:rate-limited` with `retryAfter` in its detail.
