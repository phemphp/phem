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
const fn = (markup) => helpers.PAGE + `function Home()\n{\n    return ${markup}\n}\n`;

test('< shows the HTML elements and the components', async () => {
    const r = await run(fn('<div><|'));
    assert.equal(r.find('section').snippet, 'section$1>$0</section>');
    assert.equal(r.find('img').snippet, 'img$1 />$0');
    assert.ok(r.find('Layout'));

    assert.equal((await run(fn('<div><inp| type="text" />'))).find('input').snippet, 'input');
    assert.equal((await run(fn('<div><Lay|'))).find('section'), undefined);
});

test('the attributes of an HTML element', async () => {
    const r = await run(fn('<input |'));
    for (const label of ['type', 'name', 'placeholder', 'id', 'class', 'className', 'aria-label', 'onChange', 'key']) {
        assert.ok(r.find(label), label);
    }
    assert.equal(r.find('href'), undefined);
    assert.equal(r.find('required').snippet, 'required');
    assert.equal(r.find('type').snippet, 'type="${1}"');
    assert.equal(r.find('style').snippet, 'style={{ {${1}} }}');

    const a = await run(fn('<a href="/" |'));
    assert.equal(a.find('href'), undefined, 'an attribute that is set');
    assert.ok(a.find('target'));
});

test('the values of an enumerated attribute', async () => {
    let r = await run(fn('<input type="|'));
    assert.ok(r.find('email'));
    assert.ok(r.find('checkbox'));

    r = await run(fn('<button type="s|"'));
    assert.deepEqual(r.labels, ['submit', 'button', 'reset']);
    assert.deepEqual(r.find('submit').range, [r.text.indexOf('s"'), r.text.indexOf('s"') + 1]);

    assert.ok((await run(fn('<a target="|'))).find('_blank'));
    assert.deepEqual((await run(fn('<input value="|'))).items, []);
    assert.deepEqual((await run(fn('<input constructor="|'))).items, [], 'a key of Object.prototype');
    assert.deepEqual((await run(fn('<input type="{{ |'))).items.filter((i) => i.kind === 'value'), []);
});
