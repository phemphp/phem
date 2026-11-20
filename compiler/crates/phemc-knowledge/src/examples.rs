//! The examples of the packages (`extra.phem.examples`): `.phem` files with one `#[Component]`
//! for each use case. The summary of the docblock is the title of an example, and the rest says
//! when to use it. The components that it uses come from its tags.
//!
//! A component of the examples that another example uses (an icon, a part of a demo) is a
//! helper: it is not an example of its own, and its code goes with the examples that use it,
//! with the constants that they read.

use crate::catalog::short;
use crate::imports::{has_word, use_block, used_imports, Import};
use crate::scanned::{tags_in, Scanned};
use phemc_syntax::doc::{doc_before, doc_tags};
use phemc_syntax::php::TokKind;
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
    /// The FQCN of the components that the example uses, in the order of the source, with the
    /// components of its helpers.
    pub uses: Vec<String>,
    /// The `use` statements that the code of the example, of its constants and of its helpers
    /// needs, as their files write them.
    pub imports: Vec<String>,
    /// The source of the example, with its attributes and without its docblock.
    pub code: String,
    /// The source of the constants and of the classes (not components) that the example and its
    /// helpers use, in the order of the file.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub declarations: Vec<String>,
    /// The source of the helpers that the example uses, in the order of the file.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub helpers: Vec<String>,
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

        // As on the demo site: the imports, the constants and the classes, the example, then its
        // helpers.
        let (functions, classes): (Vec<&String>, Vec<&String>) = self.imports.iter().partition(|i| i.starts_with("use function "));
        let groups: Vec<String> = [classes, functions].into_iter().filter(|g| !g.is_empty()).map(|g| g.iter().map(|i| i.as_str()).collect::<Vec<_>>().join("\n")).collect();
        let imports = groups.join("\n\n");
        let code: Vec<&str> = std::iter::once("<?phem")
            .chain((!imports.is_empty()).then_some(imports.as_str()))
            .chain(self.declarations.iter().map(String::as_str))
            .chain(std::iter::once(self.code.as_str()))
            .chain(self.helpers.iter().map(String::as_str))
            .collect();
        out.push_str(&format!("Uses: {}\nSource: {}\n\n```php\n{}\n```\n", self.uses_names().join(", "), self.path, code.join("\n\n")));

        out
    }
}

/// A component or a constant of an example file.
struct Part {
    fqcn: Option<String>,
    /// The name that the code uses: the short name, or the name of a constant.
    name: String,
    code: String,
    /// The imports of the file that the code uses.
    imports: Vec<Import>,
    start: usize,
    doc: String,
    uses: Vec<String>,
    package: Option<String>,
    family: String,
    path: String,
}

/// The examples in the example files.
pub fn examples(files: &[Scanned]) -> Vec<Example> {
    let parts: Vec<Part> = files.iter().filter(|f| f.example).flat_map(parts_of).collect();
    let components: Vec<&Part> = parts.iter().filter(|p| p.fqcn.is_some()).collect();

    // A helper: another example of the package uses it.
    let is_helper = |p: &Part| components.iter().any(|o| o.fqcn != p.fqcn && o.package == p.package && o.uses.iter().any(|u| Some(u) == p.fqcn.as_ref()));

    let mut out = Vec::new();
    for p in components.iter().filter(|p| !is_helper(p)) {
        let fqcn = p.fqcn.clone().unwrap_or_default();
        let helpers = helpers_of(p, &parts);
        let mut uses = p.uses.clone();
        for h in &helpers {
            for u in &h.uses {
                if !uses.contains(u) && h.fqcn.as_ref() != Some(u) {
                    uses.push(u.clone());
                }
            }
        }

        uses.retain(|u| u != &fqcn && !helpers.iter().any(|h| h.fqcn.as_ref() == Some(u)));

        // The imports of the example and of its helpers, once each.
        let mut imports: Vec<Import> = Vec::new();
        for i in std::iter::once(*p).chain(helpers.iter().copied()).flat_map(|part| &part.imports) {
            if !imports.contains(i) {
                imports.push(i.clone());
            }
        }

        out.push(Example {
            name: short(&fqcn).to_string(),
            key: fqcn,
            package: p.package.clone(),
            family: p.family.clone(),
            path: p.path.clone(),
            doc: doc_tags(&p.doc).paragraphs,
            uses,
            imports: use_block(&imports),
            code: p.code.clone(),
            declarations: helpers.iter().filter(|h| h.fqcn.is_none()).map(|h| h.code.clone()).collect(),
            helpers: helpers.iter().filter(|h| h.fqcn.is_some()).map(|h| h.code.clone()).collect(),
        });
    }

    out
}

