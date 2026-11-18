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

use Phem\State\StateJson;
use Phem\Tests\Fixtures\Renders;
use PHPUnit\Framework\TestCase;

/** Loads the /skip page, and clicks its buttons, or sends their handlers in one load event. */
trait SkipPage
{
    private string $html = '';
    private string $state = '';
    private string $signature = '';

    protected function setUp(): void
    {
        Renders::reset();

        $this->html = (string) $this->request('GET', '/skip')->getContent();
        TestCase::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $this->html, $m));
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];

        TestCase::assertSame(21, Renders::of('Probe'));
        Renders::$counts = [];
    }

    /** @return array<string, mixed> */
    private function click(string $id): array
    {
        return $this->send($this->handlerOf($id), ['type' => 'click']);
    }

    /**
     * Sends one load event with the handlers of many buttons, as the browser sends the handlers
     * of the `<Suspense>` boundaries that load together.
     *
     * @return array<string, mixed>
     */
    private function load(string ...$ids): array
    {
        return $this->send(array_values(array_map($this->handlerOf(...), $ids)), ['type' => 'load', 'value' => null, 'checked' => null, 'key' => null, 'form' => null]);
    }

    private function handlerOf(string $id): string
    {
        TestCase::assertSame(1, preg_match('/<button id="'.$id.'"[^>]*data-phem-on-click="([^"]+)"/', $this->html, $h), $id);

        return $h[1];
    }

    /**
     * @param string|list<string>  $handler
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function send(string|array $handler, array $event): array
    {
        $response = $this->event('/skip', ['state' => $this->state, 'signature' => $this->signature, 'handler' => $handler, 'event' => $event]);
        $data = json_decode((string) $response->getContent(), true);
        TestCase::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->state = StateJson::apply($this->state, $data['changes']);
        $this->signature = $data['signature'];

        return $data;
    }

    /** @return list<string> the text of each stream */
    private function texts(string $streams): array
    {
        preg_match_all('#<template>(.*?)</template>#s', $streams, $m);

        return array_map(static fn (string $html): string => trim(strip_tags($html)), $m[1]);
    }
}
