//! Emits PHP and Twig from `.phem` files, and the project manifest.
//!
//! [`compile`] runs in two passes: it scans all files to build the component registry, then
//! it generates each file. Markup literals become Twig templates. In the PHP code, each markup
//! literal becomes a value, `new \Phem\Element(template, vars)`, that the runtime renders.

mod contexts;
mod defined;
mod lower;
mod replaces;
mod vars;

use rayon::prelude::*;
use phemc_analyze::{Enums, FileInfo, Kind, MarkupScope, Registry};
use phemc_syntax::diag::codes;
use phemc_syntax::php::{lex, Lexed, TokKind};
use phemc_syntax::span::line_col;
use phemc_syntax::{Diagnostic, Span};
use serde::Serialize;
use std::collections::{BTreeMap, HashMap, HashSet};

pub const VERSION: &str = env!("CARGO_PKG_VERSION");

#[derive(Clone, Debug)]
pub struct SourceInput {
    /// The key of the file in the build, with `/` separators: the path in the source root of the
    /// app, or `vendor/<package>/<path in the package root>` for a package file. The output
    /// paths and the template names come from it.
    pub rel: String,
    /// The path of the file from the project root (`src/Ui/HomePage.phem`), for the diagnostics
    /// and the source map of the runtime.
    pub path: String,
    /// The Composer package of the file, or `None` for a file of the app.
    pub package: Option<String>,
    pub text: String,
}

impl SourceInput {
    /// A file of the app whose path is its key (the tests, and a build with no project root).
    pub fn app(rel: impl Into<String>, text: impl Into<String>) -> Self {
        let rel = rel.into();
        Self { path: rel.clone(), rel, package: None, text: text.into() }
    }
}

#[derive(Clone, Debug)]
pub struct FileOutput {
    pub rel: String,
    /// `php/<rel without .phem>.php`
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
    /// The path of the `.phem` file from the project root.
    pub source: String,
    /// The `.phem` line of the template's first line.
    pub line: usize,
}

#[derive(Debug)]
pub struct FileDiagnostics {
    pub rel: String,
    /// The path of the file from the project root.
    pub path: String,
    /// The Composer package of the file, or `None` for a file of the app.
    pub package: Option<String>,
    pub text: String,
    pub diags: Vec<Diagnostic>,
}

impl FileDiagnostics {
    /// The diagnostics that the tools show: all of them for a file of the app, and only the
    /// errors for a package file. An error stops the build, but the app cannot fix a warning of
    /// a package.
    pub fn shown(&self) -> impl Iterator<Item = &Diagnostic> {
        self.diags.iter().filter(|d| self.package.is_none() || d.is_error())
    }
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
    /// The class components with a `#[Page]` or `#[Route]` attribute (the runtime reads the routes
    /// from them), with the Composer package of each one (`""` for a page of the app).
    pub pages: BTreeMap<String, String>,
    /// The functions with an `#[ErrorPage]` attribute (the runtime reads the statuses and the
    /// paths from them). Only the app has error pages (E060): a package does not know the URL of
    /// its pages in the app.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub error_pages: Vec<String>,
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
    // Pass 1: lex and scan each file. The classes get the members that they inherit, then the
    // file checks run.
    let mut parsed: Vec<Parsed> = inputs
        .into_par_iter()
        .map(|input| match lex(&input.text) {
            Ok(lexed) => {
                let mut info = phemc_analyze::scan(&input.rel, &input.text, &lexed);
                info.path = input.path.clone();
                Parsed { input, lexed: Some(lexed), info, diags: vec![] }
            }
            Err(d) => Parsed { info: FileInfo { rel: input.rel.clone(), ..Default::default() }, input, lexed: None, diags: vec![d] },
        })
        .collect();

    phemc_analyze::resolve_inheritance(parsed.iter_mut().map(|p| &mut p.info));
    parsed.par_iter_mut().for_each(|p| {
        if let Some(lexed) = &p.lexed {
            p.diags = phemc_analyze::check(&p.info, &p.input.text, lexed);
        }
    });

    let (registry, mut dup) = phemc_analyze::registry(parsed.iter().map(|p| &p.info));
    let (enums, errors) = phemc_analyze::enums(parsed.iter().map(|p| &p.info));
    dup.extend(errors);
    let bases = base_files(&parsed);

