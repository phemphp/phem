//! Lowers a markup tree to Twig source.

use phemc_analyze::{FileInfo, Registry};
use phemc_syntax::diag::codes;
use phemc_syntax::markup::{Attr, AttrKind, AttrValue, Element, ExprPart, Node, StrPart, TwigExpr};
use phemc_syntax::{Diagnostic, Span};
use std::collections::HashSet;

use crate::php_str;

pub struct Lowerer<'a> {
    pub info: &'a FileInfo,
    pub reg: &'a Registry,
    pub diags: Vec<Diagnostic>,
    /// When lowering the template of a template component: its slot names.
    pub slots: Vec<String>,
    slots_defined: HashSet<String>,
    /// The number of open `<head>` and `<svg>` elements: a `<title>` in one is the title of the
    /// document, or the name of an image.
    title_scopes: usize,
    /// The number of open component tags: their children are blocks of an embed, so a slot of
    /// the template in them prints its prop, not a block.
    components: usize,
}

impl<'a> Lowerer<'a> {
    pub fn new(info: &'a FileInfo, reg: &'a Registry) -> Self {
        Self { info, reg, diags: vec![], slots: vec![], slots_defined: HashSet::new(), title_scopes: 0, components: 0 }
    }

    pub fn lower_root(&mut self, node: &Node, slots: Vec<String>) -> String {
        self.slots = slots;
        self.slots_defined.clear();
        let mut out = String::new();
        self.node(node, &mut out);
        out
    }

    fn error(&mut self, code: &'static str, msg: impl Into<String>, span: Span) -> &mut Diagnostic {
        self.diags.push(Diagnostic::error(code, msg, span));
        self.diags.last_mut().unwrap()
    }

    fn nodes(&mut self, nodes: &[Node], out: &mut String) {
        for n in nodes {
            self.node(n, out);
        }
    }

    fn node(&mut self, node: &Node, out: &mut String) {
        match node {
            Node::Text(t) | Node::HtmlComment(t) | Node::Verbatim(t) => out.push_str(&t.text),
            Node::TwigComment(_) => {}
            Node::Tag(t) => out.push_str(&t.raw),
            Node::Fragment(f) => self.nodes(&f.children, out),
            Node::Block(b) => {
                if b.name() == "for" {
                    self.check_keys(&b.branches[0].children);
                }
                for br in &b.branches {
                    out.push_str(&br.tag.raw);
                    self.nodes(&br.children, out);
                }
                out.push_str(&b.close.raw);
            }
            Node::Output(o) => {
                if o.expr.has_markup() {
                    match self.markup_expr(&o.expr) {
                        Ok(s) => out.push_str(&s),
                        Err(d) => self.diags.push(d),
                    }
                    return;
                }
                let code = o.expr.code();
                let code = code.trim();
                // `{{ children }}`, or `{{ children ?? 'default' }}`: the default is the content
                // of the block, so it shows when the caller gives no children.
                let name = code.split_once("??").map_or(code, |(slot, _)| slot.trim());
                if self.components == 0 && self.slots.iter().any(|s| s == name) {
                    if self.slots_defined.insert(name.to_string()) {
                        out.push_str(&format!("{{% block {name} %}}{{{{ {code} }}}}{{% endblock %}}"));
                    } else {
                        out.push_str(&format!("{{{{ block('{name}') }}}}"));
                    }
                    return;
                }
                out.push_str(&o.raw);
            }
            Node::Element(e) if e.is_component() => self.component(e, out),
            Node::Element(e) => self.element(e, out),
        }
    }

    /// W002: elements rendered in a `{% for %}` should have a `key`.
    fn check_keys(&mut self, body: &[Node]) {
        for n in body {
            match n {
                Node::Element(e) => {
                    let has_key = e.attrs.iter().any(|a| matches!(&a.kind, AttrKind::Plain { name, .. } if name == "key"));
                    if !has_key {
                        self.diags.push(
                            Diagnostic::warning(codes::MISSING_KEY, format!("each `<{}>` in a list should have a unique `key`", e.name), e.name_span)
                                .with_help("add `key={{ item.id }}`, so updates keep the right element"),
                        );
                    }
                    return;
                }
                Node::Block(_) | Node::Output(_) => return,
                _ => {}
            }
        }
    }

