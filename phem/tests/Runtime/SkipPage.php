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

/** Loads the /skip page, and clicks its buttons. */
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
        TestCase::assertSame(1, preg_match('/<button id="'.$id.'"[^>]*data-phem-on-click="([^"]+)"/', $this->html, $h), $id);
        $response = $this->event('/skip', ['state' => $this->state, 'signature' => $this->signature, 'handler' => $h[1], 'event' => ['type' => 'click']]);
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
