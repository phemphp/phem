<?php

declare(strict_types=1);

namespace App\Ui;

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
}
