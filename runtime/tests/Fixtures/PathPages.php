<?php

declare(strict_types=1);

namespace Mamu\Tests\Fixtures\PathPages;

use Mamu\Attribute\Component;
use Mamu\Attribute\Path;
use Mamu\Attribute\State;
use Mamu\Tests\Fixtures\Scientist;
use Symfony\Component\Routing\Attribute\Route;

// Pages for the tests of the #[Path] route values (plain PHP, not compiled by mamuc).

#[Route('/known/{id}', defaults: ['id' => 1])]
function Known(#[Path] int $id, string $label = 'x'): void
{
}

#[Route('/unknown/{slug}')]
function UnknownValue(#[Path] int $id = 0): void
{
}

#[Route('/default', defaults: ['mode' => 'dark'])]
function UnknownDefault(): void
{
}

#[Route('/rest/{tag}')]
function VariadicPath(#[Path] string ...$tag): void
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
    public string $label = 'x';
}

#[Component]
#[Route('/without/{id}')]
final class WithoutPath
{
    public int $id = 0;
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

#[Route('/maybe/{scientist}')]
function NullableEntity(#[Path] ?Scientist $scientist): void
{
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
