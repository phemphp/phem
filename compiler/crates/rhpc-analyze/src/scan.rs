//! A structural scan of PHP tokens: namespace, imports, functions, classes, and the
//! function that contains each markup literal. It is not a PHP parser; it reads only the
//! declarations that the compiler needs.

use crate::{Kind, ATTR_COMPONENT, ATTR_PAGE, ATTR_STATE};
use rhpc_syntax::php::{Lexed, TokKind, Token};
use rhpc_syntax::Span;
use std::collections::HashMap;

#[derive(Clone, Debug, Default)]
pub struct FileInfo {
    /// Path relative to the source root, with `/` separators.
    pub rel: String,
    pub namespace: String,
    /// Class imports: lowercase alias → FQCN.
    pub class_uses: HashMap<String, String>,
    /// Function imports: lowercase alias → FQN.
    pub function_uses: HashMap<String, String>,
    pub functions: Vec<Func>,
    pub classes: Vec<Class>,
    /// The components marked with `#[Page]` (a valid file has at most one).
    pub pages: Vec<PageDecl>,
    /// `export default` keywords (not PHP; the compiler reports them).
    pub export_defaults: Vec<Span>,
    /// Attributes with a known short name (`Page`, `Component`, `State`) that resolve to
    /// another class: (short name, resolved name, span).
    pub foreign_attrs: Vec<(String, String, Span)>,
    /// For each markup literal (by index), the function or method that contains it.
    pub markup_scopes: Vec<MarkupScope>,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MarkupScope {
    TopLevel,
    Function(usize),
    /// (class index, method index)
    Method(usize, usize),
}

/// A component marked with `#[Page]`: the page of the file's route.
#[derive(Clone, Debug)]
pub struct PageDecl {
    pub fqn: String,
    /// The `Page` attribute name.
    pub span: Span,
    /// False for a function that is not a component (a lowercase name).
    pub is_component: bool,
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
    pub component_kind: Option<Kind>,
    /// For a template component, the index of its markup literal.
    pub template_markup: Option<usize>,
}

#[derive(Clone, Debug)]
pub struct Param {
    pub name: String,
    pub ty: String,
    pub default: Option<String>,
    pub variadic: bool,
    pub promoted: bool,
    pub promoted_public: bool,
}

#[derive(Clone, Debug)]
pub struct Class {
    pub name: String,
    pub fqn: String,
    pub name_span: Span,
    pub span: Span,
    pub is_component: bool,
    pub props: Vec<Prop>,
    pub methods: Vec<Func>,
}

#[derive(Clone, Debug)]
pub struct Prop {
    pub name: String,
    pub ty: String,
    pub is_public: bool,
    pub is_readonly: bool,
    pub is_static: bool,
    pub is_state: bool,
    /// A promoted constructor parameter: a service that the container injects, not a prop.
    pub is_promoted: bool,
    pub has_default: bool,
    pub span: Span,
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
    let mut i = 0;
    let n = toks.t.len();
    while i < n {
        let t = toks.t[i];
        match t.kind {
            TokKind::AttrOpen => {
                let end = toks.matching(i);
                attrs.extend(attr_names(&toks, i + 1, end));
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
                        let (f, end) = parse_function(&toks, i, &info.namespace);
                        let attrs = std::mem::take(&mut attrs);
                        check_attrs(&mut info, &attrs);
                        if let Some(span) = page_attr(&info, &attrs) {
                            info.pages.push(PageDecl { fqn: f.fqn.clone(), span, is_component: f.component_kind.is_some() });
                        }
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
                            let page = page_attr(&info, &attrs);
                            let (c, end) = parse_class(&toks, i, &info, attrs.into_iter().map(|(a, _)| a).collect());
                            if let Some(span) = page {
                                info.pages.push(PageDecl { fqn: c.fqn.clone(), span, is_component: c.is_component });
                            }
                            info.classes.push(c);
                            i = end + 1;
                            continue;
                        }
                    }
                    "interface" | "trait" | "enum" => {
                        // Skip the whole declaration.
                        let mut j = i;
                        while j < n && !toks.is_op(j, "{") {
                            j += 1;
                        }
                        attrs.clear();
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

    // A component function whose body is only `return <markup>;` is a template component.
    for f in &mut info.functions {
        if f.component_kind == Some(Kind::Function) {
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
        // Group use: `use App\Components\{A, B as C};`
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
    let (body, end) = if toks.is_op(j, "{") {
        let close = toks.matching(j);
        (Span::new(toks.t[j].span.end, toks.t[close].span.start), close)
    } else {
        (Span::new(toks.t[j].span.start, toks.t[j].span.start), j)
    };
    let component_kind = name.starts_with(|c: char| c.is_ascii_uppercase()).then_some(Kind::Function);
    let f = Func {
        fqn: qualify(ns, &name),
        name,
        name_span,
        span: Span::new(toks.t[at].span.start, toks.t[end].span.end),
        body,
        params,
        component_kind,
        template_markup: None,
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
        let mut p = Param { name: String::new(), ty: String::new(), default: None, variadic: false, promoted: false, promoted_public: false };
        let mut ty_start: Option<usize> = None;
        let mut k = i;
        while k < j {
            let t = toks.t[k];
            if t.kind == TokKind::AttrOpen {
                k = toks.matching(k) + 1;
                continue;
            }
            let text = toks.text(k);
            let lower = text.to_ascii_lowercase();
            if t.kind == TokKind::Ident && matches!(lower.as_str(), "public" | "private" | "protected" | "readonly") {
                p.promoted = true;
                p.promoted_public |= lower == "public";
            } else if t.kind == TokKind::Var {
                p.name = text[1..].to_string();
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

/// The span of a `#[Page]` attribute in a list of attributes.
fn page_attr(info: &FileInfo, attrs: &[(String, Span)]) -> Option<Span> {
    attrs.iter().find(|(a, _)| resolve_class(info, a) == ATTR_PAGE).map(|(_, s)| *s)
}

/// Records `#[Page]` / `#[Component]` / `#[State]` that do not resolve to the rephp attributes
/// (usually a missing `use Rephp\Attribute\...;`).
fn check_attrs(info: &mut FileInfo, attrs: &[(String, Span)]) {
    for (a, span) in attrs {
        let short = a.rsplit('\\').next().unwrap_or(a);
        if matches!(short, "Page" | "Component" | "State") {
            let resolved = resolve_class(info, a);
            if !resolved.starts_with("Rephp\\Attribute\\") {
                info.foreign_attrs.push((short.to_string(), resolved, *span));
            }
        }
    }
}

fn resolve_class(info: &FileInfo, name: &str) -> String {
    if let Some(rest) = name.strip_prefix('\\') {
        return rest.to_string();
    }
    let (first, tail) = name.split_once('\\').map_or((name, None), |(a, b)| (a, Some(b)));
    if let Some(fqn) = info.class_uses.get(&first.to_ascii_lowercase()) {
        return match tail {
            Some(t) => format!("{fqn}\\{t}"),
            None => fqn.clone(),
        };
    }
    qualify(&info.namespace, name)
}

fn parse_class(toks: &Toks, at: usize, info: &FileInfo, attrs: Vec<String>) -> (Class, usize) {
    let name = toks.text(at + 1).to_string();
    let name_span = toks.t[at + 1].span;
    let mut j = at + 2;
    while j < toks.t.len() && !toks.is_op(j, "{") {
        j += 1;
    }
    let close = toks.matching(j);
    let is_component = attrs.iter().any(|a| resolve_class(info, a) == ATTR_COMPONENT);
    let mut class = Class {
        fqn: qualify(&info.namespace, &name),
        name,
        name_span,
        span: Span::new(toks.t[at].span.start, toks.t[close].span.end),
        is_component,
        props: vec![],
        methods: vec![],
    };

    let mut i = j + 1;
    let mut member_attrs: Vec<String> = Vec::new();
    let mut mods: Vec<String> = Vec::new();
    let mut ty_start: Option<usize> = None;
    while i < close {
        let t = toks.t[i];
        let text = toks.text(i);
        let lower = text.to_ascii_lowercase();
        if t.kind == TokKind::AttrOpen {
            let end = toks.matching(i);
            member_attrs.extend(attr_names(toks, i + 1, end).into_iter().map(|(a, _)| a));
            i = end + 1;
            continue;
        }
        if t.kind == TokKind::Ident && matches!(lower.as_str(), "public" | "private" | "protected" | "readonly" | "static" | "var" | "final" | "abstract") {
            mods.push(lower);
            i += 1;
            continue;
        }
        if t.kind == TokKind::Ident && (lower == "const" || lower == "use" || lower == "case") {
            while i < close && !toks.is_op(i, ";") {
                if toks.is_op(i, "{") {
                    i = toks.matching(i);
                }
                i += 1;
            }
            member_attrs.clear();
            mods.clear();
            ty_start = None;
            i += 1;
            continue;
        }
        if t.kind == TokKind::Ident && lower == "function" {
            let (m, end) = parse_function(toks, i, "");
            if m.name.eq_ignore_ascii_case("__construct") {
                for p in m.params.iter().filter(|p| p.promoted) {
                    class.props.push(Prop {
                        name: p.name.clone(),
                        ty: p.ty.clone(),
                        is_public: p.promoted_public,
                        is_readonly: false,
                        is_static: false,
                        is_state: false,
                        is_promoted: true,
                        has_default: p.default.is_some(),
                        span: m.name_span,
                    });
                }
            }
            class.methods.push(Func { fqn: format!("{}::{}", class.fqn, m.name), component_kind: None, ..m });
            member_attrs.clear();
            mods.clear();
            ty_start = None;
            i = end + 1;
            continue;
        }
        if t.kind == TokKind::Var {
            let ty = ty_start.map_or(String::new(), |s| toks.src[toks.t[s].span.start..toks.t[i - 1].span.end].trim().to_string());
            let has_default = toks.is_op(i + 1, "=");
            class.props.push(Prop {
                name: text[1..].to_string(),
                ty,
                is_public: mods.iter().any(|m| m == "public" || m == "var") || !mods.iter().any(|m| m == "private" || m == "protected"),
                is_readonly: mods.iter().any(|m| m == "readonly"),
                is_static: mods.iter().any(|m| m == "static"),
                is_state: member_attrs.iter().any(|a| resolve_class(info, a) == ATTR_STATE),
                is_promoted: false,
                has_default,
                span: t.span,
            });
            while i < close && !toks.is_op(i, ";") {
                if toks.is_op(i, "(") || toks.is_op(i, "[") || toks.is_op(i, "{") {
                    i = toks.matching(i);
                }
                i += 1;
            }
            member_attrs.clear();
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
    use rhpc_syntax::php::lex;

    fn scan_src(src: &str) -> FileInfo {
        let lexed = lex(src).unwrap();
        scan("components/test.rhp", src, &lexed)
    }

    #[test]
    fn namespace_uses_and_functions() {
        let f = scan_src(
            r#"<?php
namespace App\Components;
use function Rephp\{useState, useEffect as ue};
use App\Ui\Card, App\Ui\Button as Btn;
use Rephp\Attribute\Page;
function MyButton(int $count, ?\Closure $onClick = null, ?Html $children = null) {
    [$c, $s] = useState(0);
    return <button>{{ count }}</button>;
}
#[Page]
function Card(string $title) { return (<div>{{ title }}</div>); }
function helper() {}
"#,
        );
        assert_eq!(f.namespace, "App\\Components");
        assert_eq!(f.function_uses["usestate"], "Rephp\\useState");
        assert_eq!(f.function_uses["ue"], "Rephp\\useEffect");
        assert_eq!(f.class_uses["btn"], "App\\Ui\\Button");
        assert_eq!(f.functions.len(), 3);
        let b = &f.functions[0];
        assert_eq!(b.fqn, "App\\Components\\MyButton");
        assert_eq!(b.component_kind, Some(Kind::Function));
        assert_eq!(b.params[1].name, "onClick");
        assert_eq!(b.params[1].ty, "?\\Closure");
        assert_eq!(b.params[1].default.as_deref(), Some("null"));
        assert_eq!(f.functions[1].component_kind, Some(Kind::Template));
        assert_eq!(f.functions[1].template_markup, Some(1));
        assert_eq!(f.functions[2].component_kind, None);
        assert_eq!(f.pages.len(), 1);
        assert_eq!(f.pages[0].fqn, "App\\Components\\Card");
        assert!(f.pages[0].is_component);
        assert_eq!(f.markup_scopes, vec![MarkupScope::Function(0), MarkupScope::Function(1)]);
    }

    #[test]
    fn class_component() {
        let f = scan_src(
            r#"<?php
namespace App;
use Rephp\Attribute\{Component, State};
#[Component]
final class Counter {
    public string $label = 'Clicked';
    #[State] public int $count = 0;
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
        assert_eq!(names, [("label", true, false), ("count", true, true), ("x", false, false), ("clock", true, false)]);
        let props = &crate::components(&f)[0].props;
        assert_eq!(props, &["label"], "state, private and constructor properties are not props");
        assert_eq!(c.methods.len(), 3);
        assert_eq!(f.markup_scopes, vec![MarkupScope::Method(0, 2)]);
    }
}
