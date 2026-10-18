'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const helpers = require('./helpers');
const { symbolAt } = require('../src/resolve');

let fixture;

test.before(async () => {
    fixture = await helpers.createProject();
});

test.after(() => fixture.cleanup());

// Resolves the symbol at the `‸` of `source`.
async function at(source, file = 'src/pages/index.phpm') {
    const { text, offset } = helpers.cursor(source);
    return symbolAt({ text, offset, file: path.join(fixture.root, file), project: fixture.project });
}

// The text at a location (the line, from the character).
function target(result) {
    assert.ok(result && result.location, 'no location');
    const text = fs.readFileSync(result.location.file, 'utf8');
    return [path.relative(fixture.root, result.location.file), text.split('\n')[result.location.line].slice(result.location.character)];
}

const USES = '<?phpm\nnamespace App\\Pages;\n\nuse App\\Model\\Scientist;\nuse App\\Repository\\ScientistRepository;\n\nuse function App\\Components\\Layout;\n\n';
const fn = (markup, body = '') => USES + `function Home(Scientist $s, ScientistRepository $repo)\n{\n${body}    return ${markup};\n}\n`;

test('a component tag goes to the component', async () => {
    const r = await at(fn('<Lay‸out title="x"></Layout>'));
    assert.deepEqual(target(r), ['src/components/Layout.phpm', 'Layout(string $title, ?Html $children = null, ?Html $footer = null)']);
    assert.match(r.hover, /function Layout\(string \$title/);

    assert.deepEqual(target(await at(fn('<Layout title="x"></Lay‸out>'))), target(r));
    assert.deepEqual(target(await at(fn('<Layout title="x"><Layout:foo‸ter>a</Layout:footer></Layout>'))), ['src/components/Layout.phpm', '$footer = null)']);
});

test('the built-in Root tag has a hover and no location', async () => {
    const r = await at(USES.replace('use App\\Model', 'use Mamu\\{Html, Root};\nuse App\\Model') + 'function Document(?Html $children = null)\n{\n    return <body><Ro‸ot tag="main">{{ children }}</Root></body>;\n}\n');
    assert.equal(r.location, null);
    assert.match(r.hover, /final class Root/);
    assert.match(r.hover, /root element of the page/);
    const tag = await at(USES.replace('use App\\Model', 'use Mamu\\Root;\nuse App\\Model') + 'function Document()\n{\n    return <Root ta‸g="main" />;\n}\n');
    assert.match(tag.hover, /Prop of `Mamu\\Root`/);
    assert.equal(tag.location, null);
});

test('a prop goes to the parameter or the property', async () => {
    assert.deepEqual(target(await at(fn('<Layout ti‸tle="x" />'))), ['src/components/Layout.phpm', '$title, ?Html $children = null, ?Html $footer = null)']);
    const r = await at(USES + 'use App\\Components\\Counter;\n\nfunction Home()\n{\n    return <Counter ma‸x={{ 3 }} />;\n}\n');
    assert.deepEqual(target(r), ['src/components/Counter.phpm', '$max;']);
    assert.match(r.hover, /int \$max/);
    assert.match(r.hover, /required/);
});

test('a Twig variable goes to its PHP declaration', async () => {
    let r = await at(fn('<p>{{ s‸.name }}</p>'));
    assert.equal(r.location.file, path.join(fixture.root, 'src/pages/index.phpm'));
    assert.match(r.hover, /Scientist \$s/);

    r = await at(fn('<p>{{ s.prof‸ession }}</p>'));
    assert.deepEqual(target(r), ['src/Model/Scientist.php', '$profession,']);

    r = await at(fn('<p>{{ s.full‸Name }}</p>'));
    assert.deepEqual(target(r), ['src/Model/Scientist.php', 'getFullName(): string']);
    assert.match(r.hover, /The name and the profession\./);

    r = await at(fn('<ul>{% for x in repo.all %}<li>{{ x.sl‸ug }}</li>{% endfor %}</ul>'));
    assert.deepEqual(target(r), ['src/Model/HasSlug.php', 'slug(): string']);

    r = await at(fn('<p>{{ user.na‸me }}</p>', "    $user = ['name' => 'Hedy'];\n"));
    assert.equal(r.location.line, 10);
});

test('a handler goes to the method', async () => {
    const src = '<?phpm\nnamespace App\\Components;\n\nuse Mamu\\Attribute\\Component;\n\n#[Component]\nfinal class Counter3\n{\n    public function increment(): void\n    {\n    }\n\n    public function render()\n    {\n        return <button onClick={{ incre‸ment }}>+</button>;\n    }\n}\n';
    const r = await at(src, 'src/components/Counter3.phpm');
    assert.equal(r.location.line, 8);
    assert.match(r.hover, /public function increment\(\): void/);
});

test('PHP names: classes, members, functions, and variables', async () => {
    let r = await at(fn('<p />', '    $x = new Scien‸tist(\'a\', \'b\');\n'));
    assert.deepEqual(target(r), ['src/Model/Scientist.php', 'Scientist extends Person']);
    assert.match(r.hover, /class App\\Model\\Scientist extends Person/);
    assert.match(r.hover, /A scientist\./);

    r = await at(fn('<p />', '    $all = $repo->a‸ll();\n'));
    assert.deepEqual(target(r), ['src/Repository/ScientistRepository.php', 'all(): array']);

    r = await at(fn('<p />', '    $n = $s->li‸kes;\n'));
    assert.deepEqual(target(r), ['src/Model/Scientist.php', '$likes = 0,']);

    r = await at(fn('<p />', '    $n = Scientist::MAX_LI‸KES;\n'));
    assert.deepEqual(target(r), ['src/Model/Scientist.php', 'MAX_LIKES = 10;']);

    r = await at(fn('<p />', '    $n = $s->a‸ge();\n'));
    assert.deepEqual(target(r), ['src/Model/Person.php', 'age(): int']);

    r = await at(fn('<p />', '    $all = $re‸po->all();\n'));
    assert.match(r.hover, /ScientistRepository \$repo/, 'a variable before ->');

    r = await at(fn('<p />', '    $n = $s->sta‸tus->name;\n'));
    assert.deepEqual(target(r), ['src/Model/Scientist.php', '$status = Status::Active;']);

    r = await at(fn('<p />', '    $x = 1;\n    echo $‸x;\n'));
    assert.equal(r.location.line, 10);

    r = await at('<?phpm\nnamespace App\\Pages;\n\nuse App\\Model\\Scien‸tist;\n');
    assert.deepEqual(target(r), ['src/Model/Scientist.php', 'Scientist extends Person']);

    r = await at('<?phpm\nnamespace App\\Pages;\n\nuse function Acme\\slugify;\n\n$a = slug‸ify("x");\n');
    assert.deepEqual(target(r), ['vendor/acme/lib/functions.php', 'slugify(string $s): string { return $s; }']);
});

test('hover for Twig filters, functions, tests, tags, and HTML', async () => {
    assert.match((await at(fn('<p>{{ s.name|up‸per }}</p>'))).hover, /uppercase/);
    assert.match((await at(fn('<p>{{ pa‸th("x") }}</p>'))).hover, /path\(name/);
    assert.match((await at(fn('<p>{% if s is emp‸ty %}{% endif %}</p>'))).hover, /empty/);
    assert.match((await at(fn('<p>{% fo‸r a in b %}{% endfor %}</p>'))).hover, /Loop over/);
    assert.match((await at(fn('<p>{% for a in b %}{% endf‸or %}</p>'))).hover, /end of the \{% for %\} block/);
    assert.match((await at(fn("<p>{% component 'A' %}{% endcomp‸onent %}</p>"))).hover, /end of the \{% component %\} block/);
    assert.match((await at(fn('<sec‸tion />'))).hover, /A generic section/);
    assert.match((await at(fn('<button onCl‸ick={{ x }} />'))).hover, /Mamu event/);
});

test('a route name goes to the page', async () => {
    const r = await at(fn("<a href={{ path('gall‸ery') }}>x</a>"));
    assert.deepEqual(target(r), ['src/pages/gallery.phpm', 'Gallery']);
    assert.match(r.hover, /\/gallery/);
});
