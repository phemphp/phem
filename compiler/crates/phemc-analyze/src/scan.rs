//! A structural scan of PHP tokens: namespace, imports, functions, classes, and the
//! function that contains each markup literal. It is not a PHP parser; it reads only the
//! declarations that the compiler needs.

use crate::{Kind, ATTR_ASYNC, ATTR_BROADCAST, ATTR_BROADCAST_KEY, ATTR_COMPONENT, ATTR_CONTEXT, ATTR_PAGE, ATTR_PATH, ATTR_PROP, ATTR_PROVIDE, ATTR_ROUTE, ATTR_STATE};
use phemc_syntax::php::{Lexed, TokKind, Token};
use phemc_syntax::Span;
use std::collections::HashMap;

#[derive(Clone, Debug, Default)]
pub struct FileInfo {
    /// The key of the file in the build, with `/` separators (see `phemc_codegen::SourceInput`).
    pub rel: String,
    /// The path of the file from the project root, for the messages. Empty when it is the key.
    pub path: String,
    pub namespace: String,
    /// Class imports: lowercase alias → FQCN.
    pub class_uses: HashMap<String, String>,
    /// Function imports: lowercase alias → FQN.
    pub function_uses: HashMap<String, String>,
    pub functions: Vec<Func>,
    pub classes: Vec<Class>,
    /// The declarations marked with `#[Page]` or `#[Route]`: the pages.
    pub routes: Vec<RouteDecl>,
    /// The declarations with `#[Broadcast]` that are not pages or components (the compiler
    /// reports them).
    pub broadcasts: Vec<(String, Span)>,
    /// `export default` keywords (not PHP; the compiler reports them).
    pub export_defaults: Vec<Span>,
    /// Attributes with a known short name (`Page`, `Route`, `Component`, `State`, `Prop`, `Path`,
    /// `Context`, `Provide`) that resolve to another class: (short name, expected name, resolved
    /// name, span).
    pub foreign_attrs: Vec<(String, &'static str, String, Span)>,
    /// For each markup literal (by index), the function or method that contains it.
    pub markup_scopes: Vec<MarkupScope>,
    /// For each markup literal (by index), whether it is the value of a `return` statement:
    /// `return <div />;` or `return (<div />);`.
    pub returned_markups: Vec<bool>,
}

impl FileInfo {
    /// A class name as PHP resolves it: a fully qualified name (`\A\B`), an imported first
    /// segment (`use App\Ui;` → `Ui\Card`), or a name in the namespace.
    pub fn resolve_class(&self, name: &str) -> String {
        if let Some(rest) = name.strip_prefix('\\') {
            return rest.to_string();
        }

        let (first, tail) = name.split_once('\\').map_or((name, None), |(a, b)| (a, Some(b)));
        if let Some(fqn) = self.class_uses.get(&first.to_ascii_lowercase()) {
            return match tail {
                Some(t) => format!("{fqn}\\{t}"),
                None => fqn.clone(),
            };
        }

        qualify(&self.namespace, name)
    }

    /// The FQN of a component tag: a function imported with `use function`, or a class name.
    pub fn resolve_component(&self, name: &str) -> String {
        if !name.contains('\\') {
            if let Some(f) = self.function_uses.get(&name.to_ascii_lowercase()) {
                return f.clone();
            }
        }

        self.resolve_class(name)
    }

