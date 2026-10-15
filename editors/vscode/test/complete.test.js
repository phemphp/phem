'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { scan } = require('../src/scanner');
const { Project } = require('../src/project');
const { complete } = require('../src/complete');

const FILES = {
    'composer.json': '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
    'vendor/composer/autoload_psr4.php': `<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Rephp\\\\' => array($vendorDir . '/rephp/runtime/src'),
    'App\\\\' => array($baseDir . '/src'),
);
`,
    'vendor/composer/autoload_files.php': `<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'abc' => $vendorDir . '/acme/lib/functions.php',
);
`,
    'vendor/acme/lib/functions.php': '<?php\nnamespace Acme;\n\nfunction slugify(string $s): string { return $s; }\n',
    'vendor/rephp/runtime/src/Html.php': '<?php\n',
    'vendor/rephp/runtime/src/Attribute/Component.php': '<?php\n',
    'vendor/rephp/runtime/src/Attribute/Page.php': '<?php\n',
    'vendor/rephp/runtime/src/Attribute/State.php': '<?php\n',
    'vendor/rephp/runtime/tests/HtmlTest.php': '<?php\n',
    'src/Repository/TodoRepository.php': '<?php\n',
    'src/components/Layout.rhp': `<?php
namespace App\\Components;

use Rephp\\Html;

function Layout(string $title, ?Html $children = null, ?Html $footer = null)
{
    return <main><h1>{{ title }}</h1>{{ children }}<footer>{{ footer }}</footer></main>;
}

function Avatar(string $name, int $size = 64)
{
    return <img alt={{ name }} width={{ size }} />;
}
`,
    'src/components/Counter.rhp': `<?php
namespace App\\Components;

use Rephp\\Attribute\\{Component, State};

#[Component]
final class Counter
{
    public string $label = 'Clicked';
    public int $max;
    #[State] public int $count = 0;

    public function __construct(private readonly \\App\\Repository\\TodoRepository $todos)
    {
    }

    public function render()
    {
        return <button onClick={{ increment }}>{{ label }} {{ count }}</button>;
    }
}
`,
};

let root;
let project;

test.before(async () => {
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'rhp-test-'));
    for (const [name, content] of Object.entries(FILES)) {
        fs.mkdirSync(path.dirname(path.join(root, name)), { recursive: true });
        fs.writeFileSync(path.join(root, name), content);
    }
    project = await new Project(root).load();
});

test.after(() => {
    fs.rmSync(root, { recursive: true, force: true });
});

// Runs the completion at the `|` of `source`.
function run(source, file = 'src/pages/index.rhp') {
    const offset = source.indexOf('|');
    const text = source.slice(0, offset) + source.slice(offset + 1);
    const items = complete({ text, offset, file: path.join(root, file), project });
    return { text, items, find: (label) => items.find((i) => i.label === label) };
}

function apply(text, item) {
    let out = text;
    for (const e of [...item.edits].sort((a, b) => b.offset - a.offset)) {
        out = out.slice(0, e.offset) + e.text + out.slice(e.offset);
    }
    return out;
}

const PAGE = '<?php\nnamespace App\\Pages;\n\n';

test('the scanner finds the context at the end of the text', () => {
    const ctx = (s) => scan(s).context;
    assert.equal(ctx('<?php\n$a = $b < ').kind, 'php');
    assert.equal(ctx('<?php\nreturn <').kind, 'tagName');
    assert.equal(ctx('<?php\nreturn <Lay').partial, 'Lay');
    assert.equal(ctx('<?php\nreturn <div>{{ ok ? <Ca').partial, 'Ca');
    assert.equal(ctx('<?php\nreturn <div className="a">I\'m <b>x</b> <Lay').kind, 'tagName');
    assert.deepEqual(ctx('<?php\nreturn <Layout title="x" ').attrs, ['title']);
    assert.equal(ctx('<?php\nreturn <Layout style={{ {a: 1} }} ').tag, 'Layout');
    assert.equal(ctx('<?php\nreturn <div><p></').expected, 'p');
    assert.equal(ctx('<?php\n$x = <p>a</p>;\n$y = 1').kind, 'php');
    assert.equal(ctx('<?php\n$x = "<p>').string, true);
    assert.equal(ctx('<?php\nreturn <ul>{% for a in b %}<li>{{ a').kind, 'twig');
    assert.equal(ctx('<?php\nreturn <script>if (a < b) {').kind, 'raw');
});

