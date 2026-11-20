<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use PHPUnit\Framework\TestCase;

/**
 * The components use the tokens of `assets/theme.css`. Tailwind generates no class for an unknown
 * name, and gives no error, so this test checks each name of a color, a radius and a shadow.
 */
final class ThemeTest extends TestCase
{
    private const string PALETTE = '/^(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d+$/';

    /** The names of the utilities that are not colors, by prefix. */
    private const array NOT_COLORS = [
        'bg' => ['none', 'fixed', 'local', 'scroll', 'cover', 'contain', 'auto', 'center', 'repeat', 'no-repeat', 'clip-border', 'clip-padding', 'clip-content', 'clip-text'],
        'text' => ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', 'left', 'center', 'right', 'justify', 'start', 'end', 'wrap', 'nowrap', 'balance', 'pretty', 'ellipsis', 'clip'],
        'border' => ['0', '2', '4', '8', 'x', 'y', 't', 'b', 'l', 'r', 's', 'e', 'x-0', 'y-0', 't-0', 'b-0', 'l-0', 'r-0', 's-0', 'e-0', 'solid', 'dashed', 'dotted', 'double', 'hidden', 'none', 'collapse', 'separate'],
        'outline' => ['0', '1', '2', '4', '8', 'none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'offset-0', 'offset-1', 'offset-2', 'offset-4', 'offset-8'],
        'decoration' => ['0', '1', '2', '4', '8', 'auto', 'from-font', 'solid', 'double', 'dotted', 'dashed', 'wavy', 'clone', 'slice'],
    ];

    private const array RADII = ['none', 'full', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl'];
    private const array SHADOWS = ['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'];
    private const array KEYWORDS = ['white', 'black', 'transparent', 'current', 'inherit'];

    public function testEachTokenOfTheComponentsIsInTheTheme(): void
    {
        $root = \dirname(__DIR__, 2);
        $theme = self::themeNames((string) file_get_contents($root.'/assets/theme.css'));

        $unknown = [];
        foreach ([...glob($root.'/src/*.phem'), $root.'/src/Style.php'] as $file) {
            foreach (self::classes((string) file_get_contents($file)) as $class) {
                if (!self::isKnown($class, $theme)) {
                    $unknown[] = basename($file).': '.$class;
                }
            }
        }

        self::assertSame([], array_values(array_unique($unknown)), 'These classes have a name that is not in assets/theme.css or in the default theme of Tailwind.');
    }

    public function testTheThemeHasTheShadcnVariables(): void
    {
        $theme = self::themeNames((string) file_get_contents(\dirname(__DIR__, 2).'/assets/theme.css'));

        foreach (['background', 'foreground', 'popover', 'popover-foreground', 'primary', 'primary-foreground', 'muted', 'muted-foreground', 'accent', 'accent-foreground', 'destructive', 'border', 'input', 'ring'] as $name) {
            self::assertContains($name, $theme['color'], \sprintf('The theme has no --color-%s.', $name));
        }
    }

    /**
     * @return array{color: list<string>, radius: list<string>, shadow: list<string>}
     */
    private static function themeNames(string $css): array
    {
        $names = ['color' => [], 'radius' => [], 'shadow' => []];
        preg_match('/@theme\s+inline\s*\{([^}]*)\}/', $css, $block);
        preg_match_all('/--(color|radius|shadow)-([\w-]+)\s*:/', $block[1] ?? '', $variables, \PREG_SET_ORDER);

        foreach ($variables as [, $key, $name]) {
            $names[$key][] = $name;
        }

        return $names;
    }

    /**
     * The utilities of a color, a radius or a shadow in a file, with no variants.
     *
     * @return list<string>
     */
    private static function classes(string $source): array
    {
        preg_match_all('/(?<=[\s\'"{:])(bg|text|border|outline|decoration|rounded|shadow)-([\w.-]+(?:\/\d+)?)(?=[\s\'"])/', $source, $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $match): string => $match[1].'-'.$match[2], $matches);
    }

    /**
     * @param array{color: list<string>, radius: list<string>, shadow: list<string>} $theme
     */
    private static function isKnown(string $class, array $theme): bool
    {
        [$prefix, $name] = explode('-', $class, 2);
        $name = preg_replace('/\/\d+$/', '', $name) ?? $name;

        // The color of one side: `border-r-transparent`.
        if ('border' === $prefix && !\in_array($name, self::NOT_COLORS['border'], true)) {
            $name = preg_replace('/^(x|y|t|b|l|r|s|e)-/', '', $name) ?? $name;
        }

        return match ($prefix) {
            'rounded' => \in_array(preg_replace('/^(t|b|l|r|s|e|tl|tr|bl|br)-/', '', $name), [...self::RADII, ...$theme['radius']], true),
            'shadow' => \in_array($name, [...self::SHADOWS, ...$theme['shadow']], true),
            default => \in_array($name, [...self::NOT_COLORS[$prefix], ...self::KEYWORDS, ...$theme['color']], true)
                || 1 === preg_match(self::PALETTE, $name),
        };
    }
}
