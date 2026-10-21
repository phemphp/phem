<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem\Stream;

use Phem\Dom\Attributes;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The connection of a page with #[Subscribe] to the Mercure hub.
 *
 * The root element of the page gets `data-phem-stream-value`: the URL of the hub with a `match`
 * parameter for each topic of the page (see Topics). The `phem` Stimulus controller opens one
 * EventSource to it.
 *
 * With a subscriber key, the updates are private, and the response sets the cookie of the hub
 * with an access token for the topics (see SubscriberToken). The page passed its access rules,
 * so the viewer can see what the server renders for its topics: they expire in the time of a
 * token (see SubscriberToken::grant()). A browser has one cookie for all its tabs: the token also
 * keeps the topics of a valid cookie of the request (the newest first, up to MAX_TOPICS), so an
 * older tab can connect again. A kept topic keeps its expiry: it did not pass the access rules in
 * this request (the viewer can have lost access to its page since), so only an event of its page
 * extends it. A kept topic that lived half of its time goes, so the token lives at least that
 * long. The subject of a token is a hash of the session id: after a logout or a login (a new
 * session), the topics of the old cookie are not kept. An event renews the token when it lived
 * half of its time, or when it lacks a topic.
 * With no key, the updates are public: the hub needs the `anonymous` directive.
 *
 * The audience of the token is the URL of the hub that the browser connects to. A relative hub
 * URL takes the scheme and the host of the request: behind a proxy that ends TLS, configure the
 * trusted proxies of Symfony, or give an absolute hub URL.
 */
final class Subscription
{
    /** The request attribute with the topics of the page, for the cookie (see cookie()). */
    public const ATTRIBUTE = '_phem_topics';

    private const MAX_TOPICS = 30;

    public function __construct(
        private readonly Topics $topics,
        private readonly ?SubscriberToken $tokens = null,
        private readonly string $hub = '/.well-known/mercure',
        private readonly string $cookie = '__Secure-mercure_access_token',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Whether the updates are private (see the class). */
    public function isPrivate(): bool
    {
        return null !== $this->tokens;
    }

    /**
     * The `data-phem-stream-value` attribute of the root element of a page, or '' for a page
     * with no #[Subscribe]. The response gets the cookie of the topics (see cookie()).
     *
     * @param array<string, mixed> $values the #[Path] route values of the page
     */
    public function attribute(string $page, array $values, Request $request): string
    {
        $topics = $this->renew($page, $values, $request);
        if ([] === $topics) {
            return '';
        }
        $query = implode('&', array_map(static fn (string $topic): string => 'match='.rawurlencode($topic), $topics));

        return (string) Attributes::attr('data-phem-stream-value', $this->hub.(str_contains($this->hub, '?') ? '&' : '?').$query);
    }

    /**
     * Asks for the cookie of the topics of a page in the response of the request (an event
     * renews it). Returns the topics, or none for a page with no #[Subscribe].
     *
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    public function renew(string $page, array $values, Request $request): array
    {
        $topics = $this->topics->ofPage($page, $values);
        if ([] !== $topics) {
            $request->attributes->set(self::ATTRIBUTE, $topics);
        }

        return $topics;
    }

    /**
     * The cookie for the response of a request that renew() saw, or null (no page with
     * #[Subscribe], or public updates).
     */
    public function cookie(Request $request): ?Cookie
    {
        $topics = $request->attributes->get(self::ATTRIBUTE);
        if (null === $this->tokens || !\is_array($topics) || [] === $topics) {
            return null;
        }
        $topics = array_values(array_filter($topics, \is_string(...)));
        $audience = $this->audience($request);
        $subject = self::subject($request);
        $old = $request->cookies->get($this->cookie);
        $kept = \is_string($old) ? $this->tokens->grants($old, $audience, $subject) : [];
        if (\is_string($old) && [] === array_diff($topics, array_keys($kept)) && $this->tokens->isFresh($old, $audience, $subject)) {
            return null;
        }
        $grants = $this->tokens->grant($topics) + array_filter($kept, $this->tokens->isFreshExpiry(...));
        $path = (string) parse_url($this->hub, \PHP_URL_PATH);
        // A browser keeps a Secure cookie only from a secure origin: HTTPS, or localhost.
        $secure = $request->isSecure() || \in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($request->getHost(), '.localhost');
        if (!$secure && (str_starts_with($this->cookie, '__Secure-') || str_starts_with($this->cookie, '__Host-'))) {
            $this->logger?->warning('The browser rejects the cookie "{cookie}" of the Mercure hub on "{host}" (plain HTTP): set phem.mercure.cookie_name and the `cookie_name` of the hub to a name with no "__Secure-" prefix, or use HTTPS.', ['cookie' => $this->cookie, 'host' => $request->getHost()]);
        }

        return Cookie::create($this->cookie)
            ->withValue($this->tokens->create(\array_slice($grants, 0, self::MAX_TOPICS, true), $audience, $subject))
            ->withPath('' !== $path ? $path : '/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    /**
     * The subject of a token: a hash of the session id, or '' with no session. The id of a
     * session that started in this request wins (a login migrates it).
     */
    private static function subject(Request $request): string
    {
        if (!$request->hasSession()) {
            return '';
        }
        $session = $request->getSession();
        $id = $session->isStarted() ? $session->getId() : $request->cookies->get($session->getName());

        return \is_string($id) && '' !== $id ? hash('xxh128', $id) : '';
    }

    /** The URL of the hub as the browser connects to it: the audience of the token. */
    private function audience(Request $request): string
    {
        $url = strtok($this->hub, '?');
        $url = false !== $url ? $url : $this->hub;

        return str_starts_with($url, '/') ? $request->getSchemeAndHttpHost().$url : $url;
    }
}
