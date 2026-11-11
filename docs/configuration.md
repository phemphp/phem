# Configuration reference

Phem works with no config file: the defaults match the layout of the Flex recipe (see [Install and configure Phem](installation.md)). This page lists all the options of the `phem` key, with their defaults. Put the options that you change in `config/packages/phem.yaml`.

## The full tree

```yaml
# config/packages/phem.yaml
phem:
    build_dir: '%kernel.project_dir%/var/phem'
    secret: '%kernel.secret%'
    state_max_age: 86400
    state_bind_session: false
    state_cache: true
    state_cache_min_size: 4096
    max_event_size: 1048576
    max_upload_size: 8388608
    upload_chunk_size: 2097152
    upload_dir: '%kernel.project_dir%/var/phem_uploads'
    upload_dir_max_size: 1073741824
    suspense_stream: true
    show_exceptions: null
    class_merger:
        tailwind:
            enabled: false
            prefix: null
            cache: null
            css: null
            theme: {}
        service: null
    mercure:
        hub_url: '/.well-known/mercure'
        subscriber_key: null
        issuer: phem
        cookie_name: '__Secure-mercure_access_token'
        publisher_key: null
        publish_url: null
```

## Build and state

| Option | Default | What it does |
|---|---|---|
| `build_dir` | `%kernel.project_dir%/var/phem` | The output directory of `phemc`. It must be the `--out` of `phemc build`. See [Build directory configuration](compiler.md#build-directory-configuration) |
| `secret` | `%kernel.secret%` | The key that signs the state, the Mercure topics, the broadcast keys, the feed items and the `#[Async]` messages. See [Build for production](deployment.md#build-for-production) |
| `state_max_age` | `86400` | The seconds that a signed state is valid. `0` is no limit. An older state reloads the page. See [The page reloads after an event](failed-events.md#the-page-reloads-after-an-event) |
| `state_bind_session` | `false` | Signs the session id with the state, so the state of a session isn’t valid in another session. See [Keep security decisions out of the state](security.md#keep-security-decisions-out-of-the-state) |
| `state_cache` | `true` | Keeps each signed state in the `cache.phem.state` pool, so an event can send only its signature. See [An event makes two requests](failed-events.md#an-event-makes-two-requests) |
| `state_cache_min_size` | `4096` | The bytes of the smallest state that the pool keeps. `0` keeps all the states |

## Events and rendering

| Option | Default | What it does |
|---|---|---|
| `max_event_size` | `1048576` | The maximum size of an event body in bytes, with the state of the page. `0` is no limit. A larger event gets a `413`. See [An event fails with a 413](failed-events.md#an-event-fails-with-a-413) |
| `max_upload_size` | `8388608` | The maximum size of the files of one event in bytes, together. `0` is no limit. The files of `data-phem-upload` count too. See [Accept file uploads](forms.md#accept-file-uploads) |
| `upload_chunk_size` | `2097152` | The size in bytes of a chunk of a file with `data-phem-upload="chunked"`. Keep it under the `post_max_size` option of PHP. See [Upload a large file in chunks](forms.md#upload-a-large-file-in-chunks) |
| `upload_dir` | `'%kernel.project_dir%/var/phem_uploads'` | The directory of the chunked uploads until their event. All the servers of the app must share it |
| `upload_dir_max_size` | `1073741824` | The maximum size in bytes of the chunked uploads in `upload_dir` together. `0` is no limit. A new upload over the limit gets a `507` |
| `suspense_stream` | `true` | Streams the `<Suspense>` boundaries that aren’t lazy in the response of the page. With `false`, the browser loads them with an event after the page. See [Load slow content after the page](resilience.md#load-slow-content-after-the-page) |
| `show_exceptions` | `null` | Shows the Symfony exception page for an HTTP error, not the `#[ErrorPage]` of the app. `null` is the debug mode of the kernel. See [Preview an error page in debug mode](error-pages.md#preview-an-error-page-in-debug-mode) |

## Class merger

The `class_merger` options set how `classes()` merges the classes of the caller. By default, it removes the duplicates only. See [Style components](styling.md).

| Option | Default | What it does |
|---|---|---|
| `class_merger.tailwind` | off | Turns on the Tailwind CSS v4 merger. It needs `tales-from-a-dev/tailwind-merge-php` |
| `class_merger.tailwind.prefix` | `null` | The prefix of the Tailwind classes, with no colon (`tw`) |
| `class_merger.tailwind.cache` | `null` | The id of a PSR-16 cache service for the merged classes |
| `class_merger.tailwind.css` | `null` | The Tailwind entry CSS. Phem reads the custom names of its `@theme` blocks when the container builds. The file must exist |
| `class_merger.tailwind.theme` | `{}` | Custom theme names by theme key (`text: [caption, price-lg]`). They replace the names that Phem reads from the `css` file |
| `class_merger.service` | `null` | The id of a `Phem\Dom\ClassMerger` service of the app. Set this or `tailwind`, not both |

The valid keys of `theme` are `animate`, `aspect`, `blur`, `breakpoint`, `color`, `container`, `drop-shadow`, `ease`, `font`, `font-weight`, `inset-shadow`, `leading`, `perspective`, `radius`, `shadow`, `spacing`, `text`, `text-shadow` and `tracking`. Another key fails the container build.

## Mercure

The `mercure` options set the hub that delivers the updates of the pages with `#[Broadcast]`. See [Push updates to other browsers](real-time.md#set-up-the-mercure-hub-in-frankenphp).

| Option | Default | What it does |
|---|---|---|
| `mercure.hub_url` | `/.well-known/mercure` | The URL of the hub, as the browser connects to it. It is also the audience of the access token. Give an absolute URL for a hub on another host |
| `mercure.subscriber_key` | `null` | The subscriber key of the hub. With a key, the updates are private. With `null`, they are public, and the hub needs the `anonymous` directive |
| `mercure.issuer` | `phem` | The name of the `issuer` block of the hub in the Caddyfile (`issuer phem { … }`). The two names must be the same |
| `mercure.cookie_name` | `__Secure-mercure_access_token` | The cookie of the access token. It must be the `cookie_name` of the hub |
| `mercure.publisher_key` | `null` | The publisher key of the hub. With a key, a process with no FrankenPHP request (PHP-FPM, `symfony serve`, a command, a Messenger worker) publishes with the HTTP API of the hub. It needs `publish_url` and `symfony/http-client` |
| `mercure.publish_url` | `null` | The URL of the hub for the server, for example `http://mercure/.well-known/mercure`. See [Use a standalone Mercure hub](real-time.md#use-a-standalone-mercure-hub) |
