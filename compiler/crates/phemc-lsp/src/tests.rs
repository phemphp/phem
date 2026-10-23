//! The tests of the completion, the definition, and the hover, on a fixture project.

use crate::classes::{Index, Source};
use crate::complete::{complete, Item, ItemKind};
use crate::imports::Insert;
use crate::project::Project;
use crate::resolve::{symbol_at, Symbol};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::OnceLock;

/// A new, empty temporary directory.
pub fn temp_dir() -> PathBuf {
    static COUNTER: AtomicUsize = AtomicUsize::new(0);
    let dir = std::env::temp_dir().join(format!("phem-lsp-{}-{}", std::process::id(), COUNTER.fetch_add(1, Ordering::SeqCst)));
    let _ = std::fs::remove_dir_all(&dir);
    std::fs::create_dir_all(&dir).unwrap();
    dir
}

const FILES: &[(&str, &str)] = &[
    ("composer.json", r#"{"autoload": {"psr-4": {"App\\": "src/"}}}"#),
    (
        "vendor/composer/autoload_psr4.php",
        r"<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Phem\\' => array($vendorDir . '/phem/phem/src'),
    'App\\' => array($baseDir . '/src'),
);
",
    ),
    (
        "vendor/composer/autoload_files.php",
        r"<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'abc' => $vendorDir . '/acme/lib/functions.php',
);
",
    ),
    ("vendor/acme/lib/functions.php", "<?php\nnamespace Acme;\n\nfunction slugify(string $s): string { return $s; }\n"),
    ("vendor/phem/phem/src/Html.php", "<?php\n"),
    ("vendor/phem/phem/src/Attribute/Component.php", "<?php\n"),
    ("vendor/phem/phem/src/Attribute/Prop.php", "<?php\n"),
    ("vendor/phem/phem/src/Attribute/State.php", "<?php\n"),
    ("vendor/phem/phem/tests/HtmlTest.php", "<?php\n"),
    ("src/Repository/TodoRepository.php", "<?php\n"),
    (
        "src/components/Layout.phpm",
        r"<?phpm
namespace App\Components;

use Phem\Attribute\Component;
use Phem\Html;

#[Component]
function Layout(string $title, ?Html $children = null, ?Html $footer = null)
{
    return <main><h1>{{ title }}</h1>{{ children }}<footer>{{ footer }}</footer></main>;
}

#[Component]
function Avatar(string $name, int $size = 64)
{
    return <img alt={{ name }} width={{ size }} />;
}

// A plain PHP function: it is not a component.
function Initials(string $name): string
{
    return strtoupper($name[0]);
}
",
    ),
    (
        "src/pages/about.phpm",
        r"<?phpm
namespace App\Pages;

use Phem\Attribute\Page;

#[Page('/about')]
final class About
{
    public function render()
    {
        return <p>About</p>;
    }
}
",
    ),
    (
        "src/components/Counter.phpm",
        r"<?phpm
namespace App\Components;

use Phem\Attribute\{Component, Prop, State};

#[Component]
final class Counter
{
    #[Prop] public string $label = 'Clicked';
    #[Prop] public int $max;
    #[State] public int $count = 0;

    public function __construct(private readonly \App\Repository\TodoRepository $todos)
    {
    }

    public function render()
    {
        return <button onClick={{ increment }}>{{ label }} {{ count }}</button>;
    }
}
",
    ),
    (
        "src/Model/Person.php",
        r"<?php
namespace App\Model;

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
",
    ),
    (
        "src/Model/Named.php",
        r"<?php
namespace App\Model;

interface Named
{
    /** The display name. */
    public function displayName(): string;
}
",
    ),
    (
        "src/Model/HasSlug.php",
        r"<?php
namespace App\Model;

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
",
    ),
    (
        "src/Model/Status.php",
        r"<?php
namespace App\Model;

enum Status: string
{
    case Active = 'active';
    case Gone = 'gone';
}
",
    ),
    (
        "src/Model/Scientist.php",
        r"<?php
namespace App\Model;

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
",
    ),
    (
        "src/Repository/ScientistRepository.php",
        r"<?php
namespace App\Repository;

use App\Model\Scientist;

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
",
    ),
    (
        "src/pages/gallery.phpm",
        r"<?phpm
namespace App\Pages;

use Phem\Attribute\Page;

#[Page('/gallery', name: 'gallery')]
#[Page('/scientists/{id}')]
final class Gallery
{
    public function render()
    {
        return <p>Gallery</p>;
    }
}
",
    ),
];

/// The root of the fixture project, made once for all the tests.
fn root() -> &'static Path {
    static ROOT: OnceLock<PathBuf> = OnceLock::new();
    ROOT.get_or_init(|| {
        let root = temp_dir();
        for (name, content) in FILES {
            let path = root.join(name);
            std::fs::create_dir_all(path.parent().unwrap()).unwrap();
            std::fs::write(path, content).unwrap();
        }

        root
    })
}

/// Splits `source` at the cursor mark: the text and the offset. The mark is `‸`, or `|` when the
/// source has no `‸`.
fn cursor(source: &str) -> (String, usize) {
    let mark = if source.contains('‸') { "‸" } else { "|" };
    let offset = source.find(mark).unwrap();
    (format!("{}{}", &source[..offset], &source[offset + mark.len()..]), offset)
}

struct Run {
    text: String,
    items: Vec<Item>,
}

