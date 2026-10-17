'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { parse } = require('../src/php');
const { routesOf } = require('../src/project');

const SOURCE = `<?phpm
namespace App\\Pages;

use Mamu\\Attribute\\{Component, Page};
use Symfony\\Component\\Routing\\Attribute\\Route;

#[Page('/home', name: 'home')]
#[Page(path: '/start')]
final class Home extends Document
{
    public function render() { return <p />; }
}

#[Route('/legacy')]
#[Component]
final class Legacy extends Document
{
    public function render() { return <p />; }
}

#[Route('/plain')]
final class Plain
{
}

#[Page('/function')]
function Fn()
{
    return <p />;
}
`;

test('a page is a class with #[Page], or with #[Route] and #[Component]', () => {
    const routes = routesOf(parse(SOURCE), 'src/pages/home.phpm').map((r) => [r.name, r.path, r.fqn]);

    assert.deepEqual(routes, [
        ['home', '/home', 'App\\Pages\\Home'],
        ['app_pages_home_1', '/start', 'App\\Pages\\Home'],
        ['app_pages_legacy', '/legacy', 'App\\Pages\\Legacy'],
    ]);
});

test('a #[Page] class is a component', () => {
    const parsed = parse(SOURCE);
    const home = parsed.components.find((c) => c.name === 'Home');
    assert.ok(home, 'a component');
    assert.equal(home.page, true);
    assert.equal(parsed.components.find((c) => c.name === 'Plain'), undefined, '#[Route] without #[Component]');
    assert.equal(parsed.components.find((c) => c.name === 'Fn').page, false, 'a function is not a page');
});
