//! PHP structure scan, component registry, and diagnostics for `.phem` files.

mod hooks;
mod purity;
mod scan;

pub use purity::HELP as PURE_RENDER_HELP;
pub use scan::{scan, Class, EnumDecl, FileInfo, Func, MarkupScope, Param, Prop, Replaces, RouteDecl, Toks};

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
/// A page or a component that gets the Turbo Streams of other requests (`Phem\Stream\Streams`).
/// On a handler method of a class component, the handler refreshes its component in the other
/// browsers, and the class needs no `#[Broadcast]`.
pub const ATTR_BROADCAST: &str = "Phem\\Attribute\\Broadcast";
/// A prop that identifies an instance of a component that broadcasts: the refresh goes only to
/// the instances with the same values (see `Phem\Attribute\BroadcastKey`).
pub const ATTR_BROADCAST_KEY: &str = "Phem\\Attribute\\BroadcastKey";
/// An event handler whose slow work runs in a Messenger worker: a method of a class component
/// that returns a `Promise` (see `Phem\Attribute\Async`).
pub const ATTR_ASYNC: &str = "Phem\\Attribute\\Async";
/// The return type of an `#[Async]` handler: its work in a closure.
pub const PROMISE: &str = "Phem\\Async\\Promise";
/// A handler method that validates its component first (see `Phem\Attribute\Validated`).
pub const ATTR_VALIDATED: &str = "Phem\\Attribute\\Validated";
/// The type of the `#[State]` property that turns on the live validation of a class component.
pub const VALIDATION: &str = "Phem\\Validation";
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
/// The built-in element with a tag name from a value: `<Dynamic tag={{ tag }}>`. phemc compiles
/// it to an element in the template of its parent, so the runtime does not know it.
pub const DYNAMIC: &str = "Phem\\Dynamic";
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
    /// The key of the source file in the build (empty for built-in components): the path in the
    /// source root of the app, or `vendor/<package>/<path in the package root>`.
    #[serde(skip)]
    pub file: String,
    /// The path of the source file from the project root, for the messages. The manifest has it
    /// as `source`, as the `source` of a template.
    #[serde(rename = "source")]
    pub path: String,
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
    /// The class of each prop with one class type (nullable or not), resolved in the file of the
    /// declaration: the compiler checks a literal value of a backed enum prop against its cases.
    #[serde(skip)]
    pub types: BTreeMap<String, String>,
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
    /// The component that this component replaces in all the tags (`#[Component(replaces: ...)]`).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub replaces: Option<String>,
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
    let stem = rel.strip_suffix(".phem").unwrap_or(rel);
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

/// The scalar types and the pseudo-types of PHP: they are not classes.
const BUILTIN_TYPES: &[&str] = &[
    "string", "int", "float", "bool", "array", "iterable", "mixed", "object", "callable", "null", "void", "never", "false", "true", "self", "static", "parent",
];

