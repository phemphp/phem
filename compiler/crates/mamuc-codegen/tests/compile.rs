//! Snapshot tests of the generated PHP and Twig, and one test for each diagnostic.

use mamuc_codegen::{compile, SourceInput};
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
    let src = "<?phpm\nfunction Box(?Mamu\\Html $children = null) { return <b>{{ children }}</b>; }\nfunction App() { return <p><Box>{% set z = 1 %}</Box>{{ z }}</p>; }\n";
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

    let src = "<?phpm\nnamespace App;\nuse Mamu\\Attribute\\Component;\n#[Component]\nfinal class Btn {\n    public string $title = 'a';\n    public bool $off = false;\n    public function render() { return <b>{% if off %}{% set title = 'b' %}{% endif %}{{ title }}</b>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "Btn.phpm".into(), text: src.into() }]);
    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'title' => $this->title"), "{php}");
}

#[test]
fn a_class_component_reads_the_private_properties_of_its_traits() {
    let src = "<?phpm\nnamespace App;\nuse Mamu\\Attribute\\Component;\ntrait Paginates { private int $page = 1; }\n#[Component]\nfinal class Results {\n    use Paginates;\n    public function render() { return <p>{{ page }}</p>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput { rel: "Results.phpm".into(), text: src.into() }]);
    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'page' => $this->page"), "{php}");
}

#[test]
fn a_prop_whose_type_accepts_null_is_optional() {
    let src = "<?phpm\nfunction Box($a, mixed $b, int|null $c) { return <b>{{ a }}{{ b }}{{ c }}</b>; }\nfunction App() { return <Box />; }\n";
    assert_eq!(codes(src), Vec::<&str>::new());
    let src = "<?phpm\nfunction Box(int|string $a) { return <b>{{ a }}</b>; }\nfunction App() { return <Box />; }\n";
    assert_eq!(codes(src), ["E011"]);
}

#[test]
fn markup_reads_the_twig_globals() {
    let src = "<?phpm\nfunction App() { return <p>{{ app.user.name }}</p>; }\n";
    let (out, diags) = compile(vec![SourceInput { rel: "App.phpm".into(), text: src.into() }]);
    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    assert!(out.expect("output").files[0].php.contains("new \\Mamu\\Element('App.0.twig', [])"));
}

#[test]
fn a_class_component_gets_the_members_of_its_parent_and_traits() {
    let base = "<?phpm\nnamespace App;\nabstract class Base {\n    public string $title = '';\n    private int $secret = 1;\n    public function save(): void {}\n    public function render() { return <b>{{ title }}</b>; }\n}\ntrait Named { public string $name = ''; }\n";
    let card = "<?phpm\nnamespace App;\nuse Mamu\\Attribute\\Component;\n#[Component]\nfinal class Card extends Base {\n    use Named;\n    public function view() { return <i onClick={{ save }}>{{ title }} {{ name }}</i>; }\n}\nfunction Page() { return <Card title=\"a\" name=\"b\" />; }\n";
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
fn a_class_with_a_parent_outside_the_sources_is_not_checked() {
    let src = "<?phpm\nnamespace App;\nuse Mamu\\Attribute\\Component;\n#[Component]\nfinal class Box extends \\Vendor\\Base {\n    public function view() { return <b>{{ title }}</b>; }\n}\nfunction Page() { return <Box title=\"a\"><p>kid</p><Box:footer>f</Box:footer></Box>; }\n";
    let (out, diags) = compile(vec![SourceInput { rel: "Box.phpm".into(), text: src.into() }]);
    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'title' => $this->title ?? (method_exists($this, 'title') ? $this->title(...) : null)"), "{php}");
}
