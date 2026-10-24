//! Snapshot tests of the generated PHP and Twig, and one test for each diagnostic.

use phemc_codegen::{compile, SourceInput};
use std::path::Path;

fn read_dir(dir: &Path, base: &Path, out: &mut Vec<SourceInput>) {
    let mut entries: Vec<_> = std::fs::read_dir(dir).unwrap().map(|e| e.unwrap().path()).collect();
    entries.sort();

    for p in entries {
        if p.is_dir() {
            read_dir(&p, base, out);
        } else if p.extension().is_some_and(|e| e == "phpm") {
            let rel = p.strip_prefix(base).unwrap().to_string_lossy().replace('\\', "/");
            out.push(SourceInput { rel, text: std::fs::read_to_string(&p).unwrap() });
        }
    }
}

fn fixtures(name: &str) -> Vec<SourceInput> {
    let base = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures").join(name);
    let mut out = Vec::new();
    read_dir(&base, &base, &mut out);
    out
}

#[test]
fn compiles_the_fixture_app() {
    let (out, diags) = compile(fixtures("ok"));
    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(move |d| format!("{}: {} {}", f.rel, d.code, d.message))).collect();
    assert!(msgs.is_empty(), "unexpected diagnostics: {msgs:#?}");

    let out = out.expect("output");
    for f in &out.files {
        insta::assert_snapshot!(format!("php__{}", f.rel.replace('/', "__")), f.php);
        for t in &f.templates {
            insta::assert_snapshot!(format!("twig__{}", t.name.replace('/', "__")), t.content);
        }
    }

    insta::assert_snapshot!("manifest", out.manifest.to_json());
}

#[test]
fn reports_each_diagnostic() {
    for input in fixtures("errors") {
        let expected = input.text.lines().next().and_then(|l| l.split("expect: ").nth(1)).expect("an `// expect: CODE` comment").trim().to_string();
        let rel = input.rel.clone();

        let (out, diags) = compile(vec![input]);
        let codes: Vec<&str> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect();
        assert!(codes.contains(&expected.as_str()), "{rel}: expected {expected}, got {codes:?}");
        if expected.starts_with('E') {
            assert!(out.is_none(), "{rel}: an error must stop the build");
        } else {
            assert!(out.is_some(), "{rel}: a warning must not stop the build");
        }
    }
}

#[test]
fn passes_the_variables_of_extension_tags() {
    let src = "<?phpm\nfunction App() { $user = []; $locale = 'fr';\n    return <p>{% trans with {'%name%': user.name} into locale %}Hi %name%{% endtrans %}</p>;\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("['user' => $user ?? null, 'locale' => $locale ?? null]"), "{php}");

    // A guessed name is still an error when an expression uses it.
    let src = "<?phpm\nfunction App() { return <p>{% trans into into %}x{% endtrans %}{{ into }}</p>; }\n";
    let (_, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);

    let codes: Vec<&str> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect();
    assert_eq!(codes, ["E030"]);
}

