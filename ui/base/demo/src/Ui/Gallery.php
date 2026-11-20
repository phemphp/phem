<?php

declare(strict_types=1);

namespace App\Ui;

use Composer\InstalledVersions;

final class Gallery
{
    /**
     * The components of the demo, in the order and with the names and descriptions of the
     * "Components" section of base-ui.com (Base UI v1.9.0).
     *
     * @return array<string, array{name: string, description: string}> the slug of each component
     */
    public static function components(): array
    {
        return [
            'accordion' => ['name' => 'Accordion', 'description' => 'A set of collapsible panels with headings.'],
            'alert-dialog' => ['name' => 'Alert Dialog', 'description' => 'A dialog that requires a user response to proceed.'],
            'autocomplete' => ['name' => 'Autocomplete', 'description' => 'An input that suggests options as you type.'],
            'avatar' => ['name' => 'Avatar', 'description' => 'An easily stylable avatar component.'],
            'button' => ['name' => 'Button', 'description' => 'A button component that can be rendered as another tag or focusable when disabled.'],
            'checkbox' => ['name' => 'Checkbox', 'description' => 'An easily stylable checkbox component.'],
            'checkbox-group' => ['name' => 'Checkbox Group', 'description' => 'Provides shared state to a series of checkboxes.'],
            'collapsible' => ['name' => 'Collapsible', 'description' => 'A collapsible panel controlled by a button.'],
            'combobox' => ['name' => 'Combobox', 'description' => 'An input combined with a list of predefined items to select.'],
            'context-menu' => ['name' => 'Context Menu', 'description' => 'A menu that appears at the pointer on right click or long press.'],
            'dialog' => ['name' => 'Dialog', 'description' => 'A popup that opens on top of the entire page.'],
            'drawer' => ['name' => 'Drawer', 'description' => 'A panel that slides in from the edge of the screen.'],
            'field' => ['name' => 'Field', 'description' => 'A component that provides labeling and validation for form controls.'],
            'fieldset' => ['name' => 'Fieldset', 'description' => 'A native fieldset element with an easily stylable legend.'],
            'form' => ['name' => 'Form', 'description' => 'A native form element with consolidated error handling.'],
            'input' => ['name' => 'Input', 'description' => 'A native input element that automatically works with Field.'],
            'menu' => ['name' => 'Menu', 'description' => 'A list of actions in a dropdown, enhanced with keyboard navigation.'],
            'menubar' => ['name' => 'Menubar', 'description' => 'A menu bar providing commands and options for your application.'],
            'meter' => ['name' => 'Meter', 'description' => 'A graphical display of a numeric value within a range.'],
            'navigation-menu' => ['name' => 'Navigation Menu', 'description' => 'A collection of links and menus for website navigation.'],
            'number-field' => ['name' => 'Number Field', 'description' => 'A numeric input element with increment and decrement buttons, and a scrub area.'],
            'otp-field' => ['name' => 'OTP Field', 'description' => 'A one-time password input composed of individual character slots.'],
            'popover' => ['name' => 'Popover', 'description' => 'An accessible popup anchored to a button.'],
            'preview-card' => ['name' => 'Preview Card', 'description' => 'A link that shows a destination preview without interrupting keyboard or screen reader navigation.'],
            'progress' => ['name' => 'Progress', 'description' => 'Displays the status of a task that takes a long time.'],
            'radio-group' => ['name' => 'Radio Group', 'description' => 'Provides shared state to a series of radio buttons.'],
            'scroll-area' => ['name' => 'Scroll Area', 'description' => 'A native scroll container with custom scrollbars.'],
            'select' => ['name' => 'Select', 'description' => 'A common form component for choosing a predefined value in a dropdown menu.'],
            'separator' => ['name' => 'Separator', 'description' => 'A separator element accessible to screen readers.'],
            'slider' => ['name' => 'Slider', 'description' => 'An easily stylable range input.'],
            'switch' => ['name' => 'Switch', 'description' => 'A control that indicates whether a setting is on or off.'],
            'tabs' => ['name' => 'Tabs', 'description' => 'A component for toggling between related panels on the same page.'],
            'toast' => ['name' => 'Toast', 'description' => 'Generates toast notifications.'],
            'toggle' => ['name' => 'Toggle', 'description' => 'A two-state button that can be on or off.'],
            'toggle-group' => ['name' => 'Toggle Group', 'description' => 'Provides a shared state to a series of toggle buttons.'],
            'toolbar' => ['name' => 'Toolbar', 'description' => 'A container for grouping a set of buttons and controls.'],
            'tooltip' => ['name' => 'Tooltip', 'description' => 'A popup that appears when an element is hovered or focused, showing a hint for sighted users.'],
        ];
    }

