//! Emits PHP and Twig from `.phpm` files, and the project manifest.
//!
//! [`compile`] runs in two passes: it scans all files to build the component registry, then
//! it generates each file. Markup literals become Twig templates. In the PHP code, each markup
//! literal becomes a value, `new \Mamu\Element(template, vars)`, that the runtime renders.

mod lower;
mod vars;

pub use lower::twig_str;

use rayon::prelude::*;
use mamuc_analyze::{FileInfo, Kind, MarkupScope, Registry};
use mamuc_syntax::diag::codes;
use mamuc_syntax::php::{lex, Lexed, TokKind};
use mamuc_syntax::span::line_col;
use mamuc_syntax::{Diagnostic, Span};
use serde::Serialize;
use std::collections::BTreeMap;

pub const VERSION: &str = env!("CARGO_PKG_VERSION");

#[derive(Clone, Debug)]
pub struct SourceInput {
    /// Path relative to the source root, with `/` separators.
    pub rel: String,
    pub text: String,
}

#[derive(Clone, Debug)]
pub struct FileOutput {
    pub rel: String,
    /// `php/<rel without .phpm>.php`
    pub php_path: String,
    pub php: String,
    pub templates: Vec<TemplateOutput>,
}

#[derive(Clone, Debug, Serialize)]
pub struct TemplateOutput {
    #[serde(skip)]
    pub name: String,
    #[serde(skip)]
    pub content: String,
    pub source: String,
    /// The `.phpm` line of the template's first line.
    pub line: usize,
}

#[derive(Debug)]
pub struct FileDiagnostics {
    pub rel: String,
    pub text: String,
    pub diags: Vec<Diagnostic>,
}

#[derive(Debug)]
pub struct ProjectOutput {
    pub files: Vec<FileOutput>,
    pub manifest: Manifest,
}

#[derive(Debug, Serialize)]
pub struct Manifest {
    pub version: String,
    pub components: Registry,
    /// The components with a `#[Route]` attribute (the runtime reads the routes from them).
    pub pages: Vec<String>,
    pub templates: BTreeMap<String, TemplateOutput>,
    /// Compiled PHP files, relative to the output directory.
    pub files: Vec<String>,
}

struct Parsed {
    input: SourceInput,
    lexed: Option<Lexed>,
    info: FileInfo,
    diags: Vec<Diagnostic>,
}

/// Compiles a project. Returns the output when there is no error, and the diagnostics of each file.
pub fn compile(inputs: Vec<SourceInput>) -> (Option<ProjectOutput>, Vec<FileDiagnostics>) {
    // Pass 1: lex and scan each file.
    let parsed: Vec<Parsed> = inputs
        .into_par_iter()
        .map(|input| match lex(&input.text) {
            Ok(lexed) => {
                let info = mamuc_analyze::scan(&input.rel, &input.text, &lexed);
                let diags = mamuc_analyze::check(&info, &input.text);
                Parsed { input, lexed: Some(lexed), info, diags }
            }
            Err(d) => Parsed { info: FileInfo { rel: input.rel.clone(), ..Default::default() }, input, lexed: None, diags: vec![d] },
        })
        .collect();

    let (registry, dup) = mamuc_analyze::registry(parsed.iter().map(|p| &p.info));

    // Pass 2: generate each file.
    let results: Vec<(Option<FileOutput>, FileDiagnostics, Vec<String>)> = parsed
        .into_par_iter()
        .map(|p| {
            let mut diags = p.diags;
            diags.extend(dup.iter().filter(|(r, _)| *r == p.input.rel).map(|(_, d)| d.clone()));
            let out = p.lexed.as_ref().map(|lexed| {
                let (out, d) = emit_file(&p.input, lexed, &p.info, &registry);
                diags.extend(d);
                out
            });
            diags.sort_by_key(|d| d.span.start);
            let pages = p.info.routes.iter().map(|r| r.fqn.clone()).collect();
            (out, FileDiagnostics { rel: p.input.rel.clone(), text: p.input.text, diags }, pages)
        })
        .collect();

    let has_errors = results.iter().any(|(_, d, _)| d.diags.iter().any(Diagnostic::is_error));
    let mut files = Vec::new();
    let mut all_diags = Vec::new();
    let mut pages = Vec::new();
    for (out, d, p) in results {
        pages.extend(p);
        if let Some(o) = out {
            files.push(o);
        }
        all_diags.push(d);
    }
    if has_errors {
        return (None, all_diags);
    }
    files.sort_by(|a, b| a.rel.cmp(&b.rel));
    pages.sort();

    let mut templates = BTreeMap::new();
    for f in &files {
        for t in &f.templates {
            templates.insert(t.name.clone(), TemplateOutput { name: String::new(), content: String::new(), source: t.source.clone(), line: t.line });
        }
    }
    let manifest = Manifest {
        version: VERSION.into(),
        components: registry,
        pages,
        templates,
        files: files.iter().map(|f| f.php_path.clone()).collect(),
    };
    (Some(ProjectOutput { files, manifest }), all_diags)
}

fn has_variable(text: &str, name: &str) -> bool {
    let needle = format!("${name}");
    let b = text.as_bytes();
    let mut from = 0;
    while let Some(rel) = text[from..].find(&needle) {
        let end = from + rel + needle.len();
        if b.get(end).is_none_or(|c| !(c.is_ascii_alphanumeric() || *c == b'_' || *c >= 0x80)) {
            return true;
        }
        from = end;
    }
    false
}

