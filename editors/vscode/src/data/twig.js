'use strict';

// The tags, filters, functions, and tests of Twig 3 and of the Symfony Twig
// bridge, for completion and hover. Each entry is [signature, description].
// The phem_* functions are internal to phemc, so they are not here.

// name: [snippet, description, end tag]. The snippet goes after `{% `.
// A lookup table with no prototype: a key such as `constructor` is not in it.
function table(entries) {
    return Object.assign(Object.create(null), entries);
}

const TAGS = table({
    for: ['for ${1:item} in ${2:items} %}$0{% endfor', 'Loop over the items of a sequence', 'endfor'],
    if: ['if ${1:condition} %}$0{% endif', 'Render a part when a condition is true', 'endif'],
    else: ['else', 'The other branch of an if or a for (when the sequence is empty)'],
    elseif: ['elseif ${1:condition}', 'Another branch of an if'],
    set: ['set ${1:name} = ${2:value}', 'Assign a value to a variable'],
    apply: ['apply ${1:upper} %}$0{% endapply', 'Apply filters to a block of markup', 'endapply'],
    with: ['with ${1:{}} %}$0{% endwith', 'A new inner scope', 'endwith'],
    do: ['do ${1:expression}', 'Evaluate an expression and print nothing'],
    include: ["include '${1:template}'", 'Include a template'],
    embed: ["embed '${1:template}' %}$0{% endembed", 'Include a template and replace its blocks', 'endembed'],
    autoescape: ["autoescape '${1:html}' %}$0{% endautoescape", 'Escape a block with a strategy', 'endautoescape'],
    verbatim: ['verbatim %}$0{% endverbatim', 'Do not parse a block as Twig', 'endverbatim'],
    cache: ["cache '${1:key}' %}$0{% endcache", 'Cache a part of the template (twig/cache-extra)', 'endcache'],
    guard: ['guard ${1:function} ${2:name} %}$0{% endguard', 'Render a part only if a function, filter, or test exists', 'endguard'],
    types: ['types {${1:name}: \'${2:string}\'}', 'Declare the types of the variables'],
    deprecated: ["deprecated '${1:message}'", 'Trigger a deprecation'],
    flush: ['flush', 'Flush the output buffer'],
    sandbox: ['sandbox %}$0{% endsandbox', 'Enable the sandbox for included templates', 'endsandbox'],
    block: ['block ${1:name} %}$0{% endblock', 'A block that a child template can replace', 'endblock'],
    macro: ['macro ${1:name}(${2}) %}$0{% endmacro', 'A reusable template function', 'endmacro'],
    import: ["import '${1:template}' as ${2:macros}", 'Import macros'],
    from: ["from '${1:template}' import ${2:macro}", 'Import some macros'],
    trans: ['trans %}$0{% endtrans', 'Translate a block of text (Symfony)', 'endtrans'],
    trans_default_domain: ["trans_default_domain '${1:messages}'", 'The default translation domain of the template (Symfony)'],
    form_theme: ["form_theme ${1:form} '${2:theme}'", 'The form theme of a form (Symfony)'],
    stopwatch: ["stopwatch '${1:event}' %}$0{% endstopwatch", 'Time a part of the template (Symfony)', 'endstopwatch'],
});

// The tags that open a block, with their end tag.
const BLOCK_TAGS = table(Object.fromEntries(Object.entries(TAGS).filter(([, t]) => t[2]).map(([name, t]) => [name, t[2]])));

