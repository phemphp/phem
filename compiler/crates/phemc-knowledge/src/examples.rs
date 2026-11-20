//! The examples of the packages (`extra.phem.examples`): `.phem` files with one `#[Component]`
//! for each use case. The summary of the docblock is the title of an example, and the rest says
//! when to use it. The components that it uses come from its tags.

use crate::catalog::short;
use crate::scanned::{tags_in, Scanned};
use phemc_syntax::doc::{doc_before, doc_tags};
use phemc_syntax::Span;
use serde::Serialize;

#[derive(Clone, Debug, Serialize)]
pub struct Example {
    /// The FQCN of the example component.
    pub key: String,
    pub name: String,
    pub package: Option<String>,
    /// The name of the file: the family that the example shows (`Dialog`).
    pub family: String,
    pub path: String,
    /// The docblock, by paragraph: the first one is the title.
    pub doc: Vec<String>,
    /// The FQCN of the components that the example uses, in the order of the source.
    pub uses: Vec<String>,
    /// The source of the example, with its docblock.
    pub code: String,
}

impl Example {
    pub fn title(&self) -> String {
        self.doc.first().cloned().unwrap_or_else(|| self.name.clone())
    }

    pub fn package_name(&self) -> &str {
        self.package.as_deref().unwrap_or("app")
    }

    /// Whether the example uses a component (a FQCN).
    pub fn uses(&self, fqcn: &str) -> bool {
        self.uses.iter().any(|u| u.eq_ignore_ascii_case(fqcn))
    }

    /// The short names of the components that the example uses.
    pub fn uses_names(&self) -> Vec<&str> {
        self.uses.iter().map(|u| short(u)).collect()
    }

    /// The context for the search: `DialogFormDemo — example of Dialog (acme/ui), uses Dialog,
    /// DialogPopup`.
    pub fn context(&self) -> String {
        format!("{} — example of {} ({}), uses {}", self.name, self.family, self.package_name(), self.uses_names().join(", "))
    }

    /// `phemc examples <key>`: the example with its code.
    pub fn md(&self) -> String {
        let mut out = format!("# {} — {} ({})\n\n", self.name, self.key, self.package_name());
        for p in &self.doc {
            out.push_str(p);
            out.push_str("\n\n");
        }

        out.push_str(&format!("Uses: {}\nSource: {}\n\n```php\n{}\n```\n", self.uses_names().join(", "), self.path, self.code));

        out
    }
}

/// The examples in the example files.
pub fn examples(files: &[Scanned]) -> Vec<Example> {
    let mut out = Vec::new();
    for file in files.iter().filter(|f| f.example) {
        let family = file.input.rel.rsplit('/').next().unwrap_or_default().trim_end_matches(".phem").to_string();
        let functions = file.info.functions.iter().filter(|f| f.component_kind.is_some()).map(|f| (&f.fqn, f.span));
        let classes = file.info.classes.iter().filter(|c| c.is_component).map(|c| (&c.fqn, c.span));

        let mut found: Vec<(&String, Span)> = functions.chain(classes).collect();
        found.sort_by_key(|(_, span)| span.start);

        for (fqcn, span) in found {
            let doc = doc_before(&file.input.text, span.start);
            let start = if doc.is_empty() { span.start } else { file.input.text[..span.start].rfind(&doc).unwrap_or(span.start) };
            let uses = tags_in(file, span).into_iter().filter(|u| u != fqcn).collect();

            out.push(Example {
                key: fqcn.clone(),
                name: short(fqcn).to_string(),
                package: file.input.package.clone(),
                family: family.clone(),
                path: file.input.path.clone(),
                doc: doc_tags(&doc).paragraphs,
                uses,
                code: file.input.text[start..span.end].to_string(),
            });
        }
    }

    out
}
