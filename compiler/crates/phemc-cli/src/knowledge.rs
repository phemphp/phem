//! `phemc docs`, `phemc components`, `phemc examples` and `phemc search`: the texts for the
//! coding agents (see `phemc_knowledge`). They run from the project root.

use crate::{ProjectArgs, TextFormat};
use anyhow::{Context, Result};
use phemc_knowledge::catalog::Catalog;
use phemc_knowledge::search::{parse_eval, EvalReport};
use phemc_knowledge::Knowledge;
use serde_json::{json, Value};
use std::path::Path;

/// The knowledge of the project. With no project (no source dir and no package), only the docs
/// of phem when `required` is false.
fn load(project: &ProjectArgs, required: bool) -> Result<Knowledge> {
    let roots = match phemc_sources::roots_or_app(Path::new("."), &project.src) {
        Ok((roots, None)) => roots,
        Ok((roots, Some(e))) => {
            eprintln!("phemc: {e}: only the app is read");
            roots
        }
        Err(e) if required => return Err(e.into()),
        Err(e) => {
            eprintln!("phemc: {e}: only the docs of phem are read");
            return Ok(Knowledge::embedded());
        }
    };

    Ok(Knowledge::load(Path::new("."), &roots)?)
}

fn print(format: TextFormat, md: impl FnOnce() -> String, json: impl FnOnce() -> Value) {
    match format {
        TextFormat::Md => print!("{}", md()),
        TextFormat::Json => println!("{}", serde_json::to_string_pretty(&json()).unwrap_or_default()),
    }
}

/// A missing key: an error with the keys of the closest texts of a kind.
fn not_found(knowledge: &Knowledge, what: &str, query: &str, kinds: &[&str]) -> bool {
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
        true => eprintln!("phemc: no {what} `{query}`"),
        false => eprintln!("phemc: no {what} `{query}`. Did you mean {}?", close.join(", ")),
    }

    false
}

pub fn docs(key: Option<&str>, toc: bool, project: &ProjectArgs) -> Result<bool> {
    // Only a package guide needs the project.
    let knowledge = match key {
        Some(k) if k.contains('/') => load(project, true)?,
        _ => Knowledge::embedded(),
    };

    let lib = &knowledge.library;
    let Some(key) = key else {
        match toc {
            true => print(project.format, || lib.toc_md(), || lib.toc_json()),
            false => print(project.format, || lib.index_md(), || lib.index_json()),
        }

        return Ok(true);
    };

    let Some(found) = lib.find(key) else {
        return Ok(not_found(&knowledge, "doc", key, &["doc", "guide"]));
    };

    let page = found.page();
    print(project.format, || page.md(), || serde_json::to_value(&page).unwrap_or_default());

    Ok(true)
}

pub fn components(name: Option<&str>, package: Option<&str>, project: &ProjectArgs) -> Result<bool> {
    let knowledge = load(project, true)?;
    let catalog = &knowledge.catalog;

    let Some(name) = name else {
        let only = Catalog { components: catalog.components.iter().filter(|c| package.is_none_or(|p| c.package_name() == p)).cloned().collect() };
        let guides = knowledge.guides();
        print(project.format, || only.summary_md(&guides), || only.summary_json());

        return Ok(true);
    };

    match catalog.find(name, package).as_slice() {
        [] => Ok(not_found(&knowledge, "component", name, &["component"])),
        [c] => {
            let examples = knowledge.examples_of(&c.fqcn);
            print(project.format, || catalog.detail_md(c, &examples), || catalog.detail_json(c, &examples));

            Ok(true)
        }
        many => {
            let names: Vec<String> = many.iter().map(|c| format!("`{}` ({})", c.fqcn, c.package_name())).collect();
            eprintln!("phemc: several components are `{name}`: {}. Give the FQCN or `--package`.", names.join(", "));

            Ok(false)
        }
    }
}

pub fn examples(name: Option<&str>, package: Option<&str>, project: &ProjectArgs) -> Result<bool> {
    let knowledge = load(project, true)?;
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
        print(project.format, md, || Value::Array(list.iter().map(|e| json!({"key": e.key, "title": e.title(), "package": e.package_name(), "uses": e.uses})).collect()));

        return Ok(true);
    };

    let key = name.trim_start_matches('\\');
    let example = knowledge.examples.iter().filter(|e| in_package(e.package_name())).find(|e| e.key.eq_ignore_ascii_case(key) || e.name.eq_ignore_ascii_case(key));
    if let Some(e) = example {
        print(project.format, || e.md(), || serde_json::to_value(e).unwrap_or_default());

        return Ok(true);
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
            print(project.format, md, || Value::Array(list.iter().map(|(key, title)| json!({"key": key, "title": title})).collect()));

            Ok(true)
        }
        _ => Ok(not_found(&knowledge, "example or component", name, &["example", "component"])),
    }
}

pub fn search(query: Option<&str>, eval: Option<&Path>, limit: usize, project: &ProjectArgs) -> Result<bool> {
    let knowledge = load(project, false)?;
    let index = knowledge.index();

    if let Some(file) = eval {
        let text = std::fs::read_to_string(file).with_context(|| format!("cannot read {}", file.display()))?;
        let cases = parse_eval(&text).map_err(|e| anyhow::anyhow!("{}: {e}", file.display()))?;
        let report = EvalReport::run(&index, &cases, limit);
        match project.format {
            TextFormat::Md => eprint!("{}", report.md()),
            TextFormat::Json => println!("{}", serde_json::to_string_pretty(&report).unwrap_or_default()),
        }

        return Ok(report.passed());
    }

    let query = query.unwrap_or_default();
    let hits = index.search(query, limit);
    print(project.format, || phemc_knowledge::search::Hit::md(&hits, query), || serde_json::to_value(&hits).unwrap_or_default());

    Ok(true)
}
