# Error pages

When a request fails (a 404, a 500), Symfony renders an error page. An `#[ErrorPage]` function
renders this page with your components, in the document of your application. This page shows
the strategies, from one page for all the errors to static files for the web server.

| You need | Use |
|---|---|
| one page for all the errors | `#[ErrorPage]` |
| a page for one status, or for some statuses | `#[ErrorPage(404)]`, `#[ErrorPage([401, 403])]` |
| other pages for a section of the application | `#[ErrorPage(path: '/admin')]` |
| the status, the headers, the URL that failed | the parameters `int $status`, `FlattenException $exception`, `Request $request` |
| to see an error page in development | the preview of Symfony: `/_error/404`, `/_error/404?path=/admin` |
| the error page in the e2e tests | `phem.show_exceptions: false` |
| a test of an error page | `errorPage()` in a `KernelTestCase` |
| an error page when PHP does not answer | `bin/console error:dump` |

An error page is for the failed requests. For a part of the page that fails, use an
[`<ErrorBoundary>`](server-features.md#errors-in-a-part-of-the-page). For a failed event, see
[Failed events](errors-and-limits.md#failed-events).

## One page for all the errors

Add `#[ErrorPage]` to a function in a `.phem` file. The function needs no `#[Component]`, as a
function with `#[Page]`. It usually renders the document of the application:

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
        <Document>
            <Document:title>{{ title }}</Document:title>
            <main>
                <h1>{{ title }}</h1>
                <a href={{ path('home') }}>Back to the home page</a>
            </main>
        </Document>
    );
}
```

Run `phemc build`. A URL with no route now gets this page, with the status of the error:

```
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

