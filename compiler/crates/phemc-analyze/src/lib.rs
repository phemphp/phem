//! PHP structure scan, component registry, and diagnostics for `.phpm` files.

mod hooks;
mod purity;
mod scan;

pub use purity::HELP as PURE_RENDER_HELP;
pub use scan::{scan, Class, FileInfo, Func, MarkupScope, Param, Prop, RouteDecl};

use phemc_syntax::diag::codes;
use phemc_syntax::php::Lexed;
use phemc_syntax::{Diagnostic, Span};
use serde::Serialize;
use std::collections::{BTreeMap, HashMap, HashSet, VecDeque};

pub const ATTR_COMPONENT: &str = "Phem\\Attribute\\Component";
pub const ATTR_STATE: &str = "Phem\\Attribute\\State";
pub const ATTR_PROP: &str = "Phem\\Attribute\\Prop";
/// A property or a parameter that gets the context of its type from a component above.
pub const ATTR_CONTEXT: &str = "Phem\\Attribute\\Context";
/// A property (or a class) that provides a context to the components below.
pub const ATTR_PROVIDE: &str = "Phem\\Attribute\\Provide";
/// The route value attribute of a page property: the property is a prop, with no `#[Prop]`.
pub const ATTR_PATH: &str = "Phem\\Attribute\\Path";
/// The Phem page attribute: it exposes a class or a function as a page (it extends `Route`). The
/// class or the function is a component, with no `#[Component]`.
pub const ATTR_PAGE: &str = "Phem\\Attribute\\Page";
/// A page that gets the Turbo Streams of other requests (`Phem\Stream\Streams`). Only a page can
/// have it: each page opens its own connection to the Mercure hub.
pub const ATTR_BROADCAST: &str = "Phem\\Attribute\\Broadcast";
/// An event handler whose slow work runs in a Messenger worker: a method of a class component
/// that returns a `Promise` (see `Phem\Attribute\Async`).
pub const ATTR_ASYNC: &str = "Phem\\Attribute\\Async";
/// The return type of an `#[Async]` handler: its work in a closure.
pub const PROMISE: &str = "Phem\\Async\\Promise";
/// The Symfony route attribute: it exposes a class component as a page too. It cannot mark a
/// function.
pub const ATTR_ROUTE: &str = "Symfony\\Component\\Routing\\Attribute\\Route";
/// The built-in root element of a page: `<Root tag="main">{{ children }}</Root>` in the document
/// component. The runtime renders it (the `phem` controller, the state and the event boundary).
pub const ROOT: &str = "Phem\\Root";
/// The built-in boundary of content that loads after the page: `<Suspense lazy>` with the
/// `fallback` and `error` slots. The runtime renders it (see `Phem\Suspense`).
pub const SUSPENSE: &str = "Phem\\Suspense";
/// The built-in boundary of render errors: `<ErrorBoundary>` with the `fallback` slot. The runtime
/// renders it (see `Phem\ErrorBoundary`).
pub const ERROR_BOUNDARY: &str = "Phem\\ErrorBoundary";
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
    /// The `#[Context]` properties or parameters: they are not props.
    #[serde(skip)]
    pub contexts: Vec<ContextNeed>,
    /// The types of the contexts that the class provides (`#[Provide]`), resolved.
    #[serde(skip)]
    pub provides: Vec<String>,
    /// A page (`#[Page]`, or `#[Route]` on a class component): it is not a tag. The parameters of
    /// a function page are the arguments of a controller, not props.
    #[serde(skip)]
    pub page: bool,
}

