//! PHP structure scan, component registry, and diagnostics for `.phpm` files.

mod hooks;
mod purity;
mod scan;

pub use purity::HELP as PURE_RENDER_HELP;
pub use scan::{scan, Class, FileInfo, Func, MarkupScope, Param, Prop, RouteDecl};

use phemc_syntax::diag::codes;
use phemc_syntax::php::Lexed;
use phemc_syntax::Diagnostic;
use serde::Serialize;
use std::collections::{BTreeMap, HashMap, HashSet, VecDeque};

pub const ATTR_COMPONENT: &str = "Phem\\Attribute\\Component";
pub const ATTR_STATE: &str = "Phem\\Attribute\\State";
pub const ATTR_PROP: &str = "Phem\\Attribute\\Prop";
/// The route value attribute of a page property: the property is a prop, with no `#[Prop]`.
pub const ATTR_PATH: &str = "Phem\\Attribute\\Path";
/// The Phem page attribute: it exposes a class as a page (it extends `Route`). The class is a
/// component, with no `#[Component]`.
pub const ATTR_PAGE: &str = "Phem\\Attribute\\Page";
/// The Symfony route attribute: it exposes a class component as a page too.
pub const ATTR_ROUTE: &str = "Symfony\\Component\\Routing\\Attribute\\Route";
/// The built-in root element of a page: `<Root tag="main">{{ children }}</Root>` in the document
/// component. The runtime renders it (the `phem` controller, the state and the event boundary).
pub const ROOT: &str = "Phem\\Root";
/// The type of a prop that takes a #[State] property of the parent: `<TextField phem:bind={{ query }} />`.
pub const BOUND: &str = "Phem\\Bound";

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
    /// All prop names (parameters, or `#[Prop]` properties of a class; constructor parameters are services).
    pub props: Vec<String>,
    /// Props that receive markup (`Html`): `children` and the named slots.
    pub slots: Vec<String>,
    /// Props without a default value, whose type does not accept null.
    pub required: Vec<String>,
    /// The function has a variadic parameter (or the class a `#[Prop(rest: true)]` property), so
    /// unknown props are allowed.
    pub rest: bool,
    /// A class component with a parent class or a trait outside the sources: it can have props
    /// that the compiler does not see, so the compiler does not check its props and slots.
    #[serde(skip)]
    pub open: bool,
    /// The props of type `Bound`: `phem:bind` on the tag of the component gives one of them.
    #[serde(skip)]
    pub bound: Vec<String>,
}

/// The template name of the markup literal `index` of a source file.
pub fn template_name(rel: &str, index: usize) -> String {
    let stem = rel.strip_suffix(".phpm").unwrap_or(rel);
    format!("{stem}.{index}.twig")
}

fn is_html_type(ty: &str) -> bool {
    ty.split('|').any(|t| {
        let t = t.trim().trim_start_matches('?').trim_start_matches('\\');
        t == "Html" || t.ends_with("\\Html")
    })
}

/// Whether a PHP type accepts null: no type, `?T`, `mixed`, `null`, or a union with `null`. A prop
/// with such a type is optional: the runtime gives it null.
fn allows_null(ty: &str) -> bool {
    let ty = ty.trim();
    ty.is_empty()
        || ty.starts_with('?')
        || ty.split('|').any(|t| {
            let t = t.trim().trim_matches(|c| c == '(' || c == ')').trim();
            t.eq_ignore_ascii_case("null") || t.eq_ignore_ascii_case("mixed")
        })
}

/// Whether a PHP type accepts an array: no type, `array`, `iterable`, `mixed`, or a union with one.
fn accepts_array(ty: &str) -> bool {
    let ty = ty.trim();
    ty.is_empty()
        || ty.split('|').any(|t| {
            let t = t.trim().trim_start_matches('?').trim();
            ["array", "iterable", "mixed"].iter().any(|a| t.eq_ignore_ascii_case(a))
        })
}