impl Run {
    fn find(&self, label: &str) -> Option<&Item> {
        self.items.iter().find(|i| i.label == label)
    }

    fn get(&self, label: &str) -> &Item {
        self.find(label).unwrap_or_else(|| panic!("no item `{label}`"))
    }

    fn labels(&self) -> Vec<&str> {
        self.items.iter().map(|i| i.label.as_str()).collect()
    }

    fn sorted(&self) -> Vec<&str> {
        let mut l = self.labels();
        l.sort();
        l
    }

    fn slice(&self, range: (usize, usize)) -> &str {
        &self.text[range.0..range.1]
    }
}

fn run_with(source: &str, file: &str, trigger: Option<char>) -> Run {
    let (text, offset) = cursor(source);
    let project = Project::load(root());
    let index = Index::new(&project, Source::new(root().join(file), text.clone()));
    let items = complete(&index, offset, trigger);
    Run { text, items }
}

fn run(source: &str) -> Run {
    run_with(source, "src/pages/index.phpm", None)
}

fn run_in(source: &str, file: &str) -> Run {
    run_with(source, file, None)
}

fn apply(text: &str, item: &Item) -> String {
    let mut out = text.to_string();
    let mut edits: Vec<&Insert> = item.edits.iter().collect();
    edits.sort_by_key(|e| std::cmp::Reverse(e.offset));
    for e in edits {
        out.insert_str(e.offset, &e.text);
    }

    out
}

const PAGE: &str = "<?phpm\nnamespace App\\Pages;\n\n";

fn snippet(item: &Item) -> &str {
    item.snippet.as_deref().unwrap()
}

// --- Completion: tags, imports, and names ---------------------------------------------------

#[test]
fn a_component_tag_adds_a_use_function_import() {
    let r = run(&format!("{PAGE}function Home()\n{{\n    return <Lay|\n}}\n"));
    let item = r.get("Layout");
    assert_eq!(snippet(item), "Layout title=\"${1}\">$0</Layout>");
    assert_eq!(apply(&r.text, item), format!("{}function Home()\n{{\n    return <Lay\n}}\n", PAGE.replacen("\n\n", "\n\nuse function App\\Components\\Layout;\n\n", 1)));
}

#[test]
fn a_page_of_another_file_is_not_a_component_tag() {
    assert!(run(&format!("{PAGE}function Home()\n{{\n    return <Ab|\n}}\n")).find("About").is_none());
    let about = format!("{PAGE}use Phem\\Attribute\\Page;\n\n#[Page('/about')]\nfinal class About\n{{\n    public function render()\n    {{\n        return <p><Ab|</p>;\n    }}\n}}\n");
    assert_eq!(run_in(&about, "src/pages/about.phpm").get("About").detail, "App\\Pages\\About (page)");
}

#[test]
fn a_function_without_component_is_not_a_component_tag() {
    assert!(run(&format!("{PAGE}function Home()\n{{\n    return <Ini|\n}}\n")).find("Initials").is_none());
}

#[test]
fn a_class_component_adds_a_use_import() {
    let r = run(&format!("{PAGE}use function App\\Components\\Layout;\n\nfunction Home()\n{{\n    return <Layout title=\"x\"><Cou|</Layout>;\n}}\n"));
    let item = r.get("Counter");
    assert_eq!(snippet(item), "Counter max={{ ${1} }} />$0");
    assert!(apply(&r.text, item).contains("namespace App\\Pages;\n\nuse App\\Components\\Counter;\n\nuse function App\\Components\\Layout;\n"));
}

#[test]
fn the_builtin_root_tag_adds_a_use_import() {
    let r = run(&format!("{PAGE}function Document()\n{{\n    return <body><Ro|</body>;\n}}\n"));
    let item = r.get("Root");
    assert_eq!(item.detail, "Phem\\Root");
    assert_eq!(snippet(item), "Root>$0</Root>");
    assert!(item.documentation.as_ref().unwrap().contains("root element of the page"));
    assert!(apply(&r.text, item).contains("namespace App\\Pages;\n\nuse Phem\\Root;\n"));
}

#[test]
fn the_builtin_suspense_tag_has_its_slots() {
    let r = run(&format!("{PAGE}function Home()\n{{\n    return <div><Sus|</div>;\n}}\n"));
    let item = r.get("Suspense");
    assert_eq!(item.detail, "Phem\\Suspense");
    assert!(item.documentation.as_ref().unwrap().contains("loads after the page"));
    assert!(apply(&r.text, item).contains("namespace App\\Pages;\n\nuse Phem\\Suspense;\n"));
}

#[test]
fn the_builtin_error_boundary_tag_has_its_slot() {
    let r = run(&format!("{PAGE}function Home()\n{{\n    return <div><ErrorB|</div>;\n}}\n"));
    let item = r.get("ErrorBoundary");
    assert_eq!(item.detail, "Phem\\ErrorBoundary");
    assert!(item.documentation.as_ref().unwrap().contains("render errors"));
    assert!(apply(&r.text, item).contains("namespace App\\Pages;\n\nuse Phem\\ErrorBoundary;\n"));
}

#[test]
fn an_import_goes_into_a_group_on_one_line() {
    let r = run(&format!("{PAGE}use function App\\Components\\{{Layout}};\n\nfunction Home()\n{{\n    return <Av|\n}}\n"));
    assert!(apply(&r.text, r.get("Avatar")).contains("use function App\\Components\\{Layout, Avatar};"));
}