const FILTERS = table({
    abs: ['abs', 'The absolute value'],
    batch: ['batch(size, fill = null, preserve_keys = true)', 'Split a sequence into chunks of a size'],
    capitalize: ['capitalize', 'The first character in uppercase, the others in lowercase'],
    column: ['column(name, index = null)', 'The values of a column of a sequence of rows'],
    convert_encoding: ['convert_encoding(to, from)', 'Convert a string to another encoding'],
    date: ['date(format = null, timezone = null)', 'Format a date'],
    date_modify: ['date_modify(modifier)', 'Change a date with a modifier string'],
    default: ['default(value = \'\')', 'The value, or the default when it is undefined or empty'],
    escape: ['escape(strategy = \'html\', charset = null)', 'Escape a string (html, js, css, url, html_attr)'],
    e: ['e(strategy = \'html\', charset = null)', 'Alias of escape'],
    filter: ['filter(arrow)', 'The items for which the arrow function returns true'],
    find: ['find(arrow)', 'The first item for which the arrow function returns true'],
    first: ['first', 'The first item of a sequence, or the first character of a string'],
    format: ['format(...values)', 'Format a string with placeholders (sprintf)'],
    invoke: ['invoke(...arguments)', 'Call an arrow function'],
    join: ['join(glue = \'\', and = null)', 'Join the items of a sequence'],
    json_encode: ['json_encode(options = null)', 'The JSON value'],
    keys: ['keys', 'The keys of a sequence or a mapping'],
    last: ['last', 'The last item of a sequence, or the last character of a string'],
    length: ['length', 'The number of items, or the length of a string'],
    lower: ['lower', 'The string in lowercase'],
    map: ['map(arrow)', 'Apply an arrow function to each item'],
    merge: ['merge(values)', 'Merge two sequences or mappings'],
    nl2br: ['nl2br', 'Insert <br /> before each line break'],
    number_format: ['number_format(decimals = 0, decimal_point = \'.\', thousand_sep = \',\')', 'Format a number'],
    raw: ['raw', 'Do not escape the value'],
    reduce: ['reduce(arrow, initial = null)', 'Reduce a sequence to one value'],
    replace: ['replace(from)', 'Replace placeholders in a string: {"%name%": name}'],
    reverse: ['reverse(preserve_keys = false)', 'The items or characters in reverse order'],
    round: ['round(precision = 0, method = \'common\')', 'Round a number (common, ceil, floor)'],
    shuffle: ['shuffle', 'The items or characters in a random order'],
    slice: ['slice(start, length = null, preserve_keys = false)', 'A part of a sequence or a string'],
    sort: ['sort(arrow = null)', 'Sort a sequence'],
    spaceless: ['spaceless', 'Remove the whitespace between HTML tags'],
    split: ['split(delimiter, limit = null)', 'Split a string into a sequence'],
    striptags: ['striptags(allowed = null)', 'Remove the HTML tags'],
    title: ['title', 'Each word with its first character in uppercase'],
    trim: ['trim(characters = null, side = \'both\')', 'Remove whitespace (or characters) from the start and the end'],
    upper: ['upper', 'The string in uppercase'],
    url_encode: ['url_encode', 'Encode a string or a mapping for a URL'],
    // Symfony
    trans: ['trans(arguments = [], domain = null, locale = null)', 'Translate a message (Symfony)'],
    humanize: ['humanize', 'A readable text from a technical name (Symfony)'],
    sanitize_html: ['sanitize_html(sanitizer = null)', 'Sanitize HTML (Symfony HtmlSanitizer)'],
    serialize: ['serialize(format = \'json\', context = [])', 'Serialize a value (Symfony Serializer)'],
    yaml_encode: ['yaml_encode(inline = 0, dumpObjects = 0)', 'The YAML value (Symfony)'],
    yaml_dump: ['yaml_dump(inline = 0, dumpObjects = 0)', 'Dump a value as YAML (Symfony)'],
});