#[test]
fn a_quoted_url_with_an_expression_goes_through_phem_attr() {
    // phem_attr() drops a `javascript:` URL: the URL can come from data.
    let src = "<?phpm\nfunction App() { $url = ''; $id = 1;\n    return <form action=\"{{ url }}\"><a href=\"{{ url }}\" HREF=\"/items/{{ id }}\" title=\"{{ url }}\">x</a><img src=\"/a.png\" /><button formAction=\" {{ url }}\">y</button></form>;\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let twig = &out.expect("output").files[0].templates[0].content;
    for expected in ["{{ phem_attr('action', '' ~ (url)) }}", "{{ phem_attr('href', '' ~ (url)) }}", "{{ phem_attr('HREF', '/items/' ~ (id)) }}", "{{ phem_attr('formAction', ' ' ~ (url)) }}"] {
        assert!(twig.contains(expected), "{expected} in {twig}");
    }

    assert!(twig.contains(r#"title="{{ url }}""#), "another attribute keeps its Twig: {twig}");
    assert!(twig.contains(r#"src="/a.png""#), "a static URL keeps its text: {twig}");
}

fn codes(src: &str) -> Vec<&'static str> {
    let (_, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);
    diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect()
}

#[test]
fn a_set_in_an_if_branch_or_an_element_binds_after_it() {
    let src = "<?phpm\nfunction App() { $ok = true;\n    return <p>{% if ok %}{% set label = 'a' %}{% else %}{% set label = 'b' %}{% endif %}<b>{% set n = 1 %}</b>{{ label }} {{ n }}</p>;\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("['ok' => $ok ?? null]"), "{php}");

    // `for` has its own scope, and so do the children of a component (its slots).
    assert_eq!(codes("<?phpm\nfunction App() { $xs = [];\n    return <p>{% for x in xs %}{% set y = x %}{% endfor %}{{ y }}</p>;\n}\n"), ["E030"]);
    let src = "<?phpm\n#[\\Phem\\Attribute\\Component]\nfunction Box(?Phem\\Html $children = null) { return <b>{{ children }}</b>; }\nfunction App() { return <p><Box>{% set z = 1 %}</Box>{{ z }}</p>; }\n";
    assert_eq!(codes(src), ["E030"]);
}

#[test]
fn a_set_in_only_some_branches_keeps_the_php_variable() {
    // Without `else`, or with a set in one branch only, the PHP value shows when no set runs.
    for tags in ["{% if off %}{% set variant = 'b' %}{% endif %}", "{% if off %}{% set variant = 'b' %}{% else %}x{% endif %}"] {
        let src = format!("<?phpm\nfunction App() {{ $variant = 'a'; $off = true;\n    return <p>{tags}{{{{ variant }}}}</p>;\n}}\n");
        let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src }]);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
        let php = &out.expect("output").files[0].php;
        assert!(php.contains("'variant' => $variant"), "{php}");
    }

    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Btn {\n    public string $title = 'a';\n    public bool $off = false;\n    public function render() { return <b>{% if off %}{% set title = 'b' %}{% endif %}{{ title }}</b>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "Btn.phpm".into(), text: src.into() }]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'title' => $this->title"), "{php}");
}

#[test]
fn a_class_component_reads_the_private_properties_of_its_traits() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\ntrait Paginates { private int $page = 1; }\n#[Component]\nfinal class Results {\n    use Paginates;\n    public function render() { return <p>{{ page }}</p>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "Results.phpm".into(), text: src.into() }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'page' => $this->page"), "{php}");
}

#[test]
fn a_prop_whose_type_accepts_null_is_optional() {
    let src = "<?phpm\n#[\\Phem\\Attribute\\Component]\nfunction Box($a, mixed $b, int|null $c) { return <b>{{ a }}{{ b }}{{ c }}</b>; }\nfunction App() { return <Box />; }\n";
    assert_eq!(codes(src), Vec::<&str>::new());
    let src = "<?phpm\n#[\\Phem\\Attribute\\Component]\nfunction Box(int|string $a) { return <b>{{ a }}</b>; }\nfunction App() { return <Box />; }\n";
    assert_eq!(codes(src), ["E011"]);
}

#[test]
fn markup_reads_the_twig_globals() {
    let src = "<?phpm\nfunction App() { return <p>{{ app.user.name }}</p>; }\n";
    let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    assert!(out.expect("output").files[0].php.contains("new \\Phem\\Element('App.0.twig', [])"));
}

