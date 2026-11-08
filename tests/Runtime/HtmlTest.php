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

use Phem\Html;
use Phem\LazyHtml;
use Phem\Rendering\FiberInputs;
use Phem\Rendering\InputsComparator;
use Phem\Twig\EnvironmentConfigurator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class HtmlTest extends TestCase
{
    public function testTwigPrintsHtmlWithoutEscaping(): void
    {
        $twig = $this->twig('{{ html }}|{{ text }}|{{ html|e }}');

        self::assertSame('<b>Hi</b>|&lt;b&gt;Hi&lt;/b&gt;|&lt;b&gt;Hi&lt;/b&gt;', $twig->render('t', ['html' => new Html('<b>Hi</b>'), 'text' => '<b>Hi</b>']), 'an explicit escape filter escapes it');
    }

    public function testTwigPrintsLazyHtmlWithoutEscaping(): void
    {
        $twig = $this->twig('{{ html }}');

        self::assertSame('<i>lazy</i>', $twig->render('t', ['html' => new LazyHtml(static fn (): string => '<i>lazy</i>')]));
    }

    public function testEmptyHtmlIsEmpty(): void
    {
        $twig = $this->twig('{% if html is not empty %}<footer>{{ html }}</footer>{% endif %}');

        self::assertSame('', $twig->render('t', ['html' => Html::empty()]));
        self::assertSame('', $twig->render('t', ['html' => new LazyHtml(static fn (): string => '')]), 'a slot that renders nothing');
        self::assertSame('<footer><b>x</b></footer>', $twig->render('t', ['html' => new Html('<b>x</b>')]));
    }

    public function testHtmlIsCountableAndJsonSerializable(): void
    {
        self::assertCount(4, new Html('<b>ü'));
        self::assertSame('"<b>x<\/b>"', json_encode(new Html('<b>x</b>')));
    }

    public function testEqualHtmlPropsAreTheSame(): void
    {
        $comparator = new InputsComparator();

        self::assertTrue($comparator->same(new FiberInputs(['title' => new Html('<b>x</b>')], null), new FiberInputs(['title' => new Html('<b>x</b>')], null)));
        self::assertFalse($comparator->same(new FiberInputs(['title' => new Html('<b>x</b>')], null), new FiberInputs(['title' => new Html('<b>y</b>')], null)));
    }

    private function twig(string $template): Environment
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]));
        new EnvironmentConfigurator()->configure($twig);

        return $twig;
    }
}