    fn element(&mut self, e: &Element, out: &mut String) {
        let name = e.name.to_ascii_lowercase();
        if name == "title" && self.title_scopes == 0 {
            self.diags.push(
                Diagnostic::warning(codes::TITLE_IN_MARKUP, "a `<title>` outside `<head>` is not the title of the page", e.name_span)
                    .with_help("put the `<title>` in the `<head>` of the document component, and give the title through a slot, for example `<Document:title>Home</Document:title>`"),
            );
        }
        out.push('<');
        out.push_str(&e.name);
        if e.attrs.iter().any(|a| matches!(a.kind, AttrKind::Spread(_))) {
            self.merged_attrs(&e.attrs, out);
        } else {
            for a in &e.attrs {
                self.dom_attr(a, out);
            }
        }
        let void = phemc_syntax::markup::VOID_ELEMENTS.contains(&name.as_str());
        out.push('>');
        if void {
            return;
        }
        let scope = usize::from(name == "head" || name == "svg");
        self.title_scopes += scope;
        self.nodes(&e.children, out);
        self.title_scopes -= scope;
        out.push_str("</");
        out.push_str(&e.name);
        out.push('>');
    }

    fn expr_code(&mut self, x: &TwigExpr) -> String {
        if x.has_markup() {
            self.error(codes::MARKUP_IN_EXPRESSION, "markup is not allowed in an attribute value", x.span)
                .help = Some("assign the markup to a PHP variable first, or use a slot: `<Card:header>...</Card:header>`".into());
            return "null".into();
        }
        x.code().trim().to_string()
    }

    /// A quoted value as a Twig string expression: `'a ' ~ (b)`.
    fn quoted_expr(&mut self, parts: &[StrPart], span: Span) -> String {
        let mut items = Vec::new();
        for p in parts {
            match p {
                StrPart::Lit(s) => {
                    if s.contains("{%") || s.contains("{#") {
                        self.error(codes::BAD_ATTRIBUTE, "Twig tags are not allowed in this attribute value", span)
                            .help = Some("use an expression: `name={{ ok ? 'a' : 'b' }}`".into());
                    }
                    items.push(php_str(s));
                }
                StrPart::Expr(x) => {
                    let c = self.expr_code(x);
                    items.push(format!("({c})"));
                }
            }
        }
        match items.len() {
            0 => "''".into(),
            1 if matches!(parts[0], StrPart::Expr(_)) => format!("'' ~ {}", items[0]),
            _ => items.join(" ~ "),
        }
    }

