# Show custom error pages

When a request fails with a 404 or a 500, Symfony renders an error page. Add `#[ErrorPage]` to a function, and Phem renders that page with your components inside your app’s document. This guide covers one page for every error, pages per status or per section, previews, tests, and static files for the web server.

Pick the tool for your task:

| You need | Use |
|---|---|
| One page for every error | `#[ErrorPage]` |
| A page for one status or a few statuses | `#[ErrorPage(404)]`, `#[ErrorPage([401, 403])]` |
| Different pages for a section of the app | `#[ErrorPage(path: '/admin')]` |
| The status, headers, or failed URL | The parameters `int $status`, `FlattenException $exception`, `Request $request` |
| To see an error page in development | The Symfony preview: `/_error/404`, `/_error/404?path=/admin` |
| Error pages in end-to-end tests | `phem.show_exceptions: false` |
| A test for an error page | `errorPage()` in a `KernelTestCase` |
| An error page when PHP doesn’t answer | `bin/console error:dump` |

Error pages are for failed requests. When one part of a page fails, use an [`<ErrorBoundary>`](server-features.md#show-a-fallback-when-part-of-the-page-fails). For an event that fails, see [Failed events](errors-and-limits.md#an-event-fails-and-the-page-doesnt-change).

## Add one page for every error

Add `#[ErrorPage]` to a function in a `.phem` file. Like a `#[Page]` function, it doesn’t need `#[Component]`. In most apps it renders the app’s document:

```php
<?phem

namespace App\Ui\Errors;

use Phem\Attribute\ErrorPage;
use Phem\Node;

use function App\Ui\Common\Document;

#[ErrorPage]
function ErrorPage(int $status): Node
{
    $title = 404 === $status ? 'Page not found' : 'Something went wrong';

    return (
        <Document title={{ title }}>
            <main>
                <h1>{{ title }}</h1>
                <a href={{ path('home') }}>Back to the home page</a>
            </main>
        </Document>
    );
}
```

Run `phemc build`. A URL with no route now gets this page, with the status of the error:

```text
GET /missing

HTTP/1.1 404 Not Found
Content-Type: text/html; charset=UTF-8

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Page not found</title>
    </head>
    <body>
        <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch …">
            <main><h1>Page not found</h1><a href="/">Back to the home page</a></main>
        </div>
    </body>
</html>
```

The root element has no `data-phem-state-value` because an error page has no state. See [What an error page can do](#what-an-error-page-can-do).

## Add a page for a specific status

Pass a status or a list of statuses to the attribute. A page without a status handles every other status:

```php
use Phem\Attribute\ErrorPage;
use Symfony\Component\HttpFoundation\Request;

#[ErrorPage(404)]
function NotFoundPage(): Node
{
    return <Document>…<h1>Page not found</h1>…</Document>;
}

#[ErrorPage([401, 403])]
function DeniedPage(int $status): Node
{
    return <Document>…<h1>You cannot see this page</h1>…</Document>;
}

// All the other statuses: 429, 500, 503…
#[ErrorPage]
function ErrorPage(int $status, Request $request): Node
{
    // The URL that failed, for a "Try again" link.
    $retry = $request->getRequestUri();

    return <Document>…<a href={{ retry }}>Try again</a>…</Document>;
}
```

A status must be between 400 and 599. Symfony constants work too: `#[ErrorPage(Response::HTTP_NOT_FOUND)]`.

## Add pages for a section of the app

Give a section its own error pages with `path`. A page matches when the request path equals `path` or starts with `path/`, so `/admin` matches `/admin` and `/admin/users` but not `/administration`. The attribute is repeatable, so one function can serve two sections:

```php
// The admin: all the statuses, with a link to the dashboard.
#[ErrorPage(path: '/admin')]
function AdminErrorPage(int $status): Node
{
    return <AdminLayout>…<a href={{ path('admin_dashboard') }}>Back to the dashboard</a>…</AdminLayout>;
}

// The same page for the shop and for the blog.
#[ErrorPage(path: '/shop')]
#[ErrorPage(path: '/blog')]
function SiteErrorPage(int $status): Node { … }
```

### How Phem picks the page

Phem takes the page with the longest matching path. Among those, a page with the status wins over a page for every status. With the pages in this guide:

| Request | Status | Page |
|---|---|---|
| `GET /missing` | 404 | `NotFoundPage` |
| `GET /cart` (a bug) | 500 | `ErrorPage` |
| `GET /admin/missing` | 404 | `AdminErrorPage` (its path is longer) |
| `GET /admin/orders/9` (a bug) | 500 | `AdminErrorPage` |
| `GET /administration` | 404 | `NotFoundPage` (a path is a full segment) |

### Fix invalid error page attributes

Phem checks the attributes when the container builds: after each `phemc build` in debug mode, and during `cache:warmup`. The build stops on any of these:

- A status outside 400–599
- A path that doesn’t start with `/`
- An unknown argument
- Two pages for the same status at the same path

```text
"App\Ui\Errors\NotFoundPage" and "App\Ui\Errors\MissingPage" are both the error page of the
status 404 at "/". Remove one #[ErrorPage], or give it another status or path.
```

If the container didn’t check them, Phem skips an invalid attribute and still shows the other error pages.

## Read the error in the page

An error page accepts the same arguments as a function page: the `Request`, services, `#[Autowire]`, `#[CurrentUser]`, and the other controller arguments. It also gets these values by parameter name:

| Parameter | Value |
|---|---|
| `int $status` | The HTTP status, after Symfony maps it (`framework.exceptions`, `#[WithHttpStatus]`) |
| `FlattenException $exception` | The error: `getStatusText()`, `getHeaders()` (`Retry-After`, `Allow`), `getClass()` |
| `\Throwable $exception` | The exception itself, when the parameter has an exception type (`HttpExceptionInterface`, `\Throwable`) |

This page reads the `Retry-After` header of a 429:

```php
use Symfony\Component\ErrorHandler\Exception\FlattenException;

#[ErrorPage(429)]
function SlowDownPage(FlattenException $exception): Node
{
    $seconds = $exception->getHeaders()['Retry-After'] ?? 60;

    return <Document>…<p>Try again in {{ seconds }} seconds.</p>…</Document>;
}
```

The response keeps the exception’s headers, so a 429 sends its `Retry-After` and a 405 its `Allow`. Don’t show `$exception->getMessage()` to visitors, because it can contain private data.

Controller attributes such as `#[IsGranted]` and `#[Cache]` don’t apply to an error page, because it has no route.

## What an error page can do

An error page renders for a URL that failed, so some page features aren’t available. Keep these rules in mind:

- **Static HTML only**: An error page has no state and no handlers, because its URL is the URL that failed and an event can’t work there. It can’t return a `RedirectTo` either: it throws a `LogicException`. Links, HTML forms, and Stimulus controllers (a toast, for example) work. `<Suspense>` boundaries render inline, with no fallback and no streaming. `#[Broadcast]` on an error page is E053.
- **Document choice**: Render the app’s document (with its `<Root>`) or a plain HTML document such as `<html><body><h1>Down</h1></body></html>`. A plain document doesn’t need the services of your document component, which helps for a 500 when the database is down.
- **No user on unrouted URLs**: On a URL with no route, the Symfony firewall didn’t run. `app.user` is null, `is_granted()` returns false, and `logout_path()` throws. A 404 or 403 thrown from a page still has the user.
- **No database reads**: A 500 can come from the database. Don’t read it in an error page or in its document component, for example to show the alerts of a signed-in user.
- **Turbo visits**: A Turbo visit to a failing URL shows the error page, because Turbo replaces the head and the body.
- **App only**: Only the app declares error pages, and `#[ErrorPage]` in a package is E060. A package doesn’t know the URLs of its pages in the app. To reuse a package’s error page, render its component inside an error page of your app.

phemc reports these errors for error pages:

| Code | Error |
|---|---|
| E053 | `#[Broadcast]` on an error page: an error page has no connection to the Mercure hub |
| E056 | An error page used as a tag (`<NotFoundPage />`): an error page isn’t a component of other pages |
| E059 | `#[ErrorPage]` on a class: an error page has no state, so it must be a function |
| E060 | `#[ErrorPage]` in a package: only the app has error pages |
| W004 | `#[ErrorPage]` without `use Phem\Attribute\ErrorPage;` |

## Find out why your error page doesn’t show

Phem passes the error to the next Symfony renderer in these cases:

- **No match**: No `#[ErrorPage]` matches the status and path. Symfony then tries the [Twig template](https://symfony.com/doc/current/controller/error_pages.html) (`templates/bundles/TwigBundle/Exception/error404.html.twig`), then its own page.
- **Phem events**: The request is a Phem event. The browser doesn’t show the body of a failed event. It dispatches `phem:error` instead (see [Failed events](errors-and-limits.md#an-event-fails-and-the-page-doesnt-change)).
- **Exceptions shown**: `phem.show_exceptions` is true, so the exception page shows. See [Preview an error page in debug mode](#preview-an-error-page-in-debug-mode).
- **Page throws**: The error page itself throws. In debug mode, the exception propagates so you see the bug. In production, Phem logs it and falls back to the Symfony page, because an error page must not fail.

## Preview an error page in debug mode

In debug mode, Symfony shows the exception page with its trace instead of your error page. The `phem.show_exceptions` option controls this for error pages. It defaults to the kernel’s debug mode (`%kernel.debug%`):

```yaml
# config/packages/phem.yaml
when@test:
    phem:
        # The e2e tests (Playwright, Behat) see the error pages of the application.
        show_exceptions: false
```

You can also read it from an environment variable: `show_exceptions: '%env(bool:SHOW_EXCEPTIONS)%'`.

To see an error page in development, open the Symfony preview at `/_error/404` or `/_error/500`. The preview always shows the error page, even in debug mode. Because the preview has the URL of its own route, pass a section’s path with `path`: `/_error/404?path=/admin` shows the 404 page of `/admin`. The Symfony Flex recipe of FrameworkBundle adds the route:

```yaml
# config/routes/framework.yaml
when@dev:
    _errors:
        resource: '@FrameworkBundle/Resources/config/routing/errors.php'
        prefix: /_error
```

The preview has the user of your session. A real 404 on a URL with no route has no user.

## Test an error page

In a `KernelTestCase`, `errorPage()` renders the error page for an error at a URL. It works like the preview, so the test’s debug mode doesn’t show the exception. An `int` stands for an `HttpException` with that status:

```php
use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ErrorPageTest extends KernelTestCase
{
    use InteractsWithPhem;

    public function testAPageOfTheAdminThatIsNotThereLinksToTheDashboard(): void
    {
        $this->errorPage(404, '/admin/missing')
            ->assertStatus(404)
            ->assertSee('Back to the dashboard');
    }
```

Pass an exception to test other statuses. The page must not show the exception message:

```php
    public function testAServerErrorOffersToTryAgain(): void
    {
        $this->errorPage(new \RuntimeException('Boom.'), '/cart?page=2')
            ->assertStatus(500)
            ->assertSelectorExists('a[href="/cart?page=2"]')
            ->assertDontSee('Boom.');
    }

    public function testATooManyRequestsErrorShowsTheWait(): void
    {
        $this->errorPage(new TooManyRequestsHttpException(60), '/verify')
            ->assertStatus(429)
            ->assertSee('Try again in 60 seconds.');
    }
}
```

When no error page matches the status and URL, `errorPage()` returns the Symfony page. Its request has a session, like a browser request. An error page has no events, so tester actions such as `click()` and `fill()` don’t work.

### Test error pages through visit() and end-to-end tests

Set `show_exceptions: false` in the `test` environment (see [Preview an error page in debug mode](#preview-an-error-page-in-debug-mode)). Then `visit()` of a failing URL shows the error page, and so do end-to-end tests through a web server or a real browser:

```php
$this->visit('/admin/missing')->assertStatus(404)->assertSee('Back to the dashboard');
```

`visit()` runs Symfony in web mode, like `WebTestCase`. In CLI mode, Symfony would render an HTTP error with its CLI renderer.

## Serve error pages when PHP is down

When PHP doesn’t answer during a crash or a deploy, the web server or proxy shows its own error. Write your error pages to static files with `error:dump`, which renders your `#[ErrorPage]` functions. Run it with debug mode off, or it writes the exception page:

```sh
APP_ENV=prod APP_DEBUG=0 php bin/console error:dump public/errors 502 503 504 --force
```

The command has no request, so Phem renders each page for a GET of `/` at the router’s scheme and host. Set them with `framework.router.default_uri` (for example `https://shop.example`), or the host is `localhost`. Only pages with no `path` (or `path: '/'`) go into the files, and they can’t depend on values from a real request.

Then configure the web server or proxy to serve the files. They only show for errors that the server produces itself. A 500 from your app still shows the app’s own error page. With nginx in front of PHP-FPM or an app server:

```nginx
# nginx in front of PHP-FPM or of the application server
error_page 502 /errors/502.html;
error_page 503 /errors/503.html;
error_page 504 /errors/504.html;

location /errors/ {
    root /app/public;
    internal;
}
```

With Caddy as a proxy in front of the app:

```caddyfile
# Caddy as a proxy in front of the application
example.com {
    reverse_proxy app:8080

    handle_errors {
        root * /srv/errors
        rewrite * /{err.status_code}.html
        file_server
    }
}
```

In a Docker image, run the command after `cache:warmup`. See [Add static error pages for the web server](deployment.md#add-static-error-pages-for-the-web-server).

## How it works

Phem uses the standard Symfony error flow and changes only its last step. When a controller, the router, or the firewall throws, the Symfony `ErrorListener` maps the status, logs the exception, and sends a GET sub-request with the original URL and headers to the error controller. That controller calls the Symfony HTML error renderer.

Phem decorates that renderer. It finds the matching error page (`Phem\Http\ErrorPages`), renders it without state (`Phem\Http\StatelessPageRenderer`), and keeps the error’s status and headers (`Phem\Http\ErrorPageRenderer`). Because the preview at `/_error/{code}`, `error:dump`, and `errorPage()` all go through the same renderer, they show the same page as a real failed request.
