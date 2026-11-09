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

namespace Phem\Twig;

use Twig\Compiler;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;

/**
 * `phem_constant('App\\OrderStatus::Cancelled')`: phemc compiles `OrderStatus.Cancelled` in
 * markup to it. Twig compiles it to the PHP constant (`\App\OrderStatus::Cancelled`), so a case
 * or a constant that does not exist is an error when the template compiles: at the cache warmup,
 * not when a page renders. phemc checks only the enums of the `.phem` files.
 *
 * Twig checks the freshness of the template, not of the class. In debug mode, the template reads
 * the constant when it renders (`read()`), so a case that a change of the class removes gives the
 * same error, not a PHP error on a stale template.
 */
final class ConstantFunction extends FunctionExpression
{
    public function compile(Compiler $compiler): void
    {
        $arguments = $this->getNode('arguments');
        $first = $arguments->hasNode('0') ? $arguments->getNode('0') : null;
        $name = $first instanceof ConstantExpression ? $first->getAttribute('value') : null;

        if (1 !== \count($arguments) || !\is_string($name) || !str_contains($name, '::')) {
            parent::compile($compiler);

            return;
        }

        $name = ltrim($name, '\\');
        [$class, $member] = explode('::', $name, 2);

        if (!class_exists($class) && !interface_exists($class)) {
            throw new SyntaxError(\sprintf('The markup reads "%s", but the class "%s" does not exist. Check the "use" import of the class.', str_replace('::', '.', $name), $class), $this->getTemplateLine(), $this->getSourceContext());
        }

        if (!\defined($name)) {
            throw new SyntaxError(self::missing($class, $member), $this->getTemplateLine(), $this->getSourceContext());
        }

        if ($compiler->getEnvironment()->isDebug()) {
            $compiler->raw('\\'.self::class.'::read(')->string($name)->raw(')');

            return;
        }

        $compiler->raw('\\'.$name);
    }

    /** The constant, in debug mode: the class can change after the template compiles. */
    public static function read(string $name): mixed
    {
        if (!\defined($name)) {
            [$class, $member] = explode('::', $name, 2);

            throw new RuntimeError(self::missing($class, $member));
        }

        return \constant($name);
    }

    private static function missing(string $class, string $member): string
    {
        return \sprintf('The class "%s" has no case or constant "%s".', $class, $member);
    }
}
