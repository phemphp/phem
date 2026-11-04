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

use Phem\Rendering\Member;
use PHPUnit\Framework\TestCase;

final class MemberTest extends TestCase
{
    public function testItReadsAPropertyOrAMethodOfAParentClass(): void
    {
        $card = new MemberCard();

        $this->assertSame('Untitled', Member::read($card, 'title', MemberCard::class));
        $this->assertInstanceOf(\Closure::class, $hit = Member::read($card, 'hit', MemberCard::class));
        $this->assertSame('hit', $hit());
        $this->assertNull(Member::read($card, 'missing', MemberCard::class));
    }

    public function testItReadsInTheScopeOfTheClassOfTheMarkup(): void
    {
        // The markup is in MemberCard, and the instance is a subclass: the private property of
        // the trait of MemberCard stays visible, as `$this->secret` in MemberCard.
        $card = new MemberThemedCard();

        $this->assertSame('s3cret', Member::read($card, 'secret', MemberCard::class));
        $this->assertNull(Member::read($card, 'secret', MemberThemedCard::class));
    }
}

abstract class MemberBase
{
    protected string $title = 'Untitled';

    protected function hit(): string
    {
        return 'hit';
    }
}

trait MemberSecret
{
    private string $secret = 's3cret';
}

class MemberCard extends MemberBase
{
    use MemberSecret;
}

final class MemberThemedCard extends MemberCard
{
}
