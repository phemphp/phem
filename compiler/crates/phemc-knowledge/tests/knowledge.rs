//! The outputs of the knowledge on the fixture project (`tests/fixtures/project`): an app, and the
//! package `acme/ui` with its examples and its guide.

use phemc_knowledge::search::{parse_eval, EvalReport};
use phemc_knowledge::Knowledge;
use std::path::Path;

fn knowledge() -> Knowledge {
    let project = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/project");
    let roots = phemc_sources::roots(&project, Path::new("src")).unwrap();

    Knowledge::load(&project, &roots).unwrap()
}

#[test]
fn the_summary_lists_the_families() {
    let k = knowledge();

    insta::assert_snapshot!(k.catalog.summary_md(&k.guides()));
}

#[test]
fn a_root_shows_its_props_and_its_anatomy() {
    let k = knowledge();
    let dialog = k.catalog.find("Dialog", None)[0];

    insta::assert_snapshot!(k.catalog.detail_md(dialog, &k.examples_of(&dialog.fqcn)));
}

#[test]
fn a_part_shows_where_it_goes() {
    let k = knowledge();
    let popup = k.catalog.find("acme\\ui\\dialogpopup", Some("acme/ui"))[0];

    insta::assert_snapshot!(k.catalog.detail_md(popup, &k.examples_of(&popup.fqcn)));
}

#[test]
fn an_enum_prop_shows_its_values() {
    let k = knowledge();
    let button = k.catalog.find("Button", None)[0];

    insta::assert_snapshot!(serde_json::to_string_pretty(&k.catalog.detail_json(button, &k.examples_of(&button.fqcn))).unwrap());
}

#[test]
fn an_example_shows_its_code() {
    let k = knowledge();
    let names: Vec<_> = k.examples.iter().map(|e| e.key.as_str()).collect();
    // SaveIcon is a helper of DialogFormDemo: its code and the constant go with the example.
    assert_eq!(names, ["Acme\\Ui\\Examples\\DialogFormDemo", "Acme\\Ui\\Examples\\DialogNestedDemo"]);
    assert_eq!((k.examples[0].declarations.len(), k.examples[0].helpers.len()), (2, 1));

    // A helper of another file, with no docblock: its code starts at its attributes.
    let nested = &k.examples[1];
    assert_eq!(nested.helpers, ["#[Component]\nfunction CloseIcon(): Node\n{\n    return <svg aria-hidden=\"true\" />;\n}"]);
    assert!(!nested.uses.iter().any(|u| u.ends_with("CloseIcon")));

    insta::assert_snapshot!(k.examples[0].md());
}

#[test]
fn a_page_is_not_in_the_catalog() {
    let k = knowledge();

    assert!(k.catalog.find("HomePage", None).is_empty());
    assert_eq!(k.catalog.find("Card", Some("app"))[0].props[0].default, None);
}

#[test]
fn the_guide_of_a_package_is_a_doc() {
    let k = knowledge();

    insta::assert_snapshot!(k.library.find("acme/ui#naming").unwrap().page().md());
    assert_eq!(k.guides(), [("acme/ui".to_string(), vec!["acme/ui#install".to_string(), "acme/ui#naming".to_string()])]);
}

#[test]
fn a_search_gives_the_same_result_each_time() {
    let k = knowledge();
    let first = k.index().search("dialog with a form", 20);

    insta::assert_snapshot!(phemc_knowledge::search::Hit::md(&first[..5], "dialog with a form"));
    for _ in 0..3 {
        let again = knowledge().index().search("dialog with a form", 20);
        assert_eq!(serde_json::to_string(&again).unwrap(), serde_json::to_string(&first).unwrap());
    }
}

/// The retrieval eval of phem: real prompts and the keys that must be in their top 20. A doc or a
/// component that breaks a query shows here.
#[test]
fn the_retrieval_eval_finds_all_the_keys() {
    let k = knowledge();
    let cases = parse_eval(include_str!("retrieval.yaml")).unwrap();
    let report = EvalReport::run(&k.index(), &cases, 20);

    assert!(report.passed(), "{}", report.md());
}
