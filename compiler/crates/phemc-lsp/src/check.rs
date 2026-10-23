//! The compiler diagnostics of a project. The check compiles all the `.phpm` files of the source
//! directory, because a file can use the components of the other files. The open documents
//! replace the files on disk.

use phemc_codegen::SourceInput;
use phemc_syntax::Diagnostic;
use std::collections::HashMap;
use std::path::{Path, PathBuf};

pub struct FileCheck {
    pub file: PathBuf,
    /// The text that the compiler checked.
    pub text: String,
    pub diags: Vec<Diagnostic>,
}

/// Checks the `.phpm` files of `src`. `open` has the text of the open documents.
pub fn check(src: &Path, open: &HashMap<PathBuf, String>) -> Vec<FileCheck> {
    let mut inputs = Vec::new();
    for entry in walkdir::WalkDir::new(src).sort_by_file_name().into_iter().filter_map(Result::ok) {
        let path = entry.path();
        if !entry.file_type().is_file() || path.extension().is_none_or(|e| e != "phpm") {
            continue;
        }

        let Ok(rel) = path.strip_prefix(src) else { continue };
        let text = match open.get(path) {
            Some(t) => t.clone(),
            None => match std::fs::read_to_string(path) {
                Ok(t) => t,
                Err(_) => continue,
            },
        };

        inputs.push(SourceInput { rel: rel.to_string_lossy().replace('\\', "/"), text });
    }

    let (_, files) = phemc_codegen::compile(inputs);
    files.into_iter().map(|f| FileCheck { file: src.join(&f.rel), text: f.text, diags: f.diags }).collect()
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
