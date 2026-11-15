<?php

declare(strict_types=1);

namespace App\Ui;

final class Gallery
{
    /** @return array<string, string> the slug and the name of each component with a demo */
    public static function components(): array
    {
        return [
            'accordion' => 'Accordion',
            'alert-dialog' => 'Alert Dialog',
            'autocomplete' => 'Autocomplete',
            'avatar' => 'Avatar',
            'button' => 'Button',
            'checkbox' => 'Checkbox',
            'checkbox-group' => 'Checkbox Group',
            'collapsible' => 'Collapsible',
            'combobox' => 'Combobox',
            'context-menu' => 'Context Menu',
            'dialog' => 'Dialog',
            'drawer' => 'Drawer',
            'field' => 'Field',
            'fieldset' => 'Fieldset',
            'form' => 'Form',
            'input' => 'Input',
            'menu' => 'Menu',
            'menubar' => 'Menubar',
            'meter' => 'Meter',
            'navigation-menu' => 'Navigation Menu',
            'number-field' => 'Number Field',
            'otp-field' => 'OTP Field',
            'popover' => 'Popover',
            'preview-card' => 'Preview Card',
            'progress' => 'Progress',
            'radio-group' => 'Radio Group',
            'scroll-area' => 'Scroll Area',
            'select' => 'Select',
            'separator' => 'Separator',
            'slider' => 'Slider',
            'switch' => 'Switch',
            'tabs' => 'Tabs',
            'toast' => 'Toast',
            'toggle' => 'Toggle',
            'toggle-group' => 'Toggle Group',
            'toolbar' => 'Toolbar',
            'tooltip' => 'Tooltip',
        ];
    }
}