/// Whether a PHP type is `Bound` (or `?Bound`), with the imports of a file.
pub fn is_bound_type(file: &FileInfo, ty: &str) -> bool {
    let t = ty.trim().trim_start_matches('?');
    !t.is_empty() && !t.contains('|') && file.resolve_class(t).eq_ignore_ascii_case(BOUND)
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
                .filter(|p| p.default.is_none() && !p.variadic && !allows_null(&p.ty))
                .map(|p| p.name.clone())
                .collect(),
            rest: f.params.iter().any(|p| p.variadic),
            open: false,
            bound: f.params.iter().filter(|p| !p.variadic && is_bound_type(file, &p.ty)).map(|p| p.name.clone()).collect(),
        });
    }
    for c in file.classes.iter().filter(|c| c.is_component) {
        let props: Vec<&Prop> = c.all_props().filter(|p| p.is_prop() && !p.is_rest).collect();
        out.push(ComponentDef {
            fqcn: c.fqn.clone(),
            kind: Kind::Class,
            file: file.rel.clone(),
            template: None,
            props: props.iter().map(|p| p.name.clone()).collect(),
            slots: props.iter().filter(|p| is_html_type(&p.ty)).map(|p| p.name.clone()).collect(),
            required: props.iter().filter(|p| !p.has_default && !allows_null(&p.ty)).map(|p| p.name.clone()).collect(),
            rest: c.all_props().any(|p| p.is_prop() && p.is_rest),
            open: c.open,
            bound: props.iter().filter(|p| is_bound_type(file, &p.ty)).map(|p| p.name.clone()).collect(),
        });
    }
    out
}

/// Adds to each class the members of its parent classes and traits in the sources, so a class
/// component sees the props and methods that it inherits. A class member replaces a member of a
/// trait, and a trait member replaces a member of the parent class, as in PHP. A private
/// member of a trait is a member of the class, but a private member of a parent class (or of
/// its traits) is not visible.
pub fn resolve_inheritance<'a>(files: impl IntoIterator<Item = &'a mut FileInfo>) {
    let mut files: Vec<&mut FileInfo> = files.into_iter().collect();
    let mut declared: HashMap<String, Class> = HashMap::new();
    for f in &files {
        for c in &f.classes {
            declared.insert(c.fqn.to_ascii_lowercase(), c.clone());
        }
    }
    for f in &mut files {
        for c in &mut f.classes {
            // Each base, and whether it comes through a parent class.
            let mut queue: VecDeque<(String, bool)> = c.traits.iter().map(|t| (t.clone(), false)).chain(c.parent.iter().map(|p| (p.clone(), true))).collect();
            let mut seen = HashSet::new();
            while let Some((name, from_parent)) = queue.pop_front() {
                if !seen.insert(name.to_ascii_lowercase()) {
                    continue;
                }
                let Some(base) = declared.get(&name.to_ascii_lowercase()) else {
                    c.open = true;
                    continue;
                };
                for p in &base.props {
                    if from_parent && p.is_private {
                        continue;
                    }
                    if !c.props.iter().chain(&c.inherited_props).any(|x| x.name == p.name) {
                        c.inherited_props.push(p.clone());
                    }
                }
                for m in &base.methods {
                    if from_parent && m.is_private {
                        continue;
                    }
                    if !c.has_method(&m.name) {
                        c.inherited_methods.push(m.name.clone());
                    }
                }
                queue.extend(base.traits.iter().map(|t| (t.clone(), from_parent)).chain(base.parent.iter().map(|p| (p.clone(), true))));
            }
        }
    }
}

