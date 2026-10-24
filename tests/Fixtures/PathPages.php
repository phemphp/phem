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

namespace Phem\Tests\Fixtures\PathPages;

use Phem\Attribute\Component;
use Phem\Attribute\Page;
use Phem\Attribute\Path;
use Phem\Attribute\Prop;
use Phem\Attribute\State;
use Phem\Tests\Fixtures\Scientist;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

// Pages for the tests of the #[Path] route values (plain PHP, not compiled by phemc).

#[Component]
#[Route('/known/{id}', defaults: ['id' => 1])]
final class Known
{
    #[Path] public int $id;
    #[Prop] public string $label = 'x';
}

#[Component]
#[Route('/unknown/{slug}')]
final class UnknownValue
{
    #[Path] public int $id = 0;
}

#[Component]
#[Route('/default', defaults: ['mode' => 'dark'])]
final class UnknownDefault
{
}

// #[Page] routes, with a controller attribute of Symfony.
#[Component]
#[Page('/paged', name: 'paged')]
#[Page(['en' => '/en/paged', 'es' => '/es/paged'], name: 'paged_i18n')]
#[Cache(maxage: 30, public: true)]
final class Paged
{
}

// A function page, with a controller attribute of Symfony.
#[Page('/function')]
#[Page('/function-too', name: 'function_too')]
#[Cache(maxage: 60, public: true)]
function FunctionPage(): void
{
}

// A function page has no route values.
#[Page('/function/{slug}')]
function FunctionValue(): void
{
}

#[Page('/function-default', defaults: ['mode' => 'dark', '_format' => 'html'])]
function FunctionDefault(): void
{
}

#[Component]
#[Route('/typed/{id}/{price}/{draft}/{slug}')]
final class Typed
{
    #[Path] public int $id = 0;
    #[Path] public float $price = 0.0;
    #[Path] public bool $draft = false;
    #[Path] public string $slug = '';
    #[Prop] public string $label = 'x';
}

#[Component]
#[Route('/without/{id}')]
final class WithoutPath
{
    #[Prop] public int $id = 0;
}

#[Component]
#[Route('/state/{id}')]
final class StatePath
{
    #[Path, State] public int $id = 0;
}

#[Component]
#[Route('/scientists/{id:scientist}')]
final class EntityPath
{
    #[Path] public Scientist $scientist;
}

#[Component]
#[Route('/maybe/{scientist}')]
final class NullableEntity
{
    #[Path] public ?Scientist $scientist = null;
}

#[Component]
#[Route('/missing/{id:scientist}')]
final class MappedToNothing
{
    #[Path] public int $id = 0;
}

#[Component]
#[Route('/builtin/{slug:id}')]
final class MappedToBuiltin
{
    #[Path] public int $id = 0;
}

#[Component]
#[Route('/object/{at}')]
final class ObjectPath
{
    #[Path] public \DateTimeImmutable $at;
}