#[test]
fn a_class_component_gets_the_members_of_its_parent_and_traits() {
    let base = "<?phpm\nnamespace App;\nabstract class Base {\n    #[\\Phem\\Attribute\\Prop] public string $title = '';\n    private int $secret = 1;\n    public function save(): void {}\n    public function render() { return <b>{{ title }}</b>; }\n}\ntrait Named { #[\\Phem\\Attribute\\Prop] public string $name = ''; }\n";
    let card = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Card extends Base {\n    use Named;\n    public function view() { return <i onClick={{ save }}>{{ title }} {{ name }}</i>; }\n}\nfunction Page() { return <Card title=\"a\" name=\"b\" />; }\n";
    let inputs = vec![SourceInput { rel: "Base.phpm".into(), text: base.into() }, SourceInput { rel: "Card.phpm".into(), text: card.into() }];
    let (out, diags) = compile(inputs.clone());

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert_eq!(out.manifest.components["App\\Card"].props, ["name", "title"], "the trait, then the parent class");
    let php = &out.files.iter().find(|f| f.rel == "Card.phpm").unwrap().php;
    assert!(php.contains("'save' => $this->save(...), 'title' => $this->title, 'name' => $this->name"), "{php}");

    // A private property of the parent is not visible.
    let card = card.replace("{{ name }}", "{{ secret }}");
    let (_, diags) = compile(vec![inputs[0].clone(), SourceInput { rel: "Card.phpm".into(), text: card }]);

    let codes: Vec<&str> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect();
    assert_eq!(codes, ["E030"]);
}

#[test]
fn a_class_component_does_not_see_the_private_members_of_its_parent() {
    let base = "<?phpm\nnamespace App;\nabstract class Base {\n    public function __construct(private int $secret = 1) {}\n    private function hidden(): void {}\n}\ntrait Tools { private function tool(): void {} }\n";
    let codes = |body: &str| -> Vec<&'static str> {
        let card = format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Card extends Base {{\n    use Tools;\n    public function render() {{ return <i>{body}</i>; }}\n}}\n");
        let (_, diags) = compile(vec![SourceInput { rel: "Base.phpm".into(), text: base.into() }, SourceInput { rel: "Card.phpm".into(), text: card }]);
        diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect()
    };

    assert_eq!(codes("{{ secret }}"), ["E030"], "a private promoted property");
    assert_eq!(codes("<b onClick={{ hidden }}>x</b>"), ["E030"], "a private method");
    assert_eq!(codes("<b onClick={{ tool }}>x</b>"), Vec::<&str>::new(), "a private method of a trait");
}

