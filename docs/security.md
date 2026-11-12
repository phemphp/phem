# Secure events and state

An event is a request to your app, and the state of a page comes back from the browser. This guide shows you how to protect events with your access rules, protect one handler, keep security decisions out of the state, limit how a state can be reused, and rate limit events.

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
- **The class of a component**: all the handlers of the component need the role. On the class of a page, the attribute protects the page and its events, as above.

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

The subject comes from the component, because Phem resolves the arguments of a handler after the access check:

| `subject` | The subject that the voter gets |
|---|---|
| `'post'` | The `post` property of the component, also a private one |
| `['post', 'author' => 'owner']` | A list: `post` and `author` (the `owner` property) |
| `new Expression('this.post.author')` | The value of the expression. It has `this` (the component), `request` and an empty `args` |
| A closure | The value of `fn (array $args, Request $request, object $component)`. `$args` is empty |

To check an argument of the handler, such as the item of a list (`onClick={{ delete(item) }}`), inject `Symfony\Bundle\SecurityBundle\Security` and call `$this->security->isGranted('ITEM_DELETE', $item)` in the handler.

The check needs `symfony/security-bundle`. `#[IsGranted]` runs first, before `#[RateLimit]`, so a denied event uses no rate limit. An `#[Async]` handler doesn't check the attribute again in the worker: the check of the event request covers it.

## Keep security decisions out of the state

State is UI state, not trusted data: the browser can send back any older state that the server signed. Keep limits and security decisions, such as tries, votes and roles, in the database or the session. Two options limit how a state can be reused:

- **`state_max_age`**: the number of seconds a state stays valid; the default is `86400` (one day), and `0` turns the limit off
- **`state_bind_session`**: set it to `true` to bind each state to the user’s session

```yaml
# config/packages/phem.yaml
phem:
    state_max_age: 3600
    state_bind_session: true
```

`state_bind_session` doesn’t start a session. A visitor with no session gets states that aren’t bound, so the option protects only the users with a session. A new session id, for example after a login, makes the older states of the user invalid.

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
