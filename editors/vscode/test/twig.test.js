'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const helpers = require('./helpers');

let fixture;

test.before(async () => {
    fixture = await helpers.createProject();
});

test.after(() => fixture.cleanup());

const run = (source, file) => helpers.run(fixture, source, file);

const USES = '<?php\nnamespace App\\Pages;\n\nuse App\\Model\\Scientist;\nuse App\\Repository\\ScientistRepository;\n\n';
const fn = (body, markup) => USES + `function Home(Scientist $s, ScientistRepository $repo)\n{\n${body}    return <div>${markup}</div>;\n}\n`;

test('{{ shows the variables of the scope and the functions', async () => {
    const r = await run(fn('    $count = 3;\n', '{{ |'));
    assert.ok(r.find('s'));
    assert.ok(r.find('repo'));
    assert.ok(r.find('count'));
    assert.ok(r.find('path'));
    assert.ok(r.find('form_widget'));
    assert.equal(r.find('rhp_attr'), undefined);
    assert.equal(r.find('path').snippet, 'path($1)$0');
});

test('a dot shows the attributes of an object, with getters as names', async () => {
    const r = await run(fn('', '{{ s.|'));
    for (const label of ['name', 'profession', 'likes', 'fullName', 'famous', 'like', 'slug', 'magic']) {
        assert.ok(r.find(label), label);
    }
    assert.equal(r.find('fullName').detail, 'getFullName(): string');
    assert.equal(r.find('fullName').insert, 'fullName');
    assert.equal(r.find('getFullName'), undefined, 'the getter shows once, as a name');
    assert.equal(r.find('secret'), undefined);
    assert.equal(r.find('like').snippet, 'like');
});

test('the item of a for loop has the type of the items', async () => {
    let r = await run(fn('    $all = $repo->all();\n', '{% for x in all %}{{ x.|'));
    assert.ok(r.find('profession'));

    r = await run(fn('', '{% for x in repo.all %}{% if x.famous %}{{ x.status.|'));
    assert.ok(r.find('name'));

    r = await run(fn('', '{% for x in repo.all %}{{ loop.|'));
    assert.ok(r.find('index'));
    assert.ok(r.find('last'));

    r = await run(fn('', '{% for x in repo.all %}{% endfor %}{{ |'));
    assert.equal(r.find('x'), undefined, 'the loop variable is out of scope');
    assert.equal(r.find('loop'), undefined);

    r = await run(fn('', '{% set best = repo.find(1) %}{{ best.|'));
    assert.ok(r.find('profession'), 'the type of a {% set %} variable');
});

test('the keys of an array shape', async () => {
    const r = await run(fn("    $user = ['name' => 'Hedy', 'size' => 90, 'pet' => ['kind' => 'cat']];\n", '{{ user.pet.|'));
    assert.deepEqual(r.labels, ['kind']);
    const list = await run(fn("    $products = [['title' => 'A', 'id' => 1]];\n", '{% for p in products %}{{ p.|'));
    assert.deepEqual(list.labels.sort(), ['id', 'title']);
});

test('the properties and the methods of a class component', async () => {
    const src = '<?php\nnamespace App\\Components;\n\nuse Rephp\\Attribute\\Component;\n\n#[Component]\nfinal class Counter2\n{\n    public int $count = 0;\n    private string $label = "x";\n\n    public function increment(): void\n    {\n    }\n\n    public function render()\n    {\n        $double = $this->count * 2;\n\n        return <button onClick={{ |}}>{{ count }}</button>;\n    }\n}\n';
    const r = await run(src, 'src/components/Counter2.rhp');
    assert.ok(r.find('double'));
    assert.ok(r.find('count'));
    assert.ok(r.find('label'), 'a private property works in the markup of the class');
    assert.equal(r.find('increment').kind, 'method');
    assert.equal(r.items.find((i) => i.label === 'render' && i.kind === 'method'), undefined);
});

test('filters, tests, and tags', async () => {
    let r = await run(fn('', '{{ s.name|up‸'));
    assert.ok(r.find('upper'));
    assert.ok(r.find('trans'));

    r = await run(fn('', '{% if s.name is not ‸'));
    assert.ok(r.find('empty'));
    assert.equal(r.find('divisible by').snippet, 'divisible by(${1})');

    r = await run(fn('', '{% |'));
    assert.equal(r.find('for').snippet, 'for ${1:item} in ${2:items} %}$0{% endfor %}');

    r = await run(fn('', '{% for a in b %}{% if a %}{% |'));
    assert.equal(r.items[0].label, 'endif');
    assert.equal(r.find('endif').snippet, 'endif %}');
    assert.ok(r.find('endfor'));
    assert.ok(r.find('else'));

    // The item replaces the ` %}` after the cursor, or the `}` of an auto-closed `{`.
    r = await run(fn('', '{% for a in b %}{% end| -%}'));
    assert.equal(r.find('endfor').snippet, 'endfor -%}');
    assert.equal(r.text.slice(...r.find('endfor').range), 'end -%}');
    r = await run(fn('', '{% for a in b %}{%|}'));
    assert.equal(r.text.slice(...r.find('endfor').range), '}');
});

test('no names for a new variable', async () => {
    assert.deepEqual((await run(fn('', '{% for |'))).items, []);
    assert.deepEqual((await run(fn('', '{% set |'))).items, []);
});

test('path() shows the route names', async () => {
    const r = await run(fn('', "<a href={{ path('|') }}>x</a>"));
    assert.ok(r.find('gallery'));
    assert.equal(r.find('gallery').detail, '/gallery');
    assert.equal(r.find('app_pages_gallery_1').detail, '/scientists/{id}');
    assert.ok(r.find('app_pages_about'));
    assert.deepEqual((await run(fn('', "{{ 'a|' }}"))).items, []);
});

test('an operator as a trigger character opens no list', async () => {
    const trig = (source, c) => helpers.run(fixture, source, undefined, c);
    assert.deepEqual((await trig(fn('', '{{ s.likes >|'), '>')).items, []);
    assert.deepEqual((await trig(fn('', '{{ s.likes <|'), '<')).items, []);
    assert.deepEqual((await trig(fn('', '{{ s.likes /|'), '/')).items, []);
    assert.deepEqual((await trig(fn('', '{{ s.likes %|'), '%')).items, []);
    assert.ok((await trig(fn('', '{%|'), '%')).find('for'));
    assert.ok((await trig(USES + 'function Home(Scientist $s)\n{\n    $s->|\n}\n', '>')).find('name'));
});