The root element has no `data-phem-state-value`: an error page has no state (see
[The rules of an error page](#the-rules-of-an-error-page)).

## A page for each status

Give the status, or a list of statuses. A page with no status is for all the other statuses:

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

A status is from 400 to 599. The constants of Symfony work too:
`#[ErrorPage(Response::HTTP_NOT_FOUND)]`.

## A page for each section of the application

`path` gives a section of the application its own error pages. The request path is `path`, or
it starts with `path/`: `/admin` matches `/admin` and `/admin/users`, but not
`/administration`. The attribute is repeatable, so one function can serve two sections:

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

Phem takes the page of the longest path that matches. Then a page with the status wins over a
page for all the statuses. With the pages of this guide:

| Request | Status | Page |
|---|---|---|
| `GET /missing` | 404 | `NotFoundPage` |
| `GET /cart` (a bug) | 500 | `ErrorPage` |
| `GET /admin/missing` | 404 | `AdminErrorPage` (its path is longer) |
| `GET /admin/orders/9` (a bug) | 500 | `AdminErrorPage` |
| `GET /administration` | 404 | `NotFoundPage` (a path is a full segment) |

Phem checks the attributes when the container builds: after each `phemc build` in the debug
mode, and in `cache:warmup`. A status out of 400–599, a path with no `/` at the start, an
unknown argument, or two pages for the same status at the same path stop the build:

```
"App\Ui\Errors\NotFoundPage" and "App\Ui\Errors\MissingPage" are both the error page of the
status 404 at "/". Remove one #[ErrorPage], or give it another status or path.
```

When the container did not check them, Phem skips an attribute that is not valid: the other
error pages still show.

## The arguments of an error page

An error page gets the arguments of a function page: the Request, the services, `#[Autowire]`,
`#[CurrentUser]` and the other arguments of a controller. It also gets two values by name:

| Parameter | Value |
|---|---|
| `int $status` | the HTTP status, after the mapping of Symfony (`framework.exceptions`, `#[WithHttpStatus]`) |
| `FlattenException $exception` | the error: `getStatusText()`, `getHeaders()` (`Retry-After`, `Allow`), `getClass()` |
| `\Throwable $exception` | the exception itself, for a parameter with the type of an exception (`HttpExceptionInterface`, `\Throwable`) |

```php
use Symfony\Component\ErrorHandler\Exception\FlattenException;

#[ErrorPage(429)]
function SlowDownPage(FlattenException $exception): Node
{
    $seconds = $exception->getHeaders()['Retry-After'] ?? 60;

    return <Document>…<p>Try again in {{ seconds }} seconds.</p>…</Document>;
}
```

The response keeps the headers of the exception: a 429 gets its `Retry-After`, a 405 its
`Allow`. Do not show `$exception->getMessage()` to the user: it can have private data.

The attributes of a controller (`#[IsGranted]`, `#[Cache]`) do not apply to an error page: it
has no route.

## The rules of an error page

- An error page is static HTML. It has no state and no handlers: its URL is the URL that
  failed, so an event cannot work there. Its links, its HTML forms and its Stimulus controllers
  (a toast) work. Its `<Suspense>` boundaries render in the page, with no fallback and no
  stream. phemc reports `#[Broadcast]` on an error page (E053).
- An error page can render the document of the application (with its `<Root>`) or a plain
  HTML document: `<html><body><h1>Down</h1></body></html>`. A plain document does not need the
  services of the document component, for example for a 500 when the database is down.
- On a URL with no route, the firewall of Symfony did not run. The page has no user:
  `app.user` is null, `is_granted()` is false, and `logout_path()` throws. On a 404 or a 403
  from a page, the page has the user.
- A 500 can come from the database. Do not read the database in an error page, also not in the
  document component (for example the alerts of a signed-in user).
- A Turbo visit to a URL that fails shows the error page: Turbo replaces the head and the body.
- Only the application declares error pages: phemc reports `#[ErrorPage]` in a package
  (E060). A package does not know the URL of its pages in the application. To use the error
  page of a package, render its component in an error page of the application.

## When the error page does not show

Phem gives the error to the next renderer of Symfony in these cases:

- No `#[ErrorPage]` matches the status and the path. The order is: the Phem error page, the
  [Twig template](https://symfony.com/doc/current/controller/error_pages.html)
  (`templates/bundles/TwigBundle/Exception/error404.html.twig`), the page of Symfony.
- The request is a Phem event. The browser does not show the body of a failed event: it
  dispatches `phem:error` (see [Failed events](errors-and-limits.md#failed-events)).
- `phem.show_exceptions` is true: the page of the exception shows (see below).
- The error page throws. In the debug mode, the exception goes on, so you see the bug of the
  page. In production, Phem logs the exception and gives the page of Symfony: an error page
  must not fail.

## The debug mode and the preview

In the debug mode, Symfony shows the page of the exception, with its trace. The option
`phem.show_exceptions` decides it for the error pages. Its default is the debug mode of the
kernel (`%kernel.debug%`):

```yaml
# config/packages/phem.yaml
when@test:
    phem:
        # The e2e tests (Playwright, Behat) see the error pages of the application.
        show_exceptions: false
```

An environment variable works too: `show_exceptions: '%env(bool:SHOW_EXCEPTIONS)%'`.

To see an error page in development, open the preview of Symfony: `/_error/404`, `/_error/500`.
The preview always shows the error page, also in the debug mode. The preview has the URL of its
own route, so give the path of a section with the `path` parameter: `/_error/404?path=/admin`
shows the 404 page of `/admin`. The Symfony Flex recipe of FrameworkBundle adds the route:

```yaml
# config/routes/framework.yaml
when@dev:
    _errors:
        resource: '@FrameworkBundle/Resources/config/routing/errors.php'
        prefix: /_error
```

The preview has the user of the session. A real 404 on a URL with no route has no user.

## Test an error page

In a `KernelTestCase`, `errorPage()` renders the error page of an error at a URL. It works as
the preview, so the debug mode of the test does not show the exception. An `int` is the status
of an `HttpException`:

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

With no error page for the status and the URL, `errorPage()` gives the page of Symfony. The
request of `errorPage()` has a session, as the request of a browser. An error page has no
events, so the actions of the tester (`click()`, `fill()`) do not work.

With `show_exceptions: false` in the `test` environment (see
[The debug mode and the preview](#the-debug-mode-and-the-preview)), `visit()` of a URL that fails
also shows the error page, and so do the e2e tests through a web server or a real browser:

```php
$this->visit('/admin/missing')->assertStatus(404)->assertSee('Back to the dashboard');
```

## Static pages for the web server

When PHP does not answer (a crash, a deploy), the web server or the proxy shows its own error.
Symfony writes the error pages to static files with `error:dump`. The command renders the
`#[ErrorPage]` functions. Run it with the debug mode off, or it writes the page of the
exception:

```sh
APP_ENV=prod APP_DEBUG=0 php bin/console error:dump public/errors 502 503 504 --force
```

The command has no request: Phem renders each page for a GET of `/`, at the scheme and the host
of the router. Set them with `framework.router.default_uri` (for example
`https://shop.example`), or the request has the host `localhost`. Only the pages with no `path`
(or `path: '/'`) go in the files. The page must not need a value of a real request.

Tell the web server or the proxy to show the files. They show only for the errors that the
server makes itself: a 500 of the application still shows its own error page.

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

In a Docker image, run the command after `cache:warmup` (see [Deployment](deployment.md)).

## How it works

1. A controller, the router or the firewall throws an exception. `HttpKernel` dispatches
   `kernel.exception`.
2. The `ErrorListener` of Symfony maps the status (`framework.exceptions`, `#[WithHttpStatus]`)
   and logs the exception.
3. The `ErrorListener` sends a sub-request to the error controller. The sub-request is a GET
   with the URL and the headers of the request. It has no route values.
4. The error controller calls the HTML error renderer of Symfony (`error_renderer.html`).
5. Phem decorates this renderer, outside the Twig renderer. It finds the error page
   (`Phem\Http\ErrorPages`), renders it with no state (`Phem\Http\StatelessPageRenderer`),
   and keeps the status and the headers of the error (`Phem\Http\ErrorPageRenderer`).

The preview at `/_error/{code}`, `error:dump` and `errorPage()` use the same renderer. phemc
writes the error pages of the application in `error_pages` of `manifest.php`. Phem reads the
statuses and the paths of their attributes once, and `Phem\Http\ErrorPagesWarmer` checks them
when the container builds.

phemc reports these errors:

| Code | Error |
|---|---|
| E053 | `#[Broadcast]` on an error page: an error page has no connection to the Mercure hub |
| E056 | an error page used as a tag (`<NotFoundPage />`): an error page is not a component of other pages |
| E059 | `#[ErrorPage]` on a class: an error page has no state, so it is a function |
| E060 | `#[ErrorPage]` in a package: only the application has error pages |
| W004 | `#[ErrorPage]` without `use Phem\Attribute\ErrorPage;` |
