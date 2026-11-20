//! The texts for the coding agents, with the version of the installed code: the docs of phem
//! (embedded in the binary), the component catalog of the app and of the packages, the examples
//! and the guides of the packages, and a search over all of them.
//!
//! Each text has a stable key: a doc section (`events#debounce`), a component or an example
//! (its FQCN), or a section of a guide (`acme/ui#naming`). `phemc docs`, `phemc components`,
//! `phemc examples` and `phemc search` show them.

pub mod catalog;
pub mod commands;
pub mod docs;
pub mod examples;
mod imports;
pub mod markdown;
mod scanned;
pub mod search;

use catalog::Catalog;
use docs::Library;
use examples::Example;
use phemc_codegen::SourceInput;
use phemc_sources::SourceRoot;
use search::{Chunk, Index};
use std::fmt;
use std::path::Path;

#[derive(Debug)]
pub struct Error(String);

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for Error {}

impl From<phemc_sources::Error> for Error {
    fn from(e: phemc_sources::Error) -> Self {
        Error(e.to_string())
    }
}

#[derive(Clone, Debug, Default)]
pub struct Knowledge {
    pub library: Library,
    pub catalog: Catalog,
    pub examples: Vec<Example>,
}

impl Knowledge {
    /// The docs of phem only (no project).
    pub fn embedded() -> Self {
        Knowledge { library: Library::embedded(), ..Default::default() }
    }

    /// The docs of phem, and the components, the examples and the guides of the roots of a
    /// project (see `phemc_sources::roots`).
    pub fn load(project: &Path, roots: &[SourceRoot]) -> Result<Self, Error> {
        let mut inputs: Vec<(SourceInput, bool)> = phemc_sources::read(roots, &Default::default())?.into_iter().map(|i| (i, false)).collect();
        let mut library = Library::embedded();

        for root in roots {
            let Some(package) = &root.package else { continue };

            if root.examples.is_some() {
                let examples = phemc_sources::examples_root(project, roots, package)?;
                inputs.extend(phemc_sources::read(&[examples], &Default::default())?.into_iter().map(|i| (i, true)));
            }

            if let Some(guide) = &root.guide {
                library.add_guide(package, &phemc_sources::read_file(guide)?);
            }
        }

        let files = scanned::scan(inputs);
        let php = php_enums(roots);
        let enums = scanned::enums(files.iter().map(|f| &f.info).chain(&php));

        Ok(Knowledge { library, catalog: Catalog::build(&files, &enums), examples: examples::examples(&files) })
    }

    /// The examples that use a component (a FQCN): (key, title).
    pub fn examples_of(&self, fqcn: &str) -> Vec<(String, String)> {
        self.examples.iter().filter(|e| e.uses(fqcn)).map(|e| (e.key.clone(), e.title())).collect()
    }

    /// The units of the search.
    pub fn chunks(&self) -> Vec<Chunk> {
        let mut out = Vec::new();

        for d in &self.library.docs {
            let kind = if d.package.is_some() { "guide" } else { "doc" };
            out.push(Chunk { key: d.key.clone(), kind, context: d.context_of(None), text: d.md.intro.clone(), scored_context: true });
            for s in &d.md.sections {
                out.push(Chunk { key: d.section_key(s), kind, context: d.context_of(Some(s)), text: d.md.body_of(s).to_string(), scored_context: true });
            }
        }

        for c in &self.catalog.components {
            let props: Vec<&str> = c.props.iter().map(|p| p.name.as_str()).collect();
            let mut text = format!("{}\n\nProps: {}\n\nAlso called: {}", c.doc.join("\n\n"), props.join(", "), c.aliases.join(", "));

            // A root is the whole family: a search for the family finds it before its parts.
            if c.is_root() {
                let parts: Vec<&str> = self.catalog.family_of(c).filter(|p| !p.is_root()).map(|p| p.name.as_str()).collect();
                text.push_str(&format!("\n\nParts: {}", parts.join(", ")));
            }

            out.push(Chunk { key: c.fqcn.clone(), kind: "component", context: self.catalog.context_of(c), text, scored_context: false });
        }

        for e in &self.examples {
            // The aliases of the family find its examples too.
            let aliases = self.catalog.components.iter().filter(|c| c.package == e.package && c.name == e.family).flat_map(|c| c.aliases.clone()).collect::<Vec<_>>();
            // The context lists the components that the example uses: it would rank the
            // example with each of them, so the index reads only the family.
            let text = format!("{}\n\nExample of {}. {}", e.doc.join("\n\n"), e.family, aliases.join(", "));
            out.push(Chunk { key: e.key.clone(), kind: "example", context: e.context(), text, scored_context: false });
        }

        out
    }

    pub fn index(&self) -> Index {
        Index::new(self.chunks())
    }

    /// The section keys of the guide of each package: (package, keys).
    pub fn guides(&self) -> Vec<(String, Vec<String>)> {
        self.library.docs.iter().filter_map(|d| Some((d.package.clone()?, d.md.sections.iter().filter(|s| s.depth == 2).map(|s| d.section_key(s)).collect()))).collect()
    }
}

/// The enums of the `.php` files of the roots: a prop can have the type of an enum of a PHP
/// file.
fn php_enums(roots: &[SourceRoot]) -> Vec<phemc_analyze::FileInfo> {
    let mut out = Vec::new();
    for root in roots {
        let walk = walkdir::WalkDir::new(&root.dir).sort_by_file_name().into_iter().filter_entry(|e| !matches!(e.file_name().to_str(), Some("vendor" | "node_modules" | ".git")));
        for entry in walk.filter_map(Result::ok) {
            let path = entry.path();
            if !entry.file_type().is_file() || path.extension().is_none_or(|x| x != "php") {
                continue;
            }

            let Ok(text) = std::fs::read_to_string(path) else { continue };
            if !text.contains("enum ") {
                continue;
            }

            // The lexer reads a `.phem` file: the declarations of a PHP file are the same.
            let text = text.replacen(phemc_syntax::php::PHP_OPEN_TAG, phemc_syntax::php::OPEN_TAG, 1);
            if let Ok(lexed) = phemc_syntax::php::lex(&text) {
                out.push(phemc_analyze::scan(&path.to_string_lossy(), &text, &lexed));
            }
        }
    }

    out
}
