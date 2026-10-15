'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { scan } = require('../src/scanner');

function delimiters(text) {
    return scan(text).delimiters.map(([start, end]) => [start, text.slice(start, end)]);
}

test('records the Twig delimiters in markup', () => {
    const text = '<?php\nfunction A($s)\n{\n    if ($s) { x(); }}\n    return <p a={{ s.a }}>{{ {b: 1}.b }}{% if x %}<B c={{ 1 }} />{% endif %}</p>;\n}\n';
    const at = (s, from = 0) => text.indexOf(s, from);
    assert.deepEqual(delimiters(text), [
        [at('{{ s.a'), '{{'],
        [at('}}>'), '}}'],
        [at('{{ {b'), '{{'],
        [at('}}{%'), '}}'],
        [at('{% if'), '{%'],
        [at('%}<B'), '%}'],
        [at('{{ 1'), '{{'],
        [at('}} />'), '}}'],
        [at('{% endif'), '{%'],
        [at('%}</p>'), '%}'],
    ]);
});

test('ignores braces in PHP code, strings, and comments', () => {
    assert.deepEqual(delimiters("<?php\n$a = '{{ x }}'; // {{ y }}\nif ($a) { if ($b) { f(); }}\n"), []);
});
