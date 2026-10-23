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

use Phem\Stream\HubPublisher;
use Phem\Stream\MemoryPublisher;
use Phem\Stream\SapiPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HubPublisherTest extends TestCase
{
    public function testItPostsTheUpdateWithAPublisherToken(): void
    {
        $response = new MockResponse('urn:uuid:1');
        $client = new MockHttpClient($response);

        new HubPublisher($client, 'http://hub/.well-known/mercure', 'key')->publish(['phem:a', 'phem:*'], '<turbo-stream>', true);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://hub/.well-known/mercure', $response->getRequestUrl());
        self::assertSame('topic=phem%3Aa&topic=phem%3A%2A&data=%3Cturbo-stream%3E&private=on', $response->getRequestOptions()['body']);
        $authorization = array_values(preg_grep('/^Authorization: Bearer /', $response->getRequestOptions()['headers']) ?: [''])[0];
        [$header, $payload, $signature] = explode('.', substr($authorization, \strlen('Authorization: Bearer '))) + ['', '', ''];
        self::assertSame(['alg' => 'HS256', 'typ' => 'at+jwt'], json_decode(base64_decode(strtr($header, '-_', '+/')), true));
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        self::assertSame('phem', $claims['iss']);
        self::assertSame('http://hub/.well-known/mercure', $claims['aud']);
        self::assertSame([['type' => 'https://mercure.rocks/authorization-detail', 'actions' => ['publish'], 'topics' => [['match' => '*']]]], $claims['authorization_details']);
        self::assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, 'key', true)), '+/', '-_'), '='), $signature);
    }

    public function testAnErrorOfTheHubDoesNotFailTheWork(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        new HubPublisher($client, 'http://hub/.well-known/mercure', 'key')->publish(['phem:a'], 'data', false);

        $this->addToAssertionCount(1);
    }

    public function testTheCliPublishesWithTheHub(): void
    {
        $request = new MemoryPublisher();
        $cli = new MemoryPublisher();

        new SapiPublisher($request, $cli, 'cli')->publish(['phem:a'], 'worker', false);
        new SapiPublisher($request, $cli, 'frankenphp')->publish(['phem:a'], 'request', false);

        self::assertSame('worker', $cli->updates()[0]['data']);
        self::assertSame('request', $request->updates()[0]['data']);
    }
}