#[test]
fn a_component_of_the_same_namespace_needs_no_import() {
    let r = run_in("<?phpm\nnamespace App\\Components;\n\nfunction Home()\n{\n    return <Av|\n}\n", "src/components/Home.phpm");
    assert!(r.get("Avatar").edits.is_empty());
    assert!(r.get("Avatar").sort_text.starts_with('0'));
}

#[test]
fn a_tag_with_attributes_completes_only_the_name() {
    let r = run(&format!("{PAGE}function Home()\n{{\n    return <Lay| title=\"x\" />;\n}}\n"));
    assert_eq!(snippet(r.get("Layout")), "Layout");
}

#[test]
fn a_comparison_is_not_a_tag() {
    let r = run(&format!("{PAGE}function f($a, $b)\n{{\n    return $a <|\n}}\n"));
    assert!(r.find("Layout").is_none());
}

#[test]
fn props_slots_and_closing_tags() {
    let src = format!("{PAGE}use function App\\Components\\Layout;\nuse App\\Components\\Counter;\n\nfunction Home()\n{{\n");
    let r = run(&format!("{src}    return <Layout |\n}}\n"));
    assert_eq!(r.sorted(), ["key", "title"]);
    assert_eq!(snippet(r.get("title")), "title=\"${1}\"");

    let r = run(&format!("{src}    return <Counter max={{{{ 3 }}}} |\n}}\n"));
    assert_eq!(r.sorted(), ["key", "label"]);

    let r = run(&format!("{src}    return <Layout title=\"x\"><Layout:|\n}}\n"));
    assert_eq!(snippet(r.get("Layout:footer")), "Layout:footer>$0</Layout:footer>");

    let r = run(&format!("{src}    return <Layout title=\"x\"><p>a</|\n}}\n"));
    assert_eq!(r.get("p").insert.as_deref(), Some("p>"));

    let r = run(&format!("{src}    return <button |\n}}\n"));
    assert!(r.find("onClick").is_some());
}

#[test]
fn use_statements_complete_namespaces_and_symbols() {
    let r = run(&format!("{PAGE}use Phem\\|\n"));
    assert_eq!(r.sorted(), ["Attribute", "Html"]);
    assert_eq!(r.get("Attribute").insert.as_deref(), Some("Attribute\\"));
    assert_eq!(r.get("Html").insert.as_deref(), Some("Html;"));

    let r = run(&format!("{PAGE}use Phem\\Attribute\\{{Component, |}};\n"));
    assert_eq!(r.sorted(), ["Component", "Prop", "State"]);

    let r = run(&format!("{PAGE}use function Acme\\|\n"));
    assert_eq!(r.labels(), ["slugify"]);

    let r = run(&format!("{PAGE}use function App\\Components\\|\n"));
    assert_eq!(r.sorted(), ["Avatar", "Initials", "Layout"], "the components and the plain functions");
}

#[test]
fn a_class_name_adds_an_import() {
    let r = run(&format!("{PAGE}use Phem\\Html;\n\nfunction Home()\n{{\n    $r = new TodoRe|\n}}\n"));
    let item = r.get("TodoRepository");
    assert_eq!(item.insert.as_deref(), Some("TodoRepository"));
    assert!(apply(&r.text, item).contains("namespace App\\Pages;\n\nuse App\\Repository\\TodoRepository;\nuse Phem\\Html;\n"));
    assert!(r.find("HtmlTest").is_none());
    assert!(r.get("Html").edits.is_empty());

    let r = run(&format!("{PAGE}function Home()\n{{\n    $s = slug|\n}}\n"));
    assert!(apply(&r.text, r.get("slugify")).contains("use function Acme\\slugify;"));
}

#[test]
fn no_class_names_after_an_arrow_or_in_strings() {
    assert!(run(&format!("{PAGE}$a->Todo|")).items.is_empty());
    assert!(run(&format!("{PAGE}$a = \"Todo|")).items.is_empty());
    assert!(run(&format!("{PAGE}function Todo|")).items.is_empty());
}

fn home_fn(markup: &str) -> String {
    format!("{PAGE}function Home()\n{{\n    $search = fn () => null;\n\n    return {markup};\n}}\n")
}

#[test]
fn the_phem_attributes_of_an_html_element() {
    let r = run(&home_fn("<input onChange={{ search }} phem:|"));
    assert_eq!(snippet(r.get("phem:debounce")), "phem:debounce={{ ${1:300} }}");
    assert_eq!(snippet(r.get("phem:throttle")), "phem:throttle={{ ${1:300} }}");
    assert_eq!(snippet(r.get("phem:loading")), "phem:loading={{ ${1} }}");
    assert_eq!(snippet(r.get("phem:progress")), "phem:progress={{ ${1} }}");
    assert_eq!(snippet(r.get("phem:bind")), "phem:bind={{ ${1} }}");
    assert_eq!(snippet(r.get("phem:live")), "phem:live");
    assert_eq!(r.slice(r.get("phem:debounce").range), "phem:");

    let r = run(&home_fn("<input phem:debounce={{ 300 }} |"));
    assert!(r.find("phem:debounce").is_none(), "an attribute that is there already");
    assert!(r.find("phem:throttle").is_some());

    let r = run(&home_fn("<Layout title=\"x\" phem:|"));
    assert!(r.find("phem:debounce").is_none(), "not on a component");
}

