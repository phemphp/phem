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

const USES = '<?phpm\nnamespace App\\Pages;\n\nuse App\\Model\\Scientist;\nuse App\\Model\\Status;\nuse App\\Repository\\ScientistRepository;\n\n';

test('$x-> shows the public members of the type, with inheritance', async () => {
    const r = await run(USES + 'function Home(Scientist $s)\n{\n    $s->|\n}\n');
    for (const label of ['id', 'name', 'profession', 'likes', 'status', 'getFullName', 'like', 'age', 'slug', 'displayName']) {
        assert.ok(r.find(label), label);
    }
    // Private, protected, static, and magic members are not visible from outside.
    for (const label of ['secret', 'born', 'hidden', 'count', 'create', '__construct']) {
        assert.equal(r.find(label), undefined, label);
    }
    assert.equal(r.find('name').kind, 'field');
    assert.equal(r.find('name').detail, 'string');
    assert.equal(r.find('like').snippet, 'like($1)$0');
    assert.equal(r.find('getFullName').snippet, 'getFullName()$0');
    assert.match(r.find('getFullName').documentation, /The name and the profession\./);
    assert.ok(r.find('magic'), 'the @property tag');
});

test('the type of a local variable comes from its value', async () => {
    let r = await run(USES + 'function Home(ScientistRepository $repo)\n{\n    $s = $repo->find(1);\n    $s->|\n}\n');
    assert.ok(r.find('profession'));

    r = await run(USES + 'function Home(ScientistRepository $repo)\n{\n    foreach ($repo->all() as $s) {\n        $s->|\n    }\n}\n');
    assert.ok(r.find('profession'), 'the @return list<Scientist> item');

    r = await run(USES + 'function Home()\n{\n    $s = new Scientist(\'a\', \'b\');\n    $s->like()->|\n}\n');
    assert.ok(r.find('profession'), 'the static return type');

    r = await run(USES + 'function Home(ScientistRepository $repo)\n{\n    foreach ($repo->load([1]) as $s) {\n        $s->|\n    }\n}\n');
    assert.ok(r.find('profession'), 'a conditional return type');

    r = await run(USES + 'function Home()\n{\n    /** @var Scientist $s */\n    $s = load();\n    $s?->|\n}\n');
    assert.ok(r.find('profession'), 'the @var tag');

    r = await run(USES + 'function Home()\n{\n    $s = Scientist::create(\'a\');\n    $s->status->|\n}\n');
    assert.ok(r.find('name'), 'the enum properties');
    assert.ok(r.find('value'));
});

test('Foo:: shows the static members and the constants', async () => {
    let r = await run(USES + 'function Home()\n{\n    Scientist::|\n}\n');
    assert.deepEqual(r.labels.sort(), ['$count', 'MAX_LIKES', 'class', 'create']);

    r = await run(USES + 'function Home()\n{\n    Status::|\n}\n');
    assert.deepEqual(r.labels.filter((l) => l !== 'class').sort(), ['Active', 'Gone']);
    assert.equal(r.find('Active').kind, 'enumMember');
});

test('$this-> in a class shows all its members', async () => {
    const r = await run('<?phpm\nnamespace App\\Model;\n\nclass Chemist extends Scientist\n{\n    private int $lab = 1;\n\n    public function render()\n    {\n        $this->|\n    }\n}\n', 'src/Model/Chemist.phpm');
    for (const label of ['lab', 'name', 'born', 'age', 'render']) {
        assert.ok(r.find(label), label);
    }
    assert.equal(r.find('secret'), undefined, 'a private member of the parent');
    assert.equal(r.find('hidden'), undefined);
});

test('promoted constructor properties are members, not props', async () => {
    const src = '<?phpm\nnamespace App\\Components;\n\nuse App\\Repository\\ScientistRepository;\nuse Mamu\\Attribute\\Component;\n\n#[Component]\nfinal class List1\n{\n    public int $limit = 3;\n\n    public function __construct(private readonly ScientistRepository $repo)\n    {\n    }\n\n    public function render()\n    {\n        $this->repo->|\n    }\n}\n';
    const r = await run(src, 'src/components/List1.phpm');
    assert.deepEqual(r.labels.sort(), ['all', 'find', 'load']);
    const { parse } = require('../src/php');
    assert.deepEqual(parse(src.replace('|', '')).components[0].props.map((p) => p.name), ['limit']);
});

test('$ shows the variables of the scope', async () => {
    const r = await run(USES + 'function Home(Scientist $s, int $n = 1)\n{\n    $total = 3;\n    echo $|\n}\n');
    assert.deepEqual(r.labels.sort(), ['$n', '$s', '$total']);
    assert.equal(r.find('$s').detail, 'Scientist');
});

test('an unknown type gives no members', async () => {
    assert.deepEqual((await run(USES + 'function Home($x)\n{\n    $x->|\n}\n')).items, []);
});

test('a private member of a trait is a member of the class that uses it', async () => {
    const r = await run('<?phpm\nnamespace App\\Model;\n\nclass Writer\n{\n    use HasSlug;\n\n    public function render()\n    {\n        $this->|\n    }\n}\n', 'src/Model/Writer.phpm');
    assert.ok(r.find('slugBase'));
    assert.equal((await run(USES + 'function Home(Scientist $s)\n{\n    $s->|\n}\n')).find('slugBase'), undefined);
});

test('`->class` and named arguments are not declarations', async () => {
    const { parse } = require('../src/php');
    const parsed = parse('<?phpm\nfunction A($p) { return $p->class; }\nfunction B() { f(class: 1); $o->enum instanceof X; }\nfunction C() { return <p />; }\n');
    assert.deepEqual(parsed.functions.map((f) => f.name), ['A', 'B', 'C']);
    assert.deepEqual(parsed.classes, []);
});
