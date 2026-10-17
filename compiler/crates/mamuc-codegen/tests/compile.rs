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