#[test]
fn the_value_of_phem_debounce_in_twig() {
    let r = run(&home_fn("<input onChange={{ search }} phem:debounce={{ | }} />"));
    let blur = r.get("'blur'");
    assert_eq!(blur.insert.as_deref(), Some("'blur'"));
    let at = r.text.find("debounce").unwrap();
    let start = r.text[at..].find("{{ ").unwrap() + at + 3;
    assert_eq!(blur.range, (start, start));
    assert!(r.find("search").is_some(), "the Twig variables too");

    let r = run(&home_fn("<input onChange={{ search }} phem:debounce={{ 'bl| }} />"));
    assert_eq!(r.slice(r.get("'blur'").range), "'bl");
    assert_eq!(r.items.len(), 1, "in a string, only blur");

    let r = run(&home_fn("<input onChange={{ search }} phem:debounce={{ 'bl|' }} />"));
    assert_eq!(r.slice(r.get("'blur'").range), "'bl'", "it replaces the closing quote");

    assert!(run(&home_fn("<input onChange={{ search }} phem:throttle={{ | }} />")).find("'blur'").is_none());
    assert!(run(&home_fn("<input onChange={{ | }} />")).find("'blur'").is_none());
}

// --- Completion: members and variables --------------------------------------------------------

const USES: &str = "<?phpm\nnamespace App\\Pages;\n\nuse App\\Model\\Scientist;\nuse App\\Model\\Status;\nuse App\\Repository\\ScientistRepository;\n\n";

#[test]
fn an_arrow_shows_the_public_members_of_the_type_with_inheritance() {
    let r = run(&format!("{USES}function Home(Scientist $s)\n{{\n    $s->|\n}}\n"));
    for label in ["id", "name", "profession", "likes", "status", "getFullName", "like", "age", "slug", "displayName"] {
        assert!(r.find(label).is_some(), "{label}");
    }

    // Private, protected, static, and magic members are not visible from outside.
    for label in ["secret", "born", "hidden", "count", "create", "__construct"] {
        assert!(r.find(label).is_none(), "{label}");
    }

    assert_eq!(r.get("name").kind, ItemKind::Field);
    assert_eq!(r.get("name").detail, "string");
    assert_eq!(snippet(r.get("like")), "like($1)$0");
    assert_eq!(snippet(r.get("getFullName")), "getFullName()$0");
    assert!(r.get("getFullName").documentation.as_ref().unwrap().contains("The name and the profession."));
    assert!(r.find("magic").is_some(), "the @property tag");
}

#[test]
fn the_type_of_a_local_variable_comes_from_its_value() {
    let r = run(&format!("{USES}function Home(ScientistRepository $repo)\n{{\n    $s = $repo->find(1);\n    $s->|\n}}\n"));
    assert!(r.find("profession").is_some());

    let r = run(&format!("{USES}function Home(ScientistRepository $repo)\n{{\n    foreach ($repo->all() as $s) {{\n        $s->|\n    }}\n}}\n"));
    assert!(r.find("profession").is_some(), "the @return list<Scientist> item");

    let r = run(&format!("{USES}function Home()\n{{\n    $s = new Scientist('a', 'b');\n    $s->like()->|\n}}\n"));
    assert!(r.find("profession").is_some(), "the static return type");

    let r = run(&format!("{USES}function Home(ScientistRepository $repo)\n{{\n    foreach ($repo->load([1]) as $s) {{\n        $s->|\n    }}\n}}\n"));
    assert!(r.find("profession").is_some(), "a conditional return type");

    let r = run(&format!("{USES}function Home()\n{{\n    /** @var Scientist $s */\n    $s = load();\n    $s?->|\n}}\n"));
    assert!(r.find("profession").is_some(), "the @var tag");

    let r = run(&format!("{USES}function Home()\n{{\n    $s = Scientist::create('a');\n    $s->status->|\n}}\n"));
    assert!(r.find("name").is_some(), "the enum properties");
    assert!(r.find("value").is_some());
}

#[test]
fn a_class_name_with_double_colon_shows_the_static_members_and_the_constants() {
    let r = run(&format!("{USES}function Home()\n{{\n    Scientist::|\n}}\n"));
    assert_eq!(r.sorted(), ["$count", "MAX_LIKES", "class", "create"]);

    let r = run(&format!("{USES}function Home()\n{{\n    Status::|\n}}\n"));
    let mut labels: Vec<_> = r.labels().into_iter().filter(|l| *l != "class").collect();
    labels.sort();
    assert_eq!(labels, ["Active", "Gone"]);
    assert_eq!(r.get("Active").kind, ItemKind::EnumMember);
}

#[test]
fn this_in_a_class_shows_all_its_members() {
    let r = run_in("<?phpm\nnamespace App\\Model;\n\nclass Chemist extends Scientist\n{\n    private int $lab = 1;\n\n    public function render()\n    {\n        $this->|\n    }\n}\n", "src/Model/Chemist.phpm");
    for label in ["lab", "name", "born", "age", "render"] {
        assert!(r.find(label).is_some(), "{label}");
    }

    assert!(r.find("secret").is_none(), "a private member of the parent");
    assert!(r.find("hidden").is_none());
}

