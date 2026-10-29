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

namespace Phem\DependencyInjection;

use Phem\Event;
use Phem\Manifest\ComponentKind;
use Phem\Manifest\Manifest;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\TypedReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Registers each `#[Component]` class of the phemc manifest as a service: autowired,
 * autoconfigured, and not shared (each component instance is a new object). The
 * ContainerComponentFactory gets them through a service locator.
 *
 * An application can define a component service itself (for example to bind an argument);
 * the pass keeps that definition and only makes it not shared.
 *
 * The methods of a class component get services as arguments, as the actions of a controller:
 * the `controller.service_arguments` tag makes a Symfony locator for each public method (see
 * Phem\Argument\CallableArguments). A function page gets a locator of its parameters too (see
 * Phem\Argument\ServiceValueResolver).
 *
 * In debug mode, the container is rebuilt when `phemc build` writes a new manifest.
 */
final class ComponentPass implements CompilerPassInterface
{
    public const TAG = 'phem.component';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('phem.component_factory')) {
            return;
        }

        $buildDir = $container->getParameterBag()->resolveValue($container->getParameter('phem.build_dir'));
        $manifestFile = $buildDir.'/manifest.php';
        $container->addResource(new FileExistenceResource($manifestFile));

        $components = [];
        $pages = [];
        $manifest = new Manifest($buildDir);
        if ($manifest->exists()) {
            $container->addResource(new FileResource($manifestFile));

            // The manifest also loads the classes: they must exist for autowiring. They are not PSR-4 classes.
            foreach ($manifest->components() as $def) {
                if (ComponentKind::ClassComponent !== $def->kind) {
                    continue;
                }

                $fqcn = $def->fqcn;
                $definition = $container->hasDefinition($fqcn)
                    ? $container->getDefinition($fqcn)
                    : $container->register($fqcn, $fqcn)->setAutowired(true)->setAutoconfigured(true);
                $definition->setShared(false)->addTag(self::TAG);
                if (!$definition->hasTag('controller.service_arguments')) {
                    $definition->addTag('controller.service_arguments');
                }

                $components[$fqcn] = new Reference($fqcn);
            }

            foreach ($manifest->pages() as $page) {
                if (!\function_exists($page)) {
                    continue;
                }

                $services = self::services($container, new \ReflectionFunction($page));
                if ([] !== $services) {
                    $pages[$page] = ServiceLocatorTagPass::register($container, $services, $page.'()');
                }
            }
        }

        $container->getDefinition('phem.component_factory')->replaceArgument(0, ServiceLocatorTagPass::register($container, $components));

        if ($container->hasDefinition('phem.argument_resolver.service')) {
            $container->getDefinition('phem.argument_resolver.service')->replaceArgument(0, ServiceLocatorTagPass::register($container, $pages));
        }
    }

    /**
     * The services of the parameters of a function page, as RegisterControllerArgumentLocatorsPass
     * makes them for a controller method: a parameter with a class or interface type, or with
     * `#[Autowire]` (a service, a parameter or an expression). `#[Target]` picks a named service. The other value resolvers give the
     * Request, the session and the event.
     *
     * @return array<string, Reference>
     */
    private static function services(ContainerBuilder $container, \ReflectionFunction $function): array
    {
        $services = [];
        foreach ($function->getParameters() as $p) {
            $type = $p->getType();
            $autowire = $p->getAttributes(Autowire::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

            if (null !== $autowire) {
                $value = $container->getParameterBag()->resolveValue($autowire->newInstance()->value);
                if ($value instanceof Reference) {
                    $services[$p->name] = new Reference((string) $value, $p->allowsNull() ? ContainerInterface::NULL_ON_INVALID_REFERENCE : ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE);
                    continue;
                }

                // A parameter or an expression: a service that returns the value, as for a controller.
                $services[$p->name] = new Reference($id = '.value.'.$container->hash($value));
                $container->register($id, 'mixed')->setFactory('current')->addArgument([$value]);

                continue;
            }

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || \in_array($type->getName(), [Request::class, SessionInterface::class, Response::class, Event::class], true) || is_subclass_of($type->getName(), \UnitEnum::class)) {
                continue;
            }

            $invalid = $p->allowsNull() ? ContainerInterface::IGNORE_ON_INVALID_REFERENCE : ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE;
            $target = null;
            $name = Target::parseName($p, $target);
            $services[$p->name] = new TypedReference($type->getName(), $type->getName(), $invalid, $name, null !== $target ? [$target] : []);
        }

        return $services;
    }
}
