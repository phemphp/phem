//! Snapshot tests of the generated PHP and Twig, and one test for each diagnostic.

use phemc_codegen::{compile, SourceInput};
use std::collections::BTreeMap;
use std::path::Path;

fn read_dir(dir: &Path, base: &Path, out: &mut Vec<SourceInput>) {
    let mut entries: Vec<_> = std::fs::read_dir(dir).unwrap().map(|e| e.unwrap().path()).collect();
    entries.sort();

    for p in entries {
        if p.is_dir() {
            read_dir(&p, base, out);
        } else if p.extension().is_some_and(|e| e == "phpm") {
            let rel = p.strip_prefix(base).unwrap().to_string_lossy().replace('\\', "/");
            out.push(SourceInput::app(rel, std::fs::read_to_string(&p).unwrap()));
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

    // The release sets the version: the snapshot has a placeholder.
    let manifest = out.manifest.to_json();
    let version = format!(r#""version": "{}""#, phemc_codegen::VERSION);
    assert!(manifest.contains(&version), "the manifest has the version of phemc");
    insta::assert_snapshot!("manifest", manifest.replacen(&version, r#""version": "[version]""#, 1));
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
    let (out, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("['user' => $user ?? null, 'locale' => $locale ?? null]"), "{php}");

    // A guessed name is still an error when an expression uses it.
    let src = "<?phpm\nfunction App() { return <p>{% trans into into %}x{% endtrans %}{{ into }}</p>; }\n";
    let (_, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

    let codes: Vec<&str> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect();
    assert_eq!(codes, ["E030"]);
}

#[test]
fn a_quoted_url_with_an_expression_goes_through_phem_attr() {
    // phem_attr() drops a `javascript:` URL: the URL can come from data.
    let src = "<?phpm\nfunction App() { $url = ''; $id = 1;\n    return <form action=\"{{ url }}\"><a href=\"{{ url }}\" HREF=\"/items/{{ id }}\" title=\"{{ url }}\">x</a><img src=\"/a.png\" /><button formAction=\" {{ url }}\">y</button></form>;\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let twig = &out.expect("output").files[0].templates[0].content;
    for expected in ["{{ phem_attr('action', '' ~ (url)) }}", "{{ phem_attr('href', '' ~ (url)) }}", "{{ phem_attr('HREF', '/items/' ~ (id)) }}", "{{ phem_attr('formAction', ' ' ~ (url)) }}"] {
        assert!(twig.contains(expected), "{expected} in {twig}");
    }

    assert!(twig.contains(r#"title="{{ url }}""#), "another attribute keeps its Twig: {twig}");
    assert!(twig.contains(r#"src="/a.png""#), "a static URL keeps its text: {twig}");
}

fn codes(src: &str) -> Vec<&'static str> {
    let (_, diags) = compile(vec![SourceInput::app("App.phpm", src)]);
    diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect()
}

#[test]
fn a_set_in_an_if_branch_or_an_element_binds_after_it() {
    let src = "<?phpm\nfunction App() { $ok = true;\n    return <p>{% if ok %}{% set label = 'a' %}{% else %}{% set label = 'b' %}{% endif %}<b>{% set n = 1 %}</b>{{ label }} {{ n }}</p>;\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

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
        let (out, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
        let php = &out.expect("output").files[0].php;
        assert!(php.contains("'variant' => $variant"), "{php}");
    }

    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Btn {\n    public string $title = 'a';\n    public bool $off = false;\n    public function render() { return <b>{% if off %}{% set title = 'b' %}{% endif %}{{ title }}</b>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Btn.phpm", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'title' => $this->title"), "{php}");
}

#[test]
fn a_class_component_reads_the_private_properties_of_its_traits() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\ntrait Paginates { private int $page = 1; }\n#[Component]\nfinal class Results {\n    use Paginates;\n    public function render() { return <p>{{ page }}</p>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Results.phpm", src)]);

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
    let (out, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    assert!(out.expect("output").files[0].php.contains("new \\Phem\\Element('App.0.twig', [])"));
}

#[test]
fn a_class_component_gets_the_members_of_its_parent_and_traits() {
    let base = "<?phpm\nnamespace App;\nabstract class Base {\n    #[\\Phem\\Attribute\\Prop] public string $title = '';\n    private int $secret = 1;\n    public function save(): void {}\n    public function render() { return <b>{{ title }}</b>; }\n}\ntrait Named { #[\\Phem\\Attribute\\Prop] public string $name = ''; }\n";
    let card = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Card extends Base {\n    use Named;\n    public function view() { return <i onClick={{ save }}>{{ title }} {{ name }}</i>; }\n}\nfunction Page() { return <Card title=\"a\" name=\"b\" />; }\n";
    let inputs = vec![SourceInput::app("Base.phpm", base), SourceInput::app("Card.phpm", card)];
    let (out, diags) = compile(inputs.clone());

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert_eq!(out.manifest.components["App\\Card"].props, ["name", "title"], "the trait, then the parent class");
    let php = &out.files.iter().find(|f| f.rel == "Card.phpm").unwrap().php;
    assert!(php.contains("'save' => $this->save(...), 'title' => $this->title, 'name' => $this->name"), "{php}");

    // A private property of the parent is not visible.
    let card = card.replace("{{ name }}", "{{ secret }}");
    let (_, diags) = compile(vec![inputs[0].clone(), SourceInput::app("Card.phpm", card)]);

    let codes: Vec<&str> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect();
    assert_eq!(codes, ["E030"]);
}

#[test]
fn a_class_component_does_not_see_the_private_members_of_its_parent() {
    let base = "<?phpm\nnamespace App;\nabstract class Base {\n    public function __construct(private int $secret = 1) {}\n    private function hidden(): void {}\n}\ntrait Tools { private function tool(): void {} }\n";
    let codes = |body: &str| -> Vec<&'static str> {
        let card = format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Card extends Base {{\n    use Tools;\n    public function render() {{ return <i>{body}</i>; }}\n}}\n");
        let (_, diags) = compile(vec![SourceInput::app("Base.phpm", base), SourceInput::app("Card.phpm", card)]);
        diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect()
    };

    assert_eq!(codes("{{ secret }}"), ["E030"], "a private promoted property");
    assert_eq!(codes("<b onClick={{ hidden }}>x</b>"), ["E030"], "a private method");
    assert_eq!(codes("<b onClick={{ tool }}>x</b>"), Vec::<&str>::new(), "a private method of a trait");
}

#[test]
fn a_class_with_a_parent_outside_the_sources_is_not_checked() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Box extends \\Vendor\\Base {\n    public function view() { return <b>{{ title }}</b>; }\n}\nfunction Page() { return <Box title=\"a\"><p>kid</p><Box:footer>f</Box:footer></Box>; }\n";
    let (out, diags) = compile(vec![SourceInput::app("Box.phpm", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'title' => $this->title ?? (method_exists($this, 'title') ? $this->title(...) : null)"), "{php}");
}

#[test]
fn a_parent_class_loads_before_its_subclasses() {
    let about = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class About extends Base {\n    public function render() { return <p>{{ name }}</p>; }\n}\n";
    let base = "<?phpm\nnamespace App;\nabstract class Base {\n    public string $name = 'App';\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("About.phpm", about), SourceInput::app("Page/Base.phpm", base)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    assert_eq!(out.expect("output").manifest.files, ["php/Page/Base.php", "php/About.php"]);
}

#[test]
fn root_is_a_built_in_component() {
    let src = |imports: &str| {
        format!("<?phpm\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Document(?Html $title = null, ?Html $children = null) {{\n    return <html><head><title>{{{{ title ?? 'App' }}}}</title></head><body><Root tag=\"main\" className=\"app\" data-controller=\"extra\">{{{{ children }}}}</Root></body></html>;\n}}\n")
    };

    let (out, diags) = compile(vec![SourceInput::app("Document.phpm", src("use Phem\\{Html, Root};"))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert_eq!(
        out.files[0].templates[0].content,
        "<html><head><title>{% block title %}{{ title ?? 'App' }}{% endblock %}</title></head><body>{% component embed 'Phem\\\\Root' with {'tag': 'main', 'className': 'app', 'data-controller': 'extra'} only %}{% block children %}{% with _phem_caller only %}{{ children }}{% endwith %}{% endblock %}{% endcomponent %}</body></html>"
    );
    assert!(!out.manifest.components.contains_key("Phem\\Root"), "the runtime knows the built-in components");

    let (_, diags) = compile(vec![SourceInput::app("Document.phpm", src("use Phem\\Html;"))]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Root;`"));

    let duplicate = "<?phpm\nnamespace Phem;\n#[\\Phem\\Attribute\\Component]\nfunction Root() { return <p />; }\n";
    let (_, diags) = compile(vec![SourceInput::app("Root.phpm", duplicate)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert_eq!(msgs, ["E014 component `Phem\\Root` is a built-in component of Phem"]);
}

#[test]
fn dynamic_is_an_element_with_a_tag_from_a_value() {
    let src = |imports: &str, body: &str| format!("<?phpm\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Button(string $tag = 'button', ?Html $children = null, mixed ...$rest) {{\n    return {body};\n}}\n");
    let body = "<Dynamic tag={{ tag }} type=\"button\" {{ ...rest }} onClick={{ rest.onPress }}>{{ children }}</Dynamic>";
    let (out, diags) = compile(vec![SourceInput::app("Button.phpm", src("use Phem\\{Dynamic, Html};", body))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert_eq!(
        out.files[0].templates[0].content,
        "{% set _phem_tag_0 = phem_tag((tag)) %}{% set _phem_content_0 %}{% block children %}{{ children }}{% endblock %}{% endset %}<{{ _phem_tag_0 }} {{ phem_attrs({'type': 'button'}, rest, {'onClick': (phem_method(rest, 'onPress'))}) }}>{{ phem_end_tag(_phem_tag_0, _phem_content_0) }}",
        "the tag is evaluated and checked once"
    );
    assert!(!out.manifest.components.contains_key("Phem\\Dynamic"), "phemc compiles the built-in element");

    // No children, no attributes.
    let (out, _) = compile(vec![SourceInput::app("Button.phpm", src("use Phem\\{Dynamic, Html};", "<Dynamic tag={{ tag }} />"))]);
    assert_eq!(out.expect("output").files[0].templates[0].content, "{% set _phem_tag_0 = phem_tag((tag)) %}<{{ _phem_tag_0 }}>{{ phem_end_tag(_phem_tag_0) }}");

    let (_, diags) = compile(vec![SourceInput::app("Button.phpm", src("use Phem\\Html;", body))]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Dynamic;`"));

    let (_, diags) = compile(vec![SourceInput::app("Button.phpm", src("use Phem\\{Dynamic, Html};", "<Dynamic tag=\"Style\" />"))]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E032").expect("E032 for a <style>");
    assert_eq!(d.message, "`<Dynamic>` cannot render a `<style>`");
    assert_eq!(d.help.as_deref(), Some("write the `<style>` as static markup"));

    let error = |body: &str| {
        let (_, diags) = compile(vec![SourceInput::app("Button.phpm", src("use Phem\\{Dynamic, Html};", body))]);
        diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {} ({})", d.code, d.message, d.help.as_deref().unwrap_or("")))).collect::<Vec<_>>()
    };
    assert_eq!(error("<Dynamic tag=\"iframe\" />"), ["E032 `<Dynamic>` cannot render a `<iframe>` (write the `<iframe>` as static markup)"], "an iframe runs its `srcdoc`");
    assert_eq!(error("<Dynamic tag=\"phem-c\" />"), ["E032 `<phem-c>` is an element of Phem (give a name that does not start with `phem-`)"]);
    assert_eq!(error("<Dynamic tag=\"a\" tag={{ tag }} />"), ["E032 `<Dynamic>` has two `tag` attributes (keep one `tag`)"]);
}

#[test]
fn a_set_in_a_dynamic_element_binds_in_its_parent() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Dynamic;\n#[\\Phem\\Attribute\\Component]\nfunction Card() {\n    return <div><Dynamic tag=\"p\">{% set label = 'x' %}</Dynamic>{{ label }}</div>;\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Card.phpm", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(!php.contains("label"), "the children of <Dynamic> are in the scope of the parent: {php}");
}

#[test]
fn a_context_consumer_in_a_dynamic_element_is_placed() {
    let src = TABS.replace("use Phem\\Html;", "use Phem\\{Dynamic, Html};")
        + "#[Page('/')]\nfinal class Home { public function render() { return <Dynamic tag=\"main\"><Tab name=\"a\" /></Dynamic>; } }\n";
    assert_eq!(project(&[("App.phpm", &src)]), ["App.phpm E015"], "`<Dynamic>` is not a component: the page is the parent of the tag");

    let src = src.replace("<Dynamic tag=\"main\"><Tab name=\"a\" /></Dynamic>", "<Tabs><Dynamic tag=\"main\"><Tab name=\"a\" /></Dynamic></Tabs>");
    assert!(project(&[("App.phpm", &src)]).is_empty());
}

#[test]
fn suspense_is_a_built_in_component() {
    let src = |imports: &str, body: &str| format!("<?phpm\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Report() {{ return <p />; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{\n    return {body};\n}}\n");
    let body = "<Suspense lazy><Suspense:fallback>Loading</Suspense:fallback><Suspense:error>Error</Suspense:error><Report /></Suspense>";
    let (out, diags) = compile(vec![SourceInput::app("Home.phpm", src("use Phem\\Suspense;", body))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert!(out.files[0].templates.iter().any(|t| t.content.contains("{% component embed 'Phem\\\\Suspense' with {'lazy': true} only %}")), "{:?}", out.files[0].templates);
    assert!(!out.manifest.components.contains_key("Phem\\Suspense"), "the runtime knows the built-in components");

    let wrong = "<Suspense><Suspense:loading>Loading</Suspense:loading><Report /></Suspense>";
    assert_eq!(codes(&src("use Phem\\Suspense;", wrong)), ["E013"]);

    let (_, diags) = compile(vec![SourceInput::app("Home.phpm", src("", body))]);
    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Suspense;`"));
}

#[test]
fn error_boundary_is_a_built_in_component() {
    let src = |imports: &str, body: &str| format!("<?phpm\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Quote() {{ return <p />; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{\n    return {body};\n}}\n");
    let body = "<ErrorBoundary><ErrorBoundary:fallback>No quote</ErrorBoundary:fallback><Quote /></ErrorBoundary>";
    let (out, diags) = compile(vec![SourceInput::app("Home.phpm", src("use Phem\\ErrorBoundary;", body))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert!(out.files[0].templates.iter().any(|t| t.content.contains("{% component embed 'Phem\\\\ErrorBoundary' only %}")), "{:?}", out.files[0].templates);
    assert!(!out.manifest.components.contains_key("Phem\\ErrorBoundary"), "the runtime knows the built-in components");

    let (_, diags) = compile(vec![SourceInput::app("Home.phpm", src("use Phem\\ErrorBoundary;", "<ErrorBoundary><Quote /></ErrorBoundary>"))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.message.clone())).collect();
    assert_eq!(msgs, ["`<ErrorBoundary>` needs the slot `fallback`"]);

    let wrong = "<ErrorBoundary><ErrorBoundary:fallback>No</ErrorBoundary:fallback><ErrorBoundary:error>No</ErrorBoundary:error><Quote /></ErrorBoundary>";
    assert_eq!(codes(&src("use Phem\\ErrorBoundary;", wrong)), ["E013"]);

    let (_, diags) = compile(vec![SourceInput::app("Home.phpm", src("", body))]);
    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\ErrorBoundary;`"));
}

#[test]
fn a_page_gives_the_title_to_its_document() {
    let document = "<?phpm\nnamespace App;\nuse Phem\\{Html, Root};\n#[\\Phem\\Attribute\\Component]\nfunction Document(?Html $title = null, ?Html $children = null) {\n    return <html><head><title>{{ title ?? 'App' }}</title></head><body><Root>{{ children }}</Root></body></html>;\n}\n";
    let page = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfinal class Home {\n    public string $name = 'Ada';\n    public function render() { return <Document><Document:title>Hi {{ name }}</Document:title><p>Home</p></Document>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Document.phpm", document), SourceInput::app("Home.phpm", page)]);

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
        let (_, diags) = compile(vec![SourceInput::app("Home.phpm", src(body))]);
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
    let file = |rel: &str, text: &str| SourceInput::app(rel, format!("<?phpm\nnamespace App;\n{text}\n"));
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
        let src = format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page, Prop, Broadcast, BroadcastKey}};\nuse Symfony\\Component\\Routing\\Attribute\\Route;\n{attrs}\n{class}\n");
        compile(vec![SourceInput::app("Home.phpm", src)])
    };
    let render = "final class Home { public function render() { return <p>Home</p>; } }";

    for attrs in ["#[Page('/')]", "#[\\Phem\\Attribute\\Page('/')]", "#[Page('/')]\n#[Component]", "#[Route('/')]\n#[Component]", "#[Page('/a')]\n#[Page('/b', name: 'b')]"] {
        let (out, diags) = page(attrs, render);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "{attrs}: {:?}", diags[0].diags);
        let out = out.expect("output");
        assert_eq!(out.manifest.pages, BTreeMap::from([("App\\Home".to_string(), String::new())]), "{attrs}");
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

    assert_eq!(codes("#[Page('/')]\n#[Broadcast]", "function Home() { return <p />; }"), Vec::<&str>::new(), "a function page can have #[Broadcast]");
    assert_eq!(codes("#[Component]\n#[Broadcast]", "function Home() { return <p />; }"), Vec::<&str>::new(), "a function component can have #[Broadcast]");
    assert_eq!(codes("#[Component]\n#[Broadcast]", render), Vec::<&str>::new(), "a class component can have #[Broadcast]");
    assert_eq!(codes("#[Broadcast]", "function Home() { return <p />; }"), ["E053"], "a plain function cannot have #[Broadcast]");
    assert_eq!(codes("#[Broadcast]", "final class Home {}"), ["E053"], "a plain class cannot have #[Broadcast]");

    let handler = "final class Home { #[Prop, BroadcastKey] public int $id; #[Broadcast] public function like(): void {} public function render() { return <p />; } }";
    assert_eq!(codes("#[Component]", handler), Vec::<&str>::new(), "#[Broadcast] on a handler makes the component broadcast");
    assert_eq!(codes("#[Page('/')]", "final class Home { #[Broadcast] public function save(): void {} public function render() { return <p />; } }"), ["E053"], "a handler of a page does not refresh it");
    assert_eq!(codes("#[Component]", "final class Home { #[Prop, BroadcastKey] public int $id; public function render() { return <p />; } }"), ["E057"], "a key needs a component that broadcasts");
    assert_eq!(codes("#[Component]\n#[Broadcast]", "final class Home { public function __construct(#[BroadcastKey] private int $id) {} public function render() { return <p />; } }"), ["E057"], "a promoted parameter is not a prop");
    // A parent with the handler or the key: the analysis does not report the parent or the child.
    let base = "abstract class Base { #[Prop, BroadcastKey] public int $id; #[Broadcast] public function like(): void {} }";
    assert_eq!(codes("", &format!("{base}\n#[Component]\nfinal class Home extends Base {{ #[Prop, BroadcastKey] public int $other; public function render() {{ return <p />; }} }}")), Vec::<&str>::new(), "an inherited handler with #[Broadcast]");
}

#[test]
fn a_function_with_page_is_a_page() {
    let page = |attrs: &str, function: &str| {
        let src = format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page}};\n{attrs}\n{function}\n");
        compile(vec![SourceInput::app("Home.phpm", src)])
    };

    for (attrs, body, kind) in [
        ("#[Page('/')]", "function Home() { return <p>Home</p>; }", phemc_analyze::Kind::Template),
        ("#[Page('/')]\n#[Component]", "function Home() { return <p>Home</p>; }", phemc_analyze::Kind::Template),
        ("#[Page('/a')]\n#[Page('/b', name: 'b')]", "function Home() { $title = 'Home'; return <p>{{ title }}</p>; }", phemc_analyze::Kind::Function),
    ] {
        let (out, diags) = page(attrs, body);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "{attrs}: {:?}", diags[0].diags);
        let out = out.expect("output");
        assert_eq!(out.manifest.pages, BTreeMap::from([("App\\Home".to_string(), String::new())]), "{attrs}");
        assert_eq!(out.manifest.components["App\\Home"].kind, kind, "{attrs}");
    }
}

#[test]
fn a_function_is_a_component_only_with_the_component_attribute() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\nfunction Label(string $s) { return <b>{{ s }}</b>; }\n#[Component]\nfunction Card(string $title) { $label = Label($title); return <div>{{ label }}</div>; }\n#[Component]\nfunction Badge() { return <i>new</i>; }\nfunction App() { return <p><Card title=\"a\" /><Badge /></p>; }\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let components = out.expect("output").manifest.components;
    let kinds: Vec<_> = components.iter().map(|(k, d)| (k.as_str(), d.kind)).collect();
    assert_eq!(kinds, [("App\\Badge", phemc_analyze::Kind::Template), ("App\\Card", phemc_analyze::Kind::Function)], "`Label` and `App` are plain PHP functions");

    // A tag of a function without `#[Component]` is an unknown component, with a hint.
    let src = "<?phpm\nnamespace App;\nfunction Card() { return <div />; }\nfunction App() { return <Card />; }\n";
    let (_, diags) = compile(vec![SourceInput::app("App.phpm", src)]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without #[Component]");
    assert_eq!(d.help.as_deref(), Some("add `#[Component]` before `function Card()`, and `use Phem\\Attribute\\Component;`"));

    // Without the import, `#[Component]` is another attribute.
    let src = "<?phpm\nnamespace App;\n#[Component]\nfunction Card() { return <div />; }\nfunction App() { return <Card />; }\n";
    assert_eq!(codes(src), ["W004", "E010"]);
}

const TABS: &str = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\{Component, Context, Page, Prop, Provide};\nuse Phem\\Html;\n#[Component, Provide]\nfinal class Tabs { #[Prop] public ?Html $children = null; public function render() { return <div>{{ children }}</div>; } }\n#[Component]\nfunction Tab(string $name, #[Context] Tabs $tabs) { return <b>{{ name }}</b>; }\n#[Component]\nfunction Card(?Html $children = null) { return <section>{{ children }}</section>; }\n";

fn project(files: &[(&str, &str)]) -> Vec<String> {
    let inputs = files.iter().map(|(rel, text)| SourceInput::app(*rel, *text)).collect();
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
fn an_abstract_class_is_not_a_component() {
    let base = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\{Component, State};\n#[Component]\nabstract class Base { #[State] public string $active = ''; }\nfunction Page() { return <Base />; }\n";
    let (out, diags) = compile(vec![SourceInput::app("Base.phpm", base)]);

    let d: Vec<_> = diags.iter().flat_map(|f| &f.diags).collect();
    assert_eq!(d.iter().map(|d| d.code).collect::<Vec<_>>(), ["E045", "E010"], "no E040: the children give `render()`");
    assert_eq!(d[0].message, "component class `Base` is abstract");
    assert_eq!(d[0].help.as_deref(), Some("remove `#[Component]`: the children declare it"));
    assert!(out.is_none());

    // With no `#[Component]`, the base is a plain class.
    let plain = base.replace("#[Component]\n", "").replace("function Page() { return <Base />; }\n", "");
    assert!(project(&[("Base.phpm", &plain)]).is_empty());

    // The other modifiers can come before or after `abstract`.
    for modifiers in ["readonly abstract", "abstract readonly"] {
        assert_eq!(codes(&base.replace("abstract class", &format!("{modifiers} class"))), ["E045", "E010"], "{modifiers}");
    }

    // An `abstract` that is not a modifier of the class does not change it.
    let card = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\nconst ABSTRACT = 1;\n#[Component]\nfinal class Card { public function render() { return <b />; } }\nfunction Page() { return <Card />; }\n";
    assert!(codes(card).is_empty());

    // An abstract page.
    let page = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Page;\n#[Page('/admin')]\nabstract class AdminPage {}\n";
    let (out, diags) = compile(vec![SourceInput::app("Admin.phpm", page)]);

    let d: Vec<_> = diags.iter().flat_map(|f| &f.diags).collect();
    assert_eq!(d.iter().map(|d| (d.code, d.message.as_str(), d.help.as_deref())).collect::<Vec<_>>(), [("E045", "page class `AdminPage` is abstract", Some("remove `#[Page]`: the children declare it"))]);
    assert!(out.is_none());
}

#[test]
fn an_abstract_replacement_does_not_replace_the_original() {
    let src = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Button() { return <button />; }\n#[Component(replaces: Button::class)]\nabstract class MyButton {}\nfunction Page() { return <p><Button /><Button /></p>; }\n";
    assert_eq!(codes(src), ["E045"], "the tags of `Button` render `Button`: no E010");
}

#[test]
fn a_component_provides_itself_with_provide_on_a_parent_class() {
    let base = "<?phpm\nnamespace Acme\\Base;\nuse Phem\\Attribute\\Provide;\n#[Provide]\nabstract class AbstractTabs {}\n";
    let theme = "<?phpm\nnamespace App;\nuse Acme\\Base\\AbstractTabs;\nuse Phem\\Attribute\\{Component, Context, Page};\n#[Component]\nfinal class Tabs extends AbstractTabs { public function render() { return <div><Tab /></div>; } }\n#[Component]\nfunction Tab(#[Context] AbstractTabs $tabs) { return <b />; }\n#[Page('/')]\nfinal class Home { public function render() { return <Tabs />; } }\n";
    assert!(project(&[("Base.phpm", base), ("App.phpm", theme)]).is_empty(), "`Tabs` provides `AbstractTabs` to the `<Tab>` that it renders");

    // Also through a parent of the parent.
    let middle = "<?phpm\nnamespace App;\nabstract class Middle extends \\Acme\\Base\\AbstractTabs {}\n";
    let theme2 = theme.replace("extends AbstractTabs", "extends Middle");
    assert!(project(&[("Base.phpm", base), ("Middle.phpm", middle), ("App.phpm", &theme2)]).is_empty());

    // With no `#[Provide]` on the parent, `Tabs` provides nothing.
    let plain = base.replace("#[Provide]\n", "");
    assert_eq!(project(&[("Base.phpm", &plain), ("App.phpm", theme)]), ["App.phpm E015"]);

    // A child of a component with `#[Provide]` provides itself too.
    let tabs = "<?phpm\nnamespace App;\nuse Phem\\Attribute\\{Component, Context, Page, Provide};\n#[Component, Provide]\nclass Tabs { public function render() { return <div />; } }\n#[Component]\nfinal class MyTabs extends Tabs { public function render() { return <div><Tab /></div>; } }\n#[Component]\nfunction Tab(#[Context] Tabs $tabs) { return <b />; }\n#[Page('/')]\nfinal class Home { public function render() { return <MyTabs />; } }\n";
    assert!(project(&[("App.phpm", tabs)]).is_empty());
}

#[test]
fn a_parent_outside_the_sources_can_provide_but_a_trait_cannot() {
    let src = |body: &str| format!("<?phpm\nnamespace App;\nuse Phem\\Attribute\\{{Component, Context, Page}};\n#[Component]\nfinal class Tabs {body} {{ public function render() {{ return <div><Tab /></div>; }} }}\n#[Component]\nfunction Tab(#[Context] Tabs $tabs) {{ return <b />; }}\n#[Page('/')]\nfinal class Home {{ public function render() {{ return <Tabs />; }} }}\n");

    assert!(project(&[("App.phpm", &src("extends \\Vendor\\Base"))]).is_empty(), "the parent can have `#[Provide]`");
    assert_eq!(project(&[("App.phpm", &src("").replace("{ public function render", "{ use \\Vendor\\Tools; public function render"))]), ["App.phpm E015"], "a trait does not give its attributes");
}

#[test]
fn a_member_path_in_a_handler_is_the_method() {
    let src = "<?phpm\nfunction App() { $tabs = $form = $select = null; return <p><button onClick={{ tabs.select }} /><button onClick={{ select }} phem:loading={{ form.save }} /></p>; }\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phpm", src)]);
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

fn package(rel: &str, path: &str, text: &str) -> SourceInput {
    SourceInput { rel: rel.into(), path: path.into(), package: Some("acme/ui".into()), text: text.into() }
}

#[test]
fn a_package_compiles_with_the_app() {
    let badge = "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Page};\n#[Component]\nfunction Badge(string $label) { return <span>{{ label }}</span>; }\n#[Page('/gallery')]\nfunction Gallery() { return <ul><li>{{ 1 }}</li>{% for i in [1] %}<li>{{ i }}</li>{% endfor %}</ul>; }\n";
    let home = "<?phpm\nnamespace App;\nuse Acme\\Ui\\Badge;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfunction Home() { return <Badge label=\"New\" />; }\n";
    let (out, diags) = compile(vec![
        SourceInput { rel: "Home.phpm".into(), path: "src/Home.phpm".into(), package: None, text: home.into() },
        package("vendor/acme/ui/Badge.phpm", "vendor/acme/ui/components/Badge.phpm", badge),
    ]);

    // W002 (a list item with no key) in the package file: the tools do not show it.
    let package_diags = diags.iter().find(|f| f.package.is_some()).unwrap();
    assert_eq!(package_diags.diags.iter().map(|d| d.code).collect::<Vec<_>>(), ["W002"]);
    assert_eq!(package_diags.shown().count(), 0, "a package warning is not shown");
    assert_eq!(package_diags.path, "vendor/acme/ui/components/Badge.phpm");

    let out = out.expect("output");
    let paths: Vec<&str> = out.files.iter().map(|f| f.php_path.as_str()).collect();
    assert_eq!(paths, ["php/Home.php", "php/vendor/acme/ui/Badge.php"]);
    assert!(out.files[1].php.starts_with("<?php /* compiled by phemc from vendor/acme/ui/components/Badge.phpm;"));

    let sources: Vec<(&str, &str)> = out.manifest.templates.iter().map(|(n, t)| (n.as_str(), t.source.as_str())).collect();
    assert_eq!(sources, [("Home.0.twig", "src/Home.phpm"), ("vendor/acme/ui/Badge.0.twig", "vendor/acme/ui/components/Badge.phpm"), ("vendor/acme/ui/Badge.1.twig", "vendor/acme/ui/components/Badge.phpm")]);
    assert_eq!(out.manifest.pages, BTreeMap::from([("Acme\\Ui\\Gallery".to_string(), "acme/ui".to_string()), ("App\\Home".to_string(), String::new())]));
}

#[test]
fn a_package_error_stops_the_build() {
    let broken = "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Card() { return <Missing />; }\n";
    let (out, diags) = compile(vec![package("vendor/acme/ui/Card.phpm", "vendor/acme/ui/components/Card.phpm", broken)]);

    assert!(out.is_none());
    assert_eq!(diags[0].shown().map(|d| d.code).collect::<Vec<_>>(), ["E010"]);
}

#[test]
fn a_duplicate_component_names_the_path_of_the_other_file() {
    let badge = "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Badge() { return <b />; }\n";
    let (_, diags) = compile(vec![
        SourceInput { rel: "Badge.phpm".into(), path: "src/Badge.phpm".into(), package: None, text: badge.into() },
        package("vendor/acme/ui/Badge.phpm", "vendor/acme/ui/components/Badge.phpm", badge),
    ]);

    let messages: Vec<String> = diags.iter().flat_map(|f| f.shown().map(move |d| format!("{} {} {}", f.path, d.code, d.message))).collect();
    assert_eq!(messages, ["vendor/acme/ui/components/Badge.phpm E014 component `Acme\\Ui\\Badge` is already declared in `src/Badge.phpm`"]);
}

#[test]
fn a_theme_package_extends_a_base_package() {
    // The base has the props and the handlers, the theme gives render().
    let base = "<?phpm\nnamespace Acme\\Base;\nuse Phem\\Attribute\\{Prop, State};\nabstract class AbstractTabs { #[State] public string $active = ''; #[Prop] public string $label = ''; public function select(): void {} }\n";
    let theme = "<?phpm\nnamespace Acme\\Ui;\nuse Acme\\Base\\AbstractTabs;\nuse Phem\\Attribute\\Component;\n#[Component]\nclass Tabs extends AbstractTabs { public function render() { return <div onClick={{ select }}>{{ label }}</div>; } }\n";
    let home = "<?phpm\nnamespace App;\nuse Acme\\Ui\\Tabs;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfunction Home() { return <Tabs label=\"A\" />; }\nfunction Wrong() { return <Tabs lable=\"A\" />; }\n";
    let base_input = SourceInput { rel: "vendor/acme/base/AbstractTabs.phpm".into(), path: "vendor/acme/base/src/AbstractTabs.phpm".into(), package: Some("acme/base".into()), text: base.into() };
    let theme_input = package("vendor/acme/ui/Tabs.phpm", "vendor/acme/ui/components/Tabs.phpm", theme);
    let (out, diags) = compile(vec![SourceInput::app("Home.phpm", home), base_input, theme_input]);

    // The props of the base package are known: an unknown prop is E011.
    let codes: Vec<String> = diags.iter().flat_map(|f| f.shown().map(move |d| format!("{} {}", f.path, d.code))).collect();
    assert_eq!(codes, ["Home.phpm E011"]);
    assert!(out.is_none());
}

const UI: &str = "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Html;\n#[Component]\nfunction Button(string $label = '', ?Html $children = null, mixed ...$rest) { return <button {{ ...rest }}>{{ label }}{{ children }}</button>; }\n#[Component]\nfunction Dialog(?Html $children = null) { return <dialog><Button label=\"Close\" /></dialog>; }\n";

fn ui() -> SourceInput {
    package("vendor/acme/ui/Ui.phpm", "vendor/acme/ui/components/Ui.phpm", UI)
}

fn app(text: &str) -> SourceInput {
    SourceInput { rel: "Button.phpm".into(), path: "src/Button.phpm".into(), package: None, text: format!("<?phpm\nnamespace App;\nuse Acme\\Ui\\Button as UiButton;\nuse Phem\\Attribute\\Component;\nuse Phem\\Html;\n{text}") }
}

fn shown(diags: &[phemc_codegen::FileDiagnostics]) -> Vec<String> {
    diags.iter().flat_map(|f| f.shown().map(move |d| format!("{} {} {}", f.path, d.code, d.message))).collect()
}

#[test]
fn a_replacement_renders_in_each_tag_of_the_original() {
    let replacement = app("#[Component(replaces: UiButton::class)]\nfunction Button(?Html $children = null, mixed ...$rest) { return <UiButton className=\"rounded\" {{ ...rest }}>{{ children }}</UiButton>; }\nfunction Home() { return <main><UiButton label=\"Go\" /><Button label=\"Me\" /></main>; }\n");
    let (out, diags) = compile(vec![replacement, ui()]);
    let out = out.unwrap_or_else(|| panic!("{:?}", shown(&diags)));
    let twig = |name: &str| out.files.iter().flat_map(|f| &f.templates).find(|t| t.name == name).unwrap().content.clone();

    // In the package: the tag of the original renders the replacement of the app.
    assert!(twig("vendor/acme/ui/Ui.1.twig").contains("{% component 'App\\\\Button' with {'label': 'Close'} only %}"), "{}", twig("vendor/acme/ui/Ui.1.twig"));
    // In the replacement: the tag stays the original, so the replacement wraps it.
    assert!(twig("Button.0.twig").contains("{% component embed 'Acme\\\\Ui\\\\Button' with phem_props("), "{}", twig("Button.0.twig"));
    // In another function of the app: the replacement, also for a tag of the replacement itself.
    assert!(twig("Button.1.twig").contains("{% component 'App\\\\Button' with {'label': 'Go'} only %}{% component 'App\\\\Button' with {'label': 'Me'} only %}"), "{}", twig("Button.1.twig"));

    let manifest = out.manifest.to_json();
    assert!(manifest.contains(r#""replaces": "Acme\\Ui\\Button""#), "{manifest}");
}

#[test]
fn a_replacement_takes_a_string_or_a_class_name() {
    for arg in ["UiButton::class", "\\Acme\\Ui\\Button::class", "'Acme\\Ui\\Button'", "\"\\\\Acme\\\\Ui\\\\Button\"", "replaces: UiButton::class"] {
        let replacement = app(&format!("#[Component({arg})]\nfunction Button(?Html $children = null, mixed ...$rest) {{ return <b />; }}\n"));
        let (out, diags) = compile(vec![replacement, ui()]);
        assert!(out.is_some(), "{arg}: {:?}", shown(&diags));
        assert_eq!(out.unwrap().manifest.components["App\\Button"].replaces.as_deref(), Some("Acme\\Ui\\Button"), "{arg}");
    }
}

#[test]
fn a_replacement_accepts_each_prop_of_the_original() {
    let check = |signature: &str| {
        let (_, diags) = compile(vec![app(&format!("#[Component(replaces: UiButton::class)]\nfunction Button({signature}) {{ return <b />; }}\n")), ui()]);
        shown(&diags)
    };

    assert!(check("string $label = '', ?Html $children = null, mixed ...$rest").is_empty());
    assert_eq!(check("?Html $children = null, mixed ...$rest, ").len(), 0, "a rest prop gives the props to the original");
    assert_eq!(
        check("string $label = ''"),
        [
            "src/Button.phpm E016 `App\\Button` does not accept the slot `children` of `Acme\\Ui\\Button`",
            "src/Button.phpm E016 `Acme\\Ui\\Button` accepts any prop, and `App\\Button` has no rest prop",
        ]
    );
    assert_eq!(
        check("string $size, mixed ...$rest"),
        ["src/Button.phpm E016 `App\\Button` requires the prop `size`, and `Acme\\Ui\\Button` does not"]
    );
}

#[test]
fn a_replacement_must_be_unique_and_in_the_app() {
    let replaces = |text: &str| shown(&compile(vec![app(text), ui()]).1);
    let ok = "#[Component(replaces: UiButton::class)]\nfunction Button(?Html $children = null, mixed ...$rest) { return <b />; }\n";

    assert_eq!(
        replaces(&format!("{ok}#[Component(replaces: UiButton::class)]\nfunction Other(?Html $children = null, mixed ...$rest) {{ return <b />; }}\n")),
        ["src/Button.phpm E017 `App\\Button` already replaces `Acme\\Ui\\Button`"]
    );
    assert_eq!(
        replaces(&format!("{ok}#[Component(replaces: Button::class)]\nfunction Other(?Html $children = null, mixed ...$rest) {{ return <b />; }}\n")),
        ["src/Button.phpm E017 `App\\Button` replaces `Acme\\Ui\\Button`, and a replacement cannot be replaced: replace `Acme\\Ui\\Button`"]
    );
    assert_eq!(
        replaces("#[Component(replaces: Missing::class)]\nfunction Button() { return <b />; }\n"),
        ["src/Button.phpm E010 `App\\Button` replaces an unknown component `App\\Missing`"]
    );
    assert_eq!(
        replaces("#[Component(replaces: \\Phem\\Root::class)]\nfunction Button(?Html $children = null, mixed ...$rest) { return <b />; }\n"),
        ["src/Button.phpm E017 `Phem\\Root` is a built-in component of Phem, and the app cannot replace it"]
    );
    assert_eq!(
        replaces("#[Component(replaces: self::NAME)]\nfunction Button() { return <b />; }\n"),
        ["src/Button.phpm E017 the compiler cannot read the value of `replaces`"]
    );
    for name in ["self", "static", "parent"] {
        assert_eq!(
            replaces(&format!("#[Component(replaces: {name}::class)]\nfinal class Button {{ public function render() {{ return <b />; }} }}\n")),
            ["src/Button.phpm E017 the compiler cannot read the value of `replaces`"],
            "{name}"
        );
    }

    // A package cannot replace a component of another package.
    let theme = "<?phpm\nnamespace Acme\\Theme;\nuse Acme\\Ui\\Button as UiButton;\nuse Phem\\Attribute\\Component;\n#[Component(replaces: UiButton::class)]\nfunction Button(mixed ...$rest) { return <b />; }\n";
    let (out, diags) = compile(vec![ui(), package("vendor/acme/theme/Button.phpm", "vendor/acme/theme/components/Button.phpm", theme)]);
    assert!(out.is_none());
    assert_eq!(shown(&diags), ["vendor/acme/theme/components/Button.phpm E017 a package cannot replace a component, and `Acme\\Theme\\Button` is in a package"]);
}

#[test]
fn a_context_consumer_under_a_replacement_needs_a_provider() {
    // The page renders the Dialog of the package, whose Button is the replacement of the app: the
    // replacement needs a context that no component above provides.
    let replacement = app("use Acme\\Ui\\Dialog;\nuse Phem\\Attribute\\{Context, Page};\n#[Component(replaces: UiButton::class)]\nfunction Button(#[Context] Theme $theme, ?Html $children = null, mixed ...$rest) { return <b />; }\n#[Page('/')]\nfunction Home() { return <main><Dialog /></main>; }\n");
    let diags = compile(vec![replacement, ui()]).1;
    assert_eq!(
        shown(&diags),
        ["vendor/acme/ui/components/Ui.phpm E015 `<Button>` renders `App\\Button`, the replacement of `Acme\\Ui\\Button`, and it needs a `Theme` context, but no component above it provides one"]
    );

    let help = diags.iter().flat_map(|f| f.shown()).find_map(|d| d.help.clone()).unwrap();
    assert_eq!(help, "give `$theme` of `App\\Button` a default value, or render the tag inside a component with `#[Provide]` for `Theme`");
}

#[test]
fn a_class_replacement_keeps_the_original_in_its_render_and_in_its_parent_classes() {
    let wraps = app("use Phem\\Attribute\\Prop;\nuse Phem\\Node;\n#[Component(replaces: UiButton::class)]\nfinal class Button\n{\n    #[Prop(rest: true)] public array $attrs = [];\n    #[Prop] public ?Html $children = null;\n    public function render(): Node { return <UiButton label=\"Own\" />; }\n}\n");
    let inherits = app("use Phem\\Attribute\\Prop;\nuse Phem\\Node;\nabstract class Base\n{\n    public function render(): Node { return <UiButton label=\"Base\" />; }\n}\ntrait Parts\n{\n    public function part(): Node { return <UiButton label=\"Trait\" />; }\n}\n#[Component(replaces: UiButton::class)]\nfinal class Button extends Base\n{\n    use Parts;\n    #[Prop(rest: true)] public array $attrs = [];\n    #[Prop] public ?Html $children = null;\n}\n");

    for (replacement, labels) in [(wraps, &["Own"][..]), (inherits, &["Base", "Trait"][..])] {
        let (out, diags) = compile(vec![replacement, ui()]);
        let out = out.unwrap_or_else(|| panic!("{:?}", shown(&diags)));
        let templates: Vec<&str> = out.files.iter().flat_map(|f| &f.templates).map(|t| t.content.as_str()).collect();
        for label in labels {
            let original = format!("{{% component 'Acme\\\\Ui\\\\Button' with {{'label': '{label}'}} only %}}");
            assert!(templates.iter().any(|t| t.contains(&original)), "{label}: {templates:?}");
        }

        // The Dialog of the package renders the replacement.
        assert!(templates.iter().any(|t| t.contains("{% component 'App\\\\Button' with {'label': 'Close'} only %}")), "{templates:?}");
    }
}

#[test]
fn a_replacement_cannot_replace_itself_or_a_page() {
    let replaces = |text: &str| shown(&compile(vec![app(text), ui()]).1);

    assert_eq!(
        replaces("#[Component(replaces: Button::class)]\nfunction Button() { return <b />; }\n"),
        ["src/Button.phpm E017 `App\\Button` cannot replace itself"]
    );
    assert_eq!(
        replaces("use Phem\\Attribute\\Page;\n#[Page('/shop')]\nfunction Shop() { return <main />; }\n#[Component(replaces: Shop::class)]\nfunction Button() { return <b />; }\n"),
        ["src/Button.phpm E017 a page is not a tag, so `App\\Button` cannot replace `App\\Shop`"]
    );
}

#[test]
fn a_replacement_of_null_is_no_replacement() {
    let (out, diags) = compile(vec![app("#[Component(replaces: null)]\nfunction Button() { return <b />; }\n"), ui()]);
    let out = out.unwrap_or_else(|| panic!("{:?}", shown(&diags)));
    assert_eq!(out.manifest.components["App\\Button"].replaces, None);
}

#[test]
fn each_second_replacement_names_the_first_one() {
    let other = |name: &str| format!("#[Component(replaces: UiButton::class)]\nfunction {name}(?Html $children = null, mixed ...$rest) {{ return <b />; }}\n");
    assert_eq!(
        shown(&compile(vec![app(&format!("{}{}{}", other("Button"), other("Other"), other("Third"))), ui()]).1),
        [
            "src/Button.phpm E017 `App\\Button` already replaces `Acme\\Ui\\Button`",
            "src/Button.phpm E017 `App\\Button` already replaces `Acme\\Ui\\Button`",
        ]
    );
}

#[test]
fn a_replacement_keeps_the_bound_props_of_the_original() {
    let input = package("vendor/acme/ui/Input.phpm", "vendor/acme/ui/components/Input.phpm", "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Bound;\n#[Component]\nfunction Input(Bound $value) { return <input phem:bind={{ value }} />; }\n");
    let check = |signature: &str| shown(&compile(vec![app(&format!("use Acme\\Ui\\Input as UiInput;\nuse Phem\\Bound;\n#[Component(replaces: UiInput::class)]\nfunction Input({signature}) {{ return <b />; }}\n")), input.clone()]).1);

    assert!(check("Bound $value").is_empty());
    assert!(check("mixed ...$rest").is_empty(), "a rest prop gives the Bound to the original");
    assert_eq!(check("string $value"), ["src/Button.phpm E016 `App\\Input` takes the `Bound` prop `value` of `Acme\\Ui\\Input` with another type"]);
}

#[test]
fn a_rejected_replacement_changes_no_tag() {
    // The replacement of a package is E017: the tags keep the original, so its context gives no E015.
    let theme = "<?phpm\nnamespace Acme\\Theme;\nuse Acme\\Ui\\Button as UiButton;\nuse Phem\\Attribute\\{Component, Context};\n#[Component(replaces: UiButton::class)]\nfunction Button(#[Context] Theme $theme, mixed ...$rest) { return <b />; }\n";
    let page = app("use Acme\\Ui\\Dialog;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfunction Home() { return <main><Dialog /></main>; }\n");
    let (_, diags) = compile(vec![page, ui(), package("vendor/acme/theme/Button.phpm", "vendor/acme/theme/components/Button.phpm", theme)]);
    assert_eq!(shown(&diags), ["vendor/acme/theme/components/Button.phpm E017 a package cannot replace a component, and `Acme\\Theme\\Button` is in a package"]);
}

#[test]
fn a_replacement_accepts_each_named_slot_of_the_original() {
    let card = package("vendor/acme/ui/Card.phpm", "vendor/acme/ui/components/Card.phpm", "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Html;\n#[Component]\nfunction Card(?Html $children = null, ?Html $footer = null) { return <div>{{ children }}{{ footer }}</div>; }\n");
    let check = |signature: &str| shown(&compile(vec![app(&format!("use Acme\\Ui\\Card as UiCard;\n#[Component(replaces: UiCard::class)]\nfunction Card({signature}) {{ return <b />; }}\n")), card.clone()]).1);

    assert!(check("?Html $children = null, ?Html $footer = null").is_empty());
    assert_eq!(check("?Html $children = null"), ["src/Button.phpm E016 `App\\Card` does not accept the slot `footer` of `Acme\\Ui\\Card`"]);
}

#[test]
fn an_open_replacement_has_no_prop_check() {
    // The parent class is outside the sources: the compiler does not see all the props.
    let (_, diags) = compile(vec![app("use Phem\\Node;\n#[Component(replaces: UiButton::class)]\nfinal class Button extends \\Vendor\\Base\n{\n    public function render(): Node { return <b />; }\n}\n"), ui()]);
    assert!(!shown(&diags).iter().any(|d| d.contains("E016")), "{:?}", shown(&diags));
}

#[test]
fn a_page_cannot_be_a_replacement() {
    let (_, diags) = compile(vec![app("use Phem\\Attribute\\Page;\n#[Page('/b'), Component(replaces: UiButton::class)]\nfunction Button() { return <main />; }\n"), ui()]);
    assert_eq!(shown(&diags), ["src/Button.phpm E017 a page is not a tag, so `App\\Button` cannot replace `Acme\\Ui\\Button`"]);
}

#[test]
fn a_context_consumer_under_a_class_replacement_needs_a_provider() {
    let replacement = app("use Acme\\Ui\\Dialog;\nuse Phem\\Attribute\\{Context, Page, Prop};\nuse Phem\\Node;\n#[Component(replaces: UiButton::class)]\nfinal class Button\n{\n    #[Context] public Theme $theme;\n    #[Prop(rest: true)] public array $attrs = [];\n    #[Prop] public ?Html $children = null;\n    public function render(): Node { return <b />; }\n}\n#[Page('/')]\nfunction Home() { return <main><Dialog /></main>; }\n");
    assert_eq!(
        shown(&compile(vec![replacement, ui()]).1),
        ["vendor/acme/ui/components/Ui.phpm E015 `<Button>` renders `App\\Button`, the replacement of `Acme\\Ui\\Button`, and it needs a `Theme` context, but no component above it provides one"]
    );
}

const CARD: &str = "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Prop};\nuse Phem\\{Html, Node};\n#[Component]\nclass Card\n{\n    #[Prop] public string $title = '';\n    #[Prop] public ?Html $children = null;\n    public function render(): Node { return <div>{{ title }}<Card title=\"Inner\" /></div>; }\n}\n#[Component]\nfunction Panel() { return <aside><Card title=\"Panel\" /></aside>; }\n";

#[test]
fn a_class_replacement_can_extend_the_original_class() {
    // The replacement takes the props of the original from the parent class: no E016.
    let replacement = app("use Acme\\Ui\\Card as UiCard;\nuse Phem\\Node;\n#[Component(replaces: UiCard::class)]\nfinal class Card extends UiCard\n{\n    public function render(): Node { return <section><UiCard title={{ title }} /></section>; }\n}\n");
    let (out, diags) = compile(vec![replacement, package("vendor/acme/ui/Card.phpm", "vendor/acme/ui/components/Card.phpm", CARD)]);
    let out = out.unwrap_or_else(|| panic!("{:?}", shown(&diags)));
    let twig = |name: &str| out.files.iter().flat_map(|f| &f.templates).find(|t| t.name == name).unwrap().content.clone();

    // In the replacement: the original.
    assert!(twig("Button.0.twig").contains("{% component 'Acme\\\\Ui\\\\Card' with {'title': (title)} only %}"), "{}", twig("Button.0.twig"));
    // In the original and in the other components of the package: the replacement. The keep of
    // the original stops at the original class.
    assert!(twig("vendor/acme/ui/Card.0.twig").contains("{% component 'App\\\\Card' with {'title': 'Inner'} only %}"), "{}", twig("vendor/acme/ui/Card.0.twig"));
    assert!(twig("vendor/acme/ui/Card.1.twig").contains("{% component 'App\\\\Card' with {'title': 'Panel'} only %}"), "{}", twig("vendor/acme/ui/Card.1.twig"));
}

#[test]
fn an_open_original_has_no_prop_check() {
    // The parent class of the original is outside the sources: the compiler does not see all its props.
    let card = "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Prop};\nuse Phem\\Node;\n#[Component]\nfinal class Card extends \\Vendor\\Base\n{\n    #[Prop] public string $title = '';\n    public function render(): Node { return <div />; }\n}\n";
    let replacement = app("use Acme\\Ui\\Card as UiCard;\n#[Component(replaces: UiCard::class)]\nfunction Card() { return <b />; }\n");
    let (_, diags) = compile(vec![replacement, package("vendor/acme/ui/Card.phpm", "vendor/acme/ui/components/Card.phpm", card)]);
    assert!(!shown(&diags).iter().any(|d| d.contains("E016")), "{:?}", shown(&diags));
}

#[test]
fn a_parent_class_of_a_replacement_keeps_the_original_for_the_other_components_too() {
    let replacement = app("use Phem\\Attribute\\Prop;\nuse Phem\\Node;\nabstract class Base\n{\n    #[Prop(rest: true)] public array $attrs = [];\n    #[Prop] public ?Html $children = null;\n    public function render(): Node { return <UiButton label=\"Base\" />; }\n}\n#[Component(replaces: UiButton::class)]\nfinal class Button extends Base {}\n#[Component]\nfinal class Other extends Base {}\nfunction Home() { return <Other />; }\n");
    let (out, diags) = compile(vec![replacement, ui()]);
    let out = out.unwrap_or_else(|| panic!("{:?}", shown(&diags)));
    let templates: Vec<&str> = out.files.iter().flat_map(|f| &f.templates).map(|t| t.content.as_str()).collect();

    // One template for the render of Base: Other renders the original too.
    assert!(templates.iter().any(|t| t.contains("{% component 'Acme\\\\Ui\\\\Button' with {'label': 'Base'} only %}")), "{templates:?}");
    assert!(!templates.iter().any(|t| t.contains("{% component 'App\\\\Button' with {'label': 'Base'} only %}")), "{templates:?}");
}

#[test]
fn a_literal_value_of_a_backed_enum_prop_is_a_case() {
    // The enums are in a package root. A `case` of a `switch` in a method is not a case.
    let enums = "<?phpm\nnamespace Acme\\Ui;\nenum Variant: string\n{\n    case Primary = 'primary';\n    case Outline = \"outline\";\n    public function classes(): string { switch ($this) { case self::Primary: return 'a'; default: return 'b'; } }\n}\nenum Level: int { case Low = 1; case High = 3; }\nenum Computed: string { const P = 'p'; case A = self::P; }\n";
    let ui = "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Prop};\nuse Phem\\Node;\n#[Component]\nfunction Button(Variant $variant = Variant::Primary, ?Level $level = null, Computed $computed = Computed::A, \\Vendor\\Size $size = \\Vendor\\Size::S) { return <b />; }\n#[Component]\nfinal class Badge\n{\n    #[Prop] public Variant|null $variant = null;\n    public function render(): Node { return <i />; }\n}\n";
    let home = |body: &str| SourceInput::app("Home.phpm", format!("<?phpm\nnamespace App;\nuse Acme\\Ui\\{{Badge, Button}};\nfunction Home() {{ $v = 'big'; return <main>{body}</main>; }}\n"));
    let check = |body: &str| {
        let (_, diags) = compile(vec![home(body), package("vendor/acme/ui/Variant.phpm", "vendor/acme/ui/components/Variant.phpm", enums), package("vendor/acme/ui/Button.phpm", "vendor/acme/ui/components/Button.phpm", ui)]);
        diags.iter().flat_map(|f| f.shown().map(move |d| format!("{} {} {}", d.code, d.message, d.help.clone().unwrap_or_default()))).collect::<Vec<_>>()
    };

    assert_eq!(check("<Button variant=\"big\" />"), ["E018 `<Button>` has no variant `big` its values are: primary, outline"]);
    assert_eq!(check("<Button level=\"2\" />"), ["E018 `<Button>` has no level `2` its values are: 1, 3"]);
    assert_eq!(check("<Badge variant=\"big\" />"), ["E018 `<Badge>` has no variant `big` its values are: primary, outline"]);

    // The runtime takes only the text of the int: no leading zero.
    assert_eq!(check("<Button level=\"03\" />"), ["E018 `<Button>` has no level `03` its values are: 1, 3"]);
    // An empty value and a bare attribute (`true`) are not cases either.
    assert_eq!(check("<Button variant=\"\" />"), ["E018 `<Button>` has no variant `` its values are: primary, outline"]);
    assert_eq!(check("<Button variant />"), ["E018 `<Button>` has no variant `true` its values are: primary, outline"]);

    // A case, an expression, a case with no literal value, and an enum outside the sources: no check.
    let ok = "<Button variant=\"outline\" level=\"3\" /><Badge variant=\"primary\" /><Button variant={{ v }} /><Button variant=\"x{{ v }}\" /><Button computed=\"z\" /><Button size=\"z\" />";
    assert!(check(ok).is_empty(), "{:?}", check(ok));
}

#[test]
fn an_inherited_enum_prop_keeps_the_type_of_its_file() {
    // The base declares `Variant` in its namespace. The theme has another `Variant`, with other
    // values, and its class name differs in case: PHP class names ignore the case.
    let base = "<?phpm\nnamespace Acme\\Base;\nuse Phem\\Attribute\\Prop;\nenum Variant: string { case Primary = 'primary'; }\nabstract class AbstractButton { #[Prop] public variant $variant = Variant::Primary; }\n";
    let theme = "<?phpm\nnamespace Acme\\Ui;\nuse Acme\\Base\\AbstractButton;\nuse Phem\\Attribute\\Component;\nuse Phem\\Node;\nenum Variant: string { case Solid = 'solid'; }\n#[Component]\nclass Button extends AbstractButton { public function render(): Node { return <button />; } }\n";
    let home = |body: &str| SourceInput::app("Home.phpm", format!("<?phpm\nnamespace App;\nuse Acme\\Ui\\Button;\nfunction Home() {{ return {body}; }}\n"));
    let check = |body: &str| {
        let base = SourceInput { rel: "vendor/acme/base/Button.phpm".into(), path: "vendor/acme/base/src/Button.phpm".into(), package: Some("acme/base".into()), text: base.into() };
        let (_, diags) = compile(vec![home(body), base, package("vendor/acme/ui/Button.phpm", "vendor/acme/ui/components/Button.phpm", theme)]);
        shown(&diags)
    };

    assert!(check("<Button variant=\"primary\" />").is_empty(), "{:?}", check("<Button variant=\"primary\" />"));
    assert_eq!(check("<Button variant=\"solid\" />"), ["Home.phpm E018 `<Button>` has no variant `solid`"]);
}

#[test]
fn an_enum_that_two_files_declare_is_an_error() {
    let size = "<?phpm\nnamespace Acme\\Ui;\nenum Size: string { case S = 's'; }\n";
    let (out, diags) = compile(vec![
        SourceInput { rel: "Size.phpm".into(), path: "src/Size.phpm".into(), package: None, text: size.into() },
        package("vendor/acme/ui/Size.phpm", "vendor/acme/ui/components/Size.phpm", size),
    ]);

    assert!(out.is_none());
    assert_eq!(shown(&diags), ["vendor/acme/ui/components/Size.phpm E014 enum `Acme\\Ui\\Size` is already declared in `src/Size.phpm`"]);
}