#[test]
fn promoted_constructor_properties_are_members() {
    let src = "<?phpm\nnamespace App\\Components;\n\nuse App\\Repository\\ScientistRepository;\nuse Phem\\Attribute\\{Component, Prop};\n\n#[Component]\nfinal class List1\n{\n    #[Prop] public int $limit = 3;\n    public int $page = 1;\n\n    public function __construct(private readonly ScientistRepository $repo)\n    {\n    }\n\n    public function render()\n    {\n        $this->repo->|\n    }\n}\n";
    assert_eq!(run_in(src, "src/components/List1.phpm").sorted(), ["all", "find", "load"]);
}

#[test]
fn a_dollar_shows_the_variables_of_the_scope() {
    let r = run(&format!("{USES}function Home(Scientist $s, int $n = 1)\n{{\n    $total = 3;\n    echo $|\n}}\n"));
    assert_eq!(r.sorted(), ["$n", "$s", "$total"]);
    assert_eq!(r.get("$s").detail, "Scientist");
}

#[test]
fn an_unknown_type_gives_no_members() {
    assert!(run(&format!("{USES}function Home($x)\n{{\n    $x->|\n}}\n")).items.is_empty());
}

#[test]
fn a_private_member_of_a_trait_is_a_member_of_the_class_that_uses_it() {
    let r = run_in("<?phpm\nnamespace App\\Model;\n\nclass Writer\n{\n    use HasSlug;\n\n    public function render()\n    {\n        $this->|\n    }\n}\n", "src/Model/Writer.phpm");
    assert!(r.find("slugBase").is_some());
    assert!(run(&format!("{USES}function Home(Scientist $s)\n{{\n    $s->|\n}}\n")).find("slugBase").is_none());
}

// --- Completion: Twig ----------------------------------------------------------------------------

const TWIG_USES: &str = "<?phpm\nnamespace App\\Pages;\n\nuse App\\Model\\Scientist;\nuse App\\Repository\\ScientistRepository;\n\n";

fn twig_fn(body: &str, markup: &str) -> String {
    format!("{TWIG_USES}function Home(Scientist $s, ScientistRepository $repo)\n{{\n{body}    return <div>{markup}</div>;\n}}\n")
}

#[test]
fn braces_show_the_variables_of_the_scope_and_the_functions() {
    let r = run(&twig_fn("    $count = 3;\n", "{{ |"));
    for label in ["s", "repo", "count", "path", "form_widget"] {
        assert!(r.find(label).is_some(), "{label}");
    }

    assert!(r.find("phem_attr").is_none());
    assert_eq!(snippet(r.get("path")), "path($1)$0");
}

#[test]
fn a_dot_shows_the_attributes_of_an_object_with_getters_as_names() {
    let r = run(&twig_fn("", "{{ s.|"));
    for label in ["name", "profession", "likes", "fullName", "famous", "like", "slug", "magic"] {
        assert!(r.find(label).is_some(), "{label}");
    }

    assert_eq!(r.get("fullName").detail, "getFullName(): string");
    assert_eq!(r.get("fullName").insert.as_deref(), Some("fullName"));
    assert!(r.find("getFullName").is_none(), "the getter shows once, as a name");
    assert!(r.find("secret").is_none());
    assert_eq!(snippet(r.get("like")), "like");
}

#[test]
fn the_item_of_a_for_loop_has_the_type_of_the_items() {
    assert!(run(&twig_fn("    $all = $repo->all();\n", "{% for x in all %}{{ x.|")).find("profession").is_some());
    assert!(run(&twig_fn("", "{% for x in repo.all %}{% if x.famous %}{{ x.status.|")).find("name").is_some());

    let r = run(&twig_fn("", "{% for x in repo.all %}{{ loop.|"));
    assert!(r.find("index").is_some());
    assert!(r.find("last").is_some());

    let r = run(&twig_fn("", "{% for x in repo.all %}{% endfor %}{{ |"));
    assert!(r.find("x").is_none(), "the loop variable is out of scope");
    assert!(r.find("loop").is_none());

    assert!(run(&twig_fn("", "{% set best = repo.find(1) %}{{ best.|")).find("profession").is_some(), "the type of a {{% set %}} variable");
}

#[test]
fn the_keys_of_an_array_shape() {
    let r = run(&twig_fn("    $user = ['name' => 'Hedy', 'size' => 90, 'pet' => ['kind' => 'cat']];\n", "{{ user.pet.|"));
    assert_eq!(r.labels(), ["kind"]);

    let r = run(&twig_fn("    $products = [['title' => 'A', 'id' => 1]];\n", "{% for p in products %}{{ p.|"));
    assert_eq!(r.sorted(), ["id", "title"]);
}

#[test]
fn the_properties_and_the_methods_of_a_class_component() {
    let src = "<?phpm\nnamespace App\\Components;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfinal class Counter2\n{\n    public int $count = 0;\n    private string $label = \"x\";\n\n    public function increment(): void\n    {\n    }\n\n    public function render()\n    {\n        $double = $this->count * 2;\n\n        return <button onClick={{ |}}>{{ count }}</button>;\n    }\n}\n";
    let r = run_in(src, "src/components/Counter2.phpm");
    assert!(r.find("double").is_some());
    assert!(r.find("count").is_some());
    assert!(r.find("label").is_some(), "a private property works in the markup of the class");
    assert_eq!(r.get("increment").kind, ItemKind::Method);
    assert!(!r.items.iter().any(|i| i.label == "render" && i.kind == ItemKind::Method));
}

