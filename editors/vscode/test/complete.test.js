'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { scan } = require('../src/scanner');
const helpers = require('./helpers');

const { apply, PAGE } = helpers;
let fixture;

test.before(async () => {
    fixture = await helpers.createProject();
});

test.after(() => fixture.cleanup());

const run = (source, file) => helpers.run(fixture, source, file);


test('the scanner finds the context at the end of the text', async () => {
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

test('a component tag adds a use function import', async () => {
    const { text, find } = (await run(PAGE + 'function Home()\n{\n    return <Lay|\n}\n'));
    const item = find('Layout');
    assert.ok(item);
    assert.equal(item.snippet, 'Layout title="${1}">$0</Layout>');
    assert.equal(apply(text, item), PAGE.replace('\n\n', '\n\nuse function App\\Components\\Layout;\n\n') + 'function Home()\n{\n    return <Lay\n}\n');
});

test('a page of another file is not a component tag', async () => {
    assert.equal((await run(PAGE + 'function Home()\n{\n    return <Ab|\n}\n')).find('About'), undefined);
    const about = PAGE + "use Symfony\\Component\\Routing\\Attribute\\Route;\n\n#[Route('/about')]\nfunction About()\n{\n    return <p><Ab|</p>;\n}\n";
    assert.equal((await run(about, 'src/pages/about.rhp')).find('About').detail, 'App\\Pages\\About (page)');
});

test('a class component adds a use import', async () => {
    const { text, find } = (await run(PAGE + 'use function App\\Components\\Layout;\n\nfunction Home()\n{\n    return <Layout title="x"><Cou|</Layout>;\n}\n'));
    const item = find('Counter');
    assert.equal(item.snippet, 'Counter max={{ ${1} }} />$0');
    assert.match(apply(text, item), /namespace App\\Pages;\n\nuse App\\Components\\Counter;\n\nuse function App\\Components\\Layout;\n/);
});

test('an import goes into a group on one line', async () => {
    const { text, find } = (await run(PAGE + 'use function App\\Components\\{Layout};\n\nfunction Home()\n{\n    return <Av|\n}\n'));
    assert.match(apply(text, find('Avatar')), /use function App\\Components\\\{Layout, Avatar\};/);
});

test('a component of the same namespace needs no import', async () => {
    const { find } = (await run('<?php\nnamespace App\\Components;\n\nfunction Home()\n{\n    return <Av|\n}\n', 'src/components/Home.rhp'));
    assert.deepEqual(find('Avatar').edits, []);
    assert.equal(find('Avatar').sortText[0], '0');
});

test('a tag with attributes completes only the name', async () => {
    const { find } = (await run(PAGE + 'function Home()\n{\n    return <Lay| title="x" />;\n}\n'));
    assert.equal(find('Layout').snippet, 'Layout');
});

test('a comparison is not a tag', async () => {
    const { items } = (await run(PAGE + 'function f($a, $b)\n{\n    return $a <|\n}\n'));
    assert.equal(items.some((i) => i.label === 'Layout'), false);
});

test('props, slots, and closing tags', async () => {
    const src = PAGE + 'use function App\\Components\\Layout;\nuse App\\Components\\Counter;\n\nfunction Home()\n{\n';
    let r = (await run(src + '    return <Layout |\n}\n'));
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['key', 'title']);
    assert.equal(r.find('title').snippet, 'title="${1}"');

    r = (await run(src + '    return <Counter max={{ 3 }} |\n}\n'));
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['key', 'label']);

    r = (await run(src + '    return <Layout title="x"><Layout:|\n}\n'));
    assert.equal(r.find('Layout:footer').snippet, 'Layout:footer>$0</Layout:footer>');

    r = (await run(src + '    return <Layout title="x"><p>a</|\n}\n'));
    assert.equal(r.find('p').insert, 'p>');

    r = (await run(src + '    return <button |\n}\n'));
    assert.ok(r.find('onClick'));
});

test('use statements complete namespaces and symbols', async () => {
    let r = (await run(PAGE + 'use Rephp\\|\n'));
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['Attribute', 'Html']);
    assert.equal(r.find('Attribute').insert, 'Attribute\\');
    assert.equal(r.find('Html').insert, 'Html;');

    r = (await run(PAGE + 'use Rephp\\Attribute\\{Component, |};\n'));
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['Component', 'State']);

    r = (await run(PAGE + 'use function Acme\\|\n'));
    assert.deepEqual(r.items.map((i) => i.label), ['slugify']);

    r = (await run(PAGE + 'use function App\\Components\\|\n'));
    assert.deepEqual(r.items.map((i) => i.label).sort(), ['Avatar', 'Layout']);
});

test('a class name adds an import', async () => {
    const src = PAGE + 'use Rephp\\Html;\n\nfunction Home()\n{\n    $r = new TodoRe|\n}\n';
    const { text, find } = (await run(src));
    const item = find('TodoRepository');
    assert.equal(item.insert, 'TodoRepository');
    assert.match(apply(text, item), /namespace App\\Pages;\n\nuse App\\Repository\\TodoRepository;\nuse Rephp\\Html;\n/);
    assert.equal(find('HtmlTest'), undefined);
    assert.deepEqual(find('Html').edits, []);

    const fn = (await run(PAGE + 'function Home()\n{\n    $s = slug|\n}\n'));
    assert.match(apply(fn.text, fn.find('slugify')), /use function Acme\\slugify;/);
});

test('no class names after -> or in strings', async () => {
    assert.equal((await run(PAGE + '$a->Todo|')).items.length, 0);
    assert.equal((await run(PAGE + '$a = "Todo|')).items.length, 0);
    assert.equal((await run(PAGE + 'function Todo|')).items.length, 0);
});