test('a component tag adds a use function import', () => {
    const { text, find } = run(PAGE + 'function Home()\n{\n    return <Lay|\n}\n');
    const item = find('Layout');
    assert.ok(item);
    assert.equal(item.snippet, 'Layout title="${1}">$0</Layout>');
    assert.equal(apply(text, item), PAGE.replace('\n\n', '\n\nuse function App\\Components\\Layout;\n\n') + 'function Home()\n{\n    return <Lay\n}\n');
});

test('a class component adds a use import', () => {
    const { text, find } = run(PAGE + 'use function App\\Components\\Layout;\n\nfunction Home()\n{\n    return <Layout title="x"><Cou|</Layout>;\n}\n');
    const item = find('Counter');
    assert.equal(item.snippet, 'Counter max={{ ${1} }} />$0');
    assert.match(apply(text, item), /namespace App\\Pages;\n\nuse App\\Components\\Counter;\n\nuse function App\\Components\\Layout;\n/);
});

test('an import goes into a group on one line', () => {
    const { text, find } = run(PAGE + 'use function App\\Components\\{Layout};\n\nfunction Home()\n{\n    return <Av|\n}\n');
    assert.match(apply(text, find('Avatar')), /use function App\\Components\\\{Layout, Avatar\};/);
});

test('a component of the same namespace needs no import', () => {
    const { find } = run('<?php\nnamespace App\\Components;\n\nfunction Home()\n{\n    return <Av|\n}\n', 'src/components/Home.rhp');
    assert.deepEqual(find('Avatar').edits, []);
    assert.equal(find('Avatar').sortText[0], '0');
});

test('a tag with attributes completes only the name', () => {
    const { find } = run(PAGE + 'function Home()\n{\n    return <Lay| title="x" />;\n}\n');
    assert.equal(find('Layout').snippet, 'Layout');
});

test('a comparison is not a tag', () => {
    const { items } = run(PAGE + 'function f($a, $b)\n{\n    return $a <|\n}\n');
    assert.equal(items.some((i) => i.label === 'Layout'), false);
});

test('props, slots, and closing tags', () => {
    const src = PAGE + 'use function App\\Components\\Layout;\nuse App\\Components\\Counter;\n\nfunction Home()\n{\n';
    let r = run(src + '    return <Layout |\n}\n');
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['key', 'title']);
    assert.equal(r.find('title').snippet, 'title="${1}"');

    r = run(src + '    return <Counter max={{ 3 }} |\n}\n');
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['key', 'label']);

    r = run(src + '    return <Layout title="x"><Layout:|\n}\n');
    assert.equal(r.find('Layout:footer').snippet, 'Layout:footer>$0</Layout:footer>');

    r = run(src + '    return <Layout title="x"><p>a</|\n}\n');
    assert.equal(r.find('p').insert, 'p>');

    r = run(src + '    return <button |\n}\n');
    assert.ok(r.find('onClick'));
});

test('use statements complete namespaces and symbols', () => {
    let r = run(PAGE + 'use Rephp\\|\n');
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['Attribute', 'Html']);
    assert.equal(r.find('Attribute').insert, 'Attribute\\');
    assert.equal(r.find('Html').insert, 'Html;');

    r = run(PAGE + 'use Rephp\\Attribute\\{Component, |};\n');
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['Component', 'Page', 'State']);

    r = run(PAGE + 'use function Acme\\|\n');
    assert.deepEqual(r.items.map((i) => i.label), ['slugify']);

    r = run(PAGE + 'use function App\\Components\\|\n');
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['Avatar', 'Layout']);
});

test('a class name adds an import', () => {
    const src = PAGE + 'use Rephp\\Html;\n\nfunction Home()\n{\n    $r = new TodoRe|\n}\n';
    const { text, find } = run(src);
    const item = find('TodoRepository');
    assert.equal(item.insert, 'TodoRepository');
    assert.match(apply(text, item), /namespace App\\Pages;\n\nuse App\\Repository\\TodoRepository;\nuse Rephp\\Html;\n/);
    assert.equal(find('HtmlTest'), undefined);
    assert.deepEqual(find('Html').edits, []);

    const fn = run(PAGE + 'function Home()\n{\n    $s = slug|\n}\n');
    assert.match(apply(fn.text, fn.find('slugify')), /use function Acme\\slugify;/);
});

test('no class names after -> or in strings', () => {
    assert.equal(run(PAGE + '$a->Todo|').items.length, 0);
    assert.equal(run(PAGE + '$a = "Todo|').items.length, 0);
    assert.equal(run(PAGE + 'function Todo|').items.length, 0);
});