    /// Whether a component tag is `<Dynamic>`: phemc compiles it to an element, so its children
    /// are in the markup of its parent, not in a slot.
    pub fn is_dynamic(&self, name: &str) -> bool {
        self.resolve_component(name) == crate::DYNAMIC
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MarkupScope {
    TopLevel,
    Function(usize),
    /// (class index, method index)
    Method(usize, usize),
}

/// A declaration marked with the `#[Page]` or the Symfony `#[Route]` attribute: a page.
#[derive(Clone, Debug)]
pub struct RouteDecl {
    pub fqn: String,
    /// The short name of the attribute: `Page` or `Route`.
    pub attr: &'static str,
    /// The attribute name.
    pub span: Span,
    /// False for a class without `#[Component]`, or a function with `#[Route]`: a page is a
    /// component (`#[Page]` makes it one).
    pub is_component: bool,
    /// A function page: its parameters get the route values, the request and the services.
    pub is_function: bool,
}

#[derive(Clone, Debug)]
pub struct Func {
    pub name: String,
    pub fqn: String,
    pub name_span: Span,
    /// From the `function` keyword to the closing brace.
    pub span: Span,
    /// The body, without the braces.
    pub body: Span,
    pub params: Vec<Param>,
    /// A function with `#[Component]`: a function or a template component.
    pub component_kind: Option<Kind>,
    /// For a template component, the index of its markup literal.
    pub template_markup: Option<usize>,
    /// A `private` method: a child class cannot call it, but a class that uses its trait can.
    pub is_private: bool,
    /// The declared return type, as written (`Promise`, `?Node`), or empty.
    pub return_type: String,
    /// The span of an `#[Async]` attribute on the function or the method.
    pub async_attr: Option<Span>,
    /// The span of a `#[Broadcast]` attribute on the function or the method.
    pub broadcast_attr: Option<Span>,
    /// `#[Component(replaces: ...)]` on a function component.
    pub replaces: Option<Replaces>,
}

/// The `replaces` argument of `#[Component(replaces: Button::class)]`: the component replaces
/// another component in all the tags of the project.
#[derive(Clone, Debug)]
pub struct Replaces {
    /// The FQCN of the replaced component. It is empty when the compiler cannot read the value:
    /// the value must be `Name::class` or a string.
    pub fqcn: String,
    /// The value of the argument.
    pub span: Span,
}

#[derive(Clone, Debug)]
pub struct Param {
    pub name: String,
    pub ty: String,
    pub default: Option<String>,
    pub variadic: bool,
    pub promoted: bool,
    pub promoted_public: bool,
    pub promoted_private: bool,
    /// The attribute names of the parameter, as written.
    pub attrs: Vec<(String, Span)>,
    /// `#[Context]` on a parameter of a function component: a context, not a prop.
    pub is_context: bool,
    /// The variable name.
    pub span: Span,
}

#[derive(Clone, Debug)]
pub struct Class {
    pub name: String,
    pub fqn: String,
    pub name_span: Span,
    pub span: Span,
    pub is_component: bool,
    /// `#[Component]` or `#[Page]` on an `abstract` class (E045): the class is not a component.
    pub abstract_component: bool,
    /// A `trait`: its members go into the classes that use it.
    pub is_trait: bool,
    /// The parent class (`extends`), resolved.
    pub parent: Option<String>,
    /// The traits that the class uses, resolved.
    pub traits: Vec<String>,
    pub props: Vec<Prop>,
    pub methods: Vec<Func>,
    /// The properties from the parent classes and the traits in the sources that the class can
    /// read (see `resolve_inheritance()`).
    pub inherited_props: Vec<Prop>,
    /// The method names from the parent classes and the traits in the sources.
    pub inherited_methods: Vec<String>,
    /// A parent class or a trait is not in the sources (a `.php` file, a library), so the class
    /// can have more members than the compiler sees.
    pub open: bool,
    /// A parent class is not in the sources, so the class can get `#[Provide]` from it. A trait
    /// cannot: PHP does not give its attributes to the class.
    pub parent_open: bool,
    /// The interfaces that the class implements, resolved.
    pub interfaces: Vec<String>,
    /// `#[Provide]` on the class: the component provides itself as a context.
    pub provides_self: bool,
    /// A parent class in the sources has `#[Provide]`: the component provides itself too (see
    /// `resolve_inheritance()`).
    pub inherited_provide: bool,
    /// `#[Broadcast]` on the class.
    pub has_broadcast: bool,
    /// `#[Page]` on the class: a page, not a component that other pages render.
    pub is_page: bool,
    /// A parent class or a trait in the sources has a method with `#[Broadcast]` (see
    /// `resolve_inheritance()`).
    pub inherited_broadcast: bool,
    /// `#[Component(replaces: ...)]` on the class.
    pub replaces: Option<Replaces>,
}

impl Class {
    /// The properties of the class, then the inherited ones.
    pub fn all_props(&self) -> impl Iterator<Item = &Prop> {
        self.props.iter().chain(&self.inherited_props)
    }

    pub fn has_method(&self, name: &str) -> bool {
        self.methods.iter().any(|m| m.name.eq_ignore_ascii_case(name)) || self.inherited_methods.iter().any(|m| m.eq_ignore_ascii_case(name))
    }
}

#[derive(Clone, Debug)]
pub struct Prop {
    pub name: String,
    pub ty: String,
    pub is_public: bool,
    /// `private`: a child class cannot read it, but a class that uses its trait can.
    pub is_private: bool,
    pub is_readonly: bool,
    pub is_static: bool,
    pub is_state: bool,
    /// The attribute that marks the property as a prop: `Prop`, or `Path` on a page.
    pub prop_attr: Option<&'static str>,
    /// `#[Prop(rest: true)]`: the prop gets the props that no other prop takes.
    pub is_rest: bool,
    /// A promoted constructor parameter: a service that the container injects, not a prop.
    pub is_promoted: bool,
    pub has_default: bool,
    /// `#[Context]`: the property gets the context of its type.
    pub is_context: bool,
    /// `#[Provide]`: the property is a context for the components below.
    pub is_provide: bool,
    /// The span of a `#[BroadcastKey]`: the prop identifies an instance of the component.
    pub broadcast_key: Option<Span>,
    pub span: Span,
}

impl Prop {
    /// A `#[Prop]` (or `#[Path]`) property that is public, not static, not promoted, and not
    /// `#[State]`: a prop of the class component.
    pub fn is_prop(&self) -> bool {
        self.prop_attr.is_some() && self.is_public && !self.is_static && !self.is_state && !self.is_promoted
    }
}

/// Significant tokens (no whitespace or comments).
struct Toks<'a> {
    src: &'a str,
    t: Vec<Token>,
}

impl<'a> Toks<'a> {
    fn text(&self, i: usize) -> &'a str {
        self.t.get(i).map_or("", |t| t.text(self.src))
    }

    fn is_op(&self, i: usize, op: &str) -> bool {
        self.t.get(i).is_some_and(|t| t.kind == TokKind::Op) && self.text(i) == op
    }

    fn is_word(&self, i: usize, w: &str) -> bool {
        self.t.get(i).is_some_and(|t| t.kind == TokKind::Ident) && self.text(i).eq_ignore_ascii_case(w)
    }

