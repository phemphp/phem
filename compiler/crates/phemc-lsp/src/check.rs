//! The compiler diagnostics of a project. The check compiles all the `.phpm` files of the source
//! roots of the project (see `crate::sources`): the source directory of the app and the Composer
//! packages, because a file can use the components of the other files. The open documents replace
//! the files on disk.

use crate::sources::Roots;
use phemc_sources::SourceRoot;
use phemc_syntax::Diagnostic;
use std::collections::HashMap;
use std::path::{Path, PathBuf};

pub struct FileCheck {
    pub file: PathBuf,
    /// The text that the compiler checked.
    pub text: String,
    /// The diagnostics that the tools show: only the errors of a package file.
    pub diags: Vec<Diagnostic>,
}

pub struct Checked {
    pub files: Vec<FileCheck>,
    /// Why the check could not read the packages (a wrong `extra.phem.source`, a broken
    /// `installed.json`). The check then has only the files of the app.
    pub error: Option<String>,
}

/// Checks the project in `root` with its source roots. `open` has the text of the open documents.
/// The files of the installed packages come from the kept texts of the roots, and the check keeps
/// the texts that it reads.
pub fn check(root: &Path, roots: &mut Roots, open: &HashMap<PathBuf, String>) -> Checked {
    let mut error = roots.error.clone();
    let texts = &mut roots.texts;
    let read = phemc_sources::read_with(&roots.roots, |source, file, inner| {
        if let Some(text) = phemc_sources::open_text(open, source, file, inner).or_else(|| texts.get(file)) {
            return Ok(text.clone());
        }

        let text = phemc_sources::read_file(file)?;
        if source.installed {
            texts.insert(file.to_path_buf(), text.clone());
        }

        Ok(text)
    });

    let inputs = read.unwrap_or_else(|e| {
        error = Some(e.to_string());
        vec![]
    });

    let (_, files) = phemc_codegen::compile(inputs);
    let files = files
        .into_iter()
        .map(|f| {
            let file = editor_path(root, &roots.roots, &f.path, f.package.as_deref(), open);
            FileCheck { file, diags: f.shown().cloned().collect(), text: f.text }
        })
        .collect();

    Checked { files, error }
}

/// The path of a file as the editor opens it: the real path of a file of a linked package (see
/// `SourceRoot::real_dir`), unless a document is open at its path in `vendor/`.
fn editor_path(root: &Path, roots: &[SourceRoot], path: &str, package: Option<&str>, open: &HashMap<PathBuf, String>) -> PathBuf {
    let file = root.join(path);
    if package.is_none() || open.contains_key(&file) {
        return file;
    }

    let linked = roots.iter().find(|r| r.package.as_deref() == package).and_then(|r| Some((r.real_dir.as_ref()?, path.strip_prefix(&r.path)?.trim_start_matches('/'))));

    match linked {
        Some((real_dir, inner)) => real_dir.join(inner),
        None => file,
    }
}

/// The message of a diagnostic, with its label and its help.
pub fn message(d: &Diagnostic) -> String {
    let mut m = d.message.clone();
    if let Some(label) = &d.label {
        m += &format!(" ({label})");
    }

    if let Some(help) = &d.help {
        m += &format!("\nhelp: {help}");
    }

    m
}

/// The end of a span. An empty span gets the next character, as in the text format of phemc.
pub fn end_of(text: &str, start: usize, end: usize) -> usize {
    if end > start {
        return end;
    }

    text.get(start..).and_then(|rest| rest.chars().next()).map_or(start, |c| start + c.len_utf8())
}