    /** The id of a heading: `Open multiple panels` gives `open-multiple-panels`. */
    public static function anchor(string $title): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    }

    /**
     * The source of a demo component, as on base-ui.com: the `use` statements that it needs, the
     * constants that it reads, its function or its class, and the helper components of its file
     * that it calls. The demo is a `#[Component]` function or class of an example of the installed
     * phem/ui-base (its `extra.phem.examples` directory). The docblock is not in the source.
     */
    public static function source(string $component): string
    {
        foreach (glob(self::examples().'/*.phem') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            $definitions = self::definitions($code);

            if (!isset($definitions[$component])) {
                continue;
            }

            $constants = self::constants($code);
            $used = self::used($component, $constants + $definitions);
            $helpers = array_diff_key(array_intersect_key($definitions, $used), [$component => true]);

            // The constants, the demo, then its helpers, each in the order of the file.
            $blocks = array_filter([implode("\n", array_intersect_key($constants, $used)), $definitions[$component], ...array_values($helpers)]);
            $uses = self::uses($code, implode("\n", $blocks));

            return "<?phem\n\n".('' === $uses ? '' : $uses."\n\n").implode("\n\n", $blocks)."\n";
        }

        throw new \InvalidArgumentException(\sprintf('No example of phem/ui-base has the function or the class "%s".', $component));
    }

    /** The examples directory of the installed phem/ui-base: its `extra.phem.examples` key. */
    private static function examples(): string
    {
        $package = InstalledVersions::getInstallPath('phem/ui-base') ?? throw new \LogicException('phem/ui-base is not installed.');
        $composer = json_decode((string) file_get_contents($package.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        return $package.'/'.($composer['extra']['phem']['examples'] ?? throw new \LogicException('phem/ui-base gives no extra.phem.examples.'));
    }

    /**
     * The top-level functions and classes of a file, by name, with their attributes.
     *
     * @return array<string, string>
     */
    private static function definitions(string $code): array
    {
        preg_match_all('/^(?:#\[[^\n]+\]\n)*(?:function (\w+)\(|(?:final )?class (\w+)\b).*?^\}$/ms', $code, $matches, \PREG_SET_ORDER);

        $definitions = [];

        foreach ($matches as $match) {
            // A page is not a part of a demo.
            if (str_starts_with($match[0], '#[Page(')) {
                continue;
            }

            $definitions[$match[1] ?: $match[2]] = $match[0];
        }

        return $definitions;
    }

    /**
     * The top-level constants of a file, by name.
     *
     * @return array<string, string>
     */
    private static function constants(string $code): array
    {
        preg_match_all('/^const (\w+) = .*?;$/ms', $code, $matches, \PREG_SET_ORDER);

        return array_column($matches, 0, 1);
    }

    /**
     * The names of the demo and of the constants and definitions that it uses, also through the
     * others.
     *
     * @param array<string, string> $available
     *
     * @return array<string, true>
     */
    private static function used(string $component, array $available): array
    {
        $used = [$component => true];
        $pending = [$available[$component]];

        while (null !== $part = array_pop($pending)) {
            foreach ($available as $name => $definition) {
                if (isset($used[$name]) || 1 !== preg_match('/\b'.$name.'\b/', $part)) {
                    continue;
                }

                $used[$name] = true;
                $pending[] = $definition;
            }
        }

        return $used;
    }

    /**
     * The `use` statements of a file, with only the names that the function uses. The classes come
     * first, then the functions, each in the alphabetical order.
     */
    private static function uses(string $code, string $function): string
    {
        preg_match_all('/^use (function )?([\w\\\\]+?)(?:\\\\\{([^}]+)\})?;$/m', $code, $statements, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        $uses = [];

        foreach ($statements as [, $kind, $prefix, $group]) {
            $names = null === $group ? [$prefix] : array_map(static fn (string $name): string => $prefix.'\\'.trim($name), explode(',', $group));
            $names = array_filter($names, static fn (string $name): bool => 1 === preg_match('/\b'.preg_quote(substr((string) strrchr('\\'.$name, '\\'), 1), '/').'\b/', $function));

            if ([] === $names) {
                continue;
            }

            sort($names, \SORT_FLAG_CASE | \SORT_STRING);
            $uses[null === $kind ? 0 : 1][] = self::statement($kind ?? '', $names);
        }

        ksort($uses);

        return implode("\n", array_merge(...array_map(static function (array $statements): array {
            sort($statements, \SORT_FLAG_CASE | \SORT_STRING);

            return $statements;
        }, $uses)));
    }

    /**
     * One `use` statement: `use Phem\Node;`, or `use Phem\Ui\Base\{Accordion, AccordionItem};`. A
     * group stays on one line, also when it is longer than the code box.
     *
     * @param list<string> $names
     */
    private static function statement(string $kind, array $names): string
    {
        if (1 === \count($names)) {
            return 'use '.$kind.$names[0].';';
        }

        $prefix = substr($names[0], 0, (int) strrpos($names[0], '\\'));
        $short = array_map(static fn (string $name): string => substr($name, \strlen($prefix) + 1), $names);

        return 'use '.$kind.$prefix.'\{'.implode(', ', $short).'};';
    }
}