    /// The attributes of an element with a spread, in one call: `<a x="1" {{ ...rest }} y={{ v }}>`
    /// → `phem_attrs({'x': '1'}, rest, {'y': (v)})`. A later value replaces an earlier one, as in React.
    fn merged_attrs(&mut self, attrs: &[Attr], out: &mut String) {
        let mut args: Vec<String> = Vec::new();
        let mut hash: Vec<String> = Vec::new();
        for a in attrs {
            match &a.kind {
                AttrKind::Spread(x) => {
                    if !hash.is_empty() {
                        args.push(format!("{{{}}}", hash.join(", ")));
                        hash.clear();
                    }
                    args.push(self.expr_code(x));
                }
                // An inline script (`onclick="..."`) of the markup: the runtime does not render
                // `on*` names from phem_attrs(), because a spread can contain data.
                AttrKind::Plain { name, .. } if is_inline_script(name) => self.dom_attr(a, out),
                AttrKind::Plain { name, name_span, value } => {
                    let event = event_name(name);
                    let is_event = event.is_some();
                    if let Some(event) = &event {
                        self.check_event(name, event, *name_span);
                    }
                    let v = match value {
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            if is_event {
                                self.check_handler(name, &c, x.span);
                            }
                            format!("({c})")
                        }
                        _ if is_event => {
                            self.handler_not_function(name, *name_span);
                            continue;
                        }
                        AttrValue::None => "true".to_string(),
                        AttrValue::Quoted { parts, .. } => self.quoted_expr(parts, a.span),
                    };
                    hash.push(format!("{}: {v}", php_str(name)));
                }
            }
        }
        if !hash.is_empty() {
            args.push(format!("{{{}}}", hash.join(", ")));
        }
        let ws = attrs.first().map_or(" ", |a| if a.ws_before.is_empty() { " " } else { a.ws_before.as_str() });
        out.push_str(&format!("{ws}{{{{ phem_attrs({}) }}}}", args.join(", ")));
    }

    /// Warns about `onClick={{ handle() }}`: it calls the handler during render.
    fn check_handler(&mut self, name: &str, code: &str, span: Span) {
        if phemc_twig_scan::is_call(code) {
            self.diags.push(
                Diagnostic::warning(codes::HANDLER_CALLED, format!("`{name}` calls the handler during render"), span)
                    .with_label("this is a call")
                    .with_help(format!("pass the function, do not call it: `{name}={{{{ {} }}}}`", code.split('(').next().unwrap_or("").trim())),
            );
        }
    }

    /// W003: the `phem` client listens only to some events (`DomEvents::LISTENED` of the runtime).
    fn check_event(&mut self, name: &str, event: &str, span: Span) {
        if !LISTENED_EVENTS.contains(&event) {
            self.diags.push(
                Diagnostic::warning(codes::UNKNOWN_EVENT, format!("`{name}` never runs: the browser does not send `{event}` events"), span)
                    .with_help("use one of: onClick, onDoubleClick, onChange, onInput, onSubmit, onKeyDown, onKeyUp, onFocus, onBlur"),
            );
        }
    }

    fn handler_not_function(&mut self, name: &str, span: Span) {
        self.error(codes::BAD_ATTRIBUTE, format!("`{name}` takes a function"), span).help = Some(format!("write `{name}={{{{ handleIt }}}}`"));
    }

    fn dom_attr(&mut self, a: &Attr, out: &mut String) {
        let ws = if a.ws_before.is_empty() { " " } else { a.ws_before.as_str() };
        match &a.kind {
            AttrKind::Spread(_) => unreachable!("an element with a spread uses merged_attrs()"),
            AttrKind::Plain { name, name_span, value } => {
                let html_name = match name.as_str() {
                    "className" => "class",
                    "htmlFor" => "for",
                    n => n,
                };
                if name == "key" {
                    let k = match value {
                        AttrValue::Expr(x) => self.expr_code(x),
                        AttrValue::Quoted { parts, .. } => self.quoted_expr(parts, a.span),
                        AttrValue::None => "true".into(),
                    };
                    out.push_str(&format!("{ws}{{{{ phem_key({k}) }}}}"));
                    return;
                }
                if let Some(event) = event_name(name) {
                    self.check_event(name, &event, *name_span);
                    match value {
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            self.check_handler(name, &c, x.span);
                            out.push_str(&format!("{ws}{{{{ phem_on('{event}', {c}) }}}}"));
                        }
                        _ => self.handler_not_function(name, *name_span),
                    }
                    return;
                }
                match value {
                    AttrValue::None => out.push_str(&format!("{ws}{html_name}")),
                    AttrValue::Quoted { raw, quote, .. } => out.push_str(&format!("{ws}{html_name}={quote}{raw}{quote}")),
                    AttrValue::Expr(x) => {
                        let c = self.expr_code(x);
                        if html_name == "style" {
                            out.push_str(&format!("{ws}{{{{ phem_style({c}) }}}}"));
                        } else {
                            out.push_str(&format!("{ws}{{{{ phem_attr('{html_name}', {c}) }}}}"));
                        }
                    }
                }
            }
        }
    }

    /// The FQN of a component tag: a function imported with `use function`, or a class name.
    pub fn resolve_component(&self, name: &str) -> String {
        if !name.contains('\\') {
            if let Some(f) = self.info.function_uses.get(&name.to_ascii_lowercase()) {
                return f.clone();
            }
        }
        self.info.resolve_class(name)
    }

    fn component(&mut self, e: &Element, out: &mut String) {
        let fqcn = self.resolve_component(&e.name);
        let Some(def) = self.reg.get(&fqcn).cloned() else {
            let short = fqcn.rsplit('\\').next().unwrap_or(&fqcn).to_string();
            let similar: Vec<&str> = self.reg.keys().filter(|k| k.rsplit('\\').next() == Some(short.as_str())).map(|s| s.as_str()).collect();
            let plain = self.info.functions.iter().find(|f| f.fqn.eq_ignore_ascii_case(&fqcn));
            let help = match (plain, similar.first()) {
                (Some(f), _) => format!("add `#[Component]` before `function {}()`, and `use {};`", f.name, phemc_analyze::ATTR_COMPONENT),
                (None, Some(&phemc_analyze::ROOT)) => format!("import it: `use {};`", phemc_analyze::ROOT),
                (None, Some(s)) => format!("import it: `use function {s};` (or `use {s};` for a class component)"),
                (None, None) => format!("declare `#[Component] function {short}()` in a .phpm file, or check the `use` imports"),
            };
            self.error(codes::UNKNOWN_COMPONENT, format!("unknown component `<{}>` (resolved to `{fqcn}`)", e.name), e.name_span).help = Some(help);
            return;
        };

        let mut props = Vec::new();
        let mut key: Option<String> = None;
        let mut has_spread = false;
        let mut given = HashSet::new();
        for a in &e.attrs {
            match &a.kind {
                AttrKind::Spread(x) => {
                    has_spread = true;
                    let c = self.expr_code(x);
                    props.push(format!("...({c})"));
                }
                AttrKind::Plain { name, name_span, value } => {
                    let v = match value {
                        AttrValue::None => "true".to_string(),
                        AttrValue::Expr(x) => format!("({})", self.expr_code(x)),
                        AttrValue::Quoted { parts, .. } => self.quoted_expr(parts, a.span),
                    };
                    if name == "key" {
                        key = Some(v);
                        continue;
                    }
                    if !def.rest && !def.open && !def.props.iter().any(|p| p == name) {
                        let list = if def.props.is_empty() { "none".to_string() } else { def.props.join(", ") };
                        self.error(codes::UNKNOWN_PROP, format!("`<{}>` has no prop `{name}`", e.name), *name_span).help =
                            Some(format!("its props are: {list}"));
                    }
                    given.insert(name.clone());
                    props.push(format!("{}: {v}", php_str(name)));
                }
            }
        }

        // Children and named slots (`<Card:footer>`).
        self.components += 1;
        let slot_prefix = format!("{}:", e.name);
        let mut blocks: Vec<(String, String)> = Vec::new();
        let mut default_children: Vec<&Node> = Vec::new();
        for c in &e.children {
            match c {
                Node::Element(s) if s.name.starts_with(&slot_prefix) => {
                    let slot = s.name[slot_prefix.len()..].to_string();
                    if !def.open && !def.slots.contains(&slot) {
                        self.error(codes::UNKNOWN_SLOT, format!("`<{}>` has no slot `{slot}`", e.name), s.name_span).help =
                            Some(format!("add a `?Html ${slot} = null` parameter to `{}`", def.fqcn));
                    }
                    let mut body = String::new();
                    self.nodes(&s.children, &mut body);
                    given.insert(slot.clone());
                    blocks.push((slot, body));
                }
                other => default_children.push(other),
            }
        }
        if default_children.iter().any(|n| !n.is_blank() && !matches!(n, Node::TwigComment(_))) {
            if !def.open && !def.slots.iter().any(|s| s == "children") {
                self.error(codes::NO_CHILDREN, format!("`<{}>` does not accept children", e.name), e.name_span).help =
                    Some(format!("add a `?Html $children = null` parameter to `{}`", def.fqcn));
            }
            let mut body = String::new();
            for n in default_children {
                self.node(n, &mut body);
            }
            given.insert("children".to_string());
            blocks.insert(0, ("children".into(), body));
        }
        self.components -= 1;
        if !has_spread {
            for r in def.required.iter().filter(|r| !given.contains(*r)) {
                self.error(codes::UNKNOWN_PROP, format!("`<{}>` needs the prop `{r}`", e.name), e.name_span);
            }
        }

        let mut head = format!("component {}", php_str(&fqcn));
        if !props.is_empty() {
            head.push_str(&format!(" with {{{}}}", props.join(", ")));
        }
        if let Some(k) = key {
            head.push_str(&format!(" key {k}"));
        }
        head.push_str(" only");
        // Keep the line count of the source tag, so Twig error lines stay close.
        let newlines = e.attrs.iter().map(|a| a.newlines_before).sum::<usize>();
        let pad = "\n".repeat(newlines);
        if blocks.is_empty() {
            out.push_str(&format!("{{% {head} %}}{pad}"));
        } else {
            let head = head.replacen("component ", "component embed ", 1);
            out.push_str(&format!("{{% {head} %}}{pad}"));
            for (name, body) in blocks {
                out.push_str(&format!("{{% block {name} %}}{{% with _phem_caller only %}}{body}{{% endwith %}}{{% endblock %}}"));
            }
            out.push_str("{% endcomponent %}");
        }
    }

    /// `{{ c ? <A /> : <B /> }}` → `{% if c %}...{% else %}...{% endif %}`; `{{ c and <A /> }}` → `{% if c %}...{% endif %}`.
    fn markup_expr(&mut self, x: &TwigExpr) -> Result<String, Diagnostic> {
        let mut code = String::new();
        let mut marks: Vec<&Node> = Vec::new();
        for p in &x.parts {
            match p {
                ExprPart::Code(c) => code.push_str(c),
                ExprPart::Markup(n) => {
                    code.push_str(&format!("\u{1}{}\u{2}", marks.len()));
                    marks.push(n);
                }
            }
        }
        self.cond_branch(&code, &marks, x.span)
    }

    fn cond_branch(&mut self, s: &str, marks: &[&Node], span: Span) -> Result<String, Diagnostic> {
        let t = strip_parens(s.trim());
        if let Some(i) = placeholder(t) {
            let mut out = String::new();
            self.node(marks[i], &mut out);
            return Ok(out);
        }
        if !t.contains('\u{1}') {
            return Ok(format!("{{{{ {t} }}}}"));
        }
        let unsupported = || {
            Diagnostic::error(codes::MARKUP_IN_EXPRESSION, "markup can appear in a Twig expression only as a branch of `?:` or after `and`", span)
                .with_help("assign the markup to a PHP variable and print it: `{{ content }}`, or use `{% if %}`")
        };
        if let Some((c, a, b)) = split_ternary(t) {
            if c.contains('\u{1}') {
                return Err(unsupported());
            }
            let a = self.cond_branch(a, marks, span)?;
            let b = self.cond_branch(b, marks, span)?;
            return Ok(format!("{{% if {} %}}{a}{{% else %}}{b}{{% endif %}}", c.trim()));
        }
        if let Some((c, m)) = split_and(t) {
            if c.contains('\u{1}') {
                return Err(unsupported());
            }
            let m = self.cond_branch(m, marks, span)?;
            return Ok(format!("{{% if {} %}}{m}{{% endif %}}", c.trim()));
        }
        Err(unsupported())
    }
}

