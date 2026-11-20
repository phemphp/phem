//! The commands of the texts for the agents: `phemc docs`, `components`, `examples` and
//! `search`, and the tools of `phemc mcp` that give the same texts. Each command gives its text,
//! and the caller prints it or sends it.

use crate::catalog::Catalog;
use crate::search::Hit;
use crate::Knowledge;
use serde_json::{json, Value};

/// The output format.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Format {
    /// Markdown, for the agents.
    Md,
    /// JSON, for the tools.
    Json,
}

/// The text of a command.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Answer {
    pub text: String,
    /// False when the key is not found or names more than one text: the text is the error, with
    /// the closest keys.
    pub ok: bool,
}

impl Answer {
    fn of(format: Format, md: impl FnOnce() -> String, json: impl FnOnce() -> Value) -> Self {
        let text = match format {
            Format::Md => md(),
            Format::Json => format!("{}\n", serde_json::to_string_pretty(&json()).unwrap_or_default()),
        };

        Answer { text, ok: true }
    }

    fn error(text: String) -> Self {
        Answer { text, ok: false }
    }
}

/// Whether a doc key needs the project: the guide of a package (`acme/ui#naming`).
pub fn doc_needs_project(key: Option<&str>) -> bool {
    key.is_some_and(|k| k.contains('/'))
}

/// `phemc docs [<key>] [--toc]`.
pub fn docs(knowledge: &Knowledge, key: Option<&str>, toc: bool, format: Format) -> Answer {
    let lib = &knowledge.library;
    let Some(key) = key else {
        return match toc {
            true => Answer::of(format, || lib.toc_md(), || lib.toc_json()),
            false => Answer::of(format, || lib.index_md(), || lib.index_json()),
        };
    };

    let Some(found) = lib.find(key) else {
        return not_found(knowledge, "doc", key, &["doc", "guide"]);
    };

    let page = found.page();

    Answer::of(format, || page.md(), || serde_json::to_value(&page).unwrap_or_default())
}

/// `phemc components [<name>] [--package <name>]`.
pub fn components(knowledge: &Knowledge, name: Option<&str>, package: Option<&str>, format: Format) -> Answer {
    let catalog = &knowledge.catalog;
    let Some(name) = name else {
        let only = Catalog { components: catalog.components.iter().filter(|c| package.is_none_or(|p| c.package_name() == p)).cloned().collect() };
        let guides = knowledge.guides();

        return Answer::of(format, || only.summary_md(&guides), || only.summary_json());
    };

    match catalog.find(name, package).as_slice() {
        [] => not_found(knowledge, "component", name, &["component"]),
        [c] => {
            let examples = knowledge.examples_of(&c.fqcn);

            Answer::of(format, || catalog.detail_md(c, &examples), || catalog.detail_json(c, &examples))
        }
        many => {
            let names: Vec<String> = many.iter().map(|c| format!("`{}` ({})", c.fqcn, c.package_name())).collect();

            Answer::error(format!("several components are `{name}`: {}. Give the FQCN or the package.", names.join(", ")))
        }
    }
}

/// `phemc examples [<name>] [--package <name>]`: all the examples, the examples of a component,
/// or one example with its code.
pub fn examples(knowledge: &Knowledge, name: Option<&str>, package: Option<&str>, format: Format) -> Answer {
    let in_package = |p: &str| package.is_none_or(|only| p == only);

    let Some(name) = name else {
        let list: Vec<_> = knowledge.examples.iter().filter(|e| in_package(e.package_name())).collect();
        let md = || {
            let mut out = String::from("# Examples\n\nRead an example with `phemc examples <key>`.\n");
            let mut current = None;
            for e in &list {
                if current != Some(e.package_name()) {
                    current = Some(e.package_name());
                    out.push_str(&format!("\n## {}\n\n", e.package_name()));
                }

                out.push_str(&format!("- `{}` {} Uses: {}.\n", e.key, e.title(), e.uses_names().join(", ")));
            }

            out
        };

        return Answer::of(format, md, || Value::Array(list.iter().map(|e| json!({"key": e.key, "title": e.title(), "package": e.package_name(), "uses": e.uses})).collect()));
    };

    let key = name.trim_start_matches('\\');
    let example = knowledge.examples.iter().filter(|e| in_package(e.package_name())).find(|e| e.key.eq_ignore_ascii_case(key) || e.name.eq_ignore_ascii_case(key));
    if let Some(e) = example {
        return Answer::of(format, || e.md(), || serde_json::to_value(e).unwrap_or_default());
    }

    // The examples of a component.
    match knowledge.catalog.find(name, package).as_slice() {
        [c] => {
            let list = knowledge.examples_of(&c.fqcn);
            let md = || {
                let mut out = format!("# Examples of {} ({})\n\nRead an example with `phemc examples <key>`.\n\n", c.name, c.package_name());
                if list.is_empty() {
                    out.push_str("No example uses this component.\n");
                }

                for (key, title) in &list {
                    out.push_str(&format!("- `{key}` {title}\n"));
                }

                out
            };

            Answer::of(format, md, || Value::Array(list.iter().map(|(key, title)| json!({"key": key, "title": title})).collect()))
        }
        _ => not_found(knowledge, "example or component", name, &["example", "component"]),
    }
}

/// `phemc search <query> [--limit <n>]`.
pub fn search(knowledge: &Knowledge, query: &str, limit: usize, format: Format) -> Answer {
    let hits = knowledge.index().search(query, limit);

    Answer::of(format, || Hit::md(&hits, query), || serde_json::to_value(&hits).unwrap_or_default())
}

/// A missing key: an error with the keys of the closest texts of a kind.
fn not_found(knowledge: &Knowledge, what: &str, query: &str, kinds: &[&str]) -> Answer {
    let index = knowledge.index();
    let mut close: Vec<String> = index.ranked(query).into_iter().filter(|(c, _)| kinds.contains(&c.kind)).take(5).map(|(c, _)| format!("`{}`", c.key)).collect();

    // A typo: the keys with the longest start of the name in them (4 characters at least).
    let name = query.to_lowercase();
    let chars: Vec<char> = name.chars().collect();
    for len in (4..chars.len()).rev() {
        if !close.is_empty() {
            break;
        }

        let start: String = chars[..len].iter().collect();
        close = index.chunks().iter().filter(|c| kinds.contains(&c.kind) && c.key.to_lowercase().contains(&start)).take(5).map(|c| format!("`{}`", c.key)).collect();
    }

    match close.is_empty() {
        true => Answer::error(format!("no {what} `{query}`")),
        false => Answer::error(format!("no {what} `{query}`. Did you mean {}?", close.join(", "))),
    }
}