#[test]
fn filters_tests_and_tags() {
    let r = run(&twig_fn("", "{{ s.name|up‸"));
    assert!(r.find("upper").is_some());
    assert!(r.find("trans").is_some());

    let r = run(&twig_fn("", "{% if s.name is not ‸"));
    assert!(r.find("empty").is_some());
    assert_eq!(snippet(r.get("divisible by")), "divisible by(${1})");

    let r = run(&twig_fn("", "{% |"));
    assert_eq!(snippet(r.get("for")), "for ${1:item} in ${2:items} %}$0{% endfor %}");

    let r = run(&twig_fn("", "{% for a in b %}{% if a %}{% |"));
    assert_eq!(r.items[0].label, "endif");
    assert_eq!(snippet(r.get("endif")), "endif %}");
    assert!(r.find("endfor").is_some());
    assert!(r.find("else").is_some());

    // A tag that is not in the table opens a block when its end tag follows.
    let r = run(&twig_fn("", "{% component 'A' %}{% | %}{% endcomponent %}"));
    assert_eq!(r.items[0].label, "endcomponent");
    assert!(run(&twig_fn("", "{% props a %}{% |")).find("endprops").is_none());

    // The item replaces the ` %}` after the cursor, or the `}` of an auto-closed `{`.
    let r = run(&twig_fn("", "{% for a in b %}{% end| -%}"));
    assert_eq!(snippet(r.get("endfor")), "endfor -%}");
    assert_eq!(r.slice(r.get("endfor").range), "end -%}");

    let r = run(&twig_fn("", "{% for a in b %}{%|}"));
    assert_eq!(r.slice(r.get("endfor").range), "}");
}

#[test]
fn no_names_for_a_new_variable() {
    assert!(run(&twig_fn("", "{% for |")).items.is_empty());
    assert!(run(&twig_fn("", "{% set |")).items.is_empty());
}

#[test]
fn path_shows_the_route_names() {
    let r = run(&twig_fn("", "<a href={{ path('|') }}>x</a>"));
    assert_eq!(r.get("gallery").detail, "/gallery");
    assert_eq!(r.get("app_pages_gallery_1").detail, "/scientists/{id}");
    assert!(r.find("app_pages_about").is_some());
    assert!(run(&twig_fn("", "{{ 'a|' }}")).items.is_empty());
}

#[test]
fn an_operator_as_a_trigger_character_opens_no_list() {
    let trig = |source: &str, c| run_with(source, "src/pages/index.phpm", Some(c));
    assert!(trig(&twig_fn("", "{{ s.likes >|"), '>').items.is_empty());
    assert!(trig(&twig_fn("", "{{ s.likes <|"), '<').items.is_empty());
    assert!(trig(&twig_fn("", "{{ s.likes /|"), '/').items.is_empty());
    assert!(trig(&twig_fn("", "{{ s.likes %|"), '%').items.is_empty());
    assert!(trig(&twig_fn("", "{%|"), '%').find("for").is_some());
    assert!(trig(&format!("{TWIG_USES}function Home(Scientist $s)\n{{\n    $s->|\n}}\n"), '>').find("name").is_some());
}

// --- Completion: HTML -----------------------------------------------------------------------------

fn html_fn(markup: &str) -> String {
    format!("{PAGE}function Home()\n{{\n    return {markup}\n}}\n")
}

#[test]
fn a_tag_shows_the_html_elements_and_the_components() {
    let r = run(&html_fn("<div><|"));
    assert_eq!(snippet(r.get("section")), "section$1>$0</section>");
    assert_eq!(snippet(r.get("img")), "img$1 />$0");
    assert!(r.find("Layout").is_some());

    assert_eq!(snippet(run(&html_fn("<div><inp| type=\"text\" />")).get("input")), "input");
    assert!(run(&html_fn("<div><Lay|")).find("section").is_none());
}

#[test]
fn the_attributes_of_an_html_element() {
    let r = run(&html_fn("<input |"));
    for label in ["type", "name", "placeholder", "id", "class", "className", "aria-label", "onChange", "key"] {
        assert!(r.find(label).is_some(), "{label}");
    }

    assert!(r.find("href").is_none());
    assert_eq!(snippet(r.get("required")), "required");
    assert_eq!(snippet(r.get("type")), "type=\"${1}\"");
    assert_eq!(snippet(r.get("style")), "style={{ {${1}} }}");

    let a = run(&html_fn("<a href=\"/\" |"));
    assert!(a.find("href").is_none(), "an attribute that is set");
    assert!(a.find("target").is_some());
}

#[test]
fn the_values_of_an_enumerated_attribute() {
    let r = run(&html_fn("<input type=\"|"));
    assert!(r.find("email").is_some());
    assert!(r.find("checkbox").is_some());

    let r = run(&html_fn("<button type=\"s|\""));
    assert_eq!(r.labels(), ["submit", "button", "reset"]);
    let s = r.text.find("s\"").unwrap();
    assert_eq!(r.get("submit").range, (s, s + 1));

    assert!(run(&html_fn("<a target=\"|")).find("_blank").is_some());
    assert!(run(&html_fn("<input value=\"|")).items.is_empty());
    assert!(run(&html_fn("<input constructor=\"|")).items.is_empty());
    assert!(run(&html_fn("<input type=\"{{ |")).items.iter().all(|i| i.kind != ItemKind::Value));
}