/// The events of `event_name()` that the `phem` client listens to. The runtime makes `focus`
/// and `blur` the `focusin` and `focusout` events.
const LISTENED_EVENTS: &[&str] = &["click", "dblclick", "input", "change", "submit", "keydown", "keyup", "focus", "blur", "focusin", "focusout"];

/// `onClick` → `click`, `onDoubleClick` → `dblclick`.
fn event_name(attr: &str) -> Option<String> {
    let rest = attr.strip_prefix("on")?;
    if !rest.starts_with(|c: char| c.is_ascii_uppercase()) {
        return None;
    }
    Some(match rest {
        "DoubleClick" => "dblclick".into(),
        r => r.to_ascii_lowercase(),
    })
}

/// `onclick`, `ONLOAD`: an HTML event attribute with inline script, not a handler (`onClick`).
fn is_inline_script(attr: &str) -> bool {
    event_name(attr).is_none() && attr.get(..2).is_some_and(|p| p.eq_ignore_ascii_case("on"))
}


fn placeholder(t: &str) -> Option<usize> {
    t.strip_prefix('\u{1}')?.strip_suffix('\u{2}')?.parse().ok()
}

fn strip_parens(t: &str) -> &str {
    let mut t = t;
    while t.starts_with('(') && t.ends_with(')') {
        // Only when the outer parentheses match each other.
        let inner = &t[1..t.len() - 1];
        let mut depth = 0i32;
        let balanced = inner.chars().all(|c| {
            match c {
                '(' => depth += 1,
                ')' => depth -= 1,
                _ => {}
            }
            depth >= 0
        });
        if !balanced || depth != 0 {
            break;
        }
        t = inner.trim();
    }
    t
}

