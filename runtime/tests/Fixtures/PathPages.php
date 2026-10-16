<?php

declare(strict_types=1);

namespace Rephp\Tests\Fixtures\PathPages;

use Rephp\Attribute\Component;
use Rephp\Attribute\Path;
use Rephp\Attribute\State;
use Symfony\Component\Routing\Attribute\Route;

// Pages for the tests of the #[Path] route values (plain PHP, not compiled by rhpc).

#[Route('/known/{id}', defaults: ['id' => 1])]
function Known(#[Path] int $id, string $label = 'x')
{
}

#[Route('/unknown/{slug}')]
function UnknownValue(#[Path] int $id = 0)
{
}

#[Route('/default', defaults: ['mode' => 'dark'])]
function UnknownDefault()
{
}

#[Route('/rest/{tag}')]
function VariadicPath(#[Path] string ...$tag)
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