// --- Definition and hover ------------------------------------------------------------------------

fn at(source: &str, file: &str) -> Option<Symbol> {
    let (text, offset) = cursor(source);
    let project = Project::load(root());
    let index = Index::new(&project, Source::new(root().join(file), text));
    symbol_at(&index, offset)
}

fn at_page(source: &str) -> Symbol {
    at(source, "src/pages/index.phpm").expect("no symbol")
}

/// The file and the line text from the location.
fn target(s: &Symbol, current: &str) -> (String, String) {
    let loc = s.location.as_ref().expect("no location");
    let text = if loc.file == root().join("src/pages/index.phpm") { cursor(current).0 } else { std::fs::read_to_string(&loc.file).unwrap() };
    let line_end = text[loc.offset..].find('\n').map_or(text.len(), |i| loc.offset + i);
    (loc.file.strip_prefix(root()).unwrap().display().to_string(), text[loc.offset..line_end].to_string())
}

fn line_of(s: &Symbol, current: &str) -> usize {
    let (text, _) = cursor(current);
    text[..s.location.as_ref().unwrap().offset].matches('\n').count()
}

fn hover(s: &Symbol) -> &str {
    s.hover.as_deref().unwrap()
}

const DEF_USES: &str = "<?phpm\nnamespace App\\Pages;\n\nuse App\\Model\\Scientist;\nuse App\\Repository\\ScientistRepository;\n\nuse function App\\Components\\Layout;\n\n";

fn def_fn(markup: &str, body: &str) -> String {
    format!("{DEF_USES}function Home(Scientist $s, ScientistRepository $repo)\n{{\n{body}    return {markup};\n}}\n")
}

fn pair(file: &str, line: &str) -> (String, String) {
    (file.to_string(), line.to_string())
}

#[test]
fn a_component_tag_goes_to_the_component() {
    let src = def_fn("<Lay‸out title=\"x\"></Layout>", "");
    let r = at_page(&src);
    let layout = pair("src/components/Layout.phpm", "Layout(string $title, ?Html $children = null, ?Html $footer = null)");
    assert_eq!(target(&r, &src), layout);
    assert!(hover(&r).contains("function Layout(string $title"));

    let src = def_fn("<Layout title=\"x\"></Lay‸out>", "");
    assert_eq!(target(&at_page(&src), &src), layout);

    let src = def_fn("<Layout title=\"x\"><Layout:foo‸ter>a</Layout:footer></Layout>", "");
    assert_eq!(target(&at_page(&src), &src), pair("src/components/Layout.phpm", "$footer = null)"));
}

#[test]
fn the_builtin_root_tag_has_a_hover_and_no_location() {
    let src = DEF_USES.replace("use App\\Model", "use Phem\\{Html, Root};\nuse App\\Model")
        + "function Document(?Html $children = null)\n{\n    return <body><Ro‸ot tag=\"main\">{{ children }}</Root></body>;\n}\n";
    let r = at_page(&src);
    assert!(r.location.is_none());
    assert!(hover(&r).contains("final class Root"));
    assert!(hover(&r).contains("root element of the page"));

    let src = DEF_USES.replace("use App\\Model", "use Phem\\Root;\nuse App\\Model") + "function Document()\n{\n    return <Root ta‸g=\"main\" />;\n}\n";
    let tag = at_page(&src);
    assert!(hover(&tag).contains("Prop of `Phem\\Root`"));
    assert!(tag.location.is_none());
}

#[test]
fn a_prop_goes_to_the_parameter_or_the_property() {
    let src = def_fn("<Layout ti‸tle=\"x\" />", "");
    assert_eq!(target(&at_page(&src), &src), pair("src/components/Layout.phpm", "$title, ?Html $children = null, ?Html $footer = null)"));

    let src = format!("{DEF_USES}use App\\Components\\Counter;\n\nfunction Home()\n{{\n    return <Counter ma‸x={{{{ 3 }}}} />;\n}}\n");
    let r = at_page(&src);
    assert_eq!(target(&r, &src), pair("src/components/Counter.phpm", "$max;"));
    assert!(hover(&r).contains("int $max"));
    assert!(hover(&r).contains("required"));
}

#[test]
fn a_twig_variable_goes_to_its_php_declaration() {
    let src = def_fn("<p>{{ s‸.name }}</p>", "");
    let r = at_page(&src);
    assert_eq!(r.location.as_ref().unwrap().file, root().join("src/pages/index.phpm"));
    assert!(hover(&r).contains("Scientist $s"));

    let src = def_fn("<p>{{ s.prof‸ession }}</p>", "");
    assert_eq!(target(&at_page(&src), &src), pair("src/Model/Scientist.php", "$profession,"));

    let src = def_fn("<p>{{ s.full‸Name }}</p>", "");
    let r = at_page(&src);
    assert_eq!(target(&r, &src), pair("src/Model/Scientist.php", "getFullName(): string"));
    assert!(hover(&r).contains("The name and the profession."));

    let src = def_fn("<ul>{% for x in repo.all %}<li>{{ x.sl‸ug }}</li>{% endfor %}</ul>", "");
    assert_eq!(target(&at_page(&src), &src), pair("src/Model/HasSlug.php", "slug(): string"));

    let src = def_fn("<p>{{ user.na‸me }}</p>", "    $user = ['name' => 'Hedy'];\n");
    assert_eq!(line_of(&at_page(&src), &src), 10);
}

