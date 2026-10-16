<?php

declare(strict_types=1);

namespace Rephp\Tests\Fixtures;

/**
 * A controller attribute, as `#[IsGranted]`: AccessListener denies the requests of its controller.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Deny
{
}
