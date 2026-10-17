<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Tests\Fixtures\PathPages;

use Mamu\Attribute\Component;
use Mamu\Attribute\Page;
use Mamu\Attribute\Path;
use Mamu\Attribute\State;
use Mamu\Document;
use Mamu\Http\PageView;
use Mamu\Tests\Fixtures\Scientist;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

// Pages for the tests of the #[Path] route values (plain PHP, not compiled by mamuc).

abstract class Doc implements Document
{
    public function document(PageView $page)
    {
        return $page->root();
    }
}

#[Component]
#[Route('/known/{id}', defaults: ['id' => 1])]
final class Known extends Doc
{
    #[Path] public int $id;
    public string $label = 'x';
}

#[Component]
#[Route('/unknown/{slug}')]
final class UnknownValue extends Doc
{
    #[Path] public int $id = 0;
}

#[Component]
#[Route('/default', defaults: ['mode' => 'dark'])]
final class UnknownDefault extends Doc
{
}

// #[Page] routes, with a controller attribute of Symfony.
#[Component]
#[Page('/paged', name: 'paged')]
#[Page(['en' => '/en/paged', 'es' => '/es/paged'], name: 'paged_i18n')]
#[Cache(maxage: 30, public: true)]
final class Paged extends Doc
{
}

// A function is not a page.
#[Route('/function')]
function FunctionPage(): void
{
}

// A page must extend a document.
#[Component]
#[Route('/no-document')]
final class NoDocument
{
}

#[Component]
#[Route('/typed/{id}/{price}/{draft}/{slug}')]
final class Typed extends Doc
{
    #[Path] public int $id = 0;
    #[Path] public float $price = 0.0;
    #[Path] public bool $draft = false;
    #[Path] public string $slug = '';
    public string $label = 'x';
}

#[Component]
#[Route('/without/{id}')]
final class WithoutPath extends Doc
{
    public int $id = 0;
}

#[Component]
#[Route('/state/{id}')]
final class StatePath extends Doc
{
    #[Path, State] public int $id = 0;
}

#[Component]
#[Route('/scientists/{id:scientist}')]
final class EntityPath extends Doc
{
    #[Path] public Scientist $scientist;
}

#[Component]
#[Route('/maybe/{scientist}')]
final class NullableEntity extends Doc
{
    #[Path] public ?Scientist $scientist = null;
}

#[Component]
#[Route('/missing/{id:scientist}')]
final class MappedToNothing extends Doc
{
    #[Path] public int $id = 0;
}

#[Component]
#[Route('/builtin/{slug:id}')]
final class MappedToBuiltin extends Doc
{
    #[Path] public int $id = 0;
}

#[Component]
#[Route('/object/{at}')]
final class ObjectPath extends Doc
{
    #[Path] public \DateTimeImmutable $at;
}