const FUNCTIONS = table({
    attribute: ['attribute(object, method, arguments = [])', 'A dynamic attribute of a variable'],
    block: ['block(name, template = null)', 'The content of a block'],
    constant: ['constant(name, object = null)', 'The value of a PHP constant: constant(\'App\\\\Foo::BAR\')'],
    cycle: ['cycle(values, position)', 'Cycle over a sequence of values'],
    date: ['date(date = null, timezone = null)', 'A date, for comparison'],
    dump: ['dump(...values)', 'Dump variables (in debug mode)'],
    enum: ['enum(name)', 'An enum: enum(\'App\\\\Status\').Active'],
    enum_cases: ['enum_cases(name)', 'The cases of an enum'],
    include: ['include(template, variables = [], with_context = true)', 'The rendered content of a template'],
    max: ['max(...values)', 'The largest value'],
    min: ['min(...values)', 'The smallest value'],
    parent: ['parent()', 'The content of the parent block'],
    random: ['random(values = null, max = null)', 'A random value'],
    range: ['range(low, high, step = 1)', 'A sequence of numbers or letters'],
    source: ['source(name, ignore_missing = false)', 'The source code of a template'],
    template_from_string: ['template_from_string(template, name = null)', 'A template from a string'],
    // Symfony
    path: ['path(name, parameters = [], relative = false)', 'The relative URL of a route'],
    url: ['url(name, parameters = [], schemeRelative = false)', 'The absolute URL of a route'],
    asset: ['asset(path, packageName = null)', 'The public URL of an asset'],
    asset_version: ['asset_version(path, packageName = null)', 'The version of an asset'],
    absolute_url: ['absolute_url(path)', 'The absolute URL of a path'],
    relative_path: ['relative_path(path)', 'The relative path from the current URL'],
    csrf_token: ['csrf_token(intention)', 'A CSRF token'],
    is_granted: ['is_granted(role, object = null, field = null)', 'True if the user has a role or a permission'],
    is_granted_for_user: ['is_granted_for_user(user, attribute, subject = null)', 'True if a user has a role or a permission'],
    logout_path: ['logout_path(firewall = null)', 'The relative logout URL'],
    logout_url: ['logout_url(firewall = null)', 'The absolute logout URL'],
    impersonation_path: ['impersonation_path(identifier)', 'The URL to impersonate a user'],
    impersonation_exit_path: ['impersonation_exit_path(exitTo = null)', 'The URL to stop the impersonation'],
    controller: ['controller(controller, attributes = [], query = [])', 'A reference to a controller, for render()'],
    render: ['render(uri, options = [])', 'The response of a controller or a URL'],
    render_esi: ['render_esi(uri, options = [])', 'The response of a controller, as an ESI tag'],
    t: ['t(message, parameters = [], domain = null)', 'A translatable message (Symfony)'],
    importmap: ['importmap(entryPoint = \'app\', attributes = [])', 'The importmap and the entry point scripts (AssetMapper)'],
    form: ['form(view, variables = [])', 'The full HTML of a form'],
    form_start: ['form_start(view, variables = [])', 'The start tag of a form'],
    form_end: ['form_end(view, variables = [])', 'The end tag of a form, and the fields not rendered yet'],
    form_widget: ['form_widget(view, variables = [])', 'The HTML widget of a field'],
    form_errors: ['form_errors(view)', 'The errors of a field'],
    form_label: ['form_label(view, label = null, variables = [])', 'The label of a field'],
    form_help: ['form_help(view)', 'The help text of a field'],
    form_row: ['form_row(view, variables = [])', 'The label, the widget, the help, and the errors of a field'],
    form_rest: ['form_rest(view, variables = [])', 'The fields not rendered yet'],
    field_name: ['field_name(view)', 'The name of a field'],
    field_value: ['field_value(view)', 'The value of a field'],
    field_label: ['field_label(view)', 'The label of a field'],
    field_help: ['field_help(view)', 'The help text of a field'],
    field_errors: ['field_errors(view)', 'The error messages of a field'],
    field_choices: ['field_choices(view)', 'The choices of a field'],
});

// name: [text to insert, description]
const TESTS = table({
    constant: ['constant(${1})', 'True if the value is equal to a constant'],
    defined: ['defined', 'True if the variable is defined'],
    'divisible by': ['divisible by(${1})', 'True if the number is divisible by a number'],
    empty: ['empty', 'True if the value is empty'],
    even: ['even', 'True if the number is even'],
    iterable: ['iterable', 'True if the value is a sequence or a mapping'],
    mapping: ['mapping', 'True if the value is a mapping'],
    null: ['null', 'True if the value is null (also: none)'],
    odd: ['odd', 'True if the number is odd'],
    'same as': ['same as(${1})', 'True if the value is identical (===) to another'],
    sequence: ['sequence', 'True if the value is a sequence'],
    rootform: ['rootform', 'True if the form view is the root form (Symfony)'],
    selectedchoice: ['selectedchoice(${1})', 'True if the choice is selected (Symfony)'],
});

const KEYWORDS = ['true', 'false', 'null', 'not', 'and', 'or', 'in', 'is', 'matches', 'starts with', 'ends with', 'b-and', 'b-or', 'b-xor'];

// The attributes of the `loop` variable in a `for`.
const LOOP = table({
    index: 'The current iteration (1 indexed)',
    index0: 'The current iteration (0 indexed)',
    revindex: 'The number of iterations to the end (1 indexed)',
    revindex0: 'The number of iterations to the end (0 indexed)',
    first: 'True for the first iteration',
    last: 'True for the last iteration',
    length: 'The number of items',
    parent: 'The parent context',
});

module.exports = { TAGS, BLOCK_TAGS, FILTERS, FUNCTIONS, TESTS, KEYWORDS, LOOP };
