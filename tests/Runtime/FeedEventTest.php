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

use Phem\Rendering\FeedItemToken;
use Phem\Stream\BroadcastKeys;
use Phem\Tests\Fixtures\Chat;
use Phem\Tests\Fixtures\ChatMessage;
use PHPUnit\Framework\TestCase;

/** The answer of an event to a page with a feed (tests/Fixtures/app/src/Ui/Feed/ChatPage.phem), as the browser gets it. */
final class FeedEventTest extends TestCase
{
    use FixtureApp;

    public function testAnEventInAnItemAnswersWithTheStreamOfTheItemOnly(): void
    {
        $html = (string) $this->request('GET', '/chat/answer')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/<ul id="([^"]+)" data-phem-feed><li id="([^"]+)" data-phem-item="([^"]+)"><span>Three<\/span> <button class="edit" data-phem-on-click="([^"]+)"/', $html, $item));
        [, $feed, $id, $token, $handler] = $item;

        $response = $this->event('/chat/answer', [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $handler,
            'event' => ['type' => 'click'],
            'item' => ['feed' => $feed, 'token' => $token],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        // The component did not change: no morph, only the item.
        self::assertSame('<turbo-stream action="replace" method="morph" target="'.$id.'"><template><li id="'.$id.'" data-phem-item="'.$token.'"><span>Three!</span> <button class="edit" data-phem-on-click="'.$handler.'">Edit</button> <button class="shout" data-phem-on-click="'.self::handlerOf($html, 'shout').'">Shout</button> <button class="delete" data-phem-on-click="'.self::handlerOf($html, 'delete').'">Delete</button></li></template></turbo-stream>', $data['streams']);
    }

    public function testAnEventThatAppendsAnItemAnswersWithOneStream(): void
    {
        [$state, $signature, $html] = $this->page('/chat/append');
        self::assertSame(1, preg_match('/<ul id="([^"]+)" data-phem-feed>/', $html, $feed));
        self::assertSame(1, preg_match('/<form data-phem-on-submit="([^"]+)"/', $html, $submit));
        self::assertSame(1, preg_match('/name="draft" data-phem-bind="([^"]+)"/', $html, $bind));

        $streams = $this->streams('/chat/append', ['state' => $state, 'signature' => $signature, 'handler' => $submit[1], 'event' => ['type' => 'submit'], 'bound' => [$bind[1] => 'Six']]);

        // The HTML of the component is the same (the draft is empty again): only the new item.
        self::assertMatchesRegularExpression('#\A<turbo-stream action="append" target="'.$feed[1].'"><template><li id="'.$feed[1].'-\d+" data-phem-item="[^"]+"><span>Six</span>.*</li></template></turbo-stream>\z#', $streams);
    }

    public function testAMorphOfTheComponentHasAnEmptyFeedElementAndTheItemsComeAfterIt(): void
    {
        [$state, $signature, $html] = $this->page('/chat/clear');
        self::assertSame(1, preg_match('/<ul id="([^"]+)" data-phem-feed>/', $html, $feed));
        self::assertSame(1, preg_match('/<button id="clear" data-phem-on-click="([^"]+)"/', $html, $clear));

        $streams = $this->streams('/chat/clear', ['state' => $state, 'signature' => $signature, 'handler' => $clear[1], 'event' => ['type' => 'click']]);

        // The "Older" button goes: a morph, with no items in the feed element, then the new list.
        self::assertStringStartsWith('<turbo-stream action="replace" method="morph" targets="[data-phem-c=', $streams);
        self::assertStringContainsString('<ul id="'.$feed[1].'" data-phem-feed></ul>', $streams);
        self::assertStringNotContainsString('id="older"', $streams);
        self::assertMatchesRegularExpression('#</turbo-stream><turbo-stream action="update" target="'.$feed[1].'"><template><li id="'.$feed[1].'-5" data-phem-item="[^"]+"><span>Five</span>.*</li></template></turbo-stream>\z#', $streams);
    }

    public function testARefreshTakesAPushedItemForTheKeyOfTheInstanceOnly(): void
    {
        [$state, $signature, $html] = $this->page('/chat/push-b');
        self::assertSame(1, preg_match('/<ul id="((phem[0-9a-f]{12})-messages)" data-phem-feed>/', $html, $feed));
        $message = $this->service(Chat::class, Chat::class)->add('push-a', 'From A');
        $keys = $this->service('phem.broadcast_keys', BroadcastKeys::class);
        $tokens = $this->service('phem.feed_item_token', FeedItemToken::class);

        $refresh = fn (string $room): string => $this->streams('/chat/push-b', ['state' => $state, 'signature' => $signature, 'handler' => null, 'event' => ['type' => 'refresh'], 'bound' => [], 'stale' => [$feed[2]], 'feeds' => [[
            'feed' => $feed[1],
            'token' => $tokens->sign(FeedItemToken::pushScope($keys->ofInstance('App\Ui\Feed\ChatRoom', ['room' => $room])), 'App\Ui\Feed\ChatRoom', 'messages', ChatMessage::class, ['id' => $message->id]),
            'op' => 'append',
        ]]]);

        // A push to room A: room B does not take its item.
        self::assertStringNotContainsString('From A', $refresh('push-a'));
        // The same item with the key of room B (the server pushed it to room B).
        self::assertStringContainsString('<turbo-stream action="append" target="'.$feed[1].'">', $refresh('push-b'));
    }

    /** @return array{string, string, string} the state, the signature and the HTML of a page */
    private function page(string $uri): array
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));

        return [html_entity_decode($m[1]), $m[2], $html];
    }

    /** @param array<string, mixed> $payload */
    private function streams(string $uri, array $payload): string
    {
        $response = $this->event($uri, $payload);
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['streams'];
    }

    private static function handlerOf(string $html, string $class): string
    {
        self::assertSame(1, preg_match('/<button class="'.$class.'" data-phem-on-click="([^"]+)"/', $html, $m));

        return $m[1];
    }
}
