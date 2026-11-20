//! `phemc docs`, `phemc components`, `phemc examples` and `phemc search`: the texts for the
//! coding agents (see `phemc_knowledge::commands`). They run from the project root.

use crate::{ProjectArgs, TextFormat};
use anyhow::{Context, Result};
use phemc_knowledge::commands::{self, Answer, Format};
use phemc_knowledge::search::{parse_eval, EvalReport};
use phemc_knowledge::Knowledge;
use std::path::Path;

/// The knowledge of a project. With no project (no source dir and no package), only the docs of
/// phem when `required` is false. The warnings go to stderr.
pub fn load(project: &Path, src: &Path, required: bool) -> Result<Knowledge> {
    let roots = match phemc_sources::roots_or_app(project, src) {
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

    Ok(Knowledge::load(project, &roots)?)
}

fn format(project: &ProjectArgs) -> Format {
    match project.format {
        TextFormat::Md => Format::Md,
        TextFormat::Json => Format::Json,
    }
}

/// Prints the text of a command on stdout, or its error on stderr.
fn print(answer: Answer) -> bool {
    match answer.ok {
        true => print!("{}", answer.text),
        false => eprintln!("phemc: {}", answer.text),
    }

    answer.ok
}

pub fn docs(key: Option<&str>, toc: bool, project: &ProjectArgs) -> Result<bool> {
    // Only a package guide needs the project.
    let knowledge = if commands::doc_needs_project(key) { load(Path::new("."), &project.src, true)? } else { Knowledge::embedded() };

    Ok(print(commands::docs(&knowledge, key, toc, format(project))))
}

pub fn components(name: Option<&str>, package: Option<&str>, project: &ProjectArgs) -> Result<bool> {
    Ok(print(commands::components(&load(Path::new("."), &project.src, true)?, name, package, format(project))))
}

pub fn examples(name: Option<&str>, package: Option<&str>, project: &ProjectArgs) -> Result<bool> {
    Ok(print(commands::examples(&load(Path::new("."), &project.src, true)?, name, package, format(project))))
}

pub fn search(query: Option<&str>, eval: Option<&Path>, limit: usize, project: &ProjectArgs) -> Result<bool> {
    let knowledge = load(Path::new("."), &project.src, false)?;

    let Some(file) = eval else {
        return Ok(print(commands::search(&knowledge, query.unwrap_or_default(), limit, format(project))));
    };

    let text = std::fs::read_to_string(file).with_context(|| format!("cannot read {}", file.display()))?;
    let cases = parse_eval(&text).map_err(|e| anyhow::anyhow!("{}: {e}", file.display()))?;
    let report = EvalReport::run(&knowledge.index(), &cases, limit);
    match project.format {
        TextFormat::Md => eprint!("{}", report.md()),
        TextFormat::Json => println!("{}", serde_json::to_string_pretty(&report).unwrap_or_default()),
    }

    Ok(report.passed())
}