    /// The index of the token that closes the bracket at `open`.
    fn matching(&self, open: usize) -> usize {
        let mut depth = 0i32;
        for i in open..self.t.len() {
            if self.t[i].kind == TokKind::Op || self.t[i].kind == TokKind::AttrOpen {
                match self.text(i) {
                    "(" | "[" | "{" | "#[" => depth += 1,
                    ")" | "]" | "}" => {
                        depth -= 1;
                        if depth == 0 {
                            return i;
                        }
                    }
                    _ => {}
                }
            }
        }

        self.t.len() - 1
    }
}

pub fn scan(rel: &str, src: &str, lexed: &Lexed) -> FileInfo {
    let toks = Toks { src, t: lexed.tokens.iter().copied().filter(|t| !t.is_trivia()).collect() };
    let mut info = FileInfo { rel: rel.to_string(), ..Default::default() };
    let mut attrs: Vec<(String, Span)> = Vec::new();
    let mut replaces: Option<Replaces> = None;
    let mut i = 0;
    let n = toks.t.len();
    while i < n {
        let t = toks.t[i];
        match t.kind {
            TokKind::AttrOpen => {
                let end = toks.matching(i);
                attrs.extend(attr_names(&toks, i + 1, end));
                replaces = replaces.or_else(|| replaces_arg(&toks, i + 1, end, &info));
                i = end + 1;
                continue;
            }
            TokKind::Ident => {
                let w = toks.text(i).to_ascii_lowercase();
                match w.as_str() {
                    "namespace" if !toks.is_op(i + 1, "\\") => {
                        info.namespace = toks.text(i + 1).trim_start_matches('\\').to_string();
                        i += 2;
                        continue;
                    }
                    "use" => {
                        i = parse_use(&toks, i + 1, &mut info);
                        continue;
                    }
                    "export" if toks.is_word(i + 1, "default") => {
                        info.export_defaults.push(Span::new(t.span.start, toks.t[i + 1].span.end));
                        i += 2;
                        continue;
                    }
                    "function" if toks.t.get(i + 1).is_some_and(|x| x.kind == TokKind::Ident) || toks.is_op(i + 1, "&") => {
                        let (mut f, end) = parse_function(&toks, i, &info.namespace);
                        let attrs = std::mem::take(&mut attrs);
                        check_attrs(&mut info, &attrs);
                        for p in &mut f.params {
                            check_attrs(&mut info, &p.attrs);
                            p.is_context = p.attrs.iter().any(|(a, _)| info.resolve_class(a) == ATTR_CONTEXT);
                        }

                        // Only a function with `#[Component]` (or `#[Page]`) is a component: the
                        // other functions of the file are plain PHP functions.
                        if attrs.iter().any(|(a, _)| matches!(info.resolve_class(a).as_str(), ATTR_COMPONENT | ATTR_PAGE)) {
                            f.component_kind = Some(Kind::Function);
                        }

                        // The Symfony `#[Route]` cannot mark a function: report it before `#[Page]`.
                        let route = attrs.iter().find(|(a, _)| info.resolve_class(a) == ATTR_ROUTE).map(|(_, s)| ("Route", *s)).or_else(|| route_attr(&info, &attrs));
                        if let Some((attr, span)) = route {
                            info.routes.push(RouteDecl { fqn: f.fqn.clone(), attr, span, is_component: attr == "Page", is_function: true });
                        }

                        f.broadcast_attr = broadcast_attr(&info, &attrs);
                        if let Some(span) = f.broadcast_attr.filter(|_| f.component_kind.is_none()) {
                            info.broadcasts.push((f.fqn.clone(), span));
                        }

                        f.async_attr = async_attr(&info, &attrs);
                        f.replaces = replaces.take();
                        info.functions.push(f);
                        i = end + 1;
                        continue;
                    }
                    "final" | "abstract" | "readonly" => {
                        i += 1;
                        continue;
                    }
                    "class" if i == 0 || !toks.is_op(i - 1, "::") => {
                        if toks.t.get(i + 1).is_some_and(|x| x.kind == TokKind::Ident) {
                            let attrs = std::mem::take(&mut attrs);
                            check_attrs(&mut info, &attrs);
                            let route = route_attr(&info, &attrs);
                            let broadcast = broadcast_attr(&info, &attrs);
                            let (mut c, end) = parse_class(&toks, i, &mut info, attrs.into_iter().map(|(a, _)| a).collect());
                            c.replaces = replaces.take();
                            if let Some(span) = broadcast.filter(|_| !c.is_component && !c.abstract_component) {
                                info.broadcasts.push((c.fqn.clone(), span));
                            }

                            // An abstract page has no route: E045 reports it.
                            if let Some((attr, span)) = route.filter(|_| !c.abstract_component) {
                                info.routes.push(RouteDecl { fqn: c.fqn.clone(), attr, span, is_component: c.is_component, is_function: false });
                            }

                            info.classes.push(c);
                            i = end + 1;
                            continue;
                        }
                    }
                    "trait" if toks.t.get(i + 1).is_some_and(|x| x.kind == TokKind::Ident) => {
                        attrs.clear();
                        replaces = None;
                        let (mut c, end) = parse_class(&toks, i, &mut info, vec![]);
                        c.is_trait = true;
                        info.classes.push(c);
                        i = end + 1;
                        continue;
                    }
                    "interface" | "trait" | "enum" => {
                        // Skip the whole declaration.
                        let mut j = i;
                        while j < n && !toks.is_op(j, "{") {
                            j += 1;
                        }

                        attrs.clear();
                        replaces = None;
                        i = toks.matching(j) + 1;
                        continue;
                    }
                    _ => {}
                }
            }
            _ => {}
        }

        i += 1;
    }

    info.markup_scopes = lexed
        .markups
        .iter()
        .map(|m| {
            for (ci, c) in info.classes.iter().enumerate() {
                for (mi, f) in c.methods.iter().enumerate() {
                    if f.body.contains(m.span) {
                        return MarkupScope::Method(ci, mi);
                    }
                }
            }

            info.functions.iter().position(|f| f.body.contains(m.span)).map_or(MarkupScope::TopLevel, MarkupScope::Function)
        })
        .collect();

    let mut returned = vec![false; lexed.markups.len()];
    for (k, t) in toks.t.iter().enumerate() {
        if let TokKind::Markup(index) = t.kind {
            let before = if k >= 1 && toks.is_op(k - 1, "(") { k.checked_sub(2) } else { k.checked_sub(1) };
            if let (Some(b), Some(slot)) = (before, returned.get_mut(index)) {
                *slot = toks.is_word(b, "return");
            }
        }
    }

    info.returned_markups = returned;

    // A component function whose body is only `return <markup>;` is a template component. A
    // function with a `#[Context]` parameter needs a fiber for the lookup: it is not one.
    for f in &mut info.functions {
        if f.component_kind == Some(Kind::Function) && !f.params.iter().any(|p| p.is_context) {
            f.template_markup = template_body(&toks, f.body);
            if f.template_markup.is_some() {
                f.component_kind = Some(Kind::Template);
            }
        }
    }

    info
}

fn template_body(toks: &Toks, body: Span) -> Option<usize> {
    let inner: Vec<&Token> = toks.t.iter().filter(|t| body.contains(t.span)).collect();
    let texts: Vec<&str> = inner.iter().map(|t| t.text(toks.src)).collect();
    let markup = |t: &Token| match t.kind {
        TokKind::Markup(i) => Some(i),
        _ => None,
    };

    match inner.len() {
        3 if texts[0].eq_ignore_ascii_case("return") && texts[2] == ";" => markup(inner[1]),
        5 if texts[0].eq_ignore_ascii_case("return") && texts[1] == "(" && texts[3] == ")" && texts[4] == ";" => markup(inner[2]),
        _ => None,
    }
}

/// Attribute names in `#[A, B(1)]` (with their spans), resolved later against the imports.
fn attr_names(toks: &Toks, from: usize, to: usize) -> Vec<(String, Span)> {
    let mut out = Vec::new();
    let mut i = from;
    let mut expect_name = true;
    while i < to {
        if expect_name && toks.t[i].kind == TokKind::Ident {
            out.push((toks.text(i).to_string(), toks.t[i].span));
            expect_name = false;
        } else if toks.is_op(i, "(") || toks.is_op(i, "[") {
            i = toks.matching(i);
        } else if toks.is_op(i, ",") {
            expect_name = true;
        }

        i += 1;
    }

    out
}

/// The `replaces` argument of a `#[Component(...)]` in a list of attributes: the named argument
/// `replaces`, or the first argument with no name.
fn replaces_arg(toks: &Toks, from: usize, to: usize, info: &FileInfo) -> Option<Replaces> {
    let mut i = from;
    while i < to {
        if toks.t[i].kind == TokKind::Ident && toks.is_op(i + 1, "(") {
            let end = toks.matching(i + 1);
            if info.resolve_class(toks.text(i)) == ATTR_COMPONENT {
                return component_replaces(toks, i + 2, end, info);
            }

            i = end;
        } else if toks.is_op(i, "(") || toks.is_op(i, "[") {
            i = toks.matching(i);
        }

        i += 1;
    }

    None
}

/// The `replaces` value in the arguments of `#[Component(...)]`: `Name::class` (resolved with the
/// imports) or a string.
fn component_replaces(toks: &Toks, from: usize, to: usize, info: &FileInfo) -> Option<Replaces> {
    let mut start = from;
    let mut first = true;
    while start < to {
        let mut end = start;
        while end < to && !toks.is_op(end, ",") {
            if toks.is_op(end, "(") || toks.is_op(end, "[") {
                end = toks.matching(end);
            }

            end += 1;
        }

        let named = toks.t[start].kind == TokKind::Ident && toks.is_op(start + 1, ":");
        let value = if named && toks.text(start).eq_ignore_ascii_case("replaces") {
            Some(start + 2)
        } else if !named && first {
            Some(start)
        } else {
            None
        };

        if let Some(v) = value.filter(|v| *v < end) {
            // `replaces: null`, the default value: no replacement.
            if end - v == 1 && toks.is_word(v, "null") {
                return None;
            }

            let span = Span::new(toks.t[v].span.start, toks.t[end - 1].span.end);
            let fqcn = match end - v {
                1 if toks.t[v].kind == TokKind::Str => {
                    let raw = toks.text(v);
                    raw.get(1..raw.len().saturating_sub(1)).unwrap_or_default().replace("\\\\", "\\").trim_start_matches('\\').to_string()
                }
                // `self::class`, `static::class` and `parent::class` are not a class name.
                3 if toks.t[v].kind == TokKind::Ident && toks.is_op(v + 1, "::") && toks.is_word(v + 2, "class") && !["self", "static", "parent"].iter().any(|w| toks.is_word(v, w)) => {
                    info.resolve_class(toks.text(v))
                }
                _ => String::new(),
            };

            return Some(Replaces { fqcn, span });
        }

        first = false;
        start = end + 1;
    }

    None
}

/// The attributes of a list whose only argument is `rest: true` (or `true`): `#[Prop(rest: true)]`.
fn rest_attrs(toks: &Toks, from: usize, to: usize) -> Vec<String> {
    let mut out = Vec::new();
    let mut i = from;
    let mut name = None;
    while i < to {
        if name.is_none() && toks.t[i].kind == TokKind::Ident {
            name = Some(toks.text(i));
        } else if toks.is_op(i, "(") || toks.is_op(i, "[") {
            let end = toks.matching(i);
            let args: Vec<String> = (i + 1..end).map(|k| toks.text(k).to_ascii_lowercase()).collect();
            if toks.is_op(i, "(") && matches!(args.iter().map(String::as_str).collect::<Vec<_>>()[..], ["true"] | ["rest", ":", "true"]) {
                out.extend(name.map(str::to_string));
            }

            i = end;
        } else if toks.is_op(i, ",") {
            name = None;
        }

        i += 1;
    }

    out
}

fn parse_use(toks: &Toks, mut i: usize, info: &mut FileInfo) -> usize {
    let kind = if toks.is_word(i, "function") {
        i += 1;
        1
    } else if toks.is_word(i, "const") {
        i += 1;
        2
    } else {
        0
    };

    let add = |kind: u8, fqn: String, alias: Option<String>, info: &mut FileInfo| {
        let fqn = fqn.trim_start_matches('\\').to_string();
        let alias = alias.unwrap_or_else(|| fqn.rsplit('\\').next().unwrap_or(&fqn).to_string()).to_ascii_lowercase();
        match kind {
            0 => info.class_uses.insert(alias, fqn),
            1 => info.function_uses.insert(alias, fqn),
            _ => None,
        };
    };

    loop {
        let name = toks.text(i).to_string();
        i += 1;

        // Group use: `use App\Component\{A, B as C};`
        if toks.is_op(i, "\\") && toks.is_op(i + 1, "{") {
            let end = toks.matching(i + 1);
            let mut j = i + 2;
            while j < end {
                let mut k = kind;
                if toks.is_word(j, "function") {
                    k = 1;
                    j += 1;
                } else if toks.is_word(j, "const") {
                    k = 2;
                    j += 1;
                }

                let inner = toks.text(j).to_string();
                j += 1;
                let alias = if toks.is_word(j, "as") {
                    j += 2;
                    Some(toks.text(j - 1).to_string())
                } else {
                    None
                };

                add(k, format!("{name}\\{inner}"), alias, info);
                if toks.is_op(j, ",") {
                    j += 1;
                }
            }

            i = end + 1;
        } else {
            let alias = if toks.is_word(i, "as") {
                i += 2;
                Some(toks.text(i - 1).to_string())
            } else {
                None
            };

            add(kind, name, alias, info);
        }

        if toks.is_op(i, ",") {
            i += 1;
            continue;
        }

        // `;`
        return i + 1;
    }
}

fn qualify(ns: &str, name: &str) -> String {
    if ns.is_empty() {
        name.to_string()
    } else {
        format!("{ns}\\{name}")
    }
}

/// Parses `function name(params): type { body }` starting at the `function` token.
fn parse_function(toks: &Toks, at: usize, ns: &str) -> (Func, usize) {
    let mut i = at + 1;
    if toks.is_op(i, "&") {
        i += 1;
    }

    let name = toks.text(i).to_string();
    let name_span = toks.t[i].span;
    i += 1;

    let params_end = toks.matching(i);
    let params = parse_params(toks, i + 1, params_end);

    let mut j = params_end + 1;
    while j < toks.t.len() && !toks.is_op(j, "{") && !toks.is_op(j, ";") {
        j += 1;
    }

    let return_type = if toks.is_op(params_end + 1, ":") && params_end + 2 < j {
        toks.src[toks.t[params_end + 2].span.start..toks.t[j - 1].span.end].trim().to_string()
    } else {
        String::new()
    };

    let (body, end) = if toks.is_op(j, "{") {
        let close = toks.matching(j);
        (Span::new(toks.t[j].span.end, toks.t[close].span.start), close)
    } else {
        (Span::new(toks.t[j].span.start, toks.t[j].span.start), j)
    };

    let f = Func {
        fqn: qualify(ns, &name),
        name,
        name_span,
        span: Span::new(toks.t[at].span.start, toks.t[end].span.end),
        body,
        params,
        component_kind: None,
        template_markup: None,
        is_private: false,
        return_type,
        async_attr: None,
        broadcast_attr: None,
        replaces: None,
    };

    (f, end)
}

fn parse_params(toks: &Toks, from: usize, to: usize) -> Vec<Param> {
    let mut out = Vec::new();
    let mut i = from;
    while i < to {
        // One parameter: up to the next top-level comma.
        let mut j = i;
        while j < to && !toks.is_op(j, ",") {
            if toks.is_op(j, "(") || toks.is_op(j, "[") || toks.is_op(j, "{") || toks.t[j].kind == TokKind::AttrOpen {
                j = toks.matching(j);
            }
            j += 1;
        }

        let mut p = Param {
            name: String::new(),
            ty: String::new(),
            default: None,
            variadic: false,
            promoted: false,
            promoted_public: false,
            promoted_private: false,
            attrs: vec![],
            is_context: false,
            span: Span::default(),
        };

        let mut ty_start: Option<usize> = None;
        let mut k = i;
        while k < j {
            let t = toks.t[k];
            if t.kind == TokKind::AttrOpen {
                let end = toks.matching(k);
                p.attrs.extend(attr_names(toks, k + 1, end));
                k = end + 1;
                continue;
            }

            let text = toks.text(k);
            let lower = text.to_ascii_lowercase();
            if t.kind == TokKind::Ident && matches!(lower.as_str(), "public" | "private" | "protected" | "readonly") {
                p.promoted = true;
                p.promoted_public |= lower == "public";
                p.promoted_private |= lower == "private";
            } else if t.kind == TokKind::Var {
                p.name = text[1..].to_string();
                p.span = t.span;
                if let Some(s) = ty_start {
                    p.ty = toks.src[toks.t[s].span.start..toks.t[k - 1].span.end].replace("...", "").replace('&', "").trim().to_string();
                }

                if toks.is_op(k + 1, "=") && k + 2 < j {
                    p.default = Some(toks.src[toks.t[k + 2].span.start..toks.t[j - 1].span.end].to_string());
                }

                break;
            } else if text == "..." {
                p.variadic = true;
            } else if ty_start.is_none() && text != "&" {
                ty_start = Some(k);
            }

            k += 1;
        }

        if !p.name.is_empty() {
            out.push(p);
        }

        i = j + 1;
    }

    out
}

/// The first `#[Page]` or `#[Route]` attribute in a list of attributes (they are repeatable):
/// its short name and its span.
fn route_attr(info: &FileInfo, attrs: &[(String, Span)]) -> Option<(&'static str, Span)> {
    attrs.iter().find_map(|(a, s)| match info.resolve_class(a).as_str() {
        ATTR_PAGE => Some(("Page", *s)),
        ATTR_ROUTE => Some(("Route", *s)),
        _ => None,
    })
}

/// The span of a `#[Broadcast]` of a declaration.
fn broadcast_attr(info: &FileInfo, attrs: &[(String, Span)]) -> Option<Span> {
    attrs.iter().find(|(a, _)| info.resolve_class(a) == ATTR_BROADCAST).map(|(_, s)| *s)
}

/// The span of an `#[Async]` of a function or a method.
fn async_attr(info: &FileInfo, attrs: &[(String, Span)]) -> Option<Span> {
    attrs.iter().find(|(a, _)| info.resolve_class(a) == ATTR_ASYNC).map(|(_, s)| *s)
}

/// Records `#[Page]` / `#[Route]` / `#[Component]` / `#[State]` / `#[Prop]` / `#[Path]` that do not resolve to the expected
/// attributes (usually a missing `use ...;`).
fn check_attrs(info: &mut FileInfo, attrs: &[(String, Span)]) {
    for (a, span) in attrs {
        let short = a.rsplit('\\').next().unwrap_or(a);
        let expected = match short {
            "Page" => ATTR_PAGE,
            "Route" => ATTR_ROUTE,
            "Component" => ATTR_COMPONENT,
            "State" => ATTR_STATE,
            "Prop" => ATTR_PROP,
            "Path" => ATTR_PATH,
            "Context" => ATTR_CONTEXT,
            "Provide" => ATTR_PROVIDE,
            "Broadcast" => ATTR_BROADCAST,
            "BroadcastKey" => ATTR_BROADCAST_KEY,
            "Async" => ATTR_ASYNC,
            _ => continue,
        };

        let resolved = info.resolve_class(a);
        if resolved != expected {
            info.foreign_attrs.push((short.to_string(), expected, resolved, *span));
        }
    }
}

/// Whether the modifiers before the `class` keyword at `at` have `abstract`.
fn is_abstract(toks: &Toks, at: usize) -> bool {
    let mut j = at;
    while j > 0 && ["abstract", "final", "readonly"].iter().any(|m| toks.is_word(j - 1, m)) {
        j -= 1;
        if toks.is_word(j, "abstract") {
            return true;
        }
    }

    false
}

fn parse_class(toks: &Toks, at: usize, info: &mut FileInfo, attrs: Vec<String>) -> (Class, usize) {
    let name = toks.text(at + 1).to_string();
    let name_span = toks.t[at + 1].span;

    let mut j = at + 2;
    let mut parent = None;
    let mut interfaces = vec![];
    let mut in_implements = false;
    while j < toks.t.len() && !toks.is_op(j, "{") {
        if toks.is_word(j, "extends") && toks.t.get(j + 1).is_some_and(|x| x.kind == TokKind::Ident) {
            parent = Some(info.resolve_class(toks.text(j + 1)));
            in_implements = false;
        } else if toks.is_word(j, "implements") {
            in_implements = true;
        } else if in_implements && toks.t[j].kind == TokKind::Ident {
            interfaces.push(info.resolve_class(toks.text(j)));
        }
        j += 1;
    }

    let close = toks.matching(j);

    // A `#[Page]` class is a component too, but an abstract class is not one.
    let component_attr = attrs.iter().any(|a| matches!(info.resolve_class(a).as_str(), ATTR_COMPONENT | ATTR_PAGE));
    let is_abstract = is_abstract(toks, at);
    let provides_self = attrs.iter().any(|a| info.resolve_class(a) == ATTR_PROVIDE);
    let has_broadcast = attrs.iter().any(|a| info.resolve_class(a) == ATTR_BROADCAST);
    let is_page = attrs.iter().any(|a| info.resolve_class(a) == ATTR_PAGE);
    let mut class = Class {
        fqn: qualify(&info.namespace, &name),
        name,
        name_span,
        span: Span::new(toks.t[at].span.start, toks.t[close].span.end),
        is_component: component_attr && !is_abstract,
        abstract_component: component_attr && is_abstract,
        is_trait: false,
        parent,
        traits: vec![],
        props: vec![],
        methods: vec![],
        inherited_props: vec![],
        inherited_methods: vec![],
        open: false,
        parent_open: false,
        interfaces,
        provides_self,
        inherited_provide: false,
        has_broadcast,
        is_page,
        inherited_broadcast: false,
        replaces: None,
    };

    let mut i = j + 1;
    let mut member_attrs: Vec<(String, Span)> = Vec::new();
    let mut member_rest: Vec<String> = Vec::new();
    let mut mods: Vec<String> = Vec::new();
    let mut ty_start: Option<usize> = None;
    while i < close {
        let t = toks.t[i];
        let text = toks.text(i);
        let lower = text.to_ascii_lowercase();
        if t.kind == TokKind::AttrOpen {
            let end = toks.matching(i);
            member_attrs.extend(attr_names(toks, i + 1, end));
            member_rest.extend(rest_attrs(toks, i + 1, end));
            i = end + 1;
            continue;
        }

        if t.kind == TokKind::Ident && matches!(lower.as_str(), "public" | "private" | "protected" | "readonly" | "static" | "var" | "final" | "abstract") {
            mods.push(lower);
            i += 1;
            continue;
        }

        if t.kind == TokKind::Ident && (lower == "const" || lower == "use" || lower == "case") {
            let is_use = lower == "use";
            i += 1;
            while i < close && !toks.is_op(i, ";") {
                if toks.is_op(i, "{") {
                    // `use A, B { A::x insteadof B; }` ends without `;`.
                    i = toks.matching(i);
                    break;
                }

                if is_use && toks.t[i].kind == TokKind::Ident {
                    class.traits.push(info.resolve_class(toks.text(i)));
                }

                i += 1;
            }

            member_attrs.clear();
            member_rest.clear();
            mods.clear();
            ty_start = None;
            i += 1;
            continue;
        }

        if t.kind == TokKind::Ident && lower == "function" {
            let (m, end) = parse_function(toks, i, "");
            if m.name.eq_ignore_ascii_case("__construct") {
                for p in m.params.iter().filter(|p| p.promoted) {
                    check_attrs(info, &p.attrs);
                    class.props.push(Prop {
                        name: p.name.clone(),
                        ty: p.ty.clone(),
                        is_public: p.promoted_public,
                        is_private: p.promoted_private,
                        is_readonly: false,
                        is_static: false,
                        is_state: false,
                        prop_attr: None,
                        is_rest: false,
                        is_promoted: true,
                        has_default: p.default.is_some(),
                        is_context: false,
                        is_provide: false,
                        // A promoted parameter is a service, not a prop: the analysis reports it.
                        broadcast_key: p.attrs.iter().find(|(a, _)| info.resolve_class(a) == ATTR_BROADCAST_KEY).map(|(_, s)| *s),
                        span: m.name_span,
                    });
                }
            }

            check_attrs(info, &member_attrs);
            let async_attr = async_attr(info, &member_attrs);
            let broadcast_attr = broadcast_attr(info, &member_attrs);
            class.methods.push(Func { fqn: format!("{}::{}", class.fqn, m.name), is_private: mods.iter().any(|x| x == "private"), async_attr, broadcast_attr, ..m });

            member_attrs.clear();
            member_rest.clear();
            mods.clear();
            ty_start = None;
            i = end + 1;
            continue;
        }

        if t.kind == TokKind::Var {
            let ty = ty_start.map_or(String::new(), |s| toks.src[toks.t[s].span.start..toks.t[i - 1].span.end].trim().to_string());
            let has_default = toks.is_op(i + 1, "=");
            check_attrs(info, &member_attrs);
            let has_attr = |name: &str| member_attrs.iter().any(|(a, _)| info.resolve_class(a) == name);
            class.props.push(Prop {
                name: text[1..].to_string(),
                ty,
                is_public: mods.iter().any(|m| m == "public" || m == "var") || !mods.iter().any(|m| m == "private" || m == "protected"),
                is_private: mods.iter().any(|m| m == "private"),
                is_readonly: mods.iter().any(|m| m == "readonly"),
                is_static: mods.iter().any(|m| m == "static"),
                is_state: has_attr(ATTR_STATE),
                prop_attr: if has_attr(ATTR_PROP) {
                    Some("Prop")
                } else if has_attr(ATTR_PATH) {
                    Some("Path")
                } else {
                    None
                },
                is_rest: member_rest.iter().any(|a| info.resolve_class(a) == ATTR_PROP),
                is_promoted: false,
                has_default,
                is_context: has_attr(ATTR_CONTEXT),
                is_provide: has_attr(ATTR_PROVIDE),
                broadcast_key: member_attrs.iter().find(|(a, _)| info.resolve_class(a) == ATTR_BROADCAST_KEY).map(|(_, s)| *s),
                span: t.span,
            });

            while i < close && !toks.is_op(i, ";") {
                if toks.is_op(i, "{") {
                    i = toks.matching(i);
                    // A hook list (`$x { get => ...; }`) ends the declaration without `;`. A
                    // closure body in the default value is followed by `;` or `,`.
                    if !toks.is_op(i + 1, ";") && !toks.is_op(i + 1, ",") {
                        break;
                    }
                } else if toks.is_op(i, "(") || toks.is_op(i, "[") {
                    i = toks.matching(i);
                }

                i += 1;
            }

            member_attrs.clear();
            member_rest.clear();
            mods.clear();
            ty_start = None;
            i += 1;
            continue;
        }

        if ty_start.is_none() {
            ty_start = Some(i);
        }

        i += 1;
    }

    (class, close)
}

#[cfg(test)]
mod tests {
    use super::*;
    use phemc_syntax::php::lex;