/// A `#[Context]` property or parameter of a component.
#[derive(Clone, Debug)]
pub struct ContextNeed {
    pub name: String,
    /// The type, resolved: the key of the context.
    pub ty: String,
    /// No default value, and the type does not accept null.
    pub required: bool,
    /// The property or the parameter, in the file of the component.
    pub span: Span,
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

/// The type of a context, resolved: `?Theme` → `App\Theme`.
pub fn context_type(file: &FileInfo, ty: &str) -> String {
    file.resolve_class(ty.trim().trim_start_matches('?'))
}

/// The components that a scanned file declares.
pub fn components(file: &FileInfo) -> Vec<ComponentDef> {
    let mut out = Vec::new();
    let is_page = |fqn: &str| file.routes.iter().any(|r| r.is_component && r.fqn == fqn);
    for f in &file.functions {
        let Some(kind) = f.component_kind else { continue };

        // A `#[Context]` parameter is not a prop. A function page has no props: its parameters
        // get the route values, the request and the services.
        let page = is_page(&f.fqn);
        let params: Vec<&Param> = f.params.iter().filter(|p| !p.is_context && !page).collect();
        out.push(ComponentDef {
            fqcn: f.fqn.clone(),
            kind,
            file: file.rel.clone(),
            template: f.template_markup.map(|i| template_name(&file.rel, i)),
            props: params.iter().filter(|p| !p.variadic).map(|p| p.name.clone()).collect(),
            slots: params.iter().filter(|p| is_html_type(&p.ty)).map(|p| p.name.clone()).collect(),
            required: params
                .iter()
                .filter(|p| p.default.is_none() && !p.variadic && !allows_null(&p.ty))
                .map(|p| p.name.clone())
                .collect(),
            rest: params.iter().any(|p| p.variadic),
            open: false,
            bound: params.iter().filter(|p| !p.variadic && is_bound_type(file, &p.ty)).map(|p| p.name.clone()).collect(),
            contexts: f
                .params
                .iter()
                .filter(|p| p.is_context)
                .map(|p| ContextNeed { name: p.name.clone(), ty: context_type(file, &p.ty), required: p.default.is_none() && !allows_null(&p.ty), span: p.span })
                .collect(),
            provides: vec![],
            page,
        });
    }

    for c in file.classes.iter().filter(|c| c.is_component) {
        let props: Vec<&Prop> = c.all_props().filter(|p| p.is_prop() && !p.is_rest).collect();
        let provides = c.provides_self.then(|| c.fqn.clone()).into_iter().chain(c.all_props().filter(|p| p.is_provide).map(|p| context_type(file, &p.ty))).collect();
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
            contexts: c
                .all_props()
                .filter(|p| p.is_context)
                .map(|p| ContextNeed { name: p.name.clone(), ty: context_type(file, &p.ty), required: !p.has_default && !allows_null(&p.ty), span: p.span })
                .collect(),
            provides,
            page: is_page(&c.fqn),
        });
    }

    out
}

/// Why a type cannot be the key of a context, or None: the key is one class, interface or enum,
/// nullable or not.
fn bad_context_type(file: &FileInfo, ty: &str) -> Option<&'static str> {
    const BUILTIN: &[&str] = &[
        "string", "int", "float", "bool", "array", "iterable", "mixed", "object", "callable", "null", "void", "never", "false", "true", "self", "static", "parent",
    ];

    let t = ty.trim().trim_start_matches('?').trim();
    if t.is_empty() {
        Some("it has no type")
    } else if t.contains('|') || t.contains('&') {
        Some("a union or intersection type has no single key")
    } else if BUILTIN.iter().any(|b| t.eq_ignore_ascii_case(b)) {
        Some("a scalar, an array or a pseudo-type is not a key: use an enum or a value object")
    } else if file.resolve_class(t).eq_ignore_ascii_case("Closure") {
        Some("all closures have the same type: provide the object that has the handler")
    } else {
        None
    }
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