/// Byte positions of top-level characters (depth 0, outside strings).
fn top_level(s: &str) -> Vec<(usize, char)> {
    let mut out = Vec::new();
    let mut depth = 0i32;
    let mut quote: Option<char> = None;
    let mut esc = false;
    for (i, c) in s.char_indices() {
        if let Some(q) = quote {
            if esc {
                esc = false;
            } else if c == '\\' {
                esc = true;
            } else if c == q {
                quote = None;
            }
            continue;
        }
        match c {
            '\'' | '"' => quote = Some(c),
            '(' | '[' | '{' => depth += 1,
            ')' | ']' | '}' => depth -= 1,
            _ if depth == 0 => out.push((i, c)),
            _ => {}
        }
    }
    out
}

fn split_ternary(s: &str) -> Option<(&str, &str, &str)> {
    let top = top_level(s);
    let b = s.as_bytes();
    let is_q = |i: usize| b[i] == b'?' && b.get(i + 1) != Some(&b'?') && b.get(i + 1) != Some(&b':') && (i == 0 || b[i - 1] != b'?');
    let q = top.iter().find(|(i, c)| *c == '?' && is_q(*i))?.0;
    let mut nested = 0;
    for (i, c) in top.iter().copied().filter(|(i, _)| *i > q) {
        if c == '?' && is_q(i) {
            nested += 1;
        } else if c == ':' {
            if nested == 0 {
                return Some((&s[..q], &s[q + 1..i], &s[i + 1..]));
            }
            nested -= 1;
        }
    }
    None
}