fn emit_file(input: &SourceInput, lexed: &Lexed, info: &FileInfo, reg: &Registry) -> (FileOutput, Vec<Diagnostic>) {
    let src = input.text.as_str();
    let mut diags = Vec::new();
    let mut lowerer = lower::Lowerer::new(info, reg);
    let mut replacements: Vec<(Span, String)> = Vec::new();
    let mut templates = Vec::new();

    for (index, m) in lexed.markups.iter().enumerate() {
        let name = mamuc_analyze::template_name(&input.rel, index);
        let scope = info.markup_scopes.get(index).copied().unwrap_or(MarkupScope::TopLevel);

        // Slots of a template component become Twig blocks in its template.
        let slots = match scope {
            MarkupScope::Function(fi) if info.functions[fi].template_markup == Some(index) => {
                reg.get(&info.functions[fi].fqn).filter(|d| d.kind == Kind::Template).map(|d| d.slots.clone()).unwrap_or_default()
            }
            _ => vec![],
        };
        let content = lowerer.lower_root(&m.root, slots);

        // Resolve the free variables to PHP expressions.
        let (scope_text, class) = match scope {
            MarkupScope::Function(fi) => (info.functions[fi].span.text(src), None),
            MarkupScope::Method(ci, mi) => (info.classes[ci].methods[mi].span.text(src), Some(&info.classes[ci])),
            MarkupScope::TopLevel => (src, None),
        };
        let mut vars = Vec::new();
        for v in vars::free_vars(&m.root) {
            let php = if has_variable(scope_text, &v.name) {
                format!("${} ?? null", v.name)
            } else if let Some(c) = class {
                if v.name == "this" {
                    "$this".to_string()
                } else if c.props.iter().any(|p| p.name == v.name && !p.is_static) {
                    format!("$this->{}", v.name)
                } else if c.methods.iter().any(|f| f.name == v.name) {
                    format!("$this->{}(...)", v.name)
                } else {
                    if v.strict {
                        diags.push(undefined(&v.name, v.offset, " or a property or method of the class"));
                    }
                    continue;
                }
            } else {
                if v.strict {
                    diags.push(undefined(&v.name, v.offset, ""));
                }
                continue;
            };
            vars.push(format!("{} => {php}", php_str(&v.name)));
        }

        let newlines = m.span.text(src).matches('\n').count();
        let call = format!("new \\Mamu\\Element({}, [{}]){}", php_str(&name), vars.join(", "), "\n".repeat(newlines));
        replacements.push((m.span, call));
        templates.push(TemplateOutput { name, content, source: input.rel.clone(), line: line_col(src, m.span.start).0 });
    }
    diags.append(&mut lowerer.diags);

    // Each `<?phpm` tag becomes `<?php`. The first one gets a header comment on its line (keeps line numbers).
    for (i, open) in lexed.tokens.iter().filter(|t| t.kind == TokKind::OpenTag).enumerate() {
        let tag = if i == 0 { format!("<?php /* compiled by mamuc from {}; do not edit */", input.rel) } else { "<?php".into() };
        replacements.push((open.span, tag));
    }
    replacements.sort_by_key(|(s, _)| s.start);
    let mut php = String::with_capacity(src.len());
    let mut last = 0;
    for (span, text) in replacements {
        php.push_str(&src[last..span.start]);
        php.push_str(&text);
        last = span.end;
    }
    php.push_str(&src[last..]);

    let stem = input.rel.strip_suffix(".phpm").unwrap_or(&input.rel);
    (FileOutput { rel: input.rel.clone(), php_path: format!("php/{stem}.php"), php, templates }, diags)
}

fn undefined(name: &str, offset: usize, extra: &str) -> Diagnostic {
    Diagnostic::error(codes::UNDEFINED_VARIABLE, format!("undefined variable `{name}` in markup"), Span::new(offset, offset + name.len()))
        .with_label("not defined")
        .with_help(format!("markup reads the PHP variables of the enclosing function{extra}: define `${name}` before the markup"))
}

pub fn php_str(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}

/// Converts a JSON value to a PHP array literal (for `manifest.php`).
pub fn php_value(v: &serde_json::Value, indent: usize) -> String {
    use serde_json::Value;
    let pad = "    ".repeat(indent + 1);
    let end = "    ".repeat(indent);
    match v {
        Value::Null => "null".into(),
        Value::Bool(b) => b.to_string(),
        Value::Number(n) => n.to_string(),
        Value::String(s) => php_str(s),
        Value::Array(a) if a.is_empty() => "[]".into(),
        Value::Array(a) => format!("[\n{}{end}]", a.iter().map(|x| format!("{pad}{},\n", php_value(x, indent + 1))).collect::<String>()),
        Value::Object(o) if o.is_empty() => "[]".into(),
        Value::Object(o) => format!(
            "[\n{}{end}]",
            o.iter().map(|(k, x)| format!("{pad}{} => {},\n", php_str(k), php_value(x, indent + 1))).collect::<String>()
        ),
    }
}

impl Manifest {
    pub fn to_json(&self) -> String {
        serde_json::to_string_pretty(self).expect("manifest is serializable")
    }

    pub fn to_php(&self) -> String {
        let v = serde_json::to_value(self).expect("manifest is serializable");
        format!("<?php\n\n// Generated by mamuc {VERSION}. Do not edit.\n\nreturn {};\n", php_value(&v, 0))
    }
}