#[test]
fn a_class_with_a_parent_outside_the_sources_is_not_checked() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Box extends \\Vendor\\Base {\n    public function view() { return <b>{{ title }}</b>; }\n}\nfunction Page() { return <Box title=\"a\"><p>kid</p><Box:footer>f</Box:footer></Box>; }\n";
    let (out, diags) = compile(vec![SourceInput { rel: "Box.phpm".into(), text: src.into() }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'title' => $this->title ?? (method_exists($this, 'title') ? $this->title(...) : null)"), "{php}");
}

#[test]
fn a_parent_class_loads_before_its_subclasses() {
    let about = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class About extends Base {\n    public function render() { return <p>{{ name }}</p>; }\n}\n";
    let base = "<?phpm\nnamespace App;\nabstract class Base {\n    public string $name = 'App';\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "About.phpm".into(), text: about.into() }, SourceInput { rel: "Page/Base.phpm".into(), text: base.into() }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    assert_eq!(out.expect("output").manifest.files, ["php/Page/Base.php", "php/About.php"]);
}

#[test]
fn root_is_a_built_in_component() {
    let src = |imports: &str| {
        format!("<?phpm\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Document(?Html $title = null, ?Html $children = null) {{\n    return <html><head><title>{{{{ title ?? 'App' }}}}</title></head><body><Root tag=\"main\" className=\"app\" data-controller=\"extra\">{{{{ children }}}}</Root></body></html>;\n}}\n")
    };

    let (out, diags) = compile(vec![SourceInput { rel: "Document.phpm".into(), text: src("use Phem\\{Html, Root};") }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert_eq!(
        out.files[0].templates[0].content,
        "<html><head><title>{% block title %}{{ title ?? 'App' }}{% endblock %}</title></head><body>{% component embed 'Phem\\\\Root' with {'tag': 'main', 'className': 'app', 'data-controller': 'extra'} only %}{% block children %}{% with _phem_caller only %}{{ children }}{% endwith %}{% endblock %}{% endcomponent %}</body></html>"
    );
    assert!(!out.manifest.components.contains_key("Phem\\Root"), "the runtime knows the built-in components");

    let (_, diags) = compile(vec![SourceInput { rel: "Document.phpm".into(), text: src("use Phem\\Html;") }]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Root;`"));

    let duplicate = "<?phpm\nnamespace Phem;\n#[\\Phem\\Attribute\\Component]\nfunction Root() { return <p />; }\n";
    let (_, diags) = compile(vec![SourceInput { rel: "Root.phpm".into(), text: duplicate.into() }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert_eq!(msgs, ["E014 component `Phem\\Root` is a built-in component of Phem"]);
}

#[test]
fn suspense_is_a_built_in_component() {
    let src = |imports: &str, body: &str| format!("<?phpm\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Report() {{ return <p />; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{\n    return {body};\n}}\n");
    let body = "<Suspense lazy><Suspense:fallback>Loading</Suspense:fallback><Suspense:error>Error</Suspense:error><Report /></Suspense>";
    let (out, diags) = compile(vec![SourceInput { rel: "Home.phpm".into(), text: src("use Phem\\Suspense;", body) }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert!(out.files[0].templates.iter().any(|t| t.content.contains("{% component embed 'Phem\\\\Suspense' with {'lazy': true} only %}")), "{:?}", out.files[0].templates);
    assert!(!out.manifest.components.contains_key("Phem\\Suspense"), "the runtime knows the built-in components");

    let wrong = "<Suspense><Suspense:loading>Loading</Suspense:loading><Report /></Suspense>";
    assert_eq!(codes(&src("use Phem\\Suspense;", wrong)), ["E013"]);

    let (_, diags) = compile(vec![SourceInput { rel: "Home.phpm".into(), text: src("", body) }]);
    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Suspense;`"));
}

#[test]
fn error_boundary_is_a_built_in_component() {
    let src = |imports: &str, body: &str| format!("<?phpm\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Quote() {{ return <p />; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{\n    return {body};\n}}\n");
    let body = "<ErrorBoundary><ErrorBoundary:fallback>No quote</ErrorBoundary:fallback><Quote /></ErrorBoundary>";
    let (out, diags) = compile(vec![SourceInput { rel: "Home.phpm".into(), text: src("use Phem\\ErrorBoundary;", body) }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert!(out.files[0].templates.iter().any(|t| t.content.contains("{% component embed 'Phem\\\\ErrorBoundary' only %}")), "{:?}", out.files[0].templates);
    assert!(!out.manifest.components.contains_key("Phem\\ErrorBoundary"), "the runtime knows the built-in components");

    let (_, diags) = compile(vec![SourceInput { rel: "Home.phpm".into(), text: src("use Phem\\ErrorBoundary;", "<ErrorBoundary><Quote /></ErrorBoundary>") }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.message.clone())).collect();
    assert_eq!(msgs, ["`<ErrorBoundary>` needs the slot `fallback`"]);

    let wrong = "<ErrorBoundary><ErrorBoundary:fallback>No</ErrorBoundary:fallback><ErrorBoundary:error>No</ErrorBoundary:error><Quote /></ErrorBoundary>";
    assert_eq!(codes(&src("use Phem\\ErrorBoundary;", wrong)), ["E013"]);

    let (_, diags) = compile(vec![SourceInput { rel: "Home.phpm".into(), text: src("", body) }]);
    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\ErrorBoundary;`"));
}

#[test]
fn a_page_gives_the_title_to_its_document() {
    let document = "<?phpm\nnamespace App;\nuse Phem\\{Html, Root};\n#[\\Phem\\Attribute\\Component]\nfunction Document(?Html $title = null, ?Html $children = null) {\n    return <html><head><title>{{ title ?? 'App' }}</title></head><body><Root>{{ children }}</Root></body></html>;\n}\n";
    let page = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfinal class Home {\n    public string $name = 'Ada';\n    public function render() { return <Document><Document:title>Hi {{ name }}</Document:title><p>Home</p></Document>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "Document.phpm".into(), text: document.into() }, SourceInput { rel: "Home.phpm".into(), text: page.into() }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    let home = out.files.iter().find(|f| f.rel == "Home.phpm").expect("Home.phpm");
    assert_eq!(
        home.templates[0].content,
        "{% component embed 'App\\\\Document' only %}{% block children %}{% with _phem_caller only %}<p>Home</p>{% endwith %}{% endblock %}{% block title %}{% with _phem_caller only %}Hi {{ name }}{% endwith %}{% endblock %}{% endcomponent %}"
    );
    assert_eq!(out.manifest.components["App\\Document"].slots, ["title", "children"]);
}

#[test]
fn a_required_slot_that_the_caller_does_not_give_is_an_error() {
    let src = |body: &str| format!("<?phpm\nnamespace App;\nuse Phem\\Html;\n#[\\Phem\\Attribute\\Component]\nfunction Card(Html $title, Html $children) {{ return <div>{{{{ title }}}}{{{{ children }}}}</div>; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{ return {body}; }}\n");
    let diags = |body: &str| -> Vec<(String, Option<String>)> {
        let (_, diags) = compile(vec![SourceInput { rel: "Home.phpm".into(), text: src(body) }]);
        diags.iter().flat_map(|f| f.diags.iter().map(|d| (format!("{} {}", d.code, d.message), d.help.clone()))).collect()
    };

    assert_eq!(diags("<Card><p>Body</p></Card>"), [("E011 `<Card>` needs the slot `title`".into(), Some("add `<Card:title>...</Card:title>` in `<Card>`".into()))]);
    assert_eq!(diags("<Card><Card:title>Hi</Card:title></Card>"), [("E011 `<Card>` needs the slot `children`".into(), Some("put the content between `<Card>` and `</Card>`".into()))]);
    assert!(diags("<Card><Card:title>Hi</Card:title><p>Body</p></Card>").is_empty());
}

#[test]
fn a_title_outside_head_and_svg_is_a_warning() {
    let w005 = |markup: &str| codes(&format!("<?phpm\nfunction App() {{ $on = true; return {markup}; }}\n")).iter().filter(|c| **c == "W005").count();

    assert_eq!(w005("<main><title>Home</title></main>"), 1);
    assert_eq!(w005("<html><head><title>Home</title></head><body /></html>"), 0, "the title of a document");
    assert_eq!(w005("<main><svg><title>Icon</title></svg></main>"), 0, "the name of an image");
    assert_eq!(w005("<main><svg><svg><title>a</title></svg><title>b</title></svg></main>"), 0, "nested <svg> elements");
    assert_eq!(w005("<main><svg><title>Icon</title></svg><title>Home</title></main>"), 1, "after the </svg>");
    assert_eq!(w005("<main><head></head><title>Home</title></main>"), 1, "after the </head>");
    assert_eq!(w005("<main>{{ on ? <title>Home</title> : '' }}</main>"), 1, "in a branch of an expression");
    assert_eq!(w005("<main><svg /><title>Home</title></main>"), 1, "a self-closing <svg>");
}

#[test]
fn a_parent_class_and_a_trait_load_before_the_classes_that_use_them() {
    let file = |rel: &str, text: &str| SourceInput { rel: rel.into(), text: format!("<?phpm\nnamespace App;\n{text}\n") };
    let inputs = vec![
        file("a/C.phpm", "final class C extends B {}"),
        file("b/B.phpm", "abstract class B extends A { use T; }"),
        file("c/T.phpm", "trait T {}"),
        file("d/A.phpm", "abstract class A extends \\Exception {}"),
        file("e/Free.phpm", "final class Free extends \\Exception {}"),
    ];

    let (out, diags) = compile(inputs);
    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let files = out.expect("output").manifest.files;
    assert_eq!(files, ["php/c/T.php", "php/d/A.php", "php/b/B.php", "php/a/C.php", "php/e/Free.php"], "a parent outside the sources changes nothing");
}

#[test]
fn a_page_class_is_a_component_with_a_route() {
    let page = |attrs: &str, class: &str| {
        let src = format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page, Subscribe}};\nuse Symfony\\Component\\Routing\\Attribute\\Route;\n{attrs}\n{class}\n");
        compile(vec![SourceInput { rel: "Home.phpm".into(), text: src }])
    };
    let render = "final class Home { public function render() { return <p>Home</p>; } }";

    for attrs in ["#[Page('/')]", "#[\\Phem\\Attribute\\Page('/')]", "#[Page('/')]\n#[Component]", "#[Route('/')]\n#[Component]", "#[Page('/a')]\n#[Page('/b', name: 'b')]"] {
        let (out, diags) = page(attrs, render);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "{attrs}: {:?}", diags[0].diags);
        let out = out.expect("output");
        assert_eq!(out.manifest.pages, ["App\\Home"], "{attrs}");
        assert_eq!(out.manifest.components["App\\Home"].kind, phemc_analyze::Kind::Class, "{attrs}");
    }

    let codes = |attrs: &str, class: &str| page(attrs, class).1.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect::<Vec<_>>();

    assert_eq!(codes("#[Route('/')]", "final class Home { public function render() { return <p />; } }"), ["E052"], "#[Route] needs #[Component]");
    assert_eq!(codes("#[Page('/')]", "final class Home {}"), ["E040"], "a page needs render()");
    assert_eq!(codes("#[Page('/')]", "final class Home extends \\Vendor\\Base {}"), Vec::<&str>::new(), "the parent outside the sources can have render()");
    assert_eq!(codes("#[Route('/')]", "function Home() { return <p />; }"), ["E052"], "the Symfony #[Route] cannot mark a function");
    assert_eq!(codes("#[Route('/')]\n#[Component]", "function Home() { return <p />; }"), ["E052"]);
    assert_eq!(codes("#[Page('/')]\n#[Route('/b')]", "function Home() { return <p />; }"), ["E052"]);

    let (_, diags) = page("#[Route('/')]", "function Home() { return <p />; }");
    let d = &diags[0].diags[0];
    assert_eq!(d.message, "the Symfony `#[Route]` cannot mark a function, and `App\\Home` is one");
    assert_eq!(d.help.as_deref(), Some("use `#[Page('/path')]` and `use Phem\\Attribute\\Page;`: a function page needs no `#[Component]`"));

    assert_eq!(codes("#[Page('/')]\n#[Subscribe]", "function Home() { return <p />; }"), Vec::<&str>::new(), "a function page can subscribe");
    assert_eq!(codes("#[Component]\n#[Subscribe]", "function Home() { return <p />; }"), ["E053"], "a function component cannot subscribe");
}

#[test]
fn a_function_with_page_is_a_page() {
    let page = |attrs: &str, function: &str| {
        let src = format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page}};\n{attrs}\n{function}\n");
        compile(vec![SourceInput { rel: "Home.phpm".into(), text: src }])
    };

    for (attrs, body, kind) in [
        ("#[Page('/')]", "function Home() { return <p>Home</p>; }", phemc_analyze::Kind::Template),
        ("#[Page('/')]\n#[Component]", "function Home() { return <p>Home</p>; }", phemc_analyze::Kind::Template),
        ("#[Page('/a')]\n#[Page('/b', name: 'b')]", "function Home() { $title = 'Home'; return <p>{{ title }}</p>; }", phemc_analyze::Kind::Function),
    ] {
        let (out, diags) = page(attrs, body);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "{attrs}: {:?}", diags[0].diags);
        let out = out.expect("output");
        assert_eq!(out.manifest.pages, ["App\\Home"], "{attrs}");
        assert_eq!(out.manifest.components["App\\Home"].kind, kind, "{attrs}");
    }
}

#[test]
fn a_function_is_a_component_only_with_the_component_attribute() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\nfunction Label(string $s) { return <b>{{ s }}</b>; }\n#[Component]\nfunction Card(string $title) { $label = Label($title); return <div>{{ label }}</div>; }\n#[Component]\nfunction Badge() { return <i>new</i>; }\nfunction App() { return <p><Card title=\"a\" /><Badge /></p>; }\n";
    let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let components = out.expect("output").manifest.components;
    let kinds: Vec<_> = components.iter().map(|(k, d)| (k.as_str(), d.kind)).collect();
    assert_eq!(kinds, [("App\\Badge", phemc_analyze::Kind::Template), ("App\\Card", phemc_analyze::Kind::Function)], "`Label` and `App` are plain PHP functions");

    // A tag of a function without `#[Component]` is an unknown component, with a hint.
    let src = "<?phpm\nnamespace App;\nfunction Card() { return <div />; }\nfunction App() { return <Card />; }\n";
    let (_, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without #[Component]");
    assert_eq!(d.help.as_deref(), Some("add `#[Component]` before `function Card()`, and `use Phem\\Attribute\\Component;`"));

    // Without the import, `#[Component]` is another attribute.
    let src = "<?phpm\nnamespace App;\n#[Component]\nfunction Card() { return <div />; }\nfunction App() { return <Card />; }\n";
    assert_eq!(codes(src), ["W004", "E010"]);
}

const TABS: &str = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\{Component, Context, Page, Prop, Provide};\nuse Phem\\Html;\n#[Component, Provide]\nfinal class Tabs { #[Prop] public ?Html $children = null; public function render() { return <div>{{ children }}</div>; } }\n#[Component]\nfunction Tab(string $name, #[Context] Tabs $tabs) { return <b>{{ name }}</b>; }\n#[Component]\nfunction Card(?Html $children = null) { return <section>{{ children }}</section>; }\n";

fn project(files: &[(&str, &str)]) -> Vec<String> {
    let inputs = files.iter().map(|(rel, text)| SourceInput { rel: (*rel).into(), text: (*text).into() }).collect();
    let (_, diags) = compile(inputs);
    diags.iter().flat_map(|f| f.diags.iter().map(move |d| format!("{} {}", f.rel, d.code))).collect()
}

#[test]
fn a_context_needs_a_provider_on_each_known_chain_from_a_page() {
    let page = |body: &str| format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page}};\n#[Page('/')]\nfinal class Home {{ public function render() {{ return {body}; }} }}\n#[Component]\nfunction Panel() {{ return <div><Tab name=\"b\" /></div>; }}\n");

    // Inside the provider: covered, also under another component in its slot.
    assert!(project(&[("Tabs.phpm", TABS), ("Home.phpm", &page("<Tabs><Card><Tab name=\"a\" /></Card></Tabs>"))]).is_empty());
    // In the slot of a component that is not a provider: the compiler does not know the parent.
    assert!(project(&[("Tabs.phpm", TABS), ("Home.phpm", &page("<Card><Tab name=\"a\" /></Card>"))]).is_empty());
    // A component that the page renders, with no provider: the error is on the tag, in its file.
    assert_eq!(project(&[("Tabs.phpm", TABS), ("Home.phpm", &page("<main><Panel /></main>"))]), ["Home.phpm E015"]);
    // The same component inside the provider: covered.
    assert!(project(&[("Tabs.phpm", TABS), ("Home.phpm", &page("<Tabs><Panel /></Tabs>"))]).is_empty());
    // No page renders the component: no chain, no error.
    assert!(project(&[("Tabs.phpm", TABS), ("Home.phpm", &page("<main />"))]).is_empty());
}

#[test]
fn a_provider_of_a_type_that_the_compiler_cannot_see_can_be_a_subtype() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\{Component, Context, Page, Prop, Provide};\nuse Phem\\Html;\n#[Component]\nfinal class Shell { #[Provide] public Dark $theme; #[Prop] public ?Html $children = null; public function render() { return <div>{{ children }}</div>; } }\n#[Component]\nfunction Button(#[Context] Theme $theme) { $x = 1; return <b />; }\n#[Page('/')]\nfinal class Home { public function render() { return <Shell><Button /></Shell>; } }\n";
    assert!(project(&[("App.phpm", src)]).is_empty(), "`Dark` can implement `Theme`");

    let src = src.replace("Shell><Button /></Shell", "main><Button /></main");
    assert_eq!(project(&[("App.phpm", &src)]), ["App.phpm E015"]);
}

#[test]
fn a_member_path_in_a_handler_is_the_method() {
    let src = "<?phpm\nfunction App() { $tabs = $form = $select = null; return <p><button onClick={{ tabs.select }} /><button onClick={{ select }} phem:loading={{ form.save }} /></p>; }\n";
    let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);
    let out = out.unwrap_or_else(|| panic!("{diags:?}"));

    let twig = &out.files[0].templates[0].content;
    assert!(twig.contains("phem_on('click', phem_method(tabs, 'select'))"), "{twig}");
    assert!(twig.contains("phem_on('click', select)"), "{twig}");
    assert!(twig.contains("phem_loading(phem_method(form, 'save'))"), "{twig}");
}

#[test]
fn async_checks_only_the_attribute_and_its_return_type() {
    // A helper that returns the Promise of a handler, and an #[Async] handler of an abstract base
    // class: the runtime checks the handler that an event calls.
    let src = "<?phpm\nuse Phem\\Async\\Promise;\nuse Phem\\Attribute\\{Async, Component};\nuse Phem\\Node;\nabstract class Base\n{\n    #[Async]\n    public function run(): Promise { return $this->work(); }\n\n    private function work(): Promise { return new Promise(static function (): void {}); }\n}\n#[Component]\nfinal class Export extends Base\n{\n    public function render(): Node { return <button onClick={{ run }}>Export</button>; }\n}\n";
    assert_eq!(codes(src), Vec::<&str>::new());
}
