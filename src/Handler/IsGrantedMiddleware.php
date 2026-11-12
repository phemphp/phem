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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Checks the `#[IsGranted]` attribute of Symfony on an event handler, as on a controller
 * method: on the handler method (`#[IsGranted('ROLE_EDITOR')] public function delete()`), on a
 * closure in `render()`, and on the class of a component for all its handlers.
 *
 * The `#[IsGranted]` of a page applies to the page request and to its events (see
 * PageAttributesListener), so this middleware skips the class attributes of the page of the
 * request. When the access is denied, it throws an AccessDeniedException (the firewall gives a
 * 403, or the login), or an HttpException with the `statusCode` of the attribute, and the
 * handler does not run.
 *
 * The arguments of a handler come after the middlewares, so the subject comes from the
 * component: `subject: 'post'` is the `post` property of the component. A Closure gets
 * `([], $request, $component)`, and an Expression gets `request`, `args` (empty) and `this` (the
 * component), as for the RateLimitMiddleware.
 */
final class IsGrantedMiddleware implements HandlerMiddleware
{
    /** @var array<class-string, list<IsGranted>> */
    private array $classes = [];

    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?ExpressionLanguage $expressionLanguage = null,
        private readonly ?RequestStack $requests = null,
        private readonly HandlerAttributes $attributes = new HandlerAttributes(),
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $component = new \ReflectionFunction($handler)->getClosureThis();
        $request = $this->requests?->getCurrentRequest();

        $attributes = [...$this->classAttributes($component, $request), ...$this->attributes->of($handler, IsGranted::class)];
        foreach ($attributes as $attribute) {
            if (null !== $request && $attribute->methods && !\in_array($request->getMethod(), array_map(strtoupper(...), $attribute->methods), true)) {
                continue;
            }

            $this->check($attribute, $component, $request);
        }

        return $next();
    }

    /**
     * The `#[IsGranted]` of the class of the component, not of the page of the request.
     *
     * @return list<IsGranted>
     */
    private function classAttributes(?object $component, ?Request $request): array
    {
        if (null === $component || $component::class === $request?->attributes->get('_phem_page')) {
            return [];
        }

        return $this->classes[$component::class] ??= array_map(
            static fn (\ReflectionAttribute $a): IsGranted => $a->newInstance(),
            new \ReflectionClass($component)->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF),
        );
    }

    private function check(IsGranted $attribute, ?object $component, ?Request $request): void
    {
        $authorization = $this->authorization ?? throw new \LogicException('The #[IsGranted] attribute of an event handler needs the security of Symfony. Try running "composer require symfony/security-bundle".');

        $subject = null;
        if (\is_array($attribute->subject)) {
            foreach ($attribute->subject as $key => $reference) {
                $subject[\is_string($key) ? $key : (string) $reference] = $this->subject($reference, $component, $request);
            }
        } elseif (null !== $attribute->subject) {
            $subject = $this->subject($attribute->subject, $component, $request);
        }

        $decision = new AccessDecision();
        $decision->isGranted = $authorization->isGranted($attribute->attribute, $subject, $decision);
        if ($decision->isGranted) {
            return;
        }

        $message = $attribute->message ?: $decision->getMessage();
        if (null !== $attribute->statusCode) {
            throw new HttpException($attribute->statusCode, $message, code: $attribute->exceptionCode ?? 0);
        }

        $e = new AccessDeniedException($message, code: $attribute->exceptionCode ?? 403);
        $e->setAttributes([$attribute->attribute]);
        $e->setSubject($subject);
        $e->setAccessDecision($decision);

        throw $e;
    }

    private function subject(string|Expression|\Closure $reference, ?object $component, ?Request $request): mixed
    {
        if ($reference instanceof \Closure) {
            return $reference([], $request, $component);
        }

        if ($reference instanceof Expression) {
            $language = $this->expressionLanguage ?? (class_exists(ExpressionLanguage::class) ? new ExpressionLanguage() : throw new \LogicException('Cannot evaluate the Expression of an #[IsGranted] subject since no ExpressionLanguage service was configured.'));

            return $language->evaluate($reference, ['request' => $request, 'args' => [], 'this' => $component]);
        }

        if (null === $component || !property_exists($component, $reference)) {
            throw new \RuntimeException(\sprintf('Could not find the subject "%s" for the #[IsGranted] attribute of an event handler. The subject of a handler is a property of its component: add a "$%s" property to "%s".', $reference, $reference, null !== $component ? $component::class : 'the component'));
        }

        return \Closure::bind(fn (): mixed => $this->{$reference}, $component, $component::class)();
    }
}