/// File-level checks: removed hooks, a `render()` that is not pure, class component rules, and
/// `#[Page]` / `#[Route]`. Run it after `resolve_inheritance()`.
pub fn check(file: &FileInfo, src: &str, lexed: &Lexed) -> Vec<Diagnostic> {
    let mut diags = hooks::check(file, src, lexed);
    diags.extend(purity::check(file, src, lexed));
    for span in &file.export_defaults {
        diags.push(
            Diagnostic::error(codes::EXPORT_DEFAULT, "`export default` is not PHP", *span)
                .with_label("not PHP")
                .with_help("expose the page with `#[Page('/path')]` on the line before it, and `use Phem\\Attribute\\Page;`"),
        );
    }
    for r in &file.routes {
        if !r.is_component {
            diags.push(
                Diagnostic::error(codes::ROUTE_NOT_COMPONENT, format!("`#[{}]` must mark a class component, and `{}` is not one", r.attr, r.fqn), r.span)
                    .with_help("a page is a class with `#[Page]` and a `render()` method"),
            );
        }
    }
    for (short, expected, resolved, span) in &file.foreign_attrs {
        diags.push(
            Diagnostic::warning(codes::FOREIGN_ATTRIBUTE, format!("`#[{short}]` resolves to `{resolved}`, not to `{expected}`"), *span)
                .with_help(format!("add `use {expected};`")),
        );
    }
    for c in &file.classes {
        if !c.is_component {
            continue;
        }
        if !c.open && !c.has_method("render") {
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
        for p in c.props.iter().filter(|p| p.prop_attr.is_some() && !p.is_prop()) {
            let attr = p.prop_attr.unwrap_or_default();
            diags.push(
                Diagnostic::error(codes::INVALID_PROP, format!("`#[{attr}]` property `${}` is not a prop", p.name), p.span)
                    .with_help("a prop is public, not static, not promoted, and not `#[State]`"),
            );
        }
        // Two rest props: the error is on the second one of the class (or on the class, when
        // both are inherited).
        let rests: Vec<&Prop> = c.all_props().filter(|p| p.is_prop() && p.is_rest).collect();
        if let [first, second, ..] = rests[..] {
            let span = c.props.iter().filter(|p| p.is_rest && p.name != first.name).chain(c.props.iter().filter(|p| p.is_rest)).next().map_or(c.name_span, |p| p.span);
            diags.push(
                Diagnostic::error(codes::INVALID_REST_PROP, format!("`{}` has two rest props, `${}` and `${}`", c.name, first.name, second.name), span)
                    .with_help("a class component has at most one `#[Prop(rest: true)]` property"),
            );
        }
        for p in c.props.iter().filter(|p| p.is_prop() && p.is_rest && !accepts_array(&p.ty)) {
            diags.push(
                Diagnostic::error(codes::INVALID_REST_PROP, format!("rest prop `${}` cannot get an array", p.name), p.span)
                    .with_help("the rest prop gets a hash of props: use `public array $attrs = [];`"),
            );
        }
    }
    diags
}

/// All components of a project, by FQCN.
pub type Registry = BTreeMap<String, ComponentDef>;

/// The built-in components: they have no source file. The runtime knows them, so the manifest
/// does not list them.
pub fn builtins() -> Vec<ComponentDef> {
    vec![ComponentDef {
        fqcn: ROOT.into(),
        kind: Kind::Function,
        file: String::new(),
        template: None,
        props: vec!["tag".into()],
        slots: vec!["children".into()],
        required: Vec::new(),
        rest: true,
        open: false,
        bound: Vec::new(),
    }]
}

pub fn registry<'a>(files: impl IntoIterator<Item = &'a FileInfo>) -> (Registry, Vec<(String, Diagnostic)>) {
    let mut reg: Registry = builtins().into_iter().map(|c| (c.fqcn.clone(), c)).collect();
    let mut diags = Vec::new();
    for f in files {
        for c in components(f) {
            if let Some(prev) = reg.get(&c.fqcn) {
                let span = f.functions.iter().find(|x| x.fqn == c.fqcn).map(|x| x.name_span)
                    .or_else(|| f.classes.iter().find(|x| x.fqn == c.fqcn).map(|x| x.name_span))
                    .unwrap_or_default();
                let message = if prev.file.is_empty() {
                    format!("component `{}` is a built-in component of Phem", c.fqcn)
                } else {
                    format!("component `{}` is already declared in `{}`", c.fqcn, prev.file)
                };
                diags.push((f.rel.clone(), Diagnostic::error(codes::DUPLICATE_COMPONENT, message, span)));
                continue;
            }
            reg.insert(c.fqcn.clone(), c);
        }
    }
    (reg, diags)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn nullable_types() {
        for ty in ["", "?int", "mixed", "null", "int|null", "NULL|string", "(A&B)|null", " ?\\Closure"] {
            assert!(allows_null(ty), "{ty}");
        }
        for ty in ["int", "string|int", "Html", "(A&B)|C", "nullable"] {
            assert!(!allows_null(ty), "{ty}");
        }
    }
}
