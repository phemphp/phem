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

namespace Phem\Tests\Runtime;

use Phem\Event;
use Phem\Handler\IsGrantedMiddleware;
use Phem\Tests\Fixtures\GrantedHandlers\AdminPanel;
use Phem\Tests\Fixtures\GrantedHandlers\PostCard;
use Phem\Tests\Fixtures\GrantedHandlers\PostVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

require_once \dirname(__DIR__).'/Fixtures/GrantedHandlers.php';

final class IsGrantedMiddlewareTest extends TestCase
{
    private RequestStack $requests;

    protected function setUp(): void
    {
        $this->requests = new RequestStack();
        $this->requests->push(Request::create('/posts/1', 'POST'));
    }

    public function testAHandlerWithoutTheAttributeRuns(): void
    {
        self::assertSame('viewed', $this->handle(new PostCard()->view(...), []));
    }

    public function testARoleOnTheHandlerMethod(): void
    {
        self::assertSame('deleted', $this->handle(new PostCard()->delete(...), ['ROLE_EDITOR']));

        try {
            $this->handle(new PostCard()->delete(...), ['ROLE_USER']);
            self::fail('The handler ran.');
        } catch (AccessDeniedException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame(['ROLE_EDITOR'], $e->getAttributes());
            self::assertNotNull($e->getAccessDecision());
        }
    }

    public function testAPropertyOfTheComponentIsTheSubject(): void
    {
        self::assertSame('edited', $this->handle(new PostCard()->edit(...), [], 'ada'));
        self::assertSame('published', $this->handle(new PostCard()->publish(...), [], 'marie'), 'also a private property');

        $this->expectException(AccessDeniedException::class);
        $this->handle(new PostCard()->edit(...), [], 'marie');
    }

    public function testAnExpressionSubjectGetsTheComponent(): void
    {
        self::assertSame('renamed', $this->handle(new PostCard()->rename(...), [], 'ada'));

        $this->expectException(AccessDeniedException::class);
        $this->handle(new PostCard()->rename(...), [], 'marie');
    }

    public function testAListOfSubjects(): void
    {
        try {
            $this->handle(new PostCard()->both(...), [], 'ada');
            self::fail('The handler ran.');
        } catch (AccessDeniedException $e) {
            self::assertSame(['post', 'other'], array_keys((array) $e->getSubject()), 'the voter gets the list');
        }
    }

    public function testASubjectThatIsNotAPropertyIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find the subject "missing" for the #[IsGranted] attribute of an event handler. The subject of a handler is a property of its component: add a "$missing" property to "Phem\Tests\Fixtures\GrantedHandlers\PostCard".');

        $this->handle(new PostCard()->lost(...), [], 'ada');
    }

    public function testAStatusCodeGivesAnHttpException(): void
    {
        try {
            $this->handle(new PostCard()->hide(...), []);
            self::fail('The handler ran.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
            self::assertSame('Not here.', $e->getMessage());
        }
    }

    public function testTheMethodsOfTheAttributeLimitTheCheck(): void
    {
        self::assertSame('on get', $this->handle(new PostCard()->onGet(...), []), 'an event is a POST request');
    }

    public function testAClosureHandler(): void
    {
        self::assertSame('closure', $this->handle(new PostCard()->closure(), ['ROLE_ADMIN']));

        $this->expectException(AccessDeniedException::class);
        $this->handle(new PostCard()->closure(), []);
    }

    public function testTheClassAttributeOfAComponentAppliesToItsHandlers(): void
    {
        self::assertSame('saved', $this->handle(new AdminPanel()->save(...), ['ROLE_ADMIN']));

        $this->expectException(AccessDeniedException::class);
        $this->handle(new AdminPanel()->save(...), []);
    }

    public function testTheClassAttributeAndTheMethodAttributeMustBothPass(): void
    {
        self::assertSame('published', $this->handle(new AdminPanel()->publish(...), ['ROLE_ADMIN', 'ROLE_EDITOR']));

        foreach ([['ROLE_ADMIN'], ['ROLE_EDITOR']] as $roles) {
            try {
                $this->handle(new AdminPanel()->publish(...), $roles);
                self::fail('The handler ran with '.implode(', ', $roles).'.');
            } catch (AccessDeniedException $e) {
                self::assertSame(array_values(array_diff(['ROLE_ADMIN', 'ROLE_EDITOR'], $roles)), $e->getAttributes(), 'the attribute that failed');
            }
        }
    }

    public function testTheClassAttributeOfThePageOfTheRequestIsLeftToThePage(): void
    {
        // PageAttributesListener checks the #[IsGranted] of the page for the event request.
        $this->requests->getCurrentRequest()?->attributes->set('_phem_page', AdminPanel::class);

        self::assertSame('saved', $this->handle(new AdminPanel()->save(...), []));
    }

    public function testTheMethodAttributeOfAHandlerOfThePageIsStillChecked(): void
    {
        $this->requests->getCurrentRequest()?->attributes->set('_phem_page', AdminPanel::class);

        self::assertSame('published', $this->handle(new AdminPanel()->publish(...), ['ROLE_EDITOR']), 'the page checked ROLE_ADMIN');

        $this->expectException(AccessDeniedException::class);
        $this->handle(new AdminPanel()->publish(...), ['ROLE_ADMIN']);
    }

    public function testAHandlerWithTheAttributeNeedsTheSecurity(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[IsGranted] attribute of an event handler needs the security of Symfony.');

        new IsGrantedMiddleware(requests: $this->requests)->handle(new PostCard()->delete(...), new Event('click'), static fn (): string => 'ran');
    }

    /** @param list<string> $roles */
    private function handle(\Closure $handler, array $roles, string $user = 'ada'): mixed
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles));
        $authorization = new AuthorizationChecker($tokens, new AccessDecisionManager([new RoleVoter(), new PostVoter()]));

        return new IsGrantedMiddleware($authorization, requests: $this->requests)->handle($handler, new Event('click'), static fn (): mixed => $handler());
    }
}
