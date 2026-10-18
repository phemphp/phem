'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { parse } = require('../src/php');
const { documentSymbols } = require('../src/symbols');

const SOURCE = `<?phpm
namespace App\\Pages;

use Mamu\\Attribute\\{Component, State};
use Mamu\\Html;
use Symfony\\Component\\Routing\\Attribute\\Route;

#[Component]
function Card(string $title, ?Html $footer = null, ?Html $children = null, mixed ...$rest)
{
    return <div>{{ title }}</div>;
}

#[Route('/counter')]
#[Component]
final class Counter
{
    public const MAX = 10;

    public string $label = 'Clicked';
    #[State] public int $count = 0;

    public function __construct(private readonly \\App\\Repository\\TodoRepository $todos)
    {
    }

    public function increment(): void
    {
        ++$this->count;
    }

    public function render()
    {
        return <button onClick={{ increment }}>{{ label }}</button>;
    }
}
`;

test('the outline has the namespace, the components, and the members', () => {
    const [ns] = documentSymbols(parse(SOURCE), SOURCE);
    assert.equal(ns.name, 'App\\Pages');
    assert.equal(ns.kind, 'namespace');
    const [card, counter] = ns.children;

    assert.equal(card.name, 'Card');
    assert.equal(card.detail, 'component');
    assert.deepEqual(card.children.map((c) => [c.name, c.detail]), [
        ['title', 'prop: string'],
        ['footer', 'slot'],
        ['children', 'children'],
        ['rest', 'rest props'],
    ]);
    assert.equal(SOURCE.slice(...card.selection), 'Card');

    assert.equal(counter.kind, 'class');
    assert.equal(counter.detail, 'page /counter');
    assert.deepEqual(counter.children.map((c) => [c.name, c.kind, c.detail]), [
        ['MAX', 'constant', ''],
        ['label', 'property', 'prop: string'],
        ['count', 'property', 'state: int'],
        ['todos', 'property', '\\App\\Repository\\TodoRepository'],
        ['__construct', 'constructor', ''],
        ['increment', 'method', 'void'],
        ['render', 'method', ''],
    ]);
    const label = counter.children[1];
    assert.equal(SOURCE.slice(...label.selection), 'label');
    const increment = counter.children[5];
    assert.match(SOURCE.slice(...increment.range), /^public function increment\(\): void\n    \{\n        \+\+\$this->count;\n    \}$/);
});
