<?php

/*
 * The tasks of the agent eval (`run.php`). Each task asks for one `.phem` file in an app that
 * installs phem/ui-base. A run passes when `phemc check` gives no error, the file uses each of
 * the `tags`, the code matches each regex of `must`, and no regex of `must_not`.
 *
 * Keep the tasks real: the work that an app asks of an agent, with state, handlers and more than
 * one family. Name the components only as a user would ("a dialog", "a dropdown menu").
 */

return [
    'settings-validation' => [
        'file' => 'src/Ui/ProfileForm.phem',
        'prompt' => 'Create src/Ui/ProfileForm.phem, namespace App\\Ui: a class component ProfileForm with a "Display name" text field that is bound to a state property, a "Save" button that runs a save handler, and an error message under the field when the handler finds the name empty. Use the field components of phem/ui-base for the label, the control and the error.',
        // The control of a Field is a FieldControl or an Input.
        'tags' => ['Field', 'FieldLabel', 'FieldError'],
        'must' => ['/#\[State\]/', '/<(FieldControl|Input)\b[^>]*phem:bind=/', '/on(Click|Submit)=\{\{\s*save/'],
        'must_not' => [],
    ],
    'people-search' => [
        'file' => 'src/Ui/ReviewerPicker.phem',
        'prompt' => 'Create src/Ui/ReviewerPicker.phem, namespace App\\Ui: a class component ReviewerPicker with a combobox of phem/ui-base that searches people on the server while the user types. Keep the query in a state property, and render the matching names of a fixed PHP array as the items. Show a text when no person matches.',
        'tags' => ['Combobox', 'ComboboxInput', 'ComboboxList', 'ComboboxItem', 'ComboboxEmpty'],
        'must' => ['/#\[State\]/'],
        'must_not' => [],
    ],
    'confirm-delete' => [
        'file' => 'src/Ui/ProjectList.phem',
        'prompt' => 'Create src/Ui/ProjectList.phem, namespace App\\Ui: a class component ProjectList that keeps a list of project names in state and renders them as a list. Each row has a "Delete" button that opens a confirmation dialog of phem/ui-base ("Delete this project?" with "Cancel" and "Delete"). The "Delete" button of the dialog removes the project with a server handler.',
        'tags' => ['AlertDialog', 'AlertDialogTrigger', 'AlertDialogPopup', 'AlertDialogTitle', 'AlertDialogClose'],
        'must' => ['/#\[State\]/', '/key=\{\{/', '/onClick=\{\{\s*\w+\(/'],
        'must_not' => [],
    ],
    'format-toolbar' => [
        'file' => 'src/Ui/FormatToolbar.phem',
        'prompt' => 'Create src/Ui/FormatToolbar.phem, namespace App\\Ui: a function component FormatToolbar with a toolbar of phem/ui-base. It has a group of toggle buttons Bold, Italic and Underline where more than one can be on, a separator, and a "Share" button. Each toggle has a tooltip with its name.',
        // A Toggle in a Tooltip is its trigger. The attributes of the controller are not the API.
        'tags' => ['Toolbar', 'ToggleGroup', 'Toggle', 'ToolbarSeparator', 'Tooltip', 'TooltipPopup'],
        'must' => ['/\bmultiple\b/'],
        'must_not' => ['/data-phem-tooltip-/'],
    ],
    'settings-tabs' => [
        'file' => 'src/Ui/SettingsTabs.phem',
        'prompt' => 'Create src/Ui/SettingsTabs.phem, namespace App\\Ui: a function component SettingsTabs with the tabs of phem/ui-base: "Account", "Notifications" and "Security", each with a panel that has a short paragraph. "Notifications" is the tab that is open first.',
        'tags' => ['Tabs', 'TabsList', 'TabsTab', 'TabsPanel'],
        'must' => ['/notifications/i'],
        'must_not' => [],
    ],
    'grouped-select' => [
        'file' => 'src/Ui/TimezoneSelect.phem',
        'prompt' => 'Create src/Ui/TimezoneSelect.phem, namespace App\\Ui: a class component TimezoneSelect with a select of phem/ui-base bound to a state property "timezone". The options are in two groups with a label each: "Europe" (Madrid, Paris) and "America" (New York, Lima).',
        'tags' => ['Select', 'SelectTrigger', 'SelectPopup', 'SelectGroup', 'SelectGroupLabel', 'SelectItem'],
        'must' => ['/#\[State\]/', '/timezone/'],
        'must_not' => [],
    ],
    'mobile-drawer' => [
        'file' => 'src/Ui/MobileNav.phem',
        'prompt' => 'Create src/Ui/MobileNav.phem, namespace App\\Ui: a function component MobileNav that takes an array of links (label => URL) as a prop and renders a "Menu" button that opens a drawer of phem/ui-base from the left side, with a title and the links as a list.',
        'tags' => ['Drawer', 'DrawerTrigger', 'DrawerPopup', 'DrawerTitle'],
        'must' => ['/key=\{\{/', '/swipeDirection=["{]+[^>]*left/i'],
        'must_not' => [],
    ],
    'otp-form' => [
        'file' => 'src/Ui/VerifyCode.phem',
        'prompt' => 'Create src/Ui/VerifyCode.phem, namespace App\\Ui: a class component VerifyCode with a 6-digit one-time code input of phem/ui-base and its label, in a form with a "Verify" submit button. The submit runs a server handler that keeps the code in a state property and shows "Code accepted".',
        'tags' => ['OTPField', 'OTPFieldInput'],
        'must' => ['/#\[State\]/', '/onSubmit=|onClick=/'],
        'must_not' => [],
    ],
];