/// Whether a type name is a scalar type or a pseudo-type of PHP, not a class.
fn is_builtin_type(ty: &str) -> bool {
    BUILTIN_TYPES.iter().any(|b| ty.eq_ignore_ascii_case(b))
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
            path: if file.path.is_empty() { file.rel.clone() } else { file.path.clone() },
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
            types: params.iter().filter(|p| !p.variadic).filter_map(|p| Some((p.name.clone(), p.class_ty.clone()?))).collect(),
            contexts: f
                .params
                .iter()
                .filter(|p| p.is_context)
                .map(|p| ContextNeed { name: p.name.clone(), ty: context_type(file, &p.ty), required: p.default.is_none() && !allows_null(&p.ty), span: p.span })
                .collect(),
            provides: vec![],
            page,
            replaces: replaced(f.replaces.as_ref()),
        });
    }

    for c in file.classes.iter().filter(|c| c.is_component) {
        let props: Vec<&Prop> = c.all_props().filter(|p| p.is_prop() && !p.is_rest).collect();

        // `#[Provide]` on the class or on a parent class. A parent outside the sources can have
        // it: the component can provide itself.
        let provides_self = c.provides_self || c.inherited_provide || c.parent_open;
        let provides = provides_self.then(|| c.fqn.clone()).into_iter().chain(c.all_props().filter(|p| p.is_provide).map(|p| context_type(file, &p.ty))).collect();

        out.push(ComponentDef {
            fqcn: c.fqn.clone(),
            kind: Kind::Class,
            file: file.rel.clone(),
            path: if file.path.is_empty() { file.rel.clone() } else { file.path.clone() },
            template: None,
            props: props.iter().map(|p| p.name.clone()).collect(),
            slots: props.iter().filter(|p| is_html_type(&p.ty)).map(|p| p.name.clone()).collect(),
            required: props.iter().filter(|p| !p.has_default && !allows_null(&p.ty)).map(|p| p.name.clone()).collect(),
            rest: c.all_props().any(|p| p.is_prop() && p.is_rest),
            open: c.open,
            bound: props.iter().filter(|p| is_bound_type(file, &p.ty)).map(|p| p.name.clone()).collect(),
            types: props.iter().filter_map(|p| Some((p.name.clone(), p.class_ty.clone()?))).collect(),
            contexts: c
                .all_props()
                .filter(|p| p.is_context)
                .map(|p| ContextNeed { name: p.name.clone(), ty: context_type(file, &p.ty), required: !p.has_default && !allows_null(&p.ty), span: p.span })
                .collect(),
            provides,
            page: is_page(&c.fqn),
            replaces: replaced(c.replaces.as_ref()),
        });
    }

    out
}

/// The FQCN of a `replaces` argument that the compiler can read.
fn replaced(replaces: Option<&Replaces>) -> Option<String> {
    replaces.map(|r| r.fqcn.clone()).filter(|f| !f.is_empty())
}