#[test]
fn a_handler_goes_to_the_method() {
    let src = "<?phpm\nnamespace App\\Components;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfinal class Counter3\n{\n    public function increment(): void\n    {\n    }\n\n    public function render()\n    {\n        return <button onClick={{ incre‸ment }}>+</button>;\n    }\n}\n";
    let r = at(src, "src/components/Counter3.phpm").unwrap();
    assert_eq!(line_of(&r, src), 8);
    assert!(hover(&r).contains("public function increment(): void"));
}

#[test]
fn php_names_classes_members_functions_and_variables() {
    let check = |body: &str, expected: (&str, &str)| {
        let src = def_fn("<p />", body);
        assert_eq!(target(&at_page(&src), &src), pair(expected.0, expected.1), "{body}");
    };

    let src = def_fn("<p />", "    $x = new Scien‸tist('a', 'b');\n");
    let r = at_page(&src);
    assert_eq!(target(&r, &src), pair("src/Model/Scientist.php", "Scientist extends Person"));
    assert!(hover(&r).contains("class App\\Model\\Scientist extends Person"));
    assert!(hover(&r).contains("A scientist."));

    check("    $all = $repo->a‸ll();\n", ("src/Repository/ScientistRepository.php", "all(): array"));
    check("    $n = $s->li‸kes;\n", ("src/Model/Scientist.php", "$likes = 0,"));
    check("    $n = Scientist::MAX_LI‸KES;\n", ("src/Model/Scientist.php", "MAX_LIKES = 10;"));
    check("    $n = $s->a‸ge();\n", ("src/Model/Person.php", "age(): int"));
    check("    $n = $s->sta‸tus->name;\n", ("src/Model/Scientist.php", "$status = Status::Active;"));

    let src = def_fn("<p />", "    $all = $re‸po->all();\n");
    assert!(hover(&at_page(&src)).contains("ScientistRepository $repo"), "a variable before ->");

    let src = def_fn("<p />", "    $x = 1;\n    echo $‸x;\n");
    assert_eq!(line_of(&at_page(&src), &src), 10);

    let src = "<?phpm\nnamespace App\\Pages;\n\nuse App\\Model\\Scien‸tist;\n";
    assert_eq!(target(&at_page(src), src), pair("src/Model/Scientist.php", "Scientist extends Person"));

    let src = "<?phpm\nnamespace App\\Pages;\n\nuse function Acme\\slugify;\n\n$a = slug‸ify(\"x\");\n";
    assert_eq!(target(&at_page(src), src), pair("vendor/acme/lib/functions.php", "slugify(string $s): string { return $s; }"));
}

#[test]
fn hover_for_twig_filters_functions_tests_tags_and_html() {
    let h = |markup: &str| hover(&at_page(&def_fn(markup, ""))).to_string();
    assert!(h("<p>{{ s.name|up‸per }}</p>").contains("uppercase"));
    assert!(h("<p>{{ pa‸th(\"x\") }}</p>").contains("path(name"));
    assert!(h("<p>{% if s is emp‸ty %}{% endif %}</p>").contains("empty"));
    assert!(h("<p>{% fo‸r a in b %}{% endfor %}</p>").contains("Loop over"));
    assert!(h("<p>{% for a in b %}{% endf‸or %}</p>").contains("end of the {% for %} block"));
    assert!(h("<p>{% component 'A' %}{% endcomp‸onent %}</p>").contains("end of the {% component %} block"));
    assert!(h("<sec‸tion />").contains("A generic section"));
    assert!(h("<button onCl‸ick={{ x }} />").contains("Phem event"));
}

#[test]
fn a_route_name_goes_to_the_page() {
    let src = def_fn("<a href={{ path('gall‸ery') }}>x</a>", "");
    let r = at_page(&src);
    assert_eq!(target(&r, &src), pair("src/pages/gallery.phpm", "Gallery"));
    assert!(hover(&r).contains("/gallery"));
}

// --- Robustness -----------------------------------------------------------------------------------

/// Runs the completion and the resolver at each offset of `text`. They must not panic: a panic
/// stops the server.
fn every_offset(project: &Project, file: &Path, text: &str) {
    let index = Index::new(project, Source::new(file.to_path_buf(), text.to_string()));

    for offset in (0..=text.len()).filter(|&o| text.is_char_boundary(o)) {
        for trigger in [None, Some('<'), Some('>'), Some('$')] {
            complete(&index, offset, trigger);
        }

        symbol_at(&index, offset);
    }
}

#[test]
fn no_panic_at_any_offset() {
    let project = Project::load(root());

    for (name, _) in FILES.iter().filter(|(n, _)| n.ends_with(".phpm") || n.starts_with("src/")) {
        let file = root().join(name);
        let text = std::fs::read_to_string(&file).unwrap();
        every_offset(&project, &file, &text);

        // An incomplete file: each prefix of the text.
        let cut = text.char_indices().map(|(i, _)| i).nth(text.chars().count() / 2).unwrap();
        every_offset(&project, &file, &text[..cut]);
    }

    let odd = "<?phpm\n$s = \"é{$a[\"k\"]}\"; /* ♥ */ return <p a=\"😀\" {{ ...b }}>{{ x|y(\"é\") }}{% for é in ü %}</p>; <<<EOT\nx\n";
    every_offset(&project, &root().join("src/odd.phpm"), odd);
}