fn split_and(s: &str) -> Option<(&str, &str)> {
    let top: HashSet<usize> = top_level(s).into_iter().map(|(i, _)| i).collect();
    let b = s.as_bytes();
    let mut found = None;
    let mut from = 0;
    while let Some(rel) = s[from..].find("and") {
        let i = from + rel;
        let before = i == 0 || !(b[i - 1].is_ascii_alphanumeric() || b[i - 1] == b'_');
        let after = b.get(i + 3).is_none_or(|c| !(c.is_ascii_alphanumeric() || *c == b'_'));
        if before && after && top.contains(&i) {
            found = Some(i);
        }
        from = i + 3;
    }
    let i = found?;
    Some((&s[..i], &s[i + 3..]))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ternary_split() {
        assert_eq!(split_ternary("a ? b : c"), Some(("a ", " b ", " c")));
        assert_eq!(split_ternary("a ? (x ? 1 : 2) : c"), Some(("a ", " (x ? 1 : 2) ", " c")));
        assert_eq!(split_ternary("a ? b ? 1 : 2 : c"), Some(("a ", " b ? 1 : 2 ", " c")));
        assert_eq!(split_ternary("a ?? b"), None);
        assert_eq!(split_ternary("{x: 1}"), None);
    }

    #[test]
    fn and_split() {
        assert_eq!(split_and("a and b"), Some(("a ", " b")));
        assert_eq!(split_and("brand and x"), Some(("brand ", " x")));
        assert_eq!(split_and("a"), None);
    }

    #[test]
    fn events() {
        assert_eq!(event_name("onClick").as_deref(), Some("click"));
        assert_eq!(event_name("onDoubleClick").as_deref(), Some("dblclick"));
        assert_eq!(event_name("one"), None);
    }
}