    fn scan_src(src: &str) -> FileInfo {
        let lexed = lex(src).unwrap();
        scan("component/test.phpm", src, &lexed)
    }

    #[test]
    fn namespace_uses_and_functions() {
        let f = scan_src(
            r#"<?phpm
namespace App\Component;
use function Phem\{useState, useEffect as ue};
use App\Ui\Card, App\Ui\Button as Btn;
use Symfony\Component\Routing\Attribute\Route;
use Phem\Attribute\Component;
#[Component]
function MyButton(int $count, ?\Closure $onClick = null, ?Html $children = null) {
    [$c, $s] = useState(0);
    return <button>{{ count }}</button>;
}
#[Component]
#[Route('/card', name: 'card')]
function Card(string $title) { return (<div>{{ title }}</div>); }
function helper() {}
"#,
        );

        assert_eq!(f.namespace, "App\\Component");
        assert_eq!(f.function_uses["usestate"], "Phem\\useState");
        assert_eq!(f.function_uses["ue"], "Phem\\useEffect");
        assert_eq!(f.class_uses["btn"], "App\\Ui\\Button");
        assert_eq!(f.functions.len(), 3);

        let b = &f.functions[0];
        assert_eq!(b.fqn, "App\\Component\\MyButton");
        assert_eq!(b.component_kind, Some(Kind::Function));
        assert_eq!(b.params[1].name, "onClick");
        assert_eq!(b.params[1].ty, "?\\Closure");
        assert_eq!(b.params[1].default.as_deref(), Some("null"));

        assert_eq!(f.functions[1].component_kind, Some(Kind::Template));
        assert_eq!(f.functions[1].template_markup, Some(1));
        assert_eq!(f.functions[2].component_kind, None);
        assert_eq!(f.routes.len(), 1);
        assert_eq!(f.routes[0].fqn, "App\\Component\\Card");
        assert_eq!((f.routes[0].is_component, f.routes[0].is_function), (false, true), "the Symfony #[Route] cannot mark a function");
        assert_eq!(f.markup_scopes, vec![MarkupScope::Function(0), MarkupScope::Function(1)]);
    }

