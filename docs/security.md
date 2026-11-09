# Secure events and state

An event is a request to your app, and the state of a page comes back from the browser. This guide shows you how to protect events with your access rules, keep security decisions out of the state, limit how a state can be reused, and rate limit events.

## Protect events with your access rules

An event is a `POST` request to the URL of its page, so the page’s access rules apply to its events too: the firewall, `access_control` and `#[IsGranted]`. An event also has the route name of the page (`_route`), so a rule on a route name covers it:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { route: '^admin_', roles: ROLE_ADMIN } # admin pages and their events
```

Phem accepts events only from the same origin, so another site can’t send one.

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

## Rate limit events

Use the Symfony `#[RateLimit]` attribute to limit the events of a page. Install `symfony/rate-limiter` and configure the limiters, one for the page and one for its search handler:

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

An event is a `POST` request, so `methods: 'POST'` counts the events and not the page views. On a method, the limit applies to that handler only. Over the limit, the server returns `429` and the browser dispatches `phem:rate-limited` with `retryAfter` in its detail.
