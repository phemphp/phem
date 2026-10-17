'use strict';

// The fixture project of the tests, and helpers to run the completion.

const fs = require('fs');
const os = require('os');
const path = require('path');
const { Project } = require('../src/project');
const { complete } = require('../src/complete');

const FILES = {
    'composer.json': '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
    'vendor/composer/autoload_psr4.php': `<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Mamu\\\\' => array($vendorDir . '/mamuphp/runtime/src'),
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
    'vendor/mamuphp/runtime/src/Html.php': '<?php\n',
    'vendor/mamuphp/runtime/src/Attribute/Component.php': '<?php\n',
    'vendor/mamuphp/runtime/src/Attribute/State.php': '<?php\n',
    'vendor/mamuphp/runtime/tests/HtmlTest.php': '<?php\n',
    'src/Repository/TodoRepository.php': '<?php\n',
    'src/components/Layout.phpm': `<?phpm
namespace App\\Components;

use Mamu\\Html;

function Layout(string $title, ?Html $children = null, ?Html $footer = null)
{
    return <main><h1>{{ title }}</h1>{{ children }}<footer>{{ footer }}</footer></main>;
}

function Avatar(string $name, int $size = 64)
{
    return <img alt={{ name }} width={{ size }} />;
}
`,
    'src/pages/about.phpm': `<?phpm
namespace App\\Pages;

use Symfony\\Component\\Routing\\Attribute\\Route;

#[Route('/about')]
function About()
{
    return <p>About</p>;
}
`,
    'src/components/Counter.phpm': `<?phpm
namespace App\\Components;

use Mamu\\Attribute\\{Component, State};

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
    'src/Model/Person.php': `<?php
namespace App\\Model;

abstract class Person implements Named
{
    use HasSlug;

    protected int $born = 1900;
    private string $hidden = '';

    public function age(): int
    {
        return 2026 - $this->born;
    }
}
`,
    'src/Model/Named.php': `<?php
namespace App\\Model;

interface Named
{
    /** The display name. */
    public function displayName(): string;
}
`,
    'src/Model/HasSlug.php': `<?php
namespace App\\Model;

trait HasSlug
{
    public function slug(): string
    {
        return $this->slugBase();
    }

    private function slugBase(): string
    {
        return '';
    }
}
`,
    'src/Model/Status.php': `<?php
namespace App\\Model;

enum Status: string
{
    case Active = 'active';
    case Gone = 'gone';
}
`,
    'src/Model/Scientist.php': `<?php
namespace App\\Model;

/**
 * A scientist.
 *
 * @property string $magic
 */
class Scientist extends Person
{
    public const MAX_LIKES = 10;

    public ?int $id = null;
    public Status $status = Status::Active;
    private string $secret = '';
    public static int $count = 0;

    public function __construct(
        public string $name,
        public string $profession,
        public int $likes = 0,
    ) {
    }

    /** The name and the profession. */
    public function getFullName(): string
    {
        return $this->name . ', ' . $this->profession;
    }

    public function isFamous(): bool
    {
        return $this->likes > 5;
    }

    public function like(int $n = 1): static
    {
        $this->likes += $n;

        return $this;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public static function create(string $name): self
    {
        return new self($name, '');
    }
}
`,
    'src/Repository/ScientistRepository.php': `<?php
namespace App\\Repository;

use App\\Model\\Scientist;

final class ScientistRepository
{
    /**
     * @return list<Scientist>
     */
    public function all(): array
    {
        return [];
    }

    public function find(int $id): ?Scientist
    {
        return null;
    }

    /**
     * @return ($id is int ? Scientist : list<Scientist>)
     */
    public function load(mixed $id): mixed
    {
        return null;
    }
}
`,
    'src/pages/gallery.phpm': `<?phpm
namespace App\\Pages;

use Mamu\\Attribute\\Component;
use Symfony\\Component\\Routing\\Attribute\\Route;

#[Route('/gallery', name: 'gallery')]
#[Route('/scientists/{id}')]
#[Component]
final class Gallery
{
    public function render()
    {
        return <p>Gallery</p>;
    }
}
`,
};

async function createProject() {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'mamu-test-'));
    for (const [name, content] of Object.entries(FILES)) {
        fs.mkdirSync(path.dirname(path.join(root, name)), { recursive: true });
        fs.writeFileSync(path.join(root, name), content);
    }
    const project = await new Project(root).load();
    return { root, project, cleanup: () => fs.rmSync(root, { recursive: true, force: true }) };
}

// Splits `source` at the cursor mark: { text, offset }. The mark is `‸`, or
// `|` when the source has no `‸`.
function cursor(source) {
    const offset = source.includes('‸') ? source.indexOf('‸') : source.indexOf('|');
    return { text: source.slice(0, offset) + source.slice(offset + 1), offset };
}

// Runs the completion at the cursor mark of `source`.
async function run(fixture, source, file = 'src/pages/index.phpm', trigger = undefined) {
    const { text, offset } = cursor(source);
    const items = await complete({ text, offset, file: path.join(fixture.root, file), project: fixture.project, trigger });
    return { text, items, labels: items.map((i) => i.label), find: (label) => items.find((i) => i.label === label) };
}

function apply(text, item) {
    let out = text;
    for (const e of [...item.edits].sort((a, b) => b.offset - a.offset)) {
        out = out.slice(0, e.offset) + e.text + out.slice(e.offset);
    }
    return out;
}

const PAGE = '<?phpm\nnamespace App\\Pages;\n\n';

module.exports = { FILES, createProject, cursor, run, apply, PAGE };
