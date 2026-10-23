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
use Symfony\Component\HttpFoundation\Request;
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
 * A closure in `render()` has no method, so it has no limit of its own.
 */
final class RateLimitMiddleware implements HandlerMiddleware
{
    /** @var array<string, list<RateLimit>> */
    private array $attributes = [];

    /**
     * @param ServiceProviderInterface<RateLimiterFactoryInterface> $limiters
     */
    public function __construct(
        private readonly ServiceProviderInterface $limiters,
        private readonly ?ExpressionLanguage $expressionLanguage = null,
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $function = new \ReflectionFunction($handler);
        $class = $function->getClosureScopeClass();
        if (null === $class || !$class->hasMethod($function->getName())) {
            return $next();
        }

        $method = $class->getMethod($function->getName());
        $request = $event->request;

        foreach ($this->attributesOf($method) as $attribute) {
            if (null !== $request && $attribute->methods && !\in_array($request->getMethod(), $attribute->methods, true)) {
                continue;
            }

            $this->consume($attribute, $method, $function->getClosureThis(), $request);
        }

        return $next();
    }

    private function consume(RateLimit $attribute, \ReflectionMethod $method, ?object $component, ?Request $request): void
    {
        if (!$this->limiters->has($attribute->limiter)) {
            throw new \InvalidArgumentException(\sprintf('Rate limiter "%s" does not exist. Did you forget to configure it? Available limiters: "%s".', $attribute->limiter, implode('", "', array_keys($this->limiters->getProvidedServices()))));
        }

        $key = match (true) {
            null === $attribute->key => ($request?->getClientIp() ?? 'unknown').'~'.($request?->getMethod() ?? 'POST').'~'.($request?->getPathInfo() ?? '').'~'.$method->class.'::'.$method->name,
            // As the RateLimitAttributeListener of Symfony: the controller is the component, and a handler has no arguments.
            $attribute->key instanceof \Closure => ($attribute->key)([], $request, $component),
            $attribute->key instanceof Expression => ($this->expressionLanguage ?? throw new \LogicException('Cannot evaluate the Expression of a #[RateLimit] key since no ExpressionLanguage service was configured.'))
                ->evaluate($attribute->key, ['request' => $request, 'args' => [], 'this' => $component]),
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

    /** @return list<RateLimit> */
    private function attributesOf(\ReflectionMethod $method): array
    {
        return $this->attributes[$method->class.'::'.$method->name] ??= array_map(
            static fn (\ReflectionAttribute $attribute): RateLimit => $attribute->newInstance(),
            $method->getAttributes(RateLimit::class),
        );
    }
}