    // E016, E017: the replacements of the components (`#[Component(replaces: ...)]`). Only the
    // valid ones change the tags.
    let files: Vec<(&FileInfo, bool)> = parsed.iter().map(|p| (&p.info, p.input.package.is_some())).collect();
    let (replacements, errors) = replaces::check(&registry, &files);
    dup.extend(errors);

    // E060: only the app has error pages. A package does not know the URL of its pages in the app.
    for p in &parsed {
        let Some(package) = &p.input.package else { continue };

        for e in p.info.error_pages.iter().filter(|e| e.is_function) {
            let diag = Diagnostic::error(codes::ERROR_PAGE_IN_PACKAGE, format!("`#[ErrorPage]` is in the package `{package}`, and only the application has error pages"), e.span)
                .with_help("remove `#[ErrorPage]`: the application renders a component of the package in its own `#[ErrorPage]` function");
            dup.push((p.input.rel.clone(), diag));
        }
    }

    // E015: the uses of the components across the files, for the providers of the contexts.
    let uses: Vec<contexts::Use> = parsed.iter().filter_map(|p| p.lexed.as_ref().map(|l| contexts::uses(&p.info, l, &replacements))).flatten().collect();
    let infos: Vec<&FileInfo> = parsed.iter().map(|p| &p.info).collect();
    dup.extend(contexts::check(&registry, &infos, &uses));

    // Pass 2: generate each file.
    let results: Vec<(Option<FileOutput>, FileDiagnostics, Vec<(String, String)>, Vec<String>)> = parsed
        .into_par_iter()
        .map(|p| {
            let mut diags = p.diags;
            diags.extend(dup.iter().filter(|(r, _)| *r == p.input.rel).map(|(_, d)| d.clone()));

            let out = p.lexed.as_ref().map(|lexed| {
                let (out, d) = emit_file(&p.input, lexed, &p.info, &registry, &enums, &replacements);
                diags.extend(d);
                out
            });

            diags.sort_by_key(|d| d.span.start);
            let package = p.input.package.clone().unwrap_or_default();
            let pages = p.info.routes.iter().map(|r| (r.fqn.clone(), package.clone())).collect();
            let error_pages = p.info.error_pages.iter().filter(|e| e.is_function).map(|e| e.fqn.clone()).collect();
            let SourceInput { rel, path, package, text } = p.input;
            (out, FileDiagnostics { rel, path, package, text, diags }, pages, error_pages)
        })
        .collect();

    let has_errors = results.iter().any(|(_, d, _, _)| d.diags.iter().any(Diagnostic::is_error));
    let mut files = Vec::new();
    let mut all_diags = Vec::new();
    let mut pages = BTreeMap::new();
    let mut error_pages = Vec::new();
    for (out, d, p, e) in results {
        pages.extend(p);
        error_pages.extend(e);
        if let Some(o) = out {
            files.push(o);
        }
        all_diags.push(d);
    }

    if has_errors {
        return (None, all_diags);
    }

    files.sort_by(|a, b| a.rel.cmp(&b.rel));
    let files = load_order(files, &bases);

    let mut templates = BTreeMap::new();
    for f in &files {
        for t in &f.templates {
            templates.insert(t.name.clone(), TemplateOutput { name: String::new(), content: String::new(), source: t.source.clone(), line: t.line });
        }
    }

    error_pages.sort();
    let manifest = Manifest {
        version: VERSION.into(),
        // The runtime knows the built-in components.
        components: registry.into_iter().filter(|(_, c)| !c.file.is_empty()).collect(),
        pages,
        error_pages,
        templates,
        files: files.iter().map(|f| f.php_path.clone()).collect(),
    };

    (Some(ProjectOutput { files, manifest }), all_diags)
}

/// The global variables of Twig in a Symfony application. Markup can read them without a PHP
/// variable.
const TWIG_GLOBALS: &[&str] = &["app"];

