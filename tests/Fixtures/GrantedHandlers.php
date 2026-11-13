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

namespace Phem\Tests\Fixtures\GrantedHandlers;

use Phem\Attribute\Component;
use Phem\Attribute\Page;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Components with #[IsGranted] on their handlers (plain PHP, not compiled by phemc).

final class Post
{
    public function __construct(public readonly string $author)
    {
    }
}

/** @extends Voter<string, Post> */
final class PostVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'EDIT' === $attribute && $subject instanceof Post;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->author === $token->getUserIdentifier();
    }
}

final class PostCard
{
    public function __construct(
        public Post $post = new Post('ada'),
        // Read by the #[IsGranted] of publish(), as a private property.
        private Post $draft = new Post('marie'),
    ) {
    }

    public function draft(): Post
    {
        return $this->draft;
    }

    public function view(): string
    {
        return 'viewed';
    }

    #[IsGranted('ROLE_EDITOR')]
    public function delete(): string
    {
        return 'deleted';
    }

    #[IsGranted('EDIT', subject: 'post')]
    public function edit(): string
    {
        return 'edited';
    }

    #[IsGranted('EDIT', subject: 'draft')]
    public function publish(): string
    {
        return 'published';
    }

    #[IsGranted('EDIT', subject: new Expression('this.post'))]
    public function rename(): string
    {
        return 'renamed';
    }

    #[IsGranted('EDIT', subject: ['post', 'other' => 'draft'])]
    public function both(): string
    {
        return 'both';
    }

    // `item` is an argument of the call in the markup: `remove(item)`.
    #[IsGranted('EDIT', subject: 'item')]
    public function remove(Post $item): string
    {
        return 'removed '.$item->author;
    }

    // An argument `post` comes before the property `post`.
    #[IsGranted('EDIT', subject: 'post')]
    public function replace(Post $post): string
    {
        return 'replaced';
    }

    #[IsGranted('EDIT', subject: new Expression('args[\'item\']'))]
    public function drop(Post $item): string
    {
        return 'dropped';
    }

    // `post` is a parameter: with no argument in the call, the property is not the subject.
    #[IsGranted('EDIT', subject: 'post')]
    public function archive(?Post $post = null): string
    {
        return 'archived';
    }

    #[IsGranted('EDIT', subject: 'missing')]
    public function lost(): string
    {
        return 'lost';
    }

    #[IsGranted('ROLE_ADMIN', message: 'Not here.', statusCode: 404)]
    public function hide(): string
    {
        return 'hidden';
    }

    #[IsGranted('ROLE_ADMIN', methods: 'GET')]
    public function onGet(): string
    {
        return 'on get';
    }

    public function closure(): \Closure
    {
        return #[IsGranted('ROLE_ADMIN')] static fn (): string => 'closure';
    }
}

#[IsGranted('ROLE_ADMIN')]
final class AdminPanel
{
    public function save(): string
    {
        return 'saved';
    }

    // The class attribute and this one: both must pass.
    #[IsGranted('ROLE_EDITOR')]
    public function publish(): string
    {
        return 'published';
    }

    // A static closure has no $this, but it has the class of the component.
    public function wipe(): \Closure
    {
        return static fn (): string => 'wiped';
    }
}

// The #[IsGranted] of the class of a page is a rule of the page (see PageAttributesListener). A
// component with a route can also be a tag in another page.
#[IsGranted('ROLE_ADMIN')]
#[Component]
#[Page('/granted')]
final class GrantedPage
{
    public function save(): string
    {
        return 'saved';
    }

    #[IsGranted('ROLE_EDITOR')]
    public function publish(): string
    {
        return 'published';
    }
}

// A handler method of a parent class: the class attribute of the component applies to it.
abstract class BaseTable
{
    public function delete(): string
    {
        return 'deleted';
    }
}

#[IsGranted('ROLE_ADMIN')]
final class AdminTable extends BaseTable
{
}
