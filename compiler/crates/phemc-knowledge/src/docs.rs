//! The docs: the docs of phem, embedded in the binary, and the guides of the packages
//! (`extra.phem.guide`). A key is the slug of a doc (`events`) or the name of a package
//! (`acme/ui`), with the anchor of a section (`events#debounce`).

use crate::markdown::{first_sentence, Markdown, Section};
use serde::Serialize;
use serde_json::{json, Value};

include!(concat!(env!("OUT_DIR"), "/docs.rs"));

/// A doc of phem, or the guide of a package.
#[derive(Clone, Debug)]
pub struct Doc {
    /// The slug of a doc, or the name of a package.
    pub key: String,
    /// The package of a guide.
    pub package: Option<String>,
    pub md: Markdown,
}

impl Doc {
    pub fn title(&self) -> String {
        match (&self.package, self.md.title.is_empty()) {
            (Some(p), true) => format!("{p} guide"),
            _ => self.md.title.clone(),
        }
    }

    /// The first sentence of the doc.
    pub fn summary(&self) -> String {
        first_sentence(&self.md.intro)
    }

    pub fn section_key(&self, section: &Section) -> String {
        format!("{}#{}", self.key, section.anchor)
    }

    /// The context of a section: `Events › Debounce — <the first sentence of the doc>`.
    pub fn context_of(&self, section: Option<&Section>) -> String {
        let mut path = vec![self.title()];
        if let Some(s) = section {
            path.extend(s.parent.clone());
            path.push(s.title.clone());
        }

        let summary = crate::markdown::clip(&self.summary(), 120);
        match summary.is_empty() {
            true => path.join(" › "),
            false => format!("{} — {summary}", path.join(" › ")),
        }
    }
}

/// The docs and the guides.
#[derive(Clone, Debug, Default)]
pub struct Library {
    pub docs: Vec<Doc>,
}

/// A doc, or one section of it.
pub struct Found<'a> {
    pub doc: &'a Doc,
    pub section: Option<&'a Section>,
}

impl Library {
    /// The docs of phem.
    pub fn embedded() -> Self {
        Self::of(DOCS.iter().map(|(slug, text)| (slug.to_string(), None, text.to_string())))
    }

    /// The docs with (key, package, Markdown).
    pub fn of(docs: impl IntoIterator<Item = (String, Option<String>, String)>) -> Self {
        Library { docs: docs.into_iter().map(|(key, package, text)| Doc { key, package, md: Markdown::parse(&text) }).collect() }
    }

    /// Adds the guide of a package.
    pub fn add_guide(&mut self, package: &str, text: &str) {
        self.docs.push(Doc { key: package.to_string(), package: Some(package.to_string()), md: Markdown::parse(text) });
    }

    /// The doc or the section of a key.
    pub fn find(&self, key: &str) -> Option<Found<'_>> {
        let (slug, anchor) = key.split_once('#').map_or((key, None), |(s, a)| (s, Some(a)));
        let doc = self.docs.iter().find(|d| d.key.eq_ignore_ascii_case(slug))?;
        let section = match anchor {
            Some(a) => Some(doc.md.section(a)?),
            None => None,
        };

        Some(Found { doc, section })
    }

    /// The docs of phem, without the guides.
    pub fn phem_docs(&self) -> impl Iterator<Item = &Doc> {
        self.docs.iter().filter(|d| d.package.is_none())
    }

    /// `phemc docs`: one line for each doc.
    pub fn index_md(&self) -> String {
        let mut out = String::from("# Phem docs\n\nRead a doc with `phemc docs <key>`, and a section with `phemc docs <key>#<section>` (see `phemc docs --toc`).\n\n");
        for d in self.phem_docs() {
            out.push_str(&format!("- `{}` {}: {}\n", d.key, d.title(), d.summary()));
        }

        out
    }

    pub fn index_json(&self) -> Value {
        Value::Array(self.phem_docs().map(|d| json!({"key": d.key, "title": d.title(), "summary": d.summary()})).collect())
    }

    /// `phemc docs --toc`: the key of each section.
    pub fn toc_md(&self) -> String {
        let mut out = String::from("# Phem docs: sections\n\n");
        for d in self.phem_docs() {
            out.push_str(&format!("- `{}` {}\n", d.key, d.title()));
            for s in &d.md.sections {
                let indent = if s.depth == 3 { "    " } else { "  " };
                out.push_str(&format!("{indent}- `{}` {}\n", d.section_key(s), s.title));
            }
        }

        out
    }

    pub fn toc_json(&self) -> Value {
        let docs = self.phem_docs().map(|d| {
            let sections: Vec<Value> = d.md.sections.iter().map(|s| json!({"key": d.section_key(s), "title": s.title, "depth": s.depth})).collect();
            json!({"key": d.key, "title": d.title(), "sections": sections})
        });

        Value::Array(docs.collect())
    }
}

/// A doc or a section, as `phemc docs <key>` shows it.
#[derive(Serialize)]
pub struct Page {
    pub key: String,
    pub context: String,
    pub text: String,
}

impl Found<'_> {
    pub fn page(&self) -> Page {
        let (key, text) = match self.section {
            Some(s) => (self.doc.section_key(s), self.doc.md.text_of(s, true).to_string()),
            None => (self.doc.key.clone(), self.doc.md.text.trim().to_string()),
        };

        Page { key, context: self.doc.context_of(self.section), text }
    }
}

impl Page {
    /// The context in one line above the text.
    pub fn md(&self) -> String {
        format!("<!-- {} | {} -->\n\n{}\n", self.key, self.context, self.text)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn embeds_the_docs_of_phem() {
        let lib = Library::embedded();
        let keys: Vec<_> = lib.docs.iter().map(|d| d.key.as_str()).collect();

        assert!(keys.contains(&"events") && keys.contains(&"syntax"));
        assert!(!keys.contains(&"contributing") && !keys.contains(&"releasing"));
        assert!(keys.windows(2).all(|w| w[0] < w[1]));
    }

    #[test]
    fn finds_a_doc_or_a_section() {
        let mut lib = Library::of([("events".to_string(), None, "# Events\n\nServer events. More.\n\n## Debounce\n\nWait.\n\n### Bound\n\nB.\n".to_string())]);
        lib.add_guide("acme/ui", "# Acme UI\n\nThe components of Acme.\n\n## Naming\n\nUse Acme.\n");

        let page = lib.find("events#debounce").unwrap().page();
        assert_eq!((page.key.as_str(), page.context.as_str()), ("events#debounce", "Events › Debounce — Server events."));
        assert_eq!(page.text, "## Debounce\n\nWait.\n\n### Bound\n\nB.");

        assert_eq!(lib.find("events#bound").unwrap().page().context, "Events › Debounce › Bound — Server events.");
        assert_eq!(lib.find("acme/ui#naming").unwrap().page().md(), "<!-- acme/ui#naming | Acme UI › Naming — The components of Acme. -->\n\n## Naming\n\nUse Acme.\n");
        assert!(lib.find("events#missing").is_none() && lib.find("missing").is_none());

        // The guides are not in the index of the docs of phem.
        assert_eq!(lib.toc_md(), "# Phem docs: sections\n\n- `events` Events\n  - `events#debounce` Debounce\n    - `events#bound` Bound\n");
    }
}
