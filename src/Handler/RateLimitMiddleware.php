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

namespace Phem\Handler;

use Phem\Event;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Limits the events of one handler with the `#[RateLimit]` attribute of Symfony 8.1 on its
 * method (`#[RateLimit('phem_search')] public function search(): void`).
 *
 * The handler id is in the JSON body of an event, so the `#[RateLimit]` of a page cannot tell
 * the handlers apart (see PageAttributesListener). This middleware reads the attribute of the
 * handler method, and the default key has the class and the method of the handler: the client
 * IP, the method and the path of the request, and `Class::method`. Over the limit, it throws a
 * TooManyRequestsHttpException (429 with Retry-After), and the handler does not run.
 *
 * A closure in `render()` has no method, so it has no limit of its own. A Closure or an
 * Expression key gets the arguments of the call in the markup (see HandlerContext).
 */
final class RateLimitMiddleware implements HandlerCallMiddleware
{
    /**
     * @param ServiceProviderInterface<RateLimiterFactoryInterface> $limiters
     */
    public function __construct(
        private readonly ServiceProviderInterface $limiters,
        private readonly ?ExpressionLanguage $expressionLanguage = null,
        private readonly ?RequestStack $requests = null,
        private readonly HandlerAttributes $attributes = new HandlerAttributes(),
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        return $this->handleCall($handler, [], $event, $next);
    }

    public function handleCall(\Closure $handler, array $arguments, Event $event, \Closure $next): mixed
    {
        $function = new \ReflectionFunction($handler);
        $class = $function->getClosureScopeClass();
        if (null === $class || !$class->hasMethod($function->getName())) {
            return $next();
        }

        $method = $class->getMethod($function->getName());
        $context = HandlerContext::of($handler, $arguments, $this->requests?->getCurrentRequest());

        foreach ($this->attributes->of($handler, RateLimit::class) as $attribute) {
            if (null !== $context->request && $attribute->methods && !\in_array($context->request->getMethod(), $attribute->methods, true)) {
                continue;
            }

            $this->consume($attribute, $method, $context);
        }

        return $next();
    }

    private function consume(RateLimit $attribute, \ReflectionMethod $method, HandlerContext $context): void
    {
        $request = $context->request;

        if (!$this->limiters->has($attribute->limiter)) {
            throw new \InvalidArgumentException(\sprintf('Rate limiter "%s" does not exist. Did you forget to configure it? Available limiters: "%s".', $attribute->limiter, implode('", "', array_keys($this->limiters->getProvidedServices()))));
        }

        $key = match (true) {
            null === $attribute->key => ($request?->getClientIp() ?? 'unknown').'~'.($request?->getMethod() ?? 'POST').'~'.($request?->getPathInfo() ?? '').'~'.$method->class.'::'.$method->name,
            // As the RateLimitAttributeListener of Symfony: the controller is the component, and
            // the arguments are the arguments of the call in the markup.
            $attribute->key instanceof \Closure, $attribute->key instanceof Expression => $context->evaluate($attribute->key, $this->expressionLanguage, 'a #[RateLimit] key'),
            default => $attribute->key,
        };

        if (!\is_string($key)) {
            throw new \TypeError(\sprintf('The value of the "$key" option of the "%s" attribute must evaluate to a string, "%s" given.', RateLimit::class, get_debug_type($key)));
        }

        $limit = $this->limiters->get($attribute->limiter)->create($key)->consume($attribute->tokens);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(0, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
