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
}
