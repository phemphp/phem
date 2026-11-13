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
use Phem\Handler\HandlerCall;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\IsGrantedMiddleware;
use Phem\Tests\Fixtures\GrantedHandlers\AdminPanel;
use Phem\Tests\Fixtures\GrantedHandlers\AdminTable;
use Phem\Tests\Fixtures\GrantedHandlers\GrantedPage;
use Phem\Tests\Fixtures\GrantedHandlers\Post;
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

    public function testASubjectThatIsAParameterWithNoArgumentIsAnError(): void
    {
        // The property `post` is the post of ada, but the handler acts on its parameter.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The subject "post" of the #[IsGranted] attribute of an event handler is a parameter that the call in the markup doesn\'t give.');

        $this->handle(new PostCard()->archive(...), [], 'ada');
    }

    public function testASubjectThatIsNotAPropertyIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find the subject "missing" for the #[IsGranted] attribute of an event handler. Pass it to the handler in the markup (`delete(missing)` for a "$missing" parameter), or add a "$missing" property to "Phem\Tests\Fixtures\GrantedHandlers\PostCard".');

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

    public function testTheClassAttributeAppliesToAStaticClosureOfTheComponent(): void
    {
        self::assertSame('wiped', $this->handle(new AdminPanel()->wipe(), ['ROLE_ADMIN']));

        $this->expectException(AccessDeniedException::class);
        $this->handle(new AdminPanel()->wipe(), []);
    }

    public function testTheClassAttributeOfTheComponentAppliesToAMethodOfAParentClass(): void
    {
        self::assertSame('deleted', $this->handle(new AdminTable()->delete(...), ['ROLE_ADMIN']));

        $this->expectException(AccessDeniedException::class);
        $this->handle(new AdminTable()->delete(...), []);
    }

    public function testTheClassAttributeOfThePageOfTheRequestIsLeftToThePage(): void
    {
        // PageAttributesListener checks it for the page and its events.
        $this->requests->getCurrentRequest()?->attributes->set('_phem_page', GrantedPage::class);

        self::assertSame('saved', $this->handle(new GrantedPage()->save(...), []));
    }

    public function testTheClassAttributeOfAComponentWithARouteInAnotherPageIsChecked(): void
    {
        // A component with a route can be a tag in another page: its class has no listener.
        $this->requests->getCurrentRequest()?->attributes->set('_phem_page', 'App\Ui\OtherPage');

        $this->expectException(AccessDeniedException::class);
        $this->handle(new GrantedPage()->save(...), []);
    }

    public function testTheMethodAttributeOfAHandlerOfAPageIsChecked(): void
    {
        $this->requests->getCurrentRequest()?->attributes->set('_phem_page', GrantedPage::class);

        self::assertSame('published', $this->handle(new GrantedPage()->publish(...), ['ROLE_EDITOR']));

        $this->expectException(AccessDeniedException::class);
        $this->handle(new GrantedPage()->publish(...), ['ROLE_ADMIN']);
    }

    public function testAnArgumentOfTheCallIsTheSubject(): void
    {
        self::assertSame('removed ada', $this->handle(self::call(new PostCard()->remove(...), [new Post('ada')]), [], 'ada'));
        self::assertSame('dropped', $this->handle(self::call(new PostCard()->drop(...), [new Post('ada')]), [], 'ada'), 'an Expression gets the arguments');

        $this->expectException(AccessDeniedException::class);
        $this->handle(self::call(new PostCard()->remove(...), [new Post('marie')]), [], 'ada');
    }

    public function testAnArgumentComesBeforeAPropertyWithTheSameName(): void
    {
        // The property `post` is the post of ada.
        $this->expectException(AccessDeniedException::class);
        $this->handle(self::call(new PostCard()->replace(...), [new Post('marie')]), [], 'ada');
    }

    public function testAHandlerWithTheAttributeNeedsTheSecurity(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[IsGranted] attribute of an event handler needs the security of Symfony.');

        new IsGrantedMiddleware(requests: $this->requests)->handle(new PostCard()->delete(...), new Event('click'), static fn (): string => 'ran');
    }

    /**
     * The handler with the arguments of a call in the markup.
     *
     * @param list<mixed> $arguments
     */
    private static function call(\Closure $handler, array $arguments): \Closure
    {
        return HandlerCall::bind($handler, $arguments) ?? self::fail('No handler.');
    }

    /** @param list<string> $roles */
    private function handle(\Closure $handler, array $roles, string $user = 'ada'): mixed
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles));
        $authorization = new AuthorizationChecker($tokens, new AccessDecisionManager([new RoleVoter(), new PostVoter()]));

        return new HandlerInvoker([new IsGrantedMiddleware($authorization, requests: $this->requests)])->invoke($handler, new Event('click'));
    }
}