    #[test]
    fn a_function_needs_component_to_be_a_component() {
        let f = scan_src(
            r#"<?phpm
namespace App;
use Phem\Attribute\Component;
function Format(string $s) { return <b>{{ s }}</b>; }
#[Component]
function card() { return <div />; }
#[\App\Component]
function Other() { return <p />; }
"#,
        );

        let kinds: Vec<_> = f.functions.iter().map(|x| x.component_kind).collect();
        assert_eq!(kinds, [None, Some(Kind::Template), None], "a plain PHP function can return markup");
        assert_eq!(f.foreign_attrs.len(), 1, "`#[\\App\\Component]` is not the Phem attribute");
    }

    #[test]
    fn a_page_class_is_a_component() {
        let f = scan_src("<?phpm\nnamespace App;\nuse Phem\\Attribute\\Page;\n#[Page('/')]\nfinal class Home { public function render() { return <p />; } }\n");
        assert!(f.classes[0].is_component, "#[Page] needs no #[Component]");
        assert_eq!((f.routes[0].attr, f.routes[0].is_component, f.routes[0].is_function), ("Page", true, false));
    }

    #[test]
    fn a_page_function_is_a_component() {
        let f = scan_src("<?phpm\nnamespace App;\nuse Phem\\Attribute\\Page;\n#[Page('/a')]\n#[Page('/b', name: 'b')]\nfunction About() { return <p />; }\n#[Page('/c')]\nfunction Contact() { $to = 'me'; return <p>{{ to }}</p>; }\n");
        let kinds: Vec<_> = f.functions.iter().map(|x| x.component_kind).collect();
        assert_eq!(kinds, [Some(Kind::Template), Some(Kind::Function)], "#[Page] needs no #[Component]");
        let routes: Vec<_> = f.routes.iter().map(|r| (r.fqn.as_str(), r.attr, r.is_component, r.is_function)).collect();
        assert_eq!(routes, [("App\\About", "Page", true, true), ("App\\Contact", "Page", true, true)], "one route declaration for the repeated #[Page]");
    }