pub(crate) fn has_variable(text: &str, name: &str) -> bool {
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

fn emit_file(input: &SourceInput, lexed: &Lexed, info: &FileInfo, reg: &Registry, enums: &Enums, replacements: &replaces::Replacements) -> (FileOutput, Vec<Diagnostic>) {
    let src = input.text.as_str();
    let mut diags = Vec::new();
    let mut lowerer = lower::Lowerer::new(info, reg, enums, replacements);
    let mut edits: Vec<(Span, String)> = Vec::new();
    let mut templates = Vec::new();
    // The defined variables at each markup, by scope: one walk for each function.
    let mut defined_by_scope: HashMap<(usize, usize), HashMap<usize, HashSet<String>>> = HashMap::new();

    for (index, m) in lexed.markups.iter().enumerate() {
        let name = phemc_analyze::template_name(&input.rel, index);
        let scope = info.markup_scopes.get(index).copied().unwrap_or(MarkupScope::TopLevel);

        // Slots of a template component become Twig blocks in its template.
        let slots = match scope {
            MarkupScope::Function(fi) if info.functions[fi].template_markup == Some(index) => {
                reg.get(&info.functions[fi].fqn).filter(|d| d.kind == Kind::Template).map(|d| d.slots.clone()).unwrap_or_default()
            }
            _ => vec![],
        };

        let (scope_span, class, params, owner) = match scope {
            MarkupScope::Function(fi) => (Some(info.functions[fi].span), None, info.functions[fi].params.as_slice(), Some(info.functions[fi].fqn.as_str())),
            MarkupScope::Method(ci, mi) => (Some(info.classes[ci].methods[mi].span), Some(&info.classes[ci]), &[][..], Some(info.classes[ci].fqn.as_str())),
            MarkupScope::TopLevel => (None, None, &[][..], None),
        };

        let scope_text = scope_span.map_or(src, |s| s.text(src));

        lowerer.class = class;
        lowerer.owner = owner;
        lowerer.params = params;
        lowerer.scope_text = scope_text;
        let content = lowerer.lower_root(&m.root, slots);

        // Resolve the free variables to PHP expressions. A variable that can be undefined at the
        // markup gets `?? null`.
        let defined = scope_span
            .and_then(|s| defined_by_scope.entry((s.start, s.end)).or_insert_with(|| defined::defined_at_markups(&lexed.tokens, src, s)).get(&m.span.start).cloned())
            .unwrap_or_default();
        let mut vars = Vec::new();
        for v in vars::free_vars(info, &m.root) {
            let php = if defined.contains(&v.name) {
                format!("${}", v.name)
            } else if has_variable(scope_text, &v.name) {
                format!("${} ?? null", v.name)
            } else if let Some(c) = class {
                if v.name == "this" {
                    "$this".to_string()
                } else if let Some(p) = c.all_props().find(|p| p.name == v.name && !p.is_static) {
                    if p.is_promoted && in_render(info, scope) {
                        diags.push(
                            Diagnostic::warning(codes::IMPURE_RENDER, format!("`render()` reads the service `${}`", v.name), Span::new(v.offset, v.offset + v.name.len()))
                                .with_label("not a prop or state")
                                .with_help(phemc_analyze::PURE_RENDER_HELP),
                        );
                    }

                    format!("$this->{}", v.name)
                } else if c.has_method(&v.name) {
                    format!("$this->{}(...)", v.name)
                } else if TWIG_GLOBALS.contains(&v.name.as_str()) {
                    continue;
                } else if c.open && v.strict {
                    // A property or a method of a parent class that the compiler does not see.
                    format!("\\Phem\\Rendering\\Member::read($this, {}, self::class)", php_str(&v.name))
                } else {
                    if v.strict {
                        diags.push(undefined(&v.name, v.offset, " or a property or method of the class"));
                    }

                    continue;
                }
            } else {
                if v.strict && !TWIG_GLOBALS.contains(&v.name.as_str()) {
                    diags.push(undefined(&v.name, v.offset, ""));
                }

                continue;
            };

            vars.push(format!("{} => {php}", php_str(&v.name)));
        }

        // A bound field or component (`phem:bind`) gives its component to the runtime.
        if class.is_some() && (content.contains("phem_bind(") || content.contains("phem_bound(")) && !vars.iter().any(|v| v.starts_with("'this' =>")) {
            vars.push("'this' => $this".to_string());
        }
        let newlines = m.span.text(src).matches('\n').count();
        let call = format!("new \\Phem\\Element({}, [{}]){}", php_str(&name), vars.join(", "), "\n".repeat(newlines));
        edits.push((m.span, call));
        templates.push(TemplateOutput { name, content, source: input.path.clone(), line: line_col(src, m.span.start).0 });
    }

    diags.append(&mut lowerer.diags);

    // `replaces: Alert::class` of a function component becomes the string: a function has no
    // class, and PHPStan reports the `::class`. The attribute gets the same value.
    let replaced = info.functions.iter().filter_map(|f| f.replaces.as_ref()).chain(info.classes.iter().filter_map(|c| c.replaces.as_ref()));
    for r in replaced {
        let is_function = reg.get(&r.fqcn).is_some_and(|d| d.kind != Kind::Class);
        if is_function && r.span.text(src).trim_end().to_ascii_lowercase().ends_with("::class") {
            edits.push((r.span, php_str(&r.fqcn)));
        }
    }

    // Each `<?phem` tag becomes `<?php`. The first one gets a header comment on its line (keeps line numbers).
    for (i, open) in lexed.tokens.iter().filter(|t| t.kind == TokKind::OpenTag).enumerate() {
        let tag = if i == 0 { format!("<?php /* compiled by phemc from {}; do not edit */", input.path) } else { "<?php".into() };
        edits.push((open.span, tag));
    }

    edits.sort_by_key(|(s, _)| s.start);

    let mut php = String::with_capacity(src.len());
    let mut last = 0;
    for (span, text) in edits {
        php.push_str(&src[last..span.start]);
        php.push_str(&text);
        last = span.end;
    }

    php.push_str(&src[last..]);

    let stem = input.rel.strip_suffix(".phem").unwrap_or(&input.rel);
    (FileOutput { rel: input.rel.clone(), php_path: format!("php/{stem}.php"), php, templates }, diags)
}

/// Whether a markup literal is in the `render()` method of a class.
fn in_render(info: &FileInfo, scope: MarkupScope) -> bool {
    matches!(scope, MarkupScope::Method(ci, mi) if info.classes[ci].methods[mi].name.eq_ignore_ascii_case("render"))
}

fn undefined(name: &str, offset: usize, extra: &str) -> Diagnostic {
    Diagnostic::error(codes::UNDEFINED_VARIABLE, format!("undefined variable `{name}` in markup"), Span::new(offset, offset + name.len()))
        .with_label("not defined")
        .with_help(format!("markup reads the PHP variables of the enclosing function{extra}: define `${name}` before the markup"))
}

/// For each file, the other files that declare the parent classes and the traits of its classes.
fn base_files(parsed: &[Parsed]) -> HashMap<String, Vec<String>> {
    let declared: HashMap<&str, &str> = parsed.iter().flat_map(|p| p.info.classes.iter().map(move |c| (c.fqn.as_str(), p.input.rel.as_str()))).collect();

    parsed
        .iter()
        .map(|p| {
            let mut bases: Vec<String> = p
                .info
                .classes
                .iter()
                .flat_map(|c| c.parent.iter().chain(&c.traits))
                .filter_map(|b| declared.get(b.as_str()).copied())
                .filter(|rel| *rel != p.input.rel)
                .map(String::from)
                .collect();

            bases.sort();
            bases.dedup();
            (p.input.rel.clone(), bases)
        })
        .collect()
}

/// The files in an order that PHP can load: a file comes after the files that declare the
/// parent classes and the traits of its classes (see [`base_files`]). Otherwise, the order
/// does not change.
fn load_order(files: Vec<FileOutput>, bases: &HashMap<String, Vec<String>>) -> Vec<FileOutput> {
    fn visit(i: usize, files: &[FileOutput], index: &HashMap<&str, usize>, bases: &HashMap<String, Vec<String>>, seen: &mut [bool], order: &mut Vec<usize>) {
        if std::mem::replace(&mut seen[i], true) {
            return;
        }

        for rel in bases.get(&files[i].rel).into_iter().flatten() {
            if let Some(&j) = index.get(rel.as_str()) {
                visit(j, files, index, bases, seen, order);
            }
        }

        order.push(i);
    }

    let index: HashMap<&str, usize> = files.iter().enumerate().map(|(i, f)| (f.rel.as_str(), i)).collect();

    let mut seen = vec![false; files.len()];
    let mut order = Vec::with_capacity(files.len());
    for i in 0..files.len() {
        visit(i, &files, &index, bases, &mut seen, &mut order);
    }

    let mut slots: Vec<Option<FileOutput>> = files.into_iter().map(Some).collect();
    order.into_iter().filter_map(|i| slots[i].take()).collect()
}

/// A single-quoted string literal. PHP and Twig escape it the same way.
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
        format!("<?php\n\n// Generated by phemc {VERSION}. Do not edit.\n\nreturn {};\n", php_value(&v, 0))
    }
}
