//! PHP structure scan, component registry, and diagnostics for `.rhp` files.

mod hooks;
mod scan;

pub use scan::{scan, Class, FileInfo, Func, MarkupScope, PageDecl, Param, Prop};

use rhpc_syntax::diag::codes;
use rhpc_syntax::Diagnostic;
use serde::Serialize;
use std::collections::BTreeMap;

pub const ATTR_COMPONENT: &str = "Rephp\\Attribute\\Component";
pub const ATTR_STATE: &str = "Rephp\\Attribute\\State";
pub const ATTR_PAGE: &str = "Rephp\\Attribute\\Page";

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "lowercase")]
pub enum Kind {
    /// The body is only `return <markup>;`: a plain Twig template, no PHP call.
    Template,
    /// A function component with PHP logic or hooks.
    Function,
    /// A `#[Component]` class with `render()`.
    Class,
}

/// A component, as the manifest and the code generator see it.
#[derive(Clone, Debug, Serialize)]
pub struct ComponentDef {
    pub fqcn: String,
    pub kind: Kind,
    /// The source file, relative to the source root (empty for built-in components).
    pub file: String,
    /// The Twig template of a template component.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub template: Option<String>,
    /// All prop names (parameters, or public properties of a class; constructor parameters are services).
    pub props: Vec<String>,
    /// Props that receive markup (`Html`): `children` and the named slots.
    pub slots: Vec<String>,
    /// Props without a default value.
    pub required: Vec<String>,
    /// The function has a variadic parameter, so unknown props are allowed.
    pub rest: bool,
}

/// The template name of the markup literal `index` of a source file.
pub fn template_name(rel: &str, index: usize) -> String {
    let stem = rel.strip_suffix(".rhp").unwrap_or(rel);
    format!("{stem}.{index}.twig")
}

fn is_html_type(ty: &str) -> bool {
    ty.split('|').any(|t| {
        let t = t.trim().trim_start_matches('?').trim_start_matches('\\');
        t == "Html" || t.ends_with("\\Html")
    })
}

/// The components that a scanned file declares.
pub fn components(file: &FileInfo) -> Vec<ComponentDef> {
    let mut out = Vec::new();
    for f in &file.functions {
        let Some(kind) = f.component_kind else { continue };
        out.push(ComponentDef {
            fqcn: f.fqn.clone(),
            kind,
            file: file.rel.clone(),
            template: f.template_markup.map(|i| template_name(&file.rel, i)),
            props: f.params.iter().filter(|p| !p.variadic).map(|p| p.name.clone()).collect(),
            slots: f.params.iter().filter(|p| is_html_type(&p.ty)).map(|p| p.name.clone()).collect(),
            required: f
                .params
                .iter()
                .filter(|p| p.default.is_none() && !p.variadic && !p.ty.trim_start().starts_with('?'))
                .map(|p| p.name.clone())
                .collect(),
            rest: f.params.iter().any(|p| p.variadic),
        });
    }
    for c in file.classes.iter().filter(|c| c.is_component) {
        let props: Vec<&Prop> = c.props.iter().filter(|p| p.is_public && !p.is_static && !p.is_state && !p.is_promoted).collect();
        out.push(ComponentDef {
            fqcn: c.fqn.clone(),
            kind: Kind::Class,
            file: file.rel.clone(),
            template: None,
            props: props.iter().map(|p| p.name.clone()).collect(),
            slots: props.iter().filter(|p| is_html_type(&p.ty)).map(|p| p.name.clone()).collect(),
            required: props.iter().filter(|p| !p.has_default && !p.ty.trim_start().starts_with('?')).map(|p| p.name.clone()).collect(),
            rest: false,
        });
    }
    out
}

/// File-level checks: removed hooks, class component rules, and `#[Page]`.
pub fn check(file: &FileInfo, src: &str) -> Vec<Diagnostic> {
    let mut diags = hooks::check(file, src);
    for span in &file.export_defaults {
        diags.push(
            Diagnostic::error(codes::EXPORT_DEFAULT, "`export default` is not PHP", *span)
                .with_label("not PHP")
                .with_help("mark the page component with an attribute: `#[Page]` on the line before it, and `use Rephp\\Attribute\\Page;`"),
        );
    }
    for p in &file.pages {
        if !p.is_component {
            diags.push(
                Diagnostic::error(codes::PAGE_NOT_COMPONENT, format!("`#[Page]` must mark a component, and `{}` is not one", p.fqn), p.span)
                    .with_help("a component is a function with a capitalized name, or a `#[Component]` class"),
            );
        }
    }
    for p in file.pages.iter().skip(1) {
        diags.push(
            Diagnostic::error(codes::MULTIPLE_PAGES, "a file can have only one `#[Page]` component", p.span)
                .with_help(format!("`{}` is already the page of this file; move the other page to its own file", file.pages[0].fqn)),
        );
    }
    if let Some(p) = file.pages.first() {
        if !file.rel.starts_with("pages/") {
            diags.push(
                Diagnostic::warning(codes::PAGE_OUTSIDE_PAGES, format!("`#[Page]` has no route here: `{}` is not in `pages/`", file.rel), p.span)
                    .with_help("move the file into `src/pages/`, or remove `#[Page]`"),
            );
        }
    }
    for (short, resolved, span) in &file.foreign_attrs {
        diags.push(
            Diagnostic::warning(codes::FOREIGN_ATTRIBUTE, format!("`#[{short}]` resolves to `{resolved}`, not to `Rephp\\Attribute\\{short}`"), *span)
                .with_help(format!("add `use Rephp\\Attribute\\{short};` (or `use Rephp\\Attribute\\{{Page, Component, State}};`)")),
        );
    }
    for c in &file.classes {
        if !c.is_component {
            continue;
        }
        if !c.methods.iter().any(|m| m.name.eq_ignore_ascii_case("render")) {
            diags.push(
                Diagnostic::error(codes::CLASS_WITHOUT_RENDER, format!("component class `{}` has no `render()` method", c.name), c.name_span)
                    .with_help("add `public function render() { return <div>...</div>; }`"),
            );
        }
        for p in c.props.iter().filter(|p| p.is_state && p.is_readonly) {
            diags.push(
                Diagnostic::error(codes::READONLY_STATE, format!("state property `${}` cannot be readonly", p.name), p.span)
                    .with_help("state changes over time; remove `readonly`"),
            );
        }
    }
    diags
}

/// All components of a project, by FQCN.
pub type Registry = BTreeMap<String, ComponentDef>;

pub fn registry<'a>(files: impl IntoIterator<Item = &'a FileInfo>) -> (Registry, Vec<(String, Diagnostic)>) {
    let mut reg = Registry::new();
    let mut diags = Vec::new();
    for f in files {
        for c in components(f) {
            if let Some(prev) = reg.get(&c.fqcn) {
                let span = f.functions.iter().find(|x| x.fqn == c.fqcn).map(|x| x.name_span)
                    .or_else(|| f.classes.iter().find(|x| x.fqn == c.fqcn).map(|x| x.name_span))
                    .unwrap_or_default();
                diags.push((
                    f.rel.clone(),
                    Diagnostic::error(codes::DUPLICATE_COMPONENT, format!("component `{}` is already declared in `{}`", c.fqcn, prev.file), span),
                ));
                continue;
            }
            reg.insert(c.fqcn.clone(), c);
        }
    }
    (reg, diags)
}