/// The helpers, the constants and the classes that an example uses, also through another
/// helper: the components of the examples of its package (in any file), and the constants and
/// the classes of the file of the code that uses them. In the order of the files.
fn helpers_of<'a>(example: &Part, parts: &'a [Part]) -> Vec<&'a Part> {
    let mut found: Vec<&Part> = Vec::new();
    let mut pending: Vec<(&str, &str)> = vec![(example.code.as_str(), example.path.as_str())];

    while let Some((code, path)) = pending.pop() {
        for p in parts {
            let reachable = match p.fqcn {
                Some(_) => p.package == example.package,
                None => p.path == path,
            };

            if !reachable || p.name == example.name || found.iter().any(|f| f.name == p.name && f.path == p.path) || !has_word(code, &p.name) {
                continue;
            }

            found.push(p);
            pending.push((p.code.as_str(), p.path.as_str()));
        }
    }

    found.sort_by(|a, b| a.path.cmp(&b.path).then(a.start.cmp(&b.start)));

    found
}

/// The components and the top-level constants of an example file.
fn parts_of(file: &Scanned) -> Vec<Part> {
    let text = &file.input.text;
    let family = file.input.rel.rsplit('/').next().unwrap_or_default().trim_end_matches(".phem").to_string();
    let part = |fqcn: Option<String>, name: String, span: Span, doc: String, uses: Vec<String>| {
        let code = text[declaration_start(text, span.start, &doc)..span.end].to_string();

        Part { fqcn, name, imports: used_imports(&file.info, &code), code, start: span.start, doc, uses, package: file.input.package.clone(), family: family.clone(), path: file.input.path.clone() }
    };

    let functions = file.info.functions.iter().filter(|f| f.component_kind.is_some()).map(|f| (&f.fqn, f.span));
    let classes = file.info.classes.iter().filter(|c| c.is_component).map(|c| (&c.fqn, c.span));
    let mut out: Vec<Part> = functions
        .chain(classes)
        .map(|(fqcn, span)| {
            let uses = tags_in(file, span).into_iter().filter(|u| u != fqcn).collect();
            part(Some(fqcn.clone()), short(fqcn).to_string(), span, doc_before(text, span.start), uses)
        })
        .collect();

    // The constants and the classes that are not components: the code of the file of the part
    // that uses them.
    out.extend(constants(file).into_iter().map(|(name, span)| part(None, name, span, String::new(), vec![])));
    for c in file.info.classes.iter().filter(|c| !c.is_component) {
        out.push(part(None, c.name.clone(), c.span, doc_before(text, c.span.start), vec![]));
    }

    out.sort_by_key(|p| p.start);

    out
}

/// The top-level constants of a file (`const NAME = ...;` at the start of a line): name and span.
fn constants(file: &Scanned) -> Vec<(String, Span)> {
    let Some(lexed) = &file.lexed else { return vec![] };

    let text = &file.input.text;
    let tokens: Vec<_> = lexed.tokens.iter().filter(|t| !t.is_trivia()).collect();
    let mut out = Vec::new();

    for (i, t) in tokens.iter().enumerate() {
        let at_line_start = t.span.start == 0 || text[..t.span.start].ends_with('\n');
        if t.kind != TokKind::Ident || t.text(text) != "const" || !at_line_start {
            continue;
        }

        let Some(name) = tokens.get(i + 1).filter(|n| n.kind == TokKind::Ident) else { continue };

        let mut depth = 0i32;
        for end in &tokens[i + 2..] {
            match (end.kind, end.text(text)) {
                (TokKind::Op, "(" | "[" | "{") => depth += 1,
                (TokKind::Op, ")" | "]" | "}") => depth -= 1,
                (TokKind::Op, ";") if depth == 0 => {
                    out.push((name.text(text).to_string(), Span::new(t.span.start, end.span.end)));
                    break;
                }
                _ => {}
            }
        }
    }

    out
}

/// The start of a declaration: after its docblock, at its attributes (`#[Component]`).
fn declaration_start(text: &str, keyword: usize, doc: &str) -> usize {
    if !doc.is_empty() {
        if let Some(at) = text[..keyword].rfind(doc) {
            return (at + doc.len()..keyword).find(|&i| !text[i..].starts_with(char::is_whitespace)).unwrap_or(keyword);
        }
    }

    // The attribute lines above the keyword.
    let mut start = text[..keyword].rfind('\n').map_or(0, |i| i + 1);
    while start > 0 {
        let prev = text[..start - 1].rfind('\n').map_or(0, |i| i + 1);
        if !text[prev..start].trim_start().starts_with("#[") {
            break;
        }

        start = prev;
    }

    start
}
