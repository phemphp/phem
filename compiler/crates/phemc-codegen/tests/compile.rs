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
        } else if p.extension().is_some_and(|e| e == "phem") {
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
fn accepts_validated_with_a_validation_property() {
    // The property is in the class, or in a parent class.
    let src = "<?phem\nuse Phem\\Attribute\\{Component, State, Validated};\nuse Phem\\Node;\nuse Phem\\Validation;\n\
        abstract class Form { #[State] public Validation $validation; }\n\
        #[Component] final class Own { #[State] public Validation $validation;\n#[Validated] public function save(): void {}\npublic function render(): Node { return <form onSubmit={{ save }} />; } }\n\
        #[Component] final class Child extends Form {\n#[Validated(groups: ['draft'])] public function save(): void {}\npublic function render(): Node { return <form onSubmit={{ save }} />; } }\n";
    let (out, diags) = compile(vec![SourceInput::app("Forms.phem", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "unexpected diagnostics: {msgs:#?}");
    assert!(out.is_some());
}

#[test]
fn passes_the_variables_of_extension_tags() {
    let src = "<?phem\nfunction App() { $user = []; $locale = 'fr';\n    return <p>{% trans with {'%name%': user.name} into locale %}Hi %name%{% endtrans %}</p>;\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("['user' => $user, 'locale' => $locale]"), "{php}");

    // A guessed name is still an error when an expression uses it.
    let src = "<?phem\nfunction App() { return <p>{% trans into into %}x{% endtrans %}{{ into }}</p>; }\n";
    let (_, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    let codes: Vec<&str> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect();
    assert_eq!(codes, ["E030"]);
}

#[test]
fn a_quoted_url_with_an_expression_goes_through_phem_attr() {
    // phem_attr() drops a `javascript:` URL: the URL can come from data.
    let src = "<?phem\nfunction App() { $url = ''; $id = 1;\n    return <form action=\"{{ url }}\"><a href=\"{{ url }}\" HREF=\"/items/{{ id }}\" title=\"{{ url }}\">x</a><img src=\"/a.png\" /><button formAction=\" {{ url }}\">y</button></form>;\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let twig = &out.expect("output").files[0].templates[0].content;
    for expected in ["{{ phem_attr('action', '' ~ (url)) }}", "{{ phem_attr('href', '' ~ (url)) }}", "{{ phem_attr('HREF', '/items/' ~ (id)) }}", "{{ phem_attr('formAction', ' ' ~ (url)) }}"] {
        assert!(twig.contains(expected), "{expected} in {twig}");
    }

    assert!(twig.contains(r#"title="{{ url }}""#), "another attribute keeps its Twig: {twig}");
    assert!(twig.contains(r#"src="/a.png""#), "a static URL keeps its text: {twig}");
}

/// The variables that the markup of `body` (a function body) gives to its template.
fn template_vars(body: &str) -> String {
    let src = format!("<?phem\nfunction App(array $items, #[\\SensitiveParameter] ?string $q = null) {{\n{body}\n}}\n");
    let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "unexpected diagnostics: {msgs:#?}");
    let php = out.expect("output").files[0].php.clone();
    let start = php.rfind("App.0.twig', [").expect("a template call") + "App.0.twig', [".len();

    php[start..start + php[start..].find("])").expect("the end of the call")].to_string()
}

#[test]
fn a_defined_variable_goes_to_the_template_without_a_fallback() {
    // Parameters, and assignments that start a statement before the markup.
    assert_eq!(template_vars("$n = count($items);\n    $n += 1;\n    return <p>{{ items|length }} {{ q }} {{ n }}</p>;"), "'items' => $items, 'q' => $q, 'n' => $n");

    // Each branch of an `if` chain with an `else`.
    assert_eq!(template_vars("if ($q) { $a = 1; } elseif ($items) { $a = 2; } else { $a = 3; }\n    return <p>{{ a }}</p>;"), "'a' => $a");

    // Loop variables in the loop, the parameters of an arrow function, a closure with `use`.
    assert_eq!(template_vars("foreach ($items as $k => $item) { $rows[] = <li>{{ k }} {{ item }}</li>; }\n    return null;"), "'k' => $k, 'item' => $item");
    assert_eq!(template_vars("for ($i = 0; $i < 3; ++$i) { $rows[] = <li>{{ i }}</li>; }\n    return null;"), "'i' => $i");
    assert_eq!(template_vars("$x = 1;\n    return array_map(fn ($p) => <li>{{ p }} {{ x }}</li>, $items);"), "'p' => $p, 'x' => $x");
    assert_eq!(template_vars("$x = 1;\n    return array_map(function ($p) use ($x) { return <li>{{ p }} {{ x }}</li>; }, $items);"), "'p' => $p, 'x' => $x");

    // Destructuring, a `try` with its `catch`, and a branch that ends with `return`.
    assert_eq!(template_vars("[$a, 'k' => $b] = $items;\n    list($c) = $items;\n    return <p>{{ a }} {{ b }} {{ c }}</p>;"), "'a' => $a, 'b' => $b, 'c' => $c");
    assert_eq!(template_vars("try { $a = f(); } catch (\\Exception $e) { $a = null; } finally { echo 1; }\n    return <p>{{ a }}</p>;"), "'a' => $a");
    assert_eq!(template_vars("if ($q) { return null; } else { $a = 1; }\n    return <p>{{ a }}</p>;"), "'a' => $a");

    // A closure with a DNF return type, and a method named `fn`.
    assert_eq!(template_vars("return function ($p): (\\A&\\B)|null { return <li>{{ p }}</li>; };"), "'p' => $p");
    assert_eq!(template_vars("$x = 1;\n    $this->fn($q);\n    return <p>{{ x }}</p>;"), "'x' => $x");
}

#[test]
fn a_variable_that_can_be_undefined_keeps_the_fallback() {
    // An `if` with no `else`, an assignment in an expression, and an assignment after the markup.
    assert_eq!(template_vars("if ($q) { $a = 1; }\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");
    assert_eq!(template_vars("if ($a = $q) {}\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");
    assert_eq!(template_vars("$p = <p>{{ a }}</p>;\n    $a = 1;\n    return $p;"), "'a' => $a ?? null");

    // The loop variable after the loop, a closure with no `use`, a `switch` case, and `unset()`.
    assert_eq!(template_vars("foreach ($items as $item) {}\n    return <p>{{ item }}</p>;"), "'item' => $item ?? null");
    assert_eq!(template_vars("$x = 1;\n    return array_map(function ($p) { return <li>{{ x }}</li>; }, $items);"), "'x' => $x ?? null");
    assert_eq!(template_vars("switch ($q) { case 'a': $a = 1; break; default: return <p>{{ a }}</p>; }\n    return null;"), "'a' => $a ?? null");
    assert_eq!(template_vars("$a = 1;\n    unset($a);\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");

    // `else if` (two words) and an `else` with no braces end the check of the chain.
    assert_eq!(template_vars("if ($q) { $a = 1; } else if ($items) { $a = 2; } else { $a = 3; }\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");
    assert_eq!(template_vars("if ($q) { $a = 1; } else $a = 2;\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");

    // The markup is the value of the assignment: the variable is set after it.
    assert_eq!(template_vars("$a = <p>{{ a }}</p>;\n    return $a;"), "'a' => $a ?? null");
    assert_eq!(template_vars("if ($q) { $a = 1; } else { $a = <p>{{ a }}</p>; }\n    return $a;"), "'a' => $a ?? null");

    // An alternative syntax block, an `if` chain in a `switch` case, and a `goto` label.
    assert_eq!(template_vars("if ($q): echo 1; $a = 1; endif;\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");
    assert_eq!(template_vars("switch ($q) { case 'a': echo 1; if ($q) { $a = 1; } else { $a = 2; } break; default: return <p>{{ a }}</p>; }\n    return null;"), "'a' => $a ?? null");
    assert_eq!(template_vars("goto end;\n    $a = 1;\n    end:\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");

    // A `try` with no `catch` that defines the name, and all the branches return.
    assert_eq!(template_vars("try { $a = f(); } catch (\\Exception $e) { echo 1; }\n    return <p>{{ a }}</p>;"), "'a' => $a ?? null");

    // A closure with a DNF return type does not see the variables of the function.
    assert_eq!(template_vars("$x = 1;\n    return function (): (\\A&\\B)|null { return <li>{{ x }}</li>; };"), "'x' => $x ?? null");

    // An unbalanced bracket does not panic.
    template_vars("$a = [1, 2;\n    }\n    return <p>{{ a }}</p>;");
}

#[test]
fn an_invalid_phem_error_on_a_component_gives_no_missing_prop_error() {
    let src = "<?phem\nuse Phem\\Attribute\\{Component, State};\nuse Phem\\Html;\nuse Phem\\Node;\nuse Phem\\Validation;\n\
        #[Component] function FieldError(string $id, ?Html $children = null): Node { return <p id={{ id }}>{{ children }}</p>; }\n\
        #[Component] final class F { #[State] public string $q = '';\n#[State] public Validation $validation;\npublic function render(): Node { return <FieldError phem:error={{ q ~ 'x' }} />; } }\n";

    assert_eq!(codes(src), ["E061"]);
}

fn codes(src: &str) -> Vec<&'static str> {
    let (_, diags) = compile(vec![SourceInput::app("App.phem", src)]);
    diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect()
}

#[test]
fn a_set_in_an_if_branch_or_an_element_binds_after_it() {
    let src = "<?phem\nfunction App() { $ok = true;\n    return <p>{% if ok %}{% set label = 'a' %}{% else %}{% set label = 'b' %}{% endif %}<b>{% set n = 1 %}</b>{{ label }} {{ n }}</p>;\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("['ok' => $ok]"), "{php}");

    // `for` has its own scope, and so do the children of a component (its slots).
    assert_eq!(codes("<?phem\nfunction App() { $xs = [];\n    return <p>{% for x in xs %}{% set y = x %}{% endfor %}{{ y }}</p>;\n}\n"), ["E030"]);
    let src = "<?phem\n#[\\Phem\\Attribute\\Component]\nfunction Box(?Phem\\Html $children = null) { return <b>{{ children }}</b>; }\nfunction App() { return <p><Box>{% set z = 1 %}</Box>{{ z }}</p>; }\n";
    assert_eq!(codes(src), ["E030"]);
}

#[test]
fn a_set_in_only_some_branches_keeps_the_php_variable() {
    // Without `else`, or with a set in one branch only, the PHP value shows when no set runs.
    for tags in ["{% if off %}{% set variant = 'b' %}{% endif %}", "{% if off %}{% set variant = 'b' %}{% else %}x{% endif %}"] {
        let src = format!("<?phem\nfunction App() {{ $variant = 'a'; $off = true;\n    return <p>{tags}{{{{ variant }}}}</p>;\n}}\n");
        let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
        let php = &out.expect("output").files[0].php;
        assert!(php.contains("'variant' => $variant"), "{php}");
    }

    let src = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Btn {\n    public string $title = 'a';\n    public bool $off = false;\n    public function render() { return <b>{% if off %}{% set title = 'b' %}{% endif %}{{ title }}</b>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Btn.phem", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'title' => $this->title"), "{php}");
}

#[test]
fn a_class_component_reads_the_private_properties_of_its_traits() {
    let src = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\ntrait Paginates { private int $page = 1; }\n#[Component]\nfinal class Results {\n    use Paginates;\n    public function render() { return <p>{{ page }}</p>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Results.phem", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains("'page' => $this->page"), "{php}");
}

#[test]
fn a_prop_whose_type_accepts_null_is_optional() {
    let src = "<?phem\n#[\\Phem\\Attribute\\Component]\nfunction Box($a, mixed $b, int|null $c) { return <b>{{ a }}{{ b }}{{ c }}</b>; }\nfunction App() { return <Box />; }\n";
    assert_eq!(codes(src), Vec::<&str>::new());
    let src = "<?phem\n#[\\Phem\\Attribute\\Component]\nfunction Box(int|string $a) { return <b>{{ a }}</b>; }\nfunction App() { return <Box />; }\n";
    assert_eq!(codes(src), ["E011"]);
}

#[test]
fn markup_reads_the_twig_globals() {
    let src = "<?phem\nfunction App() { return <p>{{ app.user.name }}</p>; }\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    assert!(out.expect("output").files[0].php.contains("new \\Phem\\Element('App.0.twig', [])"));
}

#[test]
fn a_class_component_gets_the_members_of_its_parent_and_traits() {
    let base = "<?phem\nnamespace App;\nabstract class Base {\n    #[\\Phem\\Attribute\\Prop] public string $title = '';\n    private int $secret = 1;\n    public function save(): void {}\n    public function render() { return <b>{{ title }}</b>; }\n}\ntrait Named { #[\\Phem\\Attribute\\Prop] public string $name = ''; }\n";
    let card = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Card extends Base {\n    use Named;\n    public function view() { return <i onClick={{ save }}>{{ title }} {{ name }}</i>; }\n}\nfunction Page() { return <Card title=\"a\" name=\"b\" />; }\n";
    let inputs = vec![SourceInput::app("Base.phem", base), SourceInput::app("Card.phem", card)];
    let (out, diags) = compile(inputs.clone());

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert_eq!(out.manifest.components["App\\Card"].props, ["name", "title"], "the trait, then the parent class");
    let php = &out.files.iter().find(|f| f.rel == "Card.phem").unwrap().php;
    assert!(php.contains("'save' => $this->save(...), 'title' => $this->title, 'name' => $this->name"), "{php}");

    // A private property of the parent is not visible.
    let card = card.replace("{{ name }}", "{{ secret }}");
    let (_, diags) = compile(vec![inputs[0].clone(), SourceInput::app("Card.phem", card)]);

    let codes: Vec<&str> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect();
    assert_eq!(codes, ["E030"]);
}

#[test]
fn a_class_component_does_not_see_the_private_members_of_its_parent() {
    let base = "<?phem\nnamespace App;\nabstract class Base {\n    public function __construct(private int $secret = 1) {}\n    private function hidden(): void {}\n}\ntrait Tools { private function tool(): void {} }\n";
    let codes = |body: &str| -> Vec<&'static str> {
        let card = format!("<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Card extends Base {{\n    use Tools;\n    public function render() {{ return <i>{body}</i>; }}\n}}\n");
        let (_, diags) = compile(vec![SourceInput::app("Base.phem", base), SourceInput::app("Card.phem", card)]);
        diags.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect()
    };

    assert_eq!(codes("{{ secret }}"), ["E030"], "a private promoted property");
    assert_eq!(codes("<b onClick={{ hidden }}>x</b>"), ["E030"], "a private method");
    assert_eq!(codes("<b onClick={{ tool }}>x</b>"), Vec::<&str>::new(), "a private method of a trait");
}

#[test]
fn a_class_with_a_parent_outside_the_sources_is_not_checked() {
    let src = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Box extends \\Vendor\\Base {\n    public function view() { return <b>{{ title }}</b>; }\n}\nfunction Page() { return <Box title=\"a\"><p>kid</p><Box:footer>f</Box:footer></Box>; }\n";
    let (out, diags) = compile(vec![SourceInput::app("Box.phem", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(php.contains(r"'title' => \Phem\Rendering\Member::read($this, 'title', self::class)"), "{php}");
}

#[test]
fn a_parent_class_loads_before_its_subclasses() {
    let about = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class About extends Base {\n    public function render() { return <p>{{ name }}</p>; }\n}\n";
    let base = "<?phem\nnamespace App;\nabstract class Base {\n    public string $name = 'App';\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("About.phem", about), SourceInput::app("Page/Base.phem", base)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    assert_eq!(out.expect("output").manifest.files, ["php/Page/Base.php", "php/About.php"]);
}

#[test]
fn root_is_a_built_in_component() {
    let src = |imports: &str| {
        format!("<?phem\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Document(?Html $title = null, ?Html $children = null) {{\n    return <html><head><title>{{{{ title ?? 'App' }}}}</title></head><body><Root tag=\"main\" className=\"app\" data-controller=\"extra\">{{{{ children }}}}</Root></body></html>;\n}}\n")
    };

    let (out, diags) = compile(vec![SourceInput::app("Document.phem", src("use Phem\\{Html, Root};"))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert_eq!(
        out.files[0].templates[0].content,
        "<html><head><title>{% block title %}{{ title ?? 'App' }}{% endblock %}</title></head><body>{% component embed 'Phem\\\\Root' with {'tag': 'main', 'className': 'app', 'data-controller': 'extra'} only %}{% block children %}{% with _phem_caller only %}{{ children }}{% endwith %}{% endblock %}{% endcomponent %}</body></html>"
    );
    assert!(!out.manifest.components.contains_key("Phem\\Root"), "the runtime knows the built-in components");

    let (_, diags) = compile(vec![SourceInput::app("Document.phem", src("use Phem\\Html;"))]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Root;`"));

    let duplicate = "<?phem\nnamespace Phem;\n#[\\Phem\\Attribute\\Component]\nfunction Root() { return <p />; }\n";
    let (_, diags) = compile(vec![SourceInput::app("Root.phem", duplicate)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert_eq!(msgs, ["E014 component `Phem\\Root` is a built-in component of Phem"]);
}

#[test]
fn dynamic_is_an_element_with_a_tag_from_a_value() {
    let src = |imports: &str, body: &str| format!("<?phem\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Button(string $tag = 'button', ?Html $children = null, mixed ...$rest) {{\n    return {body};\n}}\n");
    let body = "<Dynamic tag={{ tag }} type=\"button\" {{ ...rest }} onClick={{ rest.onPress }}>{{ children }}</Dynamic>";
    let (out, diags) = compile(vec![SourceInput::app("Button.phem", src("use Phem\\{Dynamic, Html};", body))]);

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
    let (out, _) = compile(vec![SourceInput::app("Button.phem", src("use Phem\\{Dynamic, Html};", "<Dynamic tag={{ tag }} />"))]);
    assert_eq!(out.expect("output").files[0].templates[0].content, "{% set _phem_tag_0 = phem_tag((tag)) %}<{{ _phem_tag_0 }}>{{ phem_end_tag(_phem_tag_0) }}");

    let (_, diags) = compile(vec![SourceInput::app("Button.phem", src("use Phem\\Html;", body))]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Dynamic;`"));

    let (_, diags) = compile(vec![SourceInput::app("Button.phem", src("use Phem\\{Dynamic, Html};", "<Dynamic tag=\"Style\" />"))]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E032").expect("E032 for a <style>");
    assert_eq!(d.message, "`<Dynamic>` cannot render a `<style>`");
    assert_eq!(d.help.as_deref(), Some("write the `<style>` as static markup"));

    let error = |body: &str| {
        let (_, diags) = compile(vec![SourceInput::app("Button.phem", src("use Phem\\{Dynamic, Html};", body))]);
        diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {} ({})", d.code, d.message, d.help.as_deref().unwrap_or("")))).collect::<Vec<_>>()
    };
    assert_eq!(error("<Dynamic tag=\"iframe\" />"), ["E032 `<Dynamic>` cannot render a `<iframe>` (write the `<iframe>` as static markup)"], "an iframe runs its `srcdoc`");
    assert_eq!(error("<Dynamic tag=\"phem-c\" />"), ["E032 `<phem-c>` is an element of Phem (give a name that does not start with `phem-`)"]);
    assert_eq!(error("<Dynamic tag=\"a\" tag={{ tag }} />"), ["E032 `<Dynamic>` has two `tag` attributes (keep one `tag`)"]);
}

#[test]
fn a_set_in_a_dynamic_element_binds_in_its_parent() {
    let src = "<?phem\nnamespace App;\nuse Phem\\Dynamic;\n#[\\Phem\\Attribute\\Component]\nfunction Card() {\n    return <div><Dynamic tag=\"p\">{% set label = 'x' %}</Dynamic>{{ label }}</div>;\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Card.phem", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let php = &out.expect("output").files[0].php;
    assert!(!php.contains("label"), "the children of <Dynamic> are in the scope of the parent: {php}");
}

#[test]
fn a_context_consumer_in_a_dynamic_element_is_placed() {
    let src = TABS.replace("use Phem\\Html;", "use Phem\\{Dynamic, Html};")
        + "#[Page('/')]\nfinal class Home { public function render() { return <Dynamic tag=\"main\"><Tab name=\"a\" /></Dynamic>; } }\n";
    assert_eq!(project(&[("App.phem", &src)]), ["App.phem E015"], "`<Dynamic>` is not a component: the page is the parent of the tag");

    let src = src.replace("<Dynamic tag=\"main\"><Tab name=\"a\" /></Dynamic>", "<Tabs><Dynamic tag=\"main\"><Tab name=\"a\" /></Dynamic></Tabs>");
    assert!(project(&[("App.phem", &src)]).is_empty());
}

#[test]
fn suspense_is_a_built_in_component() {
    let src = |imports: &str, body: &str| format!("<?phem\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Report() {{ return <p />; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{\n    return {body};\n}}\n");
    let body = "<Suspense lazy><Suspense:fallback>Loading</Suspense:fallback><Suspense:error>Error</Suspense:error><Report /></Suspense>";
    let (out, diags) = compile(vec![SourceInput::app("Home.phem", src("use Phem\\Suspense;", body))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert!(out.files[0].templates.iter().any(|t| t.content.contains("{% component embed 'Phem\\\\Suspense' with {'lazy': true} only %}")), "{:?}", out.files[0].templates);
    assert!(!out.manifest.components.contains_key("Phem\\Suspense"), "the runtime knows the built-in components");

    let wrong = "<Suspense><Suspense:loading>Loading</Suspense:loading><Report /></Suspense>";
    assert_eq!(codes(&src("use Phem\\Suspense;", wrong)), ["E013"]);

    let (_, diags) = compile(vec![SourceInput::app("Home.phem", src("", body))]);
    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\Suspense;`"));
}

#[test]
fn error_boundary_is_a_built_in_component() {
    let src = |imports: &str, body: &str| format!("<?phem\nnamespace App;\n{imports}\n#[\\Phem\\Attribute\\Component]\nfunction Quote() {{ return <p />; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{\n    return {body};\n}}\n");
    let body = "<ErrorBoundary><ErrorBoundary:fallback>No quote</ErrorBoundary:fallback><Quote /></ErrorBoundary>";
    let (out, diags) = compile(vec![SourceInput::app("Home.phem", src("use Phem\\ErrorBoundary;", body))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    assert!(out.files[0].templates.iter().any(|t| t.content.contains("{% component embed 'Phem\\\\ErrorBoundary' only %}")), "{:?}", out.files[0].templates);
    assert!(!out.manifest.components.contains_key("Phem\\ErrorBoundary"), "the runtime knows the built-in components");

    let (_, diags) = compile(vec![SourceInput::app("Home.phem", src("use Phem\\ErrorBoundary;", "<ErrorBoundary><Quote /></ErrorBoundary>"))]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| d.message.clone())).collect();
    assert_eq!(msgs, ["`<ErrorBoundary>` needs the slot `fallback`"]);

    let wrong = "<ErrorBoundary><ErrorBoundary:fallback>No</ErrorBoundary:fallback><ErrorBoundary:error>No</ErrorBoundary:error><Quote /></ErrorBoundary>";
    assert_eq!(codes(&src("use Phem\\ErrorBoundary;", wrong)), ["E013"]);

    let (_, diags) = compile(vec![SourceInput::app("Home.phem", src("", body))]);
    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without the import");
    assert_eq!(d.help.as_deref(), Some("import it: `use Phem\\ErrorBoundary;`"));
}

#[test]
fn a_page_gives_the_title_to_its_document() {
    let document = "<?phem\nnamespace App;\nuse Phem\\{Html, Root};\n#[\\Phem\\Attribute\\Component]\nfunction Document(?Html $title = null, ?Html $children = null) {\n    return <html><head><title>{{ title ?? 'App' }}</title></head><body><Root>{{ children }}</Root></body></html>;\n}\n";
    let page = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfinal class Home {\n    public string $name = 'Ada';\n    public function render() { return <Document><Document:title>Hi {{ name }}</Document:title><p>Home</p></Document>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Document.phem", document), SourceInput::app("Home.phem", page)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let out = out.expect("output");
    let home = out.files.iter().find(|f| f.rel == "Home.phem").expect("Home.phem");
    assert_eq!(
        home.templates[0].content,
        "{% component embed 'App\\\\Document' only %}{% block children %}{% with _phem_caller only %}<p>Home</p>{% endwith %}{% endblock %}{% block title %}{% with _phem_caller only %}Hi {{ name }}{% endwith %}{% endblock %}{% endcomponent %}"
    );
    assert_eq!(out.manifest.components["App\\Document"].slots, ["title", "children"]);
}

#[test]
fn a_required_slot_that_the_caller_does_not_give_is_an_error() {
    let src = |body: &str| format!("<?phem\nnamespace App;\nuse Phem\\Html;\n#[\\Phem\\Attribute\\Component]\nfunction Card(Html $title, Html $children) {{ return <div>{{{{ title }}}}{{{{ children }}}}</div>; }}\n#[\\Phem\\Attribute\\Component]\nfunction Home() {{ return {body}; }}\n");
    let diags = |body: &str| -> Vec<(String, Option<String>)> {
        let (_, diags) = compile(vec![SourceInput::app("Home.phem", src(body))]);
        diags.iter().flat_map(|f| f.diags.iter().map(|d| (format!("{} {}", d.code, d.message), d.help.clone()))).collect()
    };

    assert_eq!(diags("<Card><p>Body</p></Card>"), [("E011 `<Card>` needs the slot `title`".into(), Some("add `<Card:title>...</Card:title>` in `<Card>`".into()))]);
    assert_eq!(diags("<Card><Card:title>Hi</Card:title></Card>"), [("E011 `<Card>` needs the slot `children`".into(), Some("put the content between `<Card>` and `</Card>`".into()))]);
    assert!(diags("<Card><Card:title>Hi</Card:title><p>Body</p></Card>").is_empty());
}

#[test]
fn a_title_outside_head_and_svg_is_a_warning() {
    let w005 = |markup: &str| codes(&format!("<?phem\nfunction App() {{ $on = true; return {markup}; }}\n")).iter().filter(|c| **c == "W005").count();

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
    let file = |rel: &str, text: &str| SourceInput::app(rel, format!("<?phem\nnamespace App;\n{text}\n"));
    let inputs = vec![
        file("a/C.phem", "final class C extends B {}"),
        file("b/B.phem", "abstract class B extends A { use T; }"),
        file("c/T.phem", "trait T {}"),
        file("d/A.phem", "abstract class A extends \\Exception {}"),
        file("e/Free.phem", "final class Free extends \\Exception {}"),
    ];

    let (out, diags) = compile(inputs);
    assert!(diags.iter().all(|f| f.diags.is_empty()), "unexpected diagnostics");
    let files = out.expect("output").manifest.files;
    assert_eq!(files, ["php/c/T.php", "php/d/A.php", "php/b/B.php", "php/a/C.php", "php/e/Free.php"], "a parent outside the sources changes nothing");
}

#[test]
fn a_page_class_is_a_component_with_a_route() {
    let page = |attrs: &str, class: &str| {
        let src = format!("<?phem\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page, Prop, Broadcast, BroadcastKey}};\nuse Symfony\\Component\\Routing\\Attribute\\Route;\n{attrs}\n{class}\n");
        compile(vec![SourceInput::app("Home.phem", src)])
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
    assert_eq!(codes("#[Component]\n#[Broadcast]", "final class Home { #[BroadcastKey] private int $customer; public function mount(): void { $this->customer = 1; } public function render() { return <p />; } }"), Vec::<&str>::new(), "a key on a property that mount() sets");
    assert_eq!(codes("#[Component]\n#[Broadcast]", "final class Home { #[\\Phem\\Attribute\\State, BroadcastKey] public int $id = 0; public function render() { return <p />; } }"), Vec::<&str>::new(), "a key on a #[State]");
    assert_eq!(codes("#[Component]\n#[Broadcast]", "final class Home { #[\\Phem\\Attribute\\State] public bool $open = false; #[BroadcastKey] private int $customer; public function render() { return <p />; } }"), ["E057"], "with #[State], mount() runs only at the first render");
    // A parent with the handler or the key: the analysis does not report the parent or the child.
    let base = "abstract class Base { #[Prop, BroadcastKey] public int $id; #[Broadcast] public function like(): void {} }";
    assert_eq!(codes("", &format!("{base}\n#[Component]\nfinal class Home extends Base {{ #[Prop, BroadcastKey] public int $other; public function render() {{ return <p />; }} }}")), Vec::<&str>::new(), "an inherited handler with #[Broadcast]");
}

#[test]
fn a_function_with_page_is_a_page() {
    let page = |attrs: &str, function: &str| {
        let src = format!("<?phem\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page}};\n{attrs}\n{function}\n");
        compile(vec![SourceInput::app("Home.phem", src)])
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
fn a_function_with_error_page_is_an_error_page() {
    let page = |attrs: &str, decl: &str| {
        let src = format!("<?phem\nnamespace App;\nuse Phem\\Attribute\\{{Component, ErrorPage}};\n{attrs}\n{decl}\n");
        compile(vec![SourceInput::app("NotFound.phem", src)])
    };
    let codes = |attrs: &str, decl: &str| page(attrs, decl).1.iter().flat_map(|f| f.diags.iter().map(|d| d.code)).collect::<Vec<_>>();
    let function = "function NotFound(int $status, \\Symfony\\Component\\HttpFoundation\\Request $request) { return <p>{{ status }}</p>; }";

    for attrs in ["#[ErrorPage]", "#[ErrorPage(404)]", "#[ErrorPage(404)]\n#[ErrorPage(path: '/admin')]", "#[\\Phem\\Attribute\\ErrorPage([401, 403])]"] {
        let (out, diags) = page(attrs, function);

        assert!(diags.iter().all(|f| f.diags.is_empty()), "{attrs}: {:?}", diags[0].diags);
        let out = out.expect("output");
        assert_eq!(out.manifest.error_pages, ["App\\NotFound"], "{attrs}");
        assert!(out.manifest.pages.is_empty(), "{attrs}: an error page has no route");
        assert!(out.manifest.components["App\\NotFound"].props.is_empty(), "{attrs}: its parameters are the arguments of a controller");
    }

    assert_eq!(codes("#[ErrorPage]", "final class NotFound { public function render() { return <p />; } }"), ["E059"], "an error page has no state: a function");
    let (_, diags) = page("#[ErrorPage]", "final class NotFound { public function render() { return <p />; } }");
    assert_eq!(diags[0].diags[0].message, "`#[ErrorPage]` must mark a function, and `App\\NotFound` is a class");

    let tag = "function NotFound() { return <p />; }\n#[Component]\nfunction Card() { return <NotFound />; }";
    assert_eq!(codes("#[ErrorPage]", tag), ["E056"], "an error page is not a tag");

    let src = "<?phem\nnamespace App;\n#[ErrorPage]\nfunction NotFound() { return <p />; }\n";
    let (out, diags) = compile(vec![SourceInput::app("NotFound.phem", src)]);
    assert_eq!(diags[0].diags.iter().map(|d| d.code).collect::<Vec<_>>(), ["W004"], "no `use Phem\\Attribute\\ErrorPage;`");
    assert!(out.expect("output").manifest.error_pages.is_empty());

    assert_eq!(codes("#[ErrorPage]\n#[\\Phem\\Attribute\\Broadcast]", function), ["E053"], "an error page has no connection to the hub");

    let src = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\ErrorPage;\n#[ErrorPage]\nfunction NotFound() { return <p />; }\n";
    let (out, diags) = compile(vec![package("vendor/acme/ui/NotFound.phem", "vendor/acme/ui/components/NotFound.phem", src)]);
    assert!(out.is_none(), "only the app has error pages");
    let d = diags[0].shown().next().expect("E060");
    assert_eq!((d.code, d.message.as_str()), ("E060", "`#[ErrorPage]` is in the package `acme/ui`, and only the application has error pages"));
}

#[test]
fn a_function_is_a_component_only_with_the_component_attribute() {
    let src = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\nfunction Label(string $s) { return <b>{{ s }}</b>; }\n#[Component]\nfunction Card(string $title) { $label = Label($title); return <div>{{ label }}</div>; }\n#[Component]\nfunction Badge() { return <i>new</i>; }\nfunction App() { return <p><Card title=\"a\" /><Badge /></p>; }\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "{msgs:?}");
    let components = out.expect("output").manifest.components;
    let kinds: Vec<_> = components.iter().map(|(k, d)| (k.as_str(), d.kind)).collect();
    assert_eq!(kinds, [("App\\Badge", phemc_analyze::Kind::Template), ("App\\Card", phemc_analyze::Kind::Function)], "`Label` and `App` are plain PHP functions");

    // A tag of a function without `#[Component]` is an unknown component, with a hint.
    let src = "<?phem\nnamespace App;\nfunction Card() { return <div />; }\nfunction App() { return <Card />; }\n";
    let (_, diags) = compile(vec![SourceInput::app("App.phem", src)]);

    let d = diags.iter().flat_map(|f| &f.diags).find(|d| d.code == "E010").expect("E010 without #[Component]");
    assert_eq!(d.help.as_deref(), Some("add `#[Component]` before `function Card()`, and `use Phem\\Attribute\\Component;`"));

    // Without the import, `#[Component]` is another attribute.
    let src = "<?phem\nnamespace App;\n#[Component]\nfunction Card() { return <div />; }\nfunction App() { return <Card />; }\n";
    assert_eq!(codes(src), ["W004", "E010"]);
}

const TABS: &str = "<?phem\nnamespace App;\nuse Phem\\Attribute\\{Component, Context, Page, Prop, Provide};\nuse Phem\\Html;\n#[Component, Provide]\nfinal class Tabs { #[Prop] public ?Html $children = null; public function render() { return <div>{{ children }}</div>; } }\n#[Component]\nfunction Tab(string $name, #[Context] Tabs $tabs) { return <b>{{ name }}</b>; }\n#[Component]\nfunction Card(?Html $children = null) { return <section>{{ children }}</section>; }\n";

fn project(files: &[(&str, &str)]) -> Vec<String> {
    let inputs = files.iter().map(|(rel, text)| SourceInput::app(*rel, *text)).collect();
    let (_, diags) = compile(inputs);
    diags.iter().flat_map(|f| f.diags.iter().map(move |d| format!("{} {}", f.rel, d.code))).collect()
}

#[test]
fn a_context_needs_a_provider_on_each_known_chain_from_a_page() {
    let page = |body: &str| format!("<?phem\nnamespace App;\nuse Phem\\Attribute\\{{Component, Page}};\n#[Page('/')]\nfinal class Home {{ public function render() {{ return {body}; }} }}\n#[Component]\nfunction Panel() {{ return <div><Tab name=\"b\" /></div>; }}\n");

    // Inside the provider: covered, also under another component in its slot.
    assert!(project(&[("Tabs.phem", TABS), ("Home.phem", &page("<Tabs><Card><Tab name=\"a\" /></Card></Tabs>"))]).is_empty());
    // In the slot of a component that is not a provider: the compiler does not know the parent.
    assert!(project(&[("Tabs.phem", TABS), ("Home.phem", &page("<Card><Tab name=\"a\" /></Card>"))]).is_empty());
    // A component that the page renders, with no provider: the error is on the tag, in its file.
    assert_eq!(project(&[("Tabs.phem", TABS), ("Home.phem", &page("<main><Panel /></main>"))]), ["Home.phem E015"]);
    // The same component inside the provider: covered.
    assert!(project(&[("Tabs.phem", TABS), ("Home.phem", &page("<Tabs><Panel /></Tabs>"))]).is_empty());
    // No page renders the component: no chain, no error.
    assert!(project(&[("Tabs.phem", TABS), ("Home.phem", &page("<main />"))]).is_empty());
}

#[test]
fn a_provider_of_a_type_that_the_compiler_cannot_see_can_be_a_subtype() {
    let src = "<?phem\nnamespace App;\nuse Phem\\Attribute\\{Component, Context, Page, Prop, Provide};\nuse Phem\\Html;\n#[Component]\nfinal class Shell { #[Provide] public Dark $theme; #[Prop] public ?Html $children = null; public function render() { return <div>{{ children }}</div>; } }\n#[Component]\nfunction Button(#[Context] Theme $theme) { $x = 1; return <b />; }\n#[Page('/')]\nfinal class Home { public function render() { return <Shell><Button /></Shell>; } }\n";
    assert!(project(&[("App.phem", src)]).is_empty(), "`Dark` can implement `Theme`");

    let src = src.replace("Shell><Button /></Shell", "main><Button /></main");
    assert_eq!(project(&[("App.phem", &src)]), ["App.phem E015"]);
}

#[test]
fn an_abstract_class_is_not_a_component() {
    let base = "<?phem\nnamespace App;\nuse Phem\\Attribute\\{Component, State};\n#[Component]\nabstract class Base { #[State] public string $active = ''; }\nfunction Page() { return <Base />; }\n";
    let (out, diags) = compile(vec![SourceInput::app("Base.phem", base)]);

    let d: Vec<_> = diags.iter().flat_map(|f| &f.diags).collect();
    assert_eq!(d.iter().map(|d| d.code).collect::<Vec<_>>(), ["E045", "E010"], "no E040: the children give `render()`");
    assert_eq!(d[0].message, "component class `Base` is abstract");
    assert_eq!(d[0].help.as_deref(), Some("remove `#[Component]`: the children declare it"));
    assert!(out.is_none());

    // With no `#[Component]`, the base is a plain class.
    let plain = base.replace("#[Component]\n", "").replace("function Page() { return <Base />; }\n", "");
    assert!(project(&[("Base.phem", &plain)]).is_empty());

    // The other modifiers can come before or after `abstract`.
    for modifiers in ["readonly abstract", "abstract readonly"] {
        assert_eq!(codes(&base.replace("abstract class", &format!("{modifiers} class"))), ["E045", "E010"], "{modifiers}");
    }

    // An `abstract` that is not a modifier of the class does not change it.
    let card = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\nconst ABSTRACT = 1;\n#[Component]\nfinal class Card { public function render() { return <b />; } }\nfunction Page() { return <Card />; }\n";
    assert!(codes(card).is_empty());

    // An abstract page.
    let page = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Page;\n#[Page('/admin')]\nabstract class AdminPage {}\n";
    let (out, diags) = compile(vec![SourceInput::app("Admin.phem", page)]);

    let d: Vec<_> = diags.iter().flat_map(|f| &f.diags).collect();
    assert_eq!(d.iter().map(|d| (d.code, d.message.as_str(), d.help.as_deref())).collect::<Vec<_>>(), [("E045", "page class `AdminPage` is abstract", Some("remove `#[Page]`: the children declare it"))]);
    assert!(out.is_none());
}

#[test]
fn a_handler_call_gets_its_arguments_with_no_call_during_render() {
    let src = "<?phem\nnamespace App;\nuse Phem\\Attribute\\{Component, Prop};\nuse Phem\\Event;\n#[Component]\nfinal class Reasons {\n    #[Prop] public ?\\Closure $onPick = null;\n    public array $reasons = [];\n    public function pick(string $reason, Event $e): void {}\n    public function remove(int $index): void {}\n    public function render() { return <ul>{% for r in reasons %}<li key={{ r }}><button onClick={{ pick(r) }} phem:loading={{ remove(loop.index0) }}>{{ r }}</button><Reasons onPick={{ pick(r) }} /></li>{% endfor %}<button onClick={{ onPick('none') }} /><button onClick={{ remove( 2 ) }} /></ul>; }\n}\n";
    let (out, diags) = compile(vec![SourceInput::app("Reasons.phem", src)]);

    let msgs: Vec<String> = diags.iter().flat_map(|f| f.diags.iter().map(|d| format!("{} {}", d.code, d.message))).collect();
    assert!(msgs.is_empty(), "unexpected diagnostics: {msgs:#?}");
    let file = &out.expect("output").files[0];
    let twig = &file.templates[0].content;
    for expected in [
        "{{ phem_on('click', phem_call(pick, [r])) }}",
        "{{ phem_loading(phem_call(remove, [loop.index0])) }}",
        "'onPick': (phem_call(pick, [r]))",
        "{{ phem_on('click', phem_call(onPick, ['none'])) }}",
        "{{ phem_on('click', phem_call(remove, [2])) }}",
    ] {
        assert!(twig.contains(expected), "{expected} in {twig}");
    }

    // The methods go to the template as closures.
    assert!(file.php.contains("'pick' => $this->pick(...)"), "{}", file.php);
    assert!(file.php.contains("'remove' => $this->remove(...)"), "{}", file.php);
}

#[test]
fn a_handler_call_has_the_arguments_of_the_method() {
    let base = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\nuse Phem\\Event;\n#[Component]\nfinal class Slots {\n    public array $slots = [];\n    public function remove(int $index, Event $e): void {}\n    public function pick(?string $name, float $weight, bool $flag, \\App\\Size $size, mixed ...$rest): void {}\n    public function render() { return <div><button ATTR /></div>; }\n}\n";
    let src = |attr: &str| base.replace("ATTR", attr);

    // Too many arguments, an argument for the Event, a literal of another type, a named argument.
    let (_, diags) = compile(vec![SourceInput::app("Slots.phem", &src("onClick={{ remove(1, 2) }}"))]);
    let d: Vec<_> = diags.iter().flat_map(|f| &f.diags).collect();
    assert_eq!(d.iter().map(|d| (d.code, d.message.as_str())).collect::<Vec<_>>(), [("E047", "`onClick` gives the argument `2` to the `Event` parameter `$e` of `remove()`")]);
    assert_eq!(codes(&src("onClick={{ remove('first') }}")), ["E047"]);
    assert_eq!(codes(&src("onClick={{ remove(index: 1) }}")), ["E047"]);
    assert_eq!(codes(&src("onClick={{ pick(null, 1, true, 'small') }}")), ["E047"], "a literal is not an object");
    assert_eq!(codes(&src("onClick={{ pick(1) }}")), ["E047"]);

    // The right arguments, a variadic parameter, and the values that phemc does not know.
    assert!(codes(&src("onClick={{ remove(1) }}")).is_empty());
    assert!(codes(&src("onClick={{ pick(null, 1, false, slots|first, 'a', 2) }}")).is_empty());
    assert!(codes(&src("onClick={{ remove(slots|length) }}")).is_empty());
    assert!(codes(&src("onClick={{ remove() }}")).is_empty(), "the event of the browser does not give the index: the runtime reports it");

    // A variable of the same name can be any function.
    let local = src("onClick={{ remove(1, 2) }}").replace("public function render() {", "public function render() { $remove = fn () => null;");
    assert!(codes(&local).is_empty());
}

#[test]
fn mount_and_render_are_not_handlers() {
    let base = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class Panel {\n    public function mount(): void {}\n    public function reload(): void {}\n    public function render() { return <div><button ATTR /></div>; }\n}\n";
    let src = |attr: &str| base.replace("ATTR", attr);

    let (out, diags) = compile(vec![SourceInput::app("Panel.phem", &src("onClick={{ mount }}"))]);
    let d: Vec<_> = diags.iter().flat_map(|f| &f.diags).collect();
    assert_eq!(d.iter().map(|d| (d.code, d.message.as_str(), d.help.as_deref())).collect::<Vec<_>>(), [("E046", "`onClick` cannot run `mount()`: Phem calls it itself", Some("move the code into a method with another name, and pass it: `onClick={{ reload }}`"))]);
    assert!(out.is_none());

    // `render()`, the other attributes that take a handler, and any case.
    assert_eq!(codes(&src("onChange={{ render }}")), ["E046"]);
    assert_eq!(codes(&src("phem:loading={{ mount }}")), ["E046"]);
    assert_eq!(codes(&src("onClick={{ Mount }}")), ["E046"]);

    // Another method is a handler.
    assert!(codes(&src("onClick={{ reload }}")).is_empty());

    // A local variable or a property of the same name is a value, not the method.
    let local = src("onClick={{ mount }}").replace("public function render() {", "public function render() { $mount = $this->reload(...);");
    assert!(codes(&local).is_empty());
    let property = src("onClick={{ render }}").replace("public function mount(): void {}", "public function mount(): void {}\n    public ?\\Closure $render = null;");
    assert!(codes(&property).is_empty());

    // A Twig variable of the same name is a value too, in its scope only.
    let each = |markup: &str| base.replace("<div><button ATTR /></div>", markup).replace("public function mount(): void {}", "public function mount(): void {}\n    public array $actions = [];");
    assert_eq!(codes(&each("<ul>{% for mount in actions %}<li key={{ loop.index }}><button onClick={{ mount }} /></li>{% endfor %}</ul>")), Vec::<&str>::new());
    assert_eq!(codes(&each("<div>{% set render = actions|first %}<button onClick={{ render }} /></div>")), Vec::<&str>::new());
    assert_eq!(codes(&each("<div>{% for mount in actions %}{% endfor %}<button onClick={{ mount }} /></div>")), ["E046"]);

    // The names are reserved: the method can come from a parent outside the sources.
    let open = base.replace("final class Panel {", "final class Panel extends \\Vendor\\Base {").replace("public function mount(): void {}", "");
    assert_eq!(codes(&open.replace("ATTR", "onClick={{ mount }}")), ["E046"]);

    // A class that isn't a component calls no `render()`: its methods are handlers.
    let plain = "<?phem\nnamespace App;\nfinal class Builder {\n    public function render(): void {}\n    public function button() { return <button onClick={{ render }} />; }\n}\n";
    assert!(codes(plain).is_empty());

    // A function component has no `mount()` or `render()`: its parameter is a value.
    let function = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Row(\\Closure $render) { return <button onClick={{ render }} />; }\n";
    assert!(codes(function).is_empty());
}

#[test]
fn an_abstract_replacement_does_not_replace_the_original() {
    let src = "<?phem\nnamespace App;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Button() { return <button />; }\n#[Component(replaces: Button::class)]\nabstract class MyButton {}\nfunction Page() { return <p><Button /><Button /></p>; }\n";
    assert_eq!(codes(src), ["E045"], "the tags of `Button` render `Button`: no E010");
}

#[test]
fn a_component_provides_itself_with_provide_on_a_parent_class() {
    let base = "<?phem\nnamespace Acme\\Base;\nuse Phem\\Attribute\\Provide;\n#[Provide]\nabstract class AbstractTabs {}\n";
    let theme = "<?phem\nnamespace App;\nuse Acme\\Base\\AbstractTabs;\nuse Phem\\Attribute\\{Component, Context, Page};\n#[Component]\nfinal class Tabs extends AbstractTabs { public function render() { return <div><Tab /></div>; } }\n#[Component]\nfunction Tab(#[Context] AbstractTabs $tabs) { return <b />; }\n#[Page('/')]\nfinal class Home { public function render() { return <Tabs />; } }\n";
    assert!(project(&[("Base.phem", base), ("App.phem", theme)]).is_empty(), "`Tabs` provides `AbstractTabs` to the `<Tab>` that it renders");

    // Also through a parent of the parent.
    let middle = "<?phem\nnamespace App;\nabstract class Middle extends \\Acme\\Base\\AbstractTabs {}\n";
    let theme2 = theme.replace("extends AbstractTabs", "extends Middle");
    assert!(project(&[("Base.phem", base), ("Middle.phem", middle), ("App.phem", &theme2)]).is_empty());

    // With no `#[Provide]` on the parent, `Tabs` provides nothing.
    let plain = base.replace("#[Provide]\n", "");
    assert_eq!(project(&[("Base.phem", &plain), ("App.phem", theme)]), ["App.phem E015"]);

    // A child of a component with `#[Provide]` provides itself too.
    let tabs = "<?phem\nnamespace App;\nuse Phem\\Attribute\\{Component, Context, Page, Provide};\n#[Component, Provide]\nclass Tabs { public function render() { return <div />; } }\n#[Component]\nfinal class MyTabs extends Tabs { public function render() { return <div><Tab /></div>; } }\n#[Component]\nfunction Tab(#[Context] Tabs $tabs) { return <b />; }\n#[Page('/')]\nfinal class Home { public function render() { return <MyTabs />; } }\n";
    assert!(project(&[("App.phem", tabs)]).is_empty());
}

#[test]
fn a_parent_outside_the_sources_can_provide_but_a_trait_cannot() {
    let src = |body: &str| format!("<?phem\nnamespace App;\nuse Phem\\Attribute\\{{Component, Context, Page}};\n#[Component]\nfinal class Tabs {body} {{ public function render() {{ return <div><Tab /></div>; }} }}\n#[Component]\nfunction Tab(#[Context] Tabs $tabs) {{ return <b />; }}\n#[Page('/')]\nfinal class Home {{ public function render() {{ return <Tabs />; }} }}\n");

    assert!(project(&[("App.phem", &src("extends \\Vendor\\Base"))]).is_empty(), "the parent can have `#[Provide]`");
    assert_eq!(project(&[("App.phem", &src("").replace("{ public function render", "{ use \\Vendor\\Tools; public function render"))]), ["App.phem E015"], "a trait does not give its attributes");
}

#[test]
fn a_member_path_in_a_handler_is_the_method() {
    let src = "<?phem\nfunction App() { $tabs = $form = $select = null; return <p><button onClick={{ tabs.select }} /><button onClick={{ select }} phem:loading={{ form.save }} /></p>; }\n";
    let (out, diags) = compile(vec![SourceInput::app("App.phem", src)]);
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
    let src = "<?phem\nuse Phem\\Async\\Promise;\nuse Phem\\Attribute\\{Async, Component};\nuse Phem\\Node;\nabstract class Base\n{\n    #[Async]\n    public function run(): Promise { return $this->work(); }\n\n    private function work(): Promise { return new Promise(static function (): void {}); }\n}\n#[Component]\nfinal class Export extends Base\n{\n    public function render(): Node { return <button onClick={{ run }}>Export</button>; }\n}\n";
    assert_eq!(codes(src), Vec::<&str>::new());
}

fn package(rel: &str, path: &str, text: &str) -> SourceInput {
    SourceInput { rel: rel.into(), path: path.into(), package: Some("acme/ui".into()), text: text.into() }
}

#[test]
fn a_package_compiles_with_the_app() {
    let badge = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Page};\n#[Component]\nfunction Badge(string $label) { return <span>{{ label }}</span>; }\n#[Page('/gallery')]\nfunction Gallery() { return <ul><li>{{ 1 }}</li>{% for i in [1] %}<li>{{ i }}</li>{% endfor %}</ul>; }\n";
    let home = "<?phem\nnamespace App;\nuse Acme\\Ui\\Badge;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfunction Home() { return <Badge label=\"New\" />; }\n";
    let (out, diags) = compile(vec![
        SourceInput { rel: "Home.phem".into(), path: "src/Home.phem".into(), package: None, text: home.into() },
        package("vendor/acme/ui/Badge.phem", "vendor/acme/ui/components/Badge.phem", badge),
    ]);

    // W002 (a list item with no key) in the package file: the tools do not show it.
    let package_diags = diags.iter().find(|f| f.package.is_some()).unwrap();
    assert_eq!(package_diags.diags.iter().map(|d| d.code).collect::<Vec<_>>(), ["W002"]);
    assert_eq!(package_diags.shown().count(), 0, "a package warning is not shown");
    assert_eq!(package_diags.path, "vendor/acme/ui/components/Badge.phem");

    let out = out.expect("output");
    let paths: Vec<&str> = out.files.iter().map(|f| f.php_path.as_str()).collect();
    assert_eq!(paths, ["php/Home.php", "php/vendor/acme/ui/Badge.php"]);
    assert!(out.files[1].php.starts_with("<?php /* compiled by phemc from vendor/acme/ui/components/Badge.phem;"));

    let sources: Vec<(&str, &str)> = out.manifest.templates.iter().map(|(n, t)| (n.as_str(), t.source.as_str())).collect();
    assert_eq!(sources, [("Home.0.twig", "src/Home.phem"), ("vendor/acme/ui/Badge.0.twig", "vendor/acme/ui/components/Badge.phem"), ("vendor/acme/ui/Badge.1.twig", "vendor/acme/ui/components/Badge.phem")]);
    assert_eq!(out.manifest.pages, BTreeMap::from([("Acme\\Ui\\Gallery".to_string(), "acme/ui".to_string()), ("App\\Home".to_string(), String::new())]));
}

#[test]
fn a_package_error_stops_the_build() {
    let broken = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Card() { return <Missing />; }\n";
    let (out, diags) = compile(vec![package("vendor/acme/ui/Card.phem", "vendor/acme/ui/components/Card.phem", broken)]);

    assert!(out.is_none());
    assert_eq!(diags[0].shown().map(|d| d.code).collect::<Vec<_>>(), ["E010"]);
}

#[test]
fn a_duplicate_component_names_the_path_of_the_other_file() {
    let badge = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Badge() { return <b />; }\n";
    let (_, diags) = compile(vec![
        SourceInput { rel: "Badge.phem".into(), path: "src/Badge.phem".into(), package: None, text: badge.into() },
        package("vendor/acme/ui/Badge.phem", "vendor/acme/ui/components/Badge.phem", badge),
    ]);

    let messages: Vec<String> = diags.iter().flat_map(|f| f.shown().map(move |d| format!("{} {} {}", f.path, d.code, d.message))).collect();
    assert_eq!(messages, ["vendor/acme/ui/components/Badge.phem E014 component `Acme\\Ui\\Badge` is already declared in `src/Badge.phem`"]);
}

#[test]
fn a_theme_package_extends_a_base_package() {
    // The base has the props and the handlers, the theme gives render().
    let base = "<?phem\nnamespace Acme\\Base;\nuse Phem\\Attribute\\{Prop, State};\nabstract class AbstractTabs { #[State] public string $active = ''; #[Prop] public string $label = ''; public function select(): void {} }\n";
    let theme = "<?phem\nnamespace Acme\\Ui;\nuse Acme\\Base\\AbstractTabs;\nuse Phem\\Attribute\\Component;\n#[Component]\nclass Tabs extends AbstractTabs { public function render() { return <div onClick={{ select }}>{{ label }}</div>; } }\n";
    let home = "<?phem\nnamespace App;\nuse Acme\\Ui\\Tabs;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfunction Home() { return <Tabs label=\"A\" />; }\nfunction Wrong() { return <Tabs lable=\"A\" />; }\n";
    let base_input = SourceInput { rel: "vendor/acme/base/AbstractTabs.phem".into(), path: "vendor/acme/base/src/AbstractTabs.phem".into(), package: Some("acme/base".into()), text: base.into() };
    let theme_input = package("vendor/acme/ui/Tabs.phem", "vendor/acme/ui/components/Tabs.phem", theme);
    let (out, diags) = compile(vec![SourceInput::app("Home.phem", home), base_input, theme_input]);

    // The props of the base package are known: an unknown prop is E011.
    let codes: Vec<String> = diags.iter().flat_map(|f| f.shown().map(move |d| format!("{} {}", f.path, d.code))).collect();
    assert_eq!(codes, ["Home.phem E011"]);
    assert!(out.is_none());
}

const UI: &str = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Html;\n#[Component]\nfunction Button(string $label = '', ?Html $children = null, mixed ...$rest) { return <button {{ ...rest }}>{{ label }}{{ children }}</button>; }\n#[Component]\nfunction Dialog(?Html $children = null) { return <dialog><Button label=\"Close\" /></dialog>; }\n";

fn ui() -> SourceInput {
    package("vendor/acme/ui/Ui.phem", "vendor/acme/ui/components/Ui.phem", UI)
}

fn app(text: &str) -> SourceInput {
    SourceInput { rel: "Button.phem".into(), path: "src/Button.phem".into(), package: None, text: format!("<?phem\nnamespace App;\nuse Acme\\Ui\\Button as UiButton;\nuse Phem\\Attribute\\Component;\nuse Phem\\Html;\n{text}") }
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

    // The original is a function: the PHP gets its name as a string, not a `::class`.
    let php = &out.files.iter().find(|f| f.rel == "Button.phem").unwrap().php;
    assert!(php.contains(r"#[Component(replaces: 'Acme\\Ui\\Button')]"), "{php}");
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
            "src/Button.phem E016 `App\\Button` does not accept the slot `children` of `Acme\\Ui\\Button`",
            "src/Button.phem E016 `Acme\\Ui\\Button` accepts any prop, and `App\\Button` has no rest prop",
        ]
    );
    assert_eq!(
        check("string $size, mixed ...$rest"),
        ["src/Button.phem E016 `App\\Button` requires the prop `size`, and `Acme\\Ui\\Button` does not"]
    );
}

#[test]
fn a_replacement_must_be_unique_and_in_the_app() {
    let replaces = |text: &str| shown(&compile(vec![app(text), ui()]).1);
    let ok = "#[Component(replaces: UiButton::class)]\nfunction Button(?Html $children = null, mixed ...$rest) { return <b />; }\n";

    assert_eq!(
        replaces(&format!("{ok}#[Component(replaces: UiButton::class)]\nfunction Other(?Html $children = null, mixed ...$rest) {{ return <b />; }}\n")),
        ["src/Button.phem E017 `App\\Button` already replaces `Acme\\Ui\\Button`"]
    );
    assert_eq!(
        replaces(&format!("{ok}#[Component(replaces: Button::class)]\nfunction Other(?Html $children = null, mixed ...$rest) {{ return <b />; }}\n")),
        ["src/Button.phem E017 `App\\Button` replaces `Acme\\Ui\\Button`, and a replacement cannot be replaced: replace `Acme\\Ui\\Button`"]
    );
    assert_eq!(
        replaces("#[Component(replaces: Missing::class)]\nfunction Button() { return <b />; }\n"),
        ["src/Button.phem E010 `App\\Button` replaces an unknown component `App\\Missing`"]
    );
    assert_eq!(
        replaces("#[Component(replaces: \\Phem\\Root::class)]\nfunction Button(?Html $children = null, mixed ...$rest) { return <b />; }\n"),
        ["src/Button.phem E017 `Phem\\Root` is a built-in component of Phem, and the app cannot replace it"]
    );
    assert_eq!(
        replaces("#[Component(replaces: self::NAME)]\nfunction Button() { return <b />; }\n"),
        ["src/Button.phem E017 the compiler cannot read the value of `replaces`"]
    );
    for name in ["self", "static", "parent"] {
        assert_eq!(
            replaces(&format!("#[Component(replaces: {name}::class)]\nfinal class Button {{ public function render() {{ return <b />; }} }}\n")),
            ["src/Button.phem E017 the compiler cannot read the value of `replaces`"],
            "{name}"
        );
    }

    // A package cannot replace a component of another package.
    let theme = "<?phem\nnamespace Acme\\Theme;\nuse Acme\\Ui\\Button as UiButton;\nuse Phem\\Attribute\\Component;\n#[Component(replaces: UiButton::class)]\nfunction Button(mixed ...$rest) { return <b />; }\n";
    let (out, diags) = compile(vec![ui(), package("vendor/acme/theme/Button.phem", "vendor/acme/theme/components/Button.phem", theme)]);
    assert!(out.is_none());
    assert_eq!(shown(&diags), ["vendor/acme/theme/components/Button.phem E017 a package cannot replace a component, and `Acme\\Theme\\Button` is in a package"]);
}

#[test]
fn a_context_consumer_under_a_replacement_needs_a_provider() {
    // The page renders the Dialog of the package, whose Button is the replacement of the app: the
    // replacement needs a context that no component above provides.
    let replacement = app("use Acme\\Ui\\Dialog;\nuse Phem\\Attribute\\{Context, Page};\n#[Component(replaces: UiButton::class)]\nfunction Button(#[Context] Theme $theme, ?Html $children = null, mixed ...$rest) { return <b />; }\n#[Page('/')]\nfunction Home() { return <main><Dialog /></main>; }\n");
    let diags = compile(vec![replacement, ui()]).1;
    assert_eq!(
        shown(&diags),
        ["vendor/acme/ui/components/Ui.phem E015 `<Button>` renders `App\\Button`, the replacement of `Acme\\Ui\\Button`, and it needs a `Theme` context, but no component above it provides one"]
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
        ["src/Button.phem E017 `App\\Button` cannot replace itself"]
    );
    assert_eq!(
        replaces("use Phem\\Attribute\\Page;\n#[Page('/shop')]\nfunction Shop() { return <main />; }\n#[Component(replaces: Shop::class)]\nfunction Button() { return <b />; }\n"),
        ["src/Button.phem E017 a page is not a tag, so `App\\Button` cannot replace `App\\Shop`"]
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
            "src/Button.phem E017 `App\\Button` already replaces `Acme\\Ui\\Button`",
            "src/Button.phem E017 `App\\Button` already replaces `Acme\\Ui\\Button`",
        ]
    );
}

#[test]
fn a_replacement_keeps_the_bound_props_of_the_original() {
    let input = package("vendor/acme/ui/Input.phem", "vendor/acme/ui/components/Input.phem", "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Bound;\n#[Component]\nfunction Input(Bound $value) { return <input phem:bind={{ value }} />; }\n");
    let check = |signature: &str| shown(&compile(vec![app(&format!("use Acme\\Ui\\Input as UiInput;\nuse Phem\\Bound;\n#[Component(replaces: UiInput::class)]\nfunction Input({signature}) {{ return <b />; }}\n")), input.clone()]).1);

    assert!(check("Bound $value").is_empty());
    assert!(check("mixed ...$rest").is_empty(), "a rest prop gives the Bound to the original");
    assert_eq!(check("string $value"), ["src/Button.phem E016 `App\\Input` takes the `Bound` prop `value` of `Acme\\Ui\\Input` with another type"]);
}

#[test]
fn a_rejected_replacement_changes_no_tag() {
    // The replacement of a package is E017: the tags keep the original, so its context gives no E015.
    let theme = "<?phem\nnamespace Acme\\Theme;\nuse Acme\\Ui\\Button as UiButton;\nuse Phem\\Attribute\\{Component, Context};\n#[Component(replaces: UiButton::class)]\nfunction Button(#[Context] Theme $theme, mixed ...$rest) { return <b />; }\n";
    let page = app("use Acme\\Ui\\Dialog;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfunction Home() { return <main><Dialog /></main>; }\n");
    let (_, diags) = compile(vec![page, ui(), package("vendor/acme/theme/Button.phem", "vendor/acme/theme/components/Button.phem", theme)]);
    assert_eq!(shown(&diags), ["vendor/acme/theme/components/Button.phem E017 a package cannot replace a component, and `Acme\\Theme\\Button` is in a package"]);
}

#[test]
fn a_replacement_accepts_each_named_slot_of_the_original() {
    let card = package("vendor/acme/ui/Card.phem", "vendor/acme/ui/components/Card.phem", "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Html;\n#[Component]\nfunction Card(?Html $children = null, ?Html $footer = null) { return <div>{{ children }}{{ footer }}</div>; }\n");
    let check = |signature: &str| shown(&compile(vec![app(&format!("use Acme\\Ui\\Card as UiCard;\n#[Component(replaces: UiCard::class)]\nfunction Card({signature}) {{ return <b />; }}\n")), card.clone()]).1);

    assert!(check("?Html $children = null, ?Html $footer = null").is_empty());
    assert_eq!(check("?Html $children = null"), ["src/Button.phem E016 `App\\Card` does not accept the slot `footer` of `Acme\\Ui\\Card`"]);
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
    assert_eq!(shown(&diags), ["src/Button.phem E017 a page is not a tag, so `App\\Button` cannot replace `Acme\\Ui\\Button`"]);
}

#[test]
fn a_context_consumer_under_a_class_replacement_needs_a_provider() {
    let replacement = app("use Acme\\Ui\\Dialog;\nuse Phem\\Attribute\\{Context, Page, Prop};\nuse Phem\\Node;\n#[Component(replaces: UiButton::class)]\nfinal class Button\n{\n    #[Context] public Theme $theme;\n    #[Prop(rest: true)] public array $attrs = [];\n    #[Prop] public ?Html $children = null;\n    public function render(): Node { return <b />; }\n}\n#[Page('/')]\nfunction Home() { return <main><Dialog /></main>; }\n");
    assert_eq!(
        shown(&compile(vec![replacement, ui()]).1),
        ["vendor/acme/ui/components/Ui.phem E015 `<Button>` renders `App\\Button`, the replacement of `Acme\\Ui\\Button`, and it needs a `Theme` context, but no component above it provides one"]
    );
}

const CARD: &str = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Prop};\nuse Phem\\{Html, Node};\n#[Component]\nclass Card\n{\n    #[Prop] public string $title = '';\n    #[Prop] public ?Html $children = null;\n    public function render(): Node { return <div>{{ title }}<Card title=\"Inner\" /></div>; }\n}\n#[Component]\nfunction Panel() { return <aside><Card title=\"Panel\" /></aside>; }\n";

#[test]
fn a_class_replacement_can_extend_the_original_class() {
    // The replacement takes the props of the original from the parent class: no E016.
    let replacement = app("use Acme\\Ui\\Card as UiCard;\nuse Phem\\Node;\n#[Component(replaces: UiCard::class)]\nfinal class Card extends UiCard\n{\n    public function render(): Node { return <section><UiCard title={{ title }} /></section>; }\n}\n");
    let (out, diags) = compile(vec![replacement, package("vendor/acme/ui/Card.phem", "vendor/acme/ui/components/Card.phem", CARD)]);
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
    let card = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Prop};\nuse Phem\\Node;\n#[Component]\nfinal class Card extends \\Vendor\\Base\n{\n    #[Prop] public string $title = '';\n    public function render(): Node { return <div />; }\n}\n";
    let replacement = app("use Acme\\Ui\\Card as UiCard;\n#[Component(replaces: UiCard::class)]\nfunction Card() { return <b />; }\n");
    let (_, diags) = compile(vec![replacement, package("vendor/acme/ui/Card.phem", "vendor/acme/ui/components/Card.phem", card)]);
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
    let enums = "<?phem\nnamespace Acme\\Ui;\nenum Variant: string\n{\n    case Primary = 'primary';\n    case Outline = \"outline\";\n    public function classes(): string { switch ($this) { case self::Primary: return 'a'; default: return 'b'; } }\n}\nenum Level: int { case Low = 1; case High = 3; }\nenum Computed: string { const P = 'p'; case A = self::P; }\n";
    let ui = "<?phem\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\{Component, Prop};\nuse Phem\\Node;\n#[Component]\nfunction Button(Variant $variant = Variant::Primary, ?Level $level = null, Computed $computed = Computed::A, \\Vendor\\Size $size = \\Vendor\\Size::S) { return <b />; }\n#[Component]\nfinal class Badge\n{\n    #[Prop] public Variant|null $variant = null;\n    public function render(): Node { return <i />; }\n}\n";
    let home = |body: &str| SourceInput::app("Home.phem", format!("<?phem\nnamespace App;\nuse Acme\\Ui\\{{Badge, Button}};\nfunction Home() {{ $v = 'big'; return <main>{body}</main>; }}\n"));
    let check = |body: &str| {
        let (_, diags) = compile(vec![home(body), package("vendor/acme/ui/Variant.phem", "vendor/acme/ui/components/Variant.phem", enums), package("vendor/acme/ui/Button.phem", "vendor/acme/ui/components/Button.phem", ui)]);
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
    let base = "<?phem\nnamespace Acme\\Base;\nuse Phem\\Attribute\\Prop;\nenum Variant: string { case Primary = 'primary'; }\nabstract class AbstractButton { #[Prop] public variant $variant = Variant::Primary; }\n";
    let theme = "<?phem\nnamespace Acme\\Ui;\nuse Acme\\Base\\AbstractButton;\nuse Phem\\Attribute\\Component;\nuse Phem\\Node;\nenum Variant: string { case Solid = 'solid'; }\n#[Component]\nclass Button extends AbstractButton { public function render(): Node { return <button />; } }\n";
    let home = |body: &str| SourceInput::app("Home.phem", format!("<?phem\nnamespace App;\nuse Acme\\Ui\\Button;\nfunction Home() {{ return {body}; }}\n"));
    let check = |body: &str| {
        let base = SourceInput { rel: "vendor/acme/base/Button.phem".into(), path: "vendor/acme/base/src/Button.phem".into(), package: Some("acme/base".into()), text: base.into() };
        let (_, diags) = compile(vec![home(body), base, package("vendor/acme/ui/Button.phem", "vendor/acme/ui/components/Button.phem", theme)]);
        shown(&diags)
    };

    assert!(check("<Button variant=\"primary\" />").is_empty(), "{:?}", check("<Button variant=\"primary\" />"));
    assert_eq!(check("<Button variant=\"solid\" />"), ["Home.phem E018 `<Button>` has no variant `solid`"]);
}

#[test]
fn an_enum_that_two_files_declare_is_an_error() {
    let size = "<?phem\nnamespace Acme\\Ui;\nenum Size: string { case S = 's'; }\n";
    let (out, diags) = compile(vec![
        SourceInput { rel: "Size.phem".into(), path: "src/Size.phem".into(), package: None, text: size.into() },
        package("vendor/acme/ui/Size.phem", "vendor/acme/ui/components/Size.phem", size),
    ]);

    assert!(out.is_none());
    assert_eq!(shown(&diags), ["vendor/acme/ui/components/Size.phem E014 enum `Acme\\Ui\\Size` is already declared in `src/Size.phem`"]);
}
