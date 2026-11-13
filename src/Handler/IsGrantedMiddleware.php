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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Checks the `#[IsGranted]` attribute of Symfony on an event handler, as on a controller
 * method: on the handler method (`#[IsGranted('ROLE_EDITOR')] public function delete()`), on a
 * closure in `render()`, and on the class of a component for all its handlers. The attribute of
 * the class and the attribute of the handler both apply.
 *
 * The `#[IsGranted]` on the class of the page of the request is a rule of the page: it applies
 * to the page request and to its events (see PageAttributesListener), not here. A component test
 * of a page sets the page of its request, so it doesn't apply there, as the other access rules
 * of a page.
 *
 * When the access is denied, it throws an AccessDeniedException (the firewall gives a 403, or the
 * login), or an HttpException with the `statusCode` of the attribute, and the handler does not
 * run.
 *
 * As for a controller, `subject: 'item'` is the argument `$item` of the call in the markup
 * (`onClick={{ delete(item) }}`). The other arguments come after the middlewares, so a subject
 * that names another parameter of the handler is an error. A subject that names no parameter is
 * the property of the component with this name. A Closure and an
 * Expression get the arguments of the call (see HandlerContext).
 */
final class IsGrantedMiddleware implements HandlerCallMiddleware
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
        return $this->handleCall($handler, [], $event, $next);
    }

    public function handleCall(\Closure $handler, array $arguments, Event $event, \Closure $next): mixed
    {
        $context = HandlerContext::of($handler, $arguments, $this->requests?->getCurrentRequest());
        $attributes = [...$this->classAttributes($handler, $context), ...$this->attributes->of($handler, IsGranted::class)];

        foreach ($attributes as $attribute) {
            if (null !== $context->request && $attribute->methods && !\in_array($context->request->getMethod(), array_map(strtoupper(...), $attribute->methods), true)) {
                continue;
            }

            $this->check($attribute, $context, $handler);
        }

        return $next();
    }

    /**
     * The `#[IsGranted]` of the class of the component, not of the page of the request:
     * PageAttributesListener checks it. A component with a route can be a tag in another page,
     * so only the page of the request is skipped.
     *
     * The class is the class of the component, as the class of a controller for Symfony, also
     * for a handler method of a parent class. A static closure has no component: its class is
     * the class that declares it.
     *
     * @return list<IsGranted>
     */
    private function classAttributes(\Closure $handler, HandlerContext $context): array
    {
        $class = null !== $context->component ? $context->component::class : new \ReflectionFunction($handler)->getClosureScopeClass()?->name;
        if (null === $class || $class === $context->request?->attributes->get('_phem_page')) {
            return [];
        }

        return $this->classes[$class] ??= array_map(
            static fn (\ReflectionAttribute $a): IsGranted => $a->newInstance(),
            new \ReflectionClass($class)->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF),
        );
    }

    private function check(IsGranted $attribute, HandlerContext $context, \Closure $handler): void
    {
        $authorization = $this->authorization ?? throw new \LogicException('The #[IsGranted] attribute of an event handler needs the security of Symfony. Try running "composer require symfony/security-bundle".');

        $subject = null;
        if (\is_array($attribute->subject)) {
            foreach ($attribute->subject as $key => $reference) {
                $subject[\is_string($key) ? $key : (string) $reference] = $this->subject($reference, $context, $handler);
            }
        } elseif (null !== $attribute->subject) {
            $subject = $this->subject($attribute->subject, $context, $handler);
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

    private function subject(string|Expression|\Closure $reference, HandlerContext $context, \Closure $handler): mixed
    {
        if (!\is_string($reference)) {
            $language = $this->expressionLanguage ?? (class_exists(ExpressionLanguage::class) ? new ExpressionLanguage() : null);

            return $context->evaluate($reference, $language, 'an #[IsGranted] subject');
        }

        if (\array_key_exists($reference, $context->arguments)) {
            return $context->arguments[$reference];
        }

        // A parameter that the call doesn't give gets its value after the check: the property
        // with its name is another value, so the voter would check another object.
        foreach (new \ReflectionFunction($handler)->getParameters() as $parameter) {
            if ($parameter->getName() === $reference) {
                throw new \RuntimeException(\sprintf('The subject "%s" of the #[IsGranted] attribute of an event handler is a parameter that the call in the markup doesn\'t give. Pass it in the markup (`delete(%s)`): Phem checks the access before it resolves the other arguments.', $reference, $reference));
            }
        }

        $component = $context->component;
        if (null === $component || !property_exists($component, $reference)) {
            throw new \RuntimeException(\sprintf('Could not find the subject "%s" for the #[IsGranted] attribute of an event handler. Pass it to the handler in the markup (`delete(%s)` for a "$%s" parameter), or add a "$%s" property to %s.', $reference, $reference, $reference, $reference, null !== $component ? '"'.$component::class.'"' : 'the component'));
        }

        return \Closure::bind(fn (): mixed => $this->{$reference}, $component, $component::class)();
    }
}