    #[test]
    fn class_component() {
        let f = scan_src(
            r#"<?phpm
namespace App;
use Phem\Attribute\{Component, Prop, State};
#[Component]
final class Counter {
    #[Prop] public string $label = 'Clicked';
    #[State] public int $count = 0;
    public int $max = 3;
    private int $x;
    public function __construct(public readonly Clock $clock) {}
    public function increment(): void { $this->count++; }
    public function render() { return <button>{{ label }}</button>; }
}
"#,
        );

        let c = &f.classes[0];
        assert!(c.is_component);
        assert_eq!(c.fqn, "App\\Counter");
        let names: Vec<_> = c.props.iter().map(|p| (p.name.as_str(), p.is_public, p.is_state)).collect();
        assert_eq!(names, [("label", true, false), ("count", true, true), ("max", true, false), ("x", false, false), ("clock", true, false)]);
        let props = &crate::components(&f)[0].props;
        assert_eq!(props, &["label"], "only #[Prop] properties are props");
        assert_eq!(c.methods.len(), 3);
        assert_eq!(f.markup_scopes, vec![MarkupScope::Method(0, 2)]);
    }

    #[test]
    fn property_hooks_and_closure_defaults() {
        let f = scan_src(
            r#"<?phpm
#[Component]
final class Form {
    private Form $form {
        get => $this->form ??= new Form();
    }
    public int $n = 1 { set => $this->n = $value; }
    private \Closure $f = static function () { return 1; };
    public function render() { return <div />; }
}
"#,
        );

        let c = &f.classes[0];
        let props: Vec<_> = c.props.iter().map(|p| p.name.as_str()).collect();
        assert_eq!(props, ["form", "n", "f"]);
        assert_eq!(c.methods.len(), 1);
        assert_eq!(f.markup_scopes, vec![MarkupScope::Method(0, 0)]);
    }

    #[test]
    fn rest_props() {
        let f = scan_src(
            r#"<?phpm
use Phem\Attribute\{Component, Prop, State};
#[Component]
final class Link {
    #[Prop] public string $label = '';
    #[Prop(rest: false)] public string $title = '';
    #[State, Prop(rest: true)] public array $attrs = [];
    #[Prop(true)] public array $more = [];
    #[State(rest: true)] public array $other = [];
    public function render() { return <a />; }
}
"#,
        );

        let rest: Vec<_> = f.classes[0].props.iter().map(|p| (p.name.as_str(), p.is_rest)).collect();
        assert_eq!(rest, [("label", false), ("title", false), ("attrs", true), ("more", true), ("other", false)]);
        let def = &crate::components(&f)[0];
        assert_eq!(def.props, ["label", "title"], "a rest prop is not a named prop");
        assert!(def.rest);
    }
}