/// Why a type cannot be the key of a context, or None: the key is one class, interface or enum,
/// nullable or not.
fn bad_context_type(file: &FileInfo, ty: &str) -> Option<&'static str> {
    let t = ty.trim().trim_start_matches('?').trim();
    if t.is_empty() {
        Some("it has no type")
    } else if t.contains('|') || t.contains('&') {
        Some("a union or intersection type has no single key")
    } else if is_builtin_type(t) {
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
            // Each base, whether it comes through a parent class, and whether it is a parent class
            // (not a trait).
            let mut queue: VecDeque<(String, bool, bool)> = c.traits.iter().map(|t| (t.clone(), false, false)).chain(c.parent.iter().map(|p| (p.clone(), true, true))).collect();
            let mut seen = HashSet::new();
            while let Some((name, from_parent, is_parent)) = queue.pop_front() {
                if !seen.insert(name.to_ascii_lowercase()) {
                    continue;
                }

                let Some(base) = declared.get(&name.to_ascii_lowercase()) else {
                    c.open = true;
                    c.parent_open |= is_parent;
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

                // `#[Provide]` on a parent class: PHP does not inherit the attributes of a class,
                // but the runtime looks at the parents too.
                c.inherited_provide |= from_parent && base.provides_self;

                for m in &base.methods {
                    if from_parent && m.is_private {
                        continue;
                    }

                    c.inherited_broadcast |= m.broadcast_attr.is_some();

                    if !c.has_method(&m.name) {
                        c.inherited_methods.push(m.name.clone());
                        if m.validated_attr.is_some() && !c.inherited_validated.contains(&m.name) {
                            c.inherited_validated.push(m.name.clone());
                        }
                    }
                }

                queue.extend(base.traits.iter().map(|t| (t.clone(), from_parent, false)).chain(base.parent.iter().map(|p| (p.clone(), true, true))));
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

/// `#[Broadcast]` on a handler method, `#[Broadcast(handlers: false)]` and `#[BroadcastKey]`: a
/// handler refreshes its class component, only a class component takes `handlers: false` (and
/// then no handler has `#[Broadcast]`), and a key is a prop of a component that broadcasts (with
/// `#[Broadcast]` on it or on a handler).
fn check_broadcast(file: &FileInfo) -> Vec<Diagnostic> {
    let mut diags = Vec::new();
    // A class that is not a component can be the parent of one: only a page is checked.
    for c in file.classes.iter().filter(|c| !c.is_trait) {
        // `handlers: false` on a method: the method does not refresh its component.
        for m in c.methods.iter().filter(|m| m.receive_only) {
            let Some(span) = m.broadcast_attr else { continue };
            diags.push(
                Diagnostic::error(codes::BROADCAST_NOT_COMPONENT, format!("`#[Broadcast(handlers: false)]` on `{}()`: only a class component takes the `handlers` argument", m.fqn), span)
                    .with_help("remove `#[Broadcast]` from the method: with no `#[Broadcast]`, the handler does not refresh its component"),
            );
        }

        let handlers: Vec<_> = c.methods.iter().filter(|m| !m.receive_only).filter_map(|m| m.broadcast_attr.map(|span| (m, span))).collect();
        if let Some(span) = c.receive_only {
            if c.is_page {
                diags.push(
                    Diagnostic::error(codes::BROADCAST_NOT_COMPONENT, format!("`#[Broadcast(handlers: false)]` must mark a class component, and `{}` is a page", c.fqn), span)
                        .with_help("a page has no handlers that refresh it: use `#[Broadcast]`"),
                );
            } else if c.is_component {
                for (m, span) in &handlers {
                    diags.push(
                        Diagnostic::error(codes::BROADCAST_NOT_COMPONENT, format!("`#[Broadcast]` on `{}()` refreshes the component, and `{}` has `#[Broadcast(handlers: false)]`", m.fqn, c.fqn), *span)
                            .with_help("remove `#[Broadcast]` from the method, or remove `handlers: false` from the class and put `#[Broadcast]` on the handlers that refresh it"),
                    );
                }
            }
        }

        if c.is_page {
            for (m, span) in &handlers {
                diags.push(
                    Diagnostic::error(codes::BROADCAST_NOT_COMPONENT, format!("`#[Broadcast]` on `{}()` must mark a handler of a class component, and `{}` is a page", m.fqn, c.fqn), *span)
                        .with_help("a handler refreshes its component in the other browsers: move it into a `#[Component]` class, or put `#[Broadcast]` on the page itself"),
                );
            }
        }

        // A parent outside the sources can have a handler with `#[Broadcast]`.
        let broadcasts = c.has_broadcast || !handlers.is_empty() || c.inherited_broadcast || c.open;
        // With `#[State]`, `mount()` runs only at the first render: a key that only `mount()` sets
        // has no value in the next events.
        let has_state = c.props.iter().any(|q| q.is_state && !q.is_static);
        for p in &c.props {
            let Some(span) = p.broadcast_key else { continue };

            if p.is_static {
                diags.push(
                    Diagnostic::error(codes::INVALID_BROADCAST_KEY, format!("`#[BroadcastKey]` must mark a property of the instance, and `${}` is static", p.name), span)
                        .with_help("remove `static`: the key identifies an instance of the component"),
                );
            } else if p.is_promoted {
                diags.push(
                    Diagnostic::error(codes::INVALID_BROADCAST_KEY, format!("`#[BroadcastKey]` must mark a prop or a property, and `${}` is a parameter of the constructor", p.name), span)
                        .with_help("the constructor gets the services: put the key on a `#[Prop]`, or on a property that `mount()` sets"),
                );
            } else if has_state && !p.is_prop() && !p.is_state {
                diags.push(
                    Diagnostic::error(codes::INVALID_BROADCAST_KEY, format!("`#[BroadcastKey]` on `${}` needs a value in each render, and `{}` has `#[State]`", p.name, c.fqn), span)
                        .with_help("put `#[Prop]` or `#[State]` on the property: with `#[State]`, `mount()` runs only at the first render"),
                );
            } else if c.is_page || (c.is_component && !broadcasts) {
                diags.push(
                    Diagnostic::error(codes::INVALID_BROADCAST_KEY, format!("`#[BroadcastKey]` on `${}` needs a component that broadcasts, and `{}` is not one", p.name, c.fqn), span)
                        .with_help("add `#[Broadcast]` to the component or to its handlers: the key identifies an instance for their refresh"),
                );
            }
        }
    }

    for f in &file.functions {
        let page = file.routes.iter().any(|r| r.fqn == f.fqn && r.attr == "Page");
        if let Some(span) = f.broadcast_attr.filter(|_| f.receive_only && f.component_kind.is_some()) {
            diags.push(
                Diagnostic::error(codes::BROADCAST_NOT_COMPONENT, format!("`#[Broadcast(handlers: false)]` must mark a class component, and `{}` is a function", f.fqn), span)
                    .with_help("a function component has no handlers that refresh it: use `#[Broadcast]`"),
            );
        }

        let broadcasts = f.component_kind.is_some() && !page && f.broadcast_attr.is_some();
        for p in &f.params {
            let Some((_, span)) = p.attrs.iter().find(|(a, _)| file.resolve_class(a) == ATTR_BROADCAST_KEY) else { continue };

            if !broadcasts {
                diags.push(
                    Diagnostic::error(codes::INVALID_BROADCAST_KEY, format!("`#[BroadcastKey]` on `${}` needs a component with `#[Broadcast]`, and `{}` is not one", p.name, f.fqn), *span)
                        .with_help("add `#[Broadcast]` next to `#[Component]`: the key identifies an instance for the refresh of the component"),
                );
            }
        }
    }

    diags
}

/// `#[Validated]` marks a handler of a class component with one `#[State]` property of the type
/// `Phem\Validation`, not static: the handler shows the errors in it. A parent outside the
/// sources can have the property. A trait or an abstract parent is checked in the component that
/// uses it.
fn check_validated(file: &FileInfo) -> Vec<Diagnostic> {
    let mut diags = Vec::new();
    for f in &file.functions {
        if let Some(span) = f.validated_attr {
            diags.push(
                Diagnostic::error(codes::INVALID_VALIDATED, format!("`#[Validated]` must mark a handler of a class component, and `{}` is a function", f.fqn), span)
                    .with_help("a function component has no state to validate: move the handler into a class component"),
            );
        }
    }

    for c in file.classes.iter().filter(|c| !c.is_trait && !c.open && (c.is_component || c.is_page)) {
        let is_validation = |p: &&Prop| p.is_state && !p.is_static && p.class_ty.as_deref() == Some(VALIDATION);
        let validations: Vec<_> = c.props.iter().chain(&c.inherited_props).filter(is_validation).collect();
        if validations.len() > 1 {
            // On a property of this class when it has one: the span is in this file.
            let span = c.props.iter().find(is_validation).map_or(c.name_span, |p| p.span);
            let names: Vec<_> = validations.iter().map(|p| format!("${}", p.name)).collect();
            diags.push(
                Diagnostic::error(codes::INVALID_VALIDATED, format!("`{}` has more than one `Validation` property: `{}`", c.fqn, names.join("`, `")), span)
                    .with_help("keep one `#[State] public Validation $validation;`: it validates all the `#[State]` properties of the component"),
            );
        }

        if !validations.is_empty() {
            continue;
        }

        for m in &c.methods {
            let Some(span) = m.validated_attr else { continue };

            diags.push(
                Diagnostic::error(codes::INVALID_VALIDATED, format!("`#[Validated]` on `{}()` needs a `#[State]` property of the type `Validation`, and `{}` has none", m.fqn, c.fqn), span)
                    .with_help("add `#[State] public Validation $validation;`, with `use Phem\\Validation;`: it keeps the errors that the handler shows"),
            );
        }

        for name in &c.inherited_validated {
            diags.push(
                Diagnostic::error(codes::INVALID_VALIDATED, format!("`{}` inherits the `#[Validated]` handler `{name}()`, and it has no `#[State]` property of the type `Validation`", c.fqn), c.name_span)
                    .with_help("add `#[State] public Validation $validation;`, with `use Phem\\Validation;`: it keeps the errors that the handler shows"),
            );
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
    diags.extend(check_broadcast(file));
    diags.extend(check_validated(file));

    for (short, expected, resolved, span) in &file.foreign_attrs {
        diags.push(
            Diagnostic::warning(codes::FOREIGN_ATTRIBUTE, format!("`#[{short}]` resolves to `{resolved}`, not to `{expected}`"), *span)
                .with_help(format!("add `use {expected};`")),
        );
    }

    for c in &file.classes {
        if c.abstract_component {
            let (what, attr) = if c.is_page { ("page", "Page") } else { ("component", "Component") };
            diags.push(
                Diagnostic::error(codes::ABSTRACT_COMPONENT, format!("{what} class `{}` is abstract", c.name), c.name_span)
                    .with_help(format!("remove `#[{attr}]`: the children declare it")),
            );
        }

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

/// The values of the backed enums of a project, by lowercase FQCN (PHP class names ignore the
/// case). An enum with a case that is not a literal is not in it: the compiler cannot check its
/// values.
pub type Enums = BTreeMap<String, Vec<String>>;

/// The backed enums of the files, and E014 for an enum that two files declare.
pub fn enums<'a>(files: impl IntoIterator<Item = &'a FileInfo>) -> (Enums, Vec<(String, Diagnostic)>) {
    let mut enums = Enums::new();
    let mut paths: HashMap<String, &str> = HashMap::new();
    let mut diags = Vec::new();
    for f in files {
        let path = if f.path.is_empty() { &f.rel } else { &f.path };
        for e in &f.enums {
            let key = e.fqn.to_ascii_lowercase();
            if let Some(prev) = paths.get(&key) {
                diags.push((f.rel.clone(), Diagnostic::error(codes::DUPLICATE_COMPONENT, format!("enum `{}` is already declared in `{prev}`", e.fqn), e.name_span)));
                continue;
            }

            paths.insert(key.clone(), path);
            if let Some(values) = &e.values {
                enums.insert(key, values.clone());
            }
        }
    }

    (enums, diags)
}

/// The built-in components: they have no source file. The runtime knows them (or phemc compiles
/// them, as `<Dynamic>`), so the manifest does not list them.
pub fn builtins() -> Vec<ComponentDef> {
    vec![ComponentDef {
        fqcn: ROOT.into(),
        kind: Kind::Function,
        file: String::new(),
        path: String::new(),
        template: None,
        props: vec!["tag".into()],
        slots: vec!["children".into()],
        required: Vec::new(),
        rest: true,
        open: false,
        bound: Vec::new(),
        types: BTreeMap::new(),
        contexts: Vec::new(),
        provides: Vec::new(),
        page: false,
        replaces: None,
    }, ComponentDef {
        fqcn: SUSPENSE.into(),
        kind: Kind::Class,
        file: String::new(),
        path: String::new(),
        template: None,
        props: vec!["lazy".into(), "children".into(), "fallback".into(), "error".into()],
        slots: vec!["children".into(), "fallback".into(), "error".into()],
        required: Vec::new(),
        rest: false,
        open: false,
        bound: Vec::new(),
        types: BTreeMap::new(),
        contexts: Vec::new(),
        provides: Vec::new(),
        page: false,
        replaces: None,
    }, ComponentDef {
        fqcn: ERROR_BOUNDARY.into(),
        kind: Kind::Class,
        file: String::new(),
        path: String::new(),
        template: None,
        props: vec!["children".into(), "fallback".into()],
        slots: vec!["children".into(), "fallback".into()],
        required: vec!["fallback".into()],
        rest: false,
        open: false,
        bound: Vec::new(),
        types: BTreeMap::new(),
        contexts: Vec::new(),
        provides: Vec::new(),
        page: false,
        replaces: None,
    }, ComponentDef {
        fqcn: DYNAMIC.into(),
        kind: Kind::Function,
        file: String::new(),
        path: String::new(),
        template: None,
        props: vec!["tag".into()],
        slots: vec!["children".into()],
        required: vec!["tag".into()],
        rest: true,
        open: false,
        bound: Vec::new(),
        types: BTreeMap::new(),
        contexts: Vec::new(),
        provides: Vec::new(),
        page: false,
        replaces: None,
    }]
}

/// Whether a component is a built-in component of Phem (see `builtins()`).
pub fn is_builtin(fqcn: &str) -> bool {
    fqcn == ROOT || fqcn == SUSPENSE || fqcn == ERROR_BOUNDARY || fqcn == DYNAMIC
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
                    format!("component `{}` is already declared in `{}`", c.fqcn, prev.path)
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
