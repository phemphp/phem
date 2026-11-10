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

use Phem\Attribute\State;
use Phem\Feed;
use Phem\Http\EventPayload;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\FeedIdentity;
use Phem\Rendering\FeedItemToken;
use Phem\Tests\Fixtures\Chat;
use Phem\Tests\Fixtures\ChatMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** The parts of Phem\Feed: the changes of a feed, the signed items, the event payload and the metadata. */
final class FeedTest extends TestCase
{
    public function testTheItemsRenderOneTimeWithNoDeletedItem(): void
    {
        [$a, $b, $c] = [new ChatMessage(1, 'r', 'A'), new ChatMessage(2, 'r', 'B'), new ChatMessage(3, 'r', 'C')];
        $feed = new Feed('messages');
        $feed->load($a);
        $feed->append($b, $c);
        $feed->update($a);
        $feed->delete($b);

        self::assertSame([$a, $c], $feed->items());
    }

    public function testTheOrderOfANewListFollowsTheChanges(): void
    {
        [$a, $b, $c, $d] = [new ChatMessage(1, 'r', 'A'), new ChatMessage(2, 'r', 'B'), new ChatMessage(3, 'r', 'C'), new ChatMessage(4, 'r', 'D')];
        $feed = new Feed('messages');
        $feed->append($c, $d);
        $feed->prepend($a, $b);
        // An item that is in the list already moves.
        $feed->append($a);
        $feed->delete($d);

        self::assertSame([$b, $c, $a], $feed->ordered());

        $feed->reset($d);
        self::assertSame([$d], $feed->ordered());
    }

    public function testAnEmptyAppendIsNoChange(): void
    {
        $feed = new Feed('messages');
        $feed->append();
        $feed->prepend();

        self::assertSame([], $feed->operations());
    }

    public function testATokenIsValidForItsScopeItsComponentAndItsFeedOnly(): void
    {
        $tokens = new FeedItemToken('secret');
        $scope = FeedItemToken::eventScope('App\ChatPage', ['room' => 'a'], 'phem0123456789ab');
        $token = $tokens->sign($scope, 'App\ChatRoom', 'messages', ChatMessage::class, ['id' => 41]);

        self::assertSame([ChatMessage::class, ['id' => 41]], $tokens->verify($token, $scope, 'App\ChatRoom', 'messages'));
        self::assertNull($tokens->verify($token, $scope, 'App\Other', 'messages'));
        self::assertNull($tokens->verify($token, $scope, 'App\ChatRoom', 'drafts'));
        self::assertNull(new FeedItemToken('other')->verify($token, $scope, 'App\ChatRoom', 'messages'));
        self::assertNull($tokens->verify($token.'x', $scope, 'App\ChatRoom', 'messages'));
        self::assertNull($tokens->verify('not a token', $scope, 'App\ChatRoom', 'messages'));
    }

    public function testATokenOfAnotherInstanceIsNotValid(): void
    {
        $tokens = new FeedItemToken('secret');
        $token = $tokens->sign(FeedItemToken::eventScope('App\ChatPage', ['room' => 'a'], 'phem0123456789ab'), 'App\ChatRoom', 'messages', ChatMessage::class, ['id' => 41]);

        // Another URL of the page, another fiber, or a push.
        self::assertNull($tokens->verify($token, FeedItemToken::eventScope('App\ChatPage', ['room' => 'b'], 'phem0123456789ab'), 'App\ChatRoom', 'messages'));
        self::assertNull($tokens->verify($token, FeedItemToken::eventScope('App\ChatPage', ['room' => 'a'], 'phem0123456789ac'), 'App\ChatRoom', 'messages'));
        self::assertNull($tokens->verify($token, FeedItemToken::pushScope('key'), 'App\ChatRoom', 'messages'));

        $push = $tokens->sign(FeedItemToken::pushScope('room-a'), 'App\ChatRoom', 'messages', ChatMessage::class, ['id' => 41]);
        self::assertNotNull($tokens->verify($push, FeedItemToken::pushScope('room-a'), 'App\ChatRoom', 'messages'));
        self::assertNull($tokens->verify($push, FeedItemToken::pushScope('room-b'), 'App\ChatRoom', 'messages'));
    }

    public function testATokenWithAnotherItemIsNotValid(): void
    {
        $tokens = new FeedItemToken('secret');
        $scope = FeedItemToken::pushScope('key');
        [, $mac] = explode('.', $tokens->sign($scope, 'App\ChatRoom', 'messages', ChatMessage::class, ['id' => 41]));
        [$other] = explode('.', $tokens->sign($scope, 'App\ChatRoom', 'messages', ChatMessage::class, ['id' => 42]));

        self::assertNull($tokens->verify($other.'.'.$mac, $scope, 'App\ChatRoom', 'messages'));
    }

