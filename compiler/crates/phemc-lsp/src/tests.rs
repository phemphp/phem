//! The tests of the completion, the definition, the hover, and the references, on a fixture
//! project.

use crate::classes::{Index, Source};
use crate::complete::{complete, Item, ItemKind};
use crate::imports::Insert;
use crate::project::Project;
use crate::references::{counted, target_at, Usages};
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
        "src/Ui/Common/Layout.phem",
        r"<?phem
namespace App\Ui\Common;

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
        "src/Ui/About/AboutPage.phem",
        r"<?phem
namespace App\Ui\About;

use Phem\Attribute\Page;

#[Page('/about')]
final class AboutPage
{
    public function render()
    {
        return <p>About</p>;
    }
}
",
    ),
    (
        "src/Ui/Common/Counter.phem",
        r"<?phem
namespace App\Ui\Common;

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
        "src/Ui/Gallery/GalleryPage.phem",
        r"<?phem
namespace App\Ui\Gallery;

use Phem\Attribute\Page;

#[Page('/gallery', name: 'gallery')]
#[Page('/scientists/{id}')]
final class GalleryPage
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
    run_with(source, "src/Ui/HomePage.phem", None)
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

const PAGE: &str = "<?phem\nnamespace App\\Ui;\n\n";

fn snippet(item: &Item) -> &str {
    item.snippet.as_deref().unwrap()
}

// --- Completion: tags, imports, and names ---------------------------------------------------

#[test]
fn a_component_tag_adds_a_use_function_import() {
    let r = run(&format!("{PAGE}function HomePage()\n{{\n    return <Lay|\n}}\n"));
    let item = r.get("Layout");
    assert_eq!(snippet(item), "Layout title=\"${1}\">$0</Layout>");
    assert_eq!(apply(&r.text, item), format!("{}function HomePage()\n{{\n    return <Lay\n}}\n", PAGE.replacen("\n\n", "\n\nuse function App\\Ui\\Common\\Layout;\n\n", 1)));
}

#[test]
fn a_page_is_not_a_component_tag() {
    assert!(run(&format!("{PAGE}function HomePage()\n{{\n    return <Ab|\n}}\n")).find("AboutPage").is_none());
    let about = format!("{PAGE}use Phem\\Attribute\\Page;\n\n#[Page('/about')]\nfinal class AboutPage\n{{\n    public function render()\n    {{\n        return <p><Ab|</p>;\n    }}\n}}\n");
    assert!(run_in(&about, "src/Ui/About/AboutPage.phem").find("AboutPage").is_none(), "not in its own file either");
}

#[test]
fn a_function_without_component_is_not_a_component_tag() {
    assert!(run(&format!("{PAGE}function HomePage()\n{{\n    return <Ini|\n}}\n")).find("Initials").is_none());
}

#[test]
fn a_class_component_adds_a_use_import() {
    let r = run(&format!("{PAGE}use function App\\Ui\\Common\\Layout;\n\nfunction HomePage()\n{{\n    return <Layout title=\"x\"><Cou|</Layout>;\n}}\n"));
    let item = r.get("Counter");
    assert_eq!(snippet(item), "Counter max={{ ${1} }} />$0");
    assert!(apply(&r.text, item).contains("namespace App\\Ui;\n\nuse App\\Ui\\Common\\Counter;\n\nuse function App\\Ui\\Common\\Layout;\n"));
}

#[test]
fn the_builtin_root_tag_adds_a_use_import() {
    let r = run(&format!("{PAGE}function Document()\n{{\n    return <body><Ro|</body>;\n}}\n"));
    let item = r.get("Root");
    assert_eq!(item.detail, "Phem\\Root");
    assert_eq!(snippet(item), "Root>$0</Root>");
    assert!(item.documentation.as_ref().unwrap().contains("root element of the page"));
    assert!(apply(&r.text, item).contains("namespace App\\Ui;\n\nuse Phem\\Root;\n"));
}

#[test]
fn the_builtin_suspense_tag_has_its_slots() {
    let r = run(&format!("{PAGE}function HomePage()\n{{\n    return <div><Sus|</div>;\n}}\n"));
    let item = r.get("Suspense");
    assert_eq!(item.detail, "Phem\\Suspense");
    assert!(item.documentation.as_ref().unwrap().contains("loads after the page"));
    assert!(apply(&r.text, item).contains("namespace App\\Ui;\n\nuse Phem\\Suspense;\n"));
}

#[test]
fn the_builtin_error_boundary_tag_has_its_slot() {
    let r = run(&format!("{PAGE}function HomePage()\n{{\n    return <div><ErrorB|</div>;\n}}\n"));
    let item = r.get("ErrorBoundary");
    assert_eq!(item.detail, "Phem\\ErrorBoundary");
    assert!(item.documentation.as_ref().unwrap().contains("render errors"));
    assert!(apply(&r.text, item).contains("namespace App\\Ui;\n\nuse Phem\\ErrorBoundary;\n"));
}

#[test]
fn an_import_goes_into_a_group_on_one_line() {
    let r = run(&format!("{PAGE}use function App\\Ui\\Common\\{{Layout}};\n\nfunction HomePage()\n{{\n    return <Av|\n}}\n"));
    assert!(apply(&r.text, r.get("Avatar")).contains("use function App\\Ui\\Common\\{Layout, Avatar};"));
}

#[test]
fn a_component_of_the_same_namespace_needs_no_import() {
    let r = run_in("<?phem\nnamespace App\\Ui\\Common;\n\nfunction Home()\n{\n    return <Av|\n}\n", "src/Ui/Common/Home.phem");
    assert!(r.get("Avatar").edits.is_empty());
    assert!(r.get("Avatar").sort_text.starts_with('0'));
}

#[test]
fn a_tag_with_attributes_completes_only_the_name() {
    let r = run(&format!("{PAGE}function HomePage()\n{{\n    return <Lay| title=\"x\" />;\n}}\n"));
    assert_eq!(snippet(r.get("Layout")), "Layout");
}

#[test]
fn a_comparison_is_not_a_tag() {
    let r = run(&format!("{PAGE}function f($a, $b)\n{{\n    return $a <|\n}}\n"));
    assert!(r.find("Layout").is_none());
}

#[test]
fn props_slots_and_closing_tags() {
    let src = format!("{PAGE}use function App\\Ui\\Common\\Layout;\nuse App\\Ui\\Common\\Counter;\n\nfunction HomePage()\n{{\n");
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

    let r = run(&format!("{PAGE}use function App\\Ui\\Common\\|\n"));
    assert_eq!(r.sorted(), ["Avatar", "Initials", "Layout"], "the components and the plain functions");
}

#[test]
fn a_class_name_adds_an_import() {
    let r = run(&format!("{PAGE}use Phem\\Html;\n\nfunction HomePage()\n{{\n    $r = new TodoRe|\n}}\n"));
    let item = r.get("TodoRepository");
    assert_eq!(item.insert.as_deref(), Some("TodoRepository"));
    assert!(apply(&r.text, item).contains("namespace App\\Ui;\n\nuse App\\Repository\\TodoRepository;\nuse Phem\\Html;\n"));
    assert!(r.find("HtmlTest").is_none());
    assert!(r.get("Html").edits.is_empty());

    let r = run(&format!("{PAGE}function HomePage()\n{{\n    $s = slug|\n}}\n"));
    assert!(apply(&r.text, r.get("slugify")).contains("use function Acme\\slugify;"));
}

#[test]
fn no_class_names_after_an_arrow_or_in_strings() {
    assert!(run(&format!("{PAGE}$a->Todo|")).items.is_empty());
    assert!(run(&format!("{PAGE}$a = \"Todo|")).items.is_empty());
    assert!(run(&format!("{PAGE}function Todo|")).items.is_empty());
}

fn home_fn(markup: &str) -> String {
    format!("{PAGE}function HomePage()\n{{\n    $search = fn () => null;\n\n    return {markup};\n}}\n")
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
    assert_eq!(snippet(r.get("phem:error")), "phem:error={{ ${1} }}");
    assert_eq!(r.slice(r.get("phem:debounce").range), "phem:");

    let r = run(&home_fn("<input phem:debounce={{ 300 }} |"));
    assert!(r.find("phem:debounce").is_none(), "an attribute that is there already");
    assert!(r.find("phem:throttle").is_some());

    let r = run(&home_fn("<Layout title=\"x\" phem:|"));
    assert!(r.find("phem:debounce").is_none(), "not on a component");

    let message = "use Phem\\Attribute\\Component;\nuse Phem\\Html;\n\n#[Component]\nfunction Message(string $id, ?Html $children = null)\n{\n    return <p id={{ id }}>{{ children }}</p>;\n}\n\n";
    let r = run(&format!("{PAGE}{message}function HomePage()\n{{\n    return <Message phem:|\n}}\n"));
    assert_eq!(snippet(r.get("phem:error")), "phem:error={{ ${1} }}", "on a component with an id prop");
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

const USES: &str = "<?phem\nnamespace App\\Ui;\n\nuse App\\Model\\Scientist;\nuse App\\Model\\Status;\nuse App\\Repository\\ScientistRepository;\n\n";

#[test]
fn an_arrow_shows_the_public_members_of_the_type_with_inheritance() {
    let r = run(&format!("{USES}function HomePage(Scientist $s)\n{{\n    $s->|\n}}\n"));
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
    let r = run(&format!("{USES}function HomePage(ScientistRepository $repo)\n{{\n    $s = $repo->find(1);\n    $s->|\n}}\n"));
    assert!(r.find("profession").is_some());

    let r = run(&format!("{USES}function HomePage(ScientistRepository $repo)\n{{\n    foreach ($repo->all() as $s) {{\n        $s->|\n    }}\n}}\n"));
    assert!(r.find("profession").is_some(), "the @return list<Scientist> item");

    let r = run(&format!("{USES}function HomePage()\n{{\n    $s = new Scientist('a', 'b');\n    $s->like()->|\n}}\n"));
    assert!(r.find("profession").is_some(), "the static return type");

    let r = run(&format!("{USES}function HomePage(ScientistRepository $repo)\n{{\n    foreach ($repo->load([1]) as $s) {{\n        $s->|\n    }}\n}}\n"));
    assert!(r.find("profession").is_some(), "a conditional return type");

    let r = run(&format!("{USES}function HomePage()\n{{\n    /** @var Scientist $s */\n    $s = load();\n    $s?->|\n}}\n"));
    assert!(r.find("profession").is_some(), "the @var tag");

    let r = run(&format!("{USES}function HomePage()\n{{\n    $s = Scientist::create('a');\n    $s->status->|\n}}\n"));
    assert!(r.find("name").is_some(), "the enum properties");
    assert!(r.find("value").is_some());
}

#[test]
fn a_class_name_with_double_colon_shows_the_static_members_and_the_constants() {
    let r = run(&format!("{USES}function HomePage()\n{{\n    Scientist::|\n}}\n"));
    assert_eq!(r.sorted(), ["$count", "MAX_LIKES", "class", "create"]);

    let r = run(&format!("{USES}function HomePage()\n{{\n    Status::|\n}}\n"));
    let mut labels: Vec<_> = r.labels().into_iter().filter(|l| *l != "class").collect();
    labels.sort();
    assert_eq!(labels, ["Active", "Gone"]);
    assert_eq!(r.get("Active").kind, ItemKind::EnumMember);
}

#[test]
fn this_in_a_class_shows_all_its_members() {
    let r = run_in("<?phem\nnamespace App\\Model;\n\nclass Chemist extends Scientist\n{\n    private int $lab = 1;\n\n    public function render()\n    {\n        $this->|\n    }\n}\n", "src/Model/Chemist.phem");
    for label in ["lab", "name", "born", "age", "render"] {
        assert!(r.find(label).is_some(), "{label}");
    }

    assert!(r.find("secret").is_none(), "a private member of the parent");
    assert!(r.find("hidden").is_none());
}

#[test]
fn promoted_constructor_properties_are_members() {
    let src = "<?phem\nnamespace App\\Ui\\Common;\n\nuse App\\Repository\\ScientistRepository;\nuse Phem\\Attribute\\{Component, Prop};\n\n#[Component]\nfinal class List1\n{\n    #[Prop] public int $limit = 3;\n    public int $page = 1;\n\n    public function __construct(private readonly ScientistRepository $repo)\n    {\n    }\n\n    public function render()\n    {\n        $this->repo->|\n    }\n}\n";
    assert_eq!(run_in(src, "src/Ui/Common/List1.phem").sorted(), ["all", "find", "load"]);
}

#[test]
fn a_dollar_shows_the_variables_of_the_scope() {
    let r = run(&format!("{USES}function HomePage(Scientist $s, int $n = 1)\n{{\n    $total = 3;\n    echo $|\n}}\n"));
    assert_eq!(r.sorted(), ["$n", "$s", "$total"]);
    assert_eq!(r.get("$s").detail, "Scientist");
}

#[test]
fn an_unknown_type_gives_no_members() {
    assert!(run(&format!("{USES}function HomePage($x)\n{{\n    $x->|\n}}\n")).items.is_empty());
}

#[test]
fn a_private_member_of_a_trait_is_a_member_of_the_class_that_uses_it() {
    let r = run_in("<?phem\nnamespace App\\Model;\n\nclass Writer\n{\n    use HasSlug;\n\n    public function render()\n    {\n        $this->|\n    }\n}\n", "src/Model/Writer.phem");
    assert!(r.find("slugBase").is_some());
    assert!(run(&format!("{USES}function HomePage(Scientist $s)\n{{\n    $s->|\n}}\n")).find("slugBase").is_none());
}

// --- Completion: Twig ----------------------------------------------------------------------------

const TWIG_USES: &str = "<?phem\nnamespace App\\Ui;\n\nuse App\\Model\\Scientist;\nuse App\\Repository\\ScientistRepository;\n\n";

fn twig_fn(body: &str, markup: &str) -> String {
    format!("{TWIG_USES}function HomePage(Scientist $s, ScientistRepository $repo)\n{{\n{body}    return <div>{markup}</div>;\n}}\n")
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
    let src = "<?phem\nnamespace App\\Ui\\Common;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfinal class Counter2\n{\n    public int $count = 0;\n    private string $label = \"x\";\n\n    public function increment(): void\n    {\n    }\n\n    public function render()\n    {\n        $double = $this->count * 2;\n\n        return <button onClick={{ |}}>{{ count }}</button>;\n    }\n}\n";
    let r = run_in(src, "src/Ui/Common/Counter2.phem");
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
    assert_eq!(r.get("app_ui_gallery_gallerypage_1").detail, "/scientists/{id}");
    assert!(r.find("app_ui_about_aboutpage").is_some());
    assert!(run(&twig_fn("", "{{ 'a|' }}")).items.is_empty());
}

#[test]
fn an_operator_as_a_trigger_character_opens_no_list() {
    let trig = |source: &str, c| run_with(source, "src/Ui/HomePage.phem", Some(c));
    assert!(trig(&twig_fn("", "{{ s.likes >|"), '>').items.is_empty());
    assert!(trig(&twig_fn("", "{{ s.likes <|"), '<').items.is_empty());
    assert!(trig(&twig_fn("", "{{ s.likes /|"), '/').items.is_empty());
    assert!(trig(&twig_fn("", "{{ s.likes %|"), '%').items.is_empty());
    assert!(trig(&twig_fn("", "{%|"), '%').find("for").is_some());
    assert!(trig(&format!("{TWIG_USES}function HomePage(Scientist $s)\n{{\n    $s->|\n}}\n"), '>').find("name").is_some());
}

// --- Completion: HTML -----------------------------------------------------------------------------

fn html_fn(markup: &str) -> String {
    format!("{PAGE}function HomePage()\n{{\n    return {markup}\n}}\n")
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
    at(source, "src/Ui/HomePage.phem").expect("no symbol")
}

/// The file and the line text from the location.
fn target(s: &Symbol, current: &str) -> (String, String) {
    let loc = s.location.as_ref().expect("no location");
    let text = if loc.file == root().join("src/Ui/HomePage.phem") { cursor(current).0 } else { std::fs::read_to_string(&loc.file).unwrap() };
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

const DEF_USES: &str = "<?phem\nnamespace App\\Ui;\n\nuse App\\Model\\Scientist;\nuse App\\Repository\\ScientistRepository;\n\nuse function App\\Ui\\Common\\Layout;\n\n";

fn def_fn(markup: &str, body: &str) -> String {
    format!("{DEF_USES}function HomePage(Scientist $s, ScientistRepository $repo)\n{{\n{body}    return {markup};\n}}\n")
}

fn pair(file: &str, line: &str) -> (String, String) {
    (file.to_string(), line.to_string())
}

#[test]
fn a_tag_of_a_replaced_component_goes_to_the_replacement() {
    let src = |tag: &str, inner: &str| {
        format!("{PAGE}use Phem\\Attribute\\Component;\nuse Phem\\Html;\n\n#[Component]\nfunction Plate(?Html $children = null) {{ return <div>{{{{ children }}}}</div>; }}\n\n#[Component(replaces: Plate::class)]\nfunction MyPlate(?Html $children = null) {{ return <{inner}>{{{{ children }}}}</Plate>; }}\n\nfunction HomePage() {{ return <{tag} />; }}\n")
    };

    // A tag of the original: the replacement, and the hover shows both.
    let page = src("Pla‸te", "Plate");
    let r = at_page(&page);
    assert_eq!(target(&r, &page).1, "MyPlate(?Html $children = null) { return <Plate>{{ children }}</Plate>; }");
    assert!(hover(&r).starts_with("```php\n#[Component]\nfunction MyPlate("), "{}", hover(&r));
    assert!(hover(&r).contains("Replaces `App\\Ui\\Plate`:\n\n```php\n#[Component]\nfunction Plate("), "{}", hover(&r));

    // In the replacement: the tag stays the original.
    let page = src("Plate", "Pla‸te");
    let r = at_page(&page);
    assert_eq!(target(&r, &page).1, "Plate(?Html $children = null) { return <div>{{ children }}</div>; }");
    assert!(hover(&r).contains("Replaced by `App\\Ui\\MyPlate` in the other tags"), "{}", hover(&r));

    // Two replacements are an error of the compiler: the tag goes to the original.
    let page = src("Pla‸te", "Plate") + "\n#[Component(replaces: Plate::class)]\nfunction OtherPlate(?Html $children = null) { return <b />; }\n";
    let r = at_page(&page);
    assert_eq!(target(&r, &page).1, "Plate(?Html $children = null) { return <div>{{ children }}</div>; }");
}

#[test]
fn a_tag_in_a_parent_class_or_a_trait_of_a_replacement_stays_the_original() {
    let src = |base: &str, part: &str| {
        format!("{PAGE}use Phem\\Attribute\\Component;\nuse Phem\\Node;\n\n#[Component]\nfunction Plate() {{ return <div />; }}\n\nabstract class Base\n{{\n    public function render(): Node {{ return <{base} />; }}\n}}\n\ntrait Parts\n{{\n    public function part(): Node {{ return <{part} />; }}\n}}\n\n#[Component(replaces: Plate::class)]\nfinal class MyPlate extends Base\n{{\n    use Parts;\n}}\n\nfunction HomePage() {{ return <Plate />; }}\n")
    };

    for page in [src("Pla‸te", "Plate"), src("Plate", "Pla‸te")] {
        let r = at_page(&page);
        assert_eq!(target(&r, &page).1, "Plate() { return <div />; }", "{page}");
        assert!(hover(&r).contains("Replaced by `App\\Ui\\MyPlate` in the other tags"), "{}", hover(&r));
    }
}

#[test]
fn a_replacement_that_the_compiler_refuses_changes_no_tag() {
    let plate = "#[Component]\nfunction Plate() { return <div />; }\n";
    let my_plate = "#[Component(replaces: Plate::class)]\nfunction MyPlate() { return <b />; }\n";
    let cases = [
        (format!("{plate}#[Component(replaces: null)]\nfunction MyPlate() {{ return <b />; }}\n"), "Pla‸te", "Plate() { return <div />; }"),
        ("#[Component(replaces: Plate::class)]\nfunction Plate() { return <div />; }\n".to_string(), "Pla‸te", "Plate() { return <div />; }"),
        // The replacement of a replacement: MyPlate stays MyPlate.
        (format!("{plate}{my_plate}#[Component(replaces: MyPlate::class)]\nfunction OtherPlate() {{ return <b />; }}\n"), "My‸Plate", "MyPlate() { return <b />; }"),
        (format!("{plate}#[Page('/my'), Component(replaces: Plate::class)]\nfunction MyPlate() {{ return <b />; }}\n"), "Pla‸te", "Plate() { return <div />; }"),
    ];

    for (i, (decls, tag, line)) in cases.iter().enumerate() {
        let page = format!("{PAGE}use Phem\\Attribute\\{{Component, Page}};\n\n{decls}\nfunction HomePage() {{ return <{tag} />; }}\n");
        let r = at_page(&page);
        assert_eq!(target(&r, &page).1, *line, "case {i}");
        assert!(!hover(&r).contains("Replaces"), "case {i}: {}", hover(&r));
    }
}

#[test]
fn a_replacement_takes_a_string_or_a_class_name() {
    let plate = "#[Component]\nfunction Plate() { return <div />; }\n";
    for arg in ["replaces: Plate::class", "Plate::class", "\\App\\Ui\\Plate::class", "'App\\Ui\\Plate'", "\"\\\\App\\\\Ui\\\\Plate\""] {
        let page = format!("{PAGE}use Phem\\Attribute\\Component;\n\n{plate}#[Component({arg})]\nfunction MyPlate() {{ return <b />; }}\n\nfunction HomePage() {{ return <Pla‸te />; }}\n");
        let r = at_page(&page);
        assert_eq!(target(&r, &page).1, "MyPlate() { return <b />; }", "{arg}");
    }
}

#[test]
fn a_replacement_that_the_compiler_cannot_read_or_of_a_builtin_changes_no_tag() {
    // `self::class` is E017: the tag stays the original.
    let page = format!("{PAGE}use Phem\\Attribute\\Component;\nuse Phem\\Node;\n\n#[Component]\nfinal class Plate {{ public function render(): Node {{ return <div />; }} }}\n\n#[Component(replaces: self::class)]\nfinal class MyPlate {{ public function render(): Node {{ return <b />; }} }}\n\nfunction HomePage() {{ return <Pla‸te />; }}\n");
    let r = at_page(&page);
    assert!(!hover(&r).contains("Replaces"), "{}", hover(&r));

    // A built-in component cannot be replaced (E017).
    let page = format!("{PAGE}use Phem\\Attribute\\Component;\nuse Phem\\Html;\nuse Phem\\Root;\n\n#[Component(replaces: Root::class)]\nfunction MyRoot(?Html $children = null, mixed ...$rest) {{ return <b />; }}\n\nfunction HomePage() {{ return <Ro‸ot />; }}\n");
    let r = at_page(&page);
    assert!(r.location.is_none() && !hover(&r).contains("Replaces"), "{}", hover(&r));
}

#[test]
fn a_prop_or_a_slot_of_a_replaced_tag_goes_to_the_replacement() {
    let src = |signature: &str, tag: &str| {
        format!("{PAGE}use Phem\\Attribute\\Component;\nuse Phem\\Html;\n\n#[Component]\nfunction Plate(string $title = '', ?Html $children = null, ?Html $footer = null) {{ return <div />; }}\n\n#[Component(replaces: Plate::class)]\nfunction MyPlate({signature}) {{ return <b />; }}\n\nfunction HomePage() {{ return {tag}; }}\n")
    };
    let own = "string $title = '', ?Html $children = null, ?Html $footer = null";
    let page = src(own, "<Plate ti‸tle=\"x\" />");
    let r = at_page(&page);
    assert_eq!(target(&r, &page).1, "$title = '', ?Html $children = null, ?Html $footer = null) { return <b />; }");
    assert!(hover(&r).contains("Prop of `App\\Ui\\MyPlate`, the replacement of `App\\Ui\\Plate`"), "{}", hover(&r));

    let page = src(own, "<Plate><Plate:foo‸ter>x</Plate:footer></Plate>");
    let r = at_page(&page);
    assert_eq!(target(&r, &page).1, "$footer = null) { return <b />; }");
    assert!(hover(&r).contains("Slot of `App\\Ui\\MyPlate`, the replacement of `App\\Ui\\Plate`"), "{}", hover(&r));

    // The replacement takes the prop in its rest prop: the prop of the original.
    let page = src("?Html $children = null, ?Html $footer = null, mixed ...$rest", "<Plate ti‸tle=\"x\" />");
    let r = at_page(&page);
    assert_eq!(target(&r, &page).1, "$title = '', ?Html $children = null, ?Html $footer = null) { return <div />; }");
    assert!(hover(&r).contains("Prop of `App\\Ui\\Plate`") && !hover(&r).contains("the replacement"), "{}", hover(&r));
}

#[test]
fn a_component_tag_goes_to_the_component() {
    let src = def_fn("<Lay‸out title=\"x\"></Layout>", "");
    let r = at_page(&src);
    let layout = pair("src/Ui/Common/Layout.phem", "Layout(string $title, ?Html $children = null, ?Html $footer = null)");
    assert_eq!(target(&r, &src), layout);
    assert!(hover(&r).contains("function Layout(string $title"));

    let src = def_fn("<Layout title=\"x\"></Lay‸out>", "");
    assert_eq!(target(&at_page(&src), &src), layout);

    let src = def_fn("<Layout title=\"x\"><Layout:foo‸ter>a</Layout:footer></Layout>", "");
    assert_eq!(target(&at_page(&src), &src), pair("src/Ui/Common/Layout.phem", "$footer = null)"));
}

#[test]
fn the_builtin_dynamic_tag_needs_its_tag() {
    let r = run(&format!("{PAGE}function Button()\n{{\n    return <Dyn|;\n}}\n"));
    let item = r.get("Dynamic");
    assert_eq!(item.detail, "Phem\\Dynamic");
    assert!(item.documentation.as_ref().unwrap().contains("tag name is a value"));
    assert!(apply(&r.text, item).contains("namespace App\\Ui;\n\nuse Phem\\Dynamic;\n"));
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
fn the_hover_of_an_error_page_shows_its_statuses_and_no_route() {
    let src = format!("{DEF_USES}use Phem\\Attribute\\ErrorPage;\n\n#[ErrorPage(404, path: '/admin')]\n#[ErrorPage]\nfunction NotFound(int $status) {{ return <p />; }}\n\nfunction HomePage() {{ return <NotF‸ound />; }}\n");
    let r = at_page(&src);
    assert!(hover(&r).contains("#[ErrorPage]\nfunction NotFound(int $status)"), "{}", hover(&r));
    assert!(hover(&r).contains("Error page: `404, path: '/admin'`, all the statuses"), "{}", hover(&r));
    assert!(!hover(&r).contains("\n\nPage"), "an error page has no route: {}", hover(&r));
}

#[test]
fn a_prop_goes_to_the_parameter_or_the_property() {
    let src = def_fn("<Layout ti‸tle=\"x\" />", "");
    assert_eq!(target(&at_page(&src), &src), pair("src/Ui/Common/Layout.phem", "$title, ?Html $children = null, ?Html $footer = null)"));

    let src = format!("{DEF_USES}use App\\Ui\\Common\\Counter;\n\nfunction HomePage()\n{{\n    return <Counter ma‸x={{{{ 3 }}}} />;\n}}\n");
    let r = at_page(&src);
    assert_eq!(target(&r, &src), pair("src/Ui/Common/Counter.phem", "$max;"));
    assert!(hover(&r).contains("int $max"));
    assert!(hover(&r).contains("required"));
}

#[test]
fn a_twig_variable_goes_to_its_php_declaration() {
    let src = def_fn("<p>{{ s‸.name }}</p>", "");
    let r = at_page(&src);
    assert_eq!(r.location.as_ref().unwrap().file, root().join("src/Ui/HomePage.phem"));
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
    let src = "<?phem\nnamespace App\\Ui\\Common;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfinal class Counter3\n{\n    public function increment(): void\n    {\n    }\n\n    public function render()\n    {\n        return <button onClick={{ incre‸ment }}>+</button>;\n    }\n}\n";
    let r = at(src, "src/Ui/Common/Counter3.phem").unwrap();
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

    let src = "<?phem\nnamespace App\\Ui;\n\nuse App\\Model\\Scien‸tist;\n";
    assert_eq!(target(&at_page(src), src), pair("src/Model/Scientist.php", "Scientist extends Person"));

    let src = "<?phem\nnamespace App\\Ui;\n\nuse function Acme\\slugify;\n\n$a = slug‸ify(\"x\");\n";
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
    assert_eq!(target(&r, &src), pair("src/Ui/Gallery/GalleryPage.phem", "GalleryPage"));
    assert!(hover(&r).contains("/gallery"));
}

// --- The components of the installed packages ----------------------------------------------------

const BADGE: &str = "<?phem\nnamespace Acme\\Ui;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Badge(string $label)\n{\n    return <b>{{ label }}</b>;\n}\n";

/// A project that installs the package `acme/ui` (with the `.phem` root `components`) in its
/// vendor dir `vendor_dir`.
fn package_project(vendor_dir: &str) -> PathBuf {
    let dir = temp_dir();
    let files = [
        ("composer.json".to_string(), format!(r#"{{"config": {{"vendor-dir": "{vendor_dir}"}}}}"#)),
        (format!("{vendor_dir}/composer/installed.json"), r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#.to_string()),
        (format!("{vendor_dir}/acme/ui/composer.json"), "{}".to_string()),
        (format!("{vendor_dir}/acme/ui/components/Badge.phem"), BADGE.to_string()),
    ];

    for (name, content) in files {
        let path = dir.join(name);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(path, content).unwrap();
    }

    dir
}

/// The completion items at the cursor of `source`, in the page of the project.
fn complete_in(project: &Project, source: &str) -> Vec<Item> {
    let (text, offset) = cursor(source);
    let index = Index::new(project, Source::new(project.root.join("src/Ui/HomePage.phem"), text));

    complete(&index, offset, None)
}

#[test]
fn a_replacement_in_a_package_changes_no_tag() {
    // A package cannot replace a component (E017): the tag goes to the original.
    let dir = package_project("vendor");
    let chip = "<?phem\nnamespace Acme\\Ui;\n\nuse Phem\\Attribute\\Component;\n\n#[Component(replaces: Badge::class)]\nfunction Chip(string $label) { return <i />; }\n";
    std::fs::write(dir.join("vendor/acme/ui/components/Chip.phem"), chip).unwrap();
    let project = Project::load(&dir);

    let (text, offset) = cursor(&format!("{PAGE}use function Acme\\Ui\\Badge;\n\nfunction HomePage()\n{{\n    return <Ba‸dge label=\"x\" />;\n}}\n"));
    let symbol = symbol_at(&Index::new(&project, Source::new(dir.join("src/Ui/HomePage.phem"), text)), offset).expect("no symbol");
    assert_eq!(symbol.location.expect("no location").file, dir.join("vendor/acme/ui/components/Badge.phem"));
    assert!(!symbol.hover.unwrap().contains("Replace"));

    std::fs::remove_dir_all(dir).unwrap();
}

#[test]
fn a_component_of_an_installed_package_completes_and_goes_to_its_file() {
    let dir = package_project("deps");
    let badge = dir.join("deps/acme/ui/components/Badge.phem");
    let mut project = Project::load(&dir);

    let source = format!("{PAGE}function HomePage()\n{{\n    return <Bad|\n}}\n");
    let items = complete_in(&project, &source);
    let item = items.iter().find(|i| i.label == "Badge").expect("no item `Badge`");
    assert!(apply(&cursor(&source).0, item).contains("use function Acme\\Ui\\Badge;"));

    let (text, offset) = cursor(&format!("{PAGE}use function Acme\\Ui\\Badge;\n\nfunction HomePage()\n{{\n    return <Ba‸dge label=\"x\" />;\n}}\n"));
    let symbol = symbol_at(&Index::new(&project, Source::new(dir.join("src/Ui/HomePage.phem"), text)), offset).expect("no symbol");
    assert_eq!(symbol.location.expect("no location").file, badge);

    // The package file changes on disk: the index of the project reads it again.
    std::fs::write(&badge, BADGE.replace("Badge", "Chip")).unwrap();
    project.package_changed(&badge, None);
    let items = complete_in(&project, &format!("{PAGE}function HomePage()\n{{\n    return <C|\n}}\n"));
    assert!(items.iter().any(|i| i.label == "Chip") && !items.iter().any(|i| i.label == "Badge"));

    // The project that installs the package comes from its vendor dir.
    assert_eq!(crate::project::installer_root(&badge, None), Some(dir.clone()));

    std::fs::remove_dir_all(dir).unwrap();
}

#[cfg(unix)]
#[test]
fn a_component_of_a_linked_package_has_its_real_path() {
    // As a Composer path repository: `vendor/acme/ui` is a link to `packages/ui`.
    let dir = package_project("vendor");
    std::fs::rename(dir.join("vendor/acme/ui"), dir.join("packages-ui")).unwrap();
    std::os::unix::fs::symlink(dir.join("packages-ui"), dir.join("vendor/acme/ui")).unwrap();
    let real = dir.join("packages-ui/components/Badge.phem");
    let mut project = Project::load(&dir);

    assert_eq!(project.package_file(&dir.join("vendor/acme/ui/components/Badge.phem")), Some(real.clone()));
    assert_eq!(project.package_file(&real), Some(real.clone()));

    // The open document at the real path replaces the file.
    project.package_changed(&real, Some(&BADGE.replace("Badge", "Chip")));
    let items = complete_in(&project, &format!("{PAGE}function HomePage()\n{{\n    return <C|\n}}\n"));
    assert!(items.iter().any(|i| i.label == "Chip") && !items.iter().any(|i| i.label == "Badge"));

    std::fs::remove_dir_all(dir).unwrap();
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

    for (name, _) in FILES.iter().filter(|(n, _)| n.ends_with(".phem") || n.starts_with("src/")) {
        let file = root().join(name);
        let text = std::fs::read_to_string(&file).unwrap();
        every_offset(&project, &file, &text);

        // An incomplete file: each prefix of the text.
        let cut = text.char_indices().map(|(i, _)| i).nth(text.chars().count() / 2).unwrap();
        every_offset(&project, &file, &text[..cut]);
    }

    let odd = "<?phem\n$s = \"é{$a[\"k\"]}\"; /* ♥ */ return <p a=\"😀\" {{ ...b }}>{{ x|y(\"é\") }}{% for é in ü %}</p>; <<<EOT\nx\n";
    every_offset(&project, &root().join("src/odd.phem"), odd);
}

// --- References ----------------------------------------------------------------------------------

const USER: &str = r"<?phem
namespace App\Ui;

use Phem\Attribute\{Component, Page, State};
use function App\Ui\Common\{Layout, Avatar, Initials};

#[Page('/team')]
final class TeamPage
{
    #[State] public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function reset(): void
    {
        $this->increment();
    }

    public function render()
    {
        $short = Initials('Ada');

        return (
            <Layout title=x>
                <Avatar name={{ short }} />
                <button onClick={{ increment }}>+</button>
                <Layout:footer><Avatar name=y /></Layout:footer>
            </Layout>
        );
    }
}
";

/// The project with the `USER` page, and its usages.
fn with_usages<T>(f: impl FnOnce(&Usages, &Path) -> T) -> T {
    let file = root().join("src/Ui/TeamPage.phem");
    let mut project = Project::load(root());
    project.update_phem(&file, Some(USER));

    let mut sources: Vec<_> = project.phem.keys().filter(|f| **f != file).map(|f| Source::new(f.clone(), std::fs::read_to_string(f).unwrap())).collect();
    sources.push(Source::new(file.clone(), USER.to_string()));
    let usages = Usages::new(&project, sources);

    f(&usages, &file)
}

/// The references of the declaration at the first `needle` of a file, as `file:text`.
fn references_of(file: &str, needle: &str) -> Vec<String> {
    with_usages(|usages, team| {
        let path = root().join(file);
        let index = usages.index(&path).unwrap();
        let offset = index.current.text.find(needle).expect("no needle");
        let target = target_at(&index, offset).expect("no declaration");

        usages.find(&[target]).remove(0).iter().map(|r| {
            let text = if r.file == team { USER.to_string() } else { std::fs::read_to_string(&r.file).unwrap() };
            let line = text[..r.range.0].matches('\n').count() + 1;
            format!("{}:{line}:{}", r.file.strip_prefix(root()).unwrap().display(), &text[r.range.0..r.range.1])
        }).collect()
    })
}

#[test]
fn the_references_of_a_function_component_are_its_tags() {
    // No import, no closing tag, and no slot tag.
    assert_eq!(references_of("src/Ui/Common/Layout.phem", "Layout("), ["src/Ui/TeamPage.phem:27:Layout"]);
    assert_eq!(references_of("src/Ui/Common/Layout.phem", "Avatar("), ["src/Ui/TeamPage.phem:28:Avatar", "src/Ui/TeamPage.phem:30:Avatar"]);
}

#[test]
fn the_references_of_a_tag_are_the_references_of_its_component() {
    assert_eq!(references_of("src/Ui/TeamPage.phem", "Avatar name=y"), ["src/Ui/TeamPage.phem:28:Avatar", "src/Ui/TeamPage.phem:30:Avatar"]);
}

#[test]
fn the_references_of_a_plain_function_are_its_calls() {
    assert_eq!(references_of("src/Ui/Common/Layout.phem", "Initials("), ["src/Ui/TeamPage.phem:24:Initials"]);
}

#[test]
fn the_references_of_a_method_are_its_calls_and_its_handlers() {
    assert_eq!(references_of("src/Ui/TeamPage.phem", "increment(): void"), ["src/Ui/TeamPage.phem:19:increment", "src/Ui/TeamPage.phem:29:increment"]);
    assert_eq!(references_of("src/Ui/TeamPage.phem", "increment();"), ["src/Ui/TeamPage.phem:19:increment", "src/Ui/TeamPage.phem:29:increment"]);
    assert!(references_of("src/Ui/TeamPage.phem", "reset").is_empty());
}

#[test]
fn a_page_render_and_magic_methods_get_no_usage_count() {
    with_usages(|usages, team| {
        let index = usages.index(team).unwrap();
        let names: Vec<_> = counted(&index.current.parsed, team).into_iter().map(|d| d.name).collect();
        assert_eq!(names, ["increment", "reset"]);

        let counter = root().join("src/Ui/Common/Counter.phem");
        let index = usages.index(&counter).unwrap();
        let names: Vec<_> = counted(&index.current.parsed, &counter).into_iter().map(|d| d.name).collect();
        assert_eq!(names, ["Counter"]);
    });
}