/// `#[Async]` marks a method that declares `Promise` as its return type. The compiler does not
/// know which methods are handlers (an abstract base class can have one, and a private helper can
/// return the `Promise` of a handler): the runtime checks the handler that an event calls.
fn check_async(file: &FileInfo) -> Vec<Diagnostic> {
    let mut diags = Vec::new();
    for f in &file.functions {
        if let Some(span) = f.async_attr {
            diags.push(
                Diagnostic::error(codes::ASYNC_NOT_METHOD, format!("`#[Async]` must mark a method of a class component, and `{}` is a function", f.fqn), span)
                    .with_help("the worker renders the page to the handler again: move the handler into a class component"),
            );
        }
    }

    for c in &file.classes {
        for m in &c.methods {
            let Some(span) = m.async_attr else { continue };

            if m.return_type.is_empty() || file.resolve_class(&m.return_type) != PROMISE {
                diags.push(
                    Diagnostic::error(codes::ASYNC_WITHOUT_PROMISE, format!("the `#[Async]` handler `{}()` must declare `Promise` as its return type", m.fqn), span)
                        .with_label("no `: Promise`")
                        .with_help("return the slow work in a closure: `return new Promise(function (): void { … });`, with `use Phem\\Async\\Promise;`"),
                );
            }
        }
    }

    diags
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

    for r in file.routes.iter().filter(|r| !r.is_component) {
        let diag = if r.is_function {
            Diagnostic::error(codes::ROUTE_NOT_COMPONENT, format!("the Symfony `#[Route]` cannot mark a function, and `{}` is one", r.fqn), r.span)
                .with_help("use `#[Page('/path')]` and `use Phem\\Attribute\\Page;`: a function page needs no `#[Component]`")
        } else {
            Diagnostic::error(codes::ROUTE_NOT_COMPONENT, format!("`#[{}]` must mark a class component, and `{}` is not one", r.attr, r.fqn), r.span)
                .with_help("add `#[Component]`, or use `#[Page('/path')]`: a page class needs no `#[Component]`")
        };

        diags.push(diag);
    }

    for (fqn, span) in &file.broadcasts {
        diags.push(
            Diagnostic::error(codes::BROADCAST_NOT_COMPONENT, format!("`#[Broadcast]` must mark a page or a component, and `{fqn}` is neither"), *span)
                .with_help("a page opens the connection to the Mercure hub for itself and its components: put `#[Broadcast]` next to `#[Page]` or `#[Component]`"),
        );
    }

    diags.extend(check_async(file));

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

        for p in c.props.iter().filter(|p| p.is_context) {
            if p.is_static || p.is_readonly || p.is_promoted || p.is_state || p.prop_attr.is_some() {
                diags.push(
                    Diagnostic::error(codes::INVALID_CONTEXT, format!("`#[Context]` property `${}` cannot be static, readonly, a prop or state", p.name), p.span)
                        .with_help("the context sets the property in each render: use `#[Context] public Theme $theme;`"),
                );
            }
        }
    }

    for (what, name, ty, span) in context_declarations(file) {
        if let Some(reason) = bad_context_type(file, ty) {
            diags.push(
                Diagnostic::error(codes::INVALID_CONTEXT, format!("{what} `${name}` cannot have the type `{}`", ty.trim()), span)
                    .with_label(reason)
                    .with_help("the key of a context is one class, interface or enum, nullable or not"),
            );
        }
    }

    diags
}

/// The `#[Context]` and `#[Provide]` declarations of a file, for the type check: (attribute, name, type, span).
fn context_declarations(file: &FileInfo) -> Vec<(&'static str, &str, &str, Span)> {
    let mut out = Vec::new();
    for f in file.functions.iter().filter(|f| f.component_kind.is_some()) {
        out.extend(f.params.iter().filter(|p| p.is_context).map(|p| ("`#[Context]` parameter", p.name.as_str(), p.ty.as_str(), p.span)));
    }

    for c in &file.classes {
        out.extend(c.props.iter().filter(|p| p.is_context).map(|p| ("`#[Context]` property", p.name.as_str(), p.ty.as_str(), p.span)));
        out.extend(c.props.iter().filter(|p| p.is_provide).map(|p| ("`#[Provide]` property", p.name.as_str(), p.ty.as_str(), p.span)));
    }

    out
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
        contexts: Vec::new(),
        provides: Vec::new(),
        page: false,
    }, ComponentDef {
        fqcn: SUSPENSE.into(),
        kind: Kind::Class,
        file: String::new(),
        template: None,
        props: vec!["lazy".into(), "children".into(), "fallback".into(), "error".into()],
        slots: vec!["children".into(), "fallback".into(), "error".into()],
        required: Vec::new(),
        rest: false,
        open: false,
        bound: Vec::new(),
        contexts: Vec::new(),
        provides: Vec::new(),
        page: false,
    }, ComponentDef {
        fqcn: ERROR_BOUNDARY.into(),
        kind: Kind::Class,
        file: String::new(),
        template: None,
        props: vec!["children".into(), "fallback".into()],
        slots: vec!["children".into(), "fallback".into()],
        required: vec!["fallback".into()],
        rest: false,
        open: false,
        bound: Vec::new(),
        contexts: Vec::new(),
        provides: Vec::new(),
        page: false,
    }]
}

/// Whether a component is a built-in component of Phem (see `builtins()`).
pub fn is_builtin(fqcn: &str) -> bool {
    fqcn == ROOT || fqcn == SUSPENSE || fqcn == ERROR_BOUNDARY
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