    public function testTheIdOfAnItemHasTheIdentifierValues(): void
    {
        $identity = new FeedIdentity(new Chat());

        self::assertSame('phem0123456789ab-messages-41', $identity->elementId('phem0123456789ab-messages', 'messages', new ChatMessage(41, 'r', 'A')));
    }

    public function testACompositeOrAnUnsafeIdentifierGivesAHashedKey(): void
    {
        $identity = new FeedIdentity(new class implements \Phem\Handler\ObjectIdentifier {
            public function identify(object $value): array
            {
                return (array) $value;
            }
        });

        $a = $identity->key('messages', (object) ['a' => '1_2', 'b' => '3']);
        $b = $identity->key('messages', (object) ['a' => '1', 'b' => '2_3']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $a);
        self::assertNotSame($a, $b);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $identity->key('messages', (object) ['id' => 'a b']));
        self::assertSame('41', $identity->key('messages', (object) ['id' => 41]));
    }

    public function testAnIdentifierWithAnObjectThrows(): void
    {
        $identity = new FeedIdentity(new class implements \Phem\Handler\ObjectIdentifier {
            public function identify(object $value): array
            {
                return ['order' => new \stdClass()];
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the field "order" of its identifier is a stdClass');

        $identity->of('messages', new \stdClass());
    }

    public function testAnItemWithNoIdentifierThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The item of the feed "messages" is a stdClass object, but Phem cannot identify it.');

        new FeedIdentity(new Chat())->key('messages', new \stdClass());
    }

    public function testThePayloadReadsTheItemAndTheItemsOfThePushes(): void
    {
        $payload = EventPayload::fromJson(json_encode([
            'handler' => 'phem0123456789ab.x',
            'signature' => 's',
            'item' => ['feed' => 'phem0123456789ab-messages', 'token' => 'a.b'],
            'feeds' => [['feed' => 'phem0123456789ab-messages', 'token' => 'c.d', 'op' => 'prepend']],
        ], \JSON_THROW_ON_ERROR));

        [$item, $push] = $payload->feedItems();
        self::assertSame(['phem0123456789ab', 'messages', 'a.b', null], [$item->fiber, $item->feed, $item->token, $item->operation]);
        self::assertSame(['phem0123456789ab', 'messages', 'c.d', 'prepend'], [$push->fiber, $push->feed, $push->token, $push->operation]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badItems(): iterable
    {
        yield 'a string' => [['item' => 'a.b']];
        yield 'another id' => [['item' => ['feed' => 'messages', 'token' => 'a.b']]];
        yield 'a long token' => [['item' => ['feed' => 'phem0123456789ab-messages', 'token' => str_repeat('a', 1025)]]];
        yield 'a push with no change' => [['feeds' => [['feed' => 'phem0123456789ab-messages', 'token' => 'a.b']]]];
        yield 'a push that deletes' => [['feeds' => [['feed' => 'phem0123456789ab-messages', 'token' => 'a.b', 'op' => 'delete']]]];
        yield 'too many pushes' => [['feeds' => array_fill(0, 101, ['feed' => 'phem0123456789ab-messages', 'token' => 'a.b', 'op' => 'append'])]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badItems')]
    public function testThePayloadRejectsAnItemThatDoesNotComeFromTheBrowser(array $data): void
    {
        $this->expectException(BadRequestHttpException::class);

        EventPayload::fromJson(json_encode(['handler' => 'phem0123456789ab.x', 'signature' => 's'] + $data, \JSON_THROW_ON_ERROR));
    }

    public function testAFeedPropertyIsPublicAndHasNoAttribute(): void
    {
        $metadata = new ComponentMetadata();

        self::assertSame(['messages'], array_map(static fn (\ReflectionProperty $p): string => $p->getName(), $metadata->feedProperties(FeedComponent::class)));
        self::assertFalse($metadata->mountsEachRender(FeedComponent::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The feed "Phem\Tests\Runtime\FeedInState::$messages" must be a public property');

        $metadata->feedProperties(FeedInState::class);
    }
}

final class FeedComponent
{
    public Feed $messages;
    public string $title = '';

    public function mount(): void
    {
    }
}

final class FeedInState
{
    #[State] public Feed $messages;
}
