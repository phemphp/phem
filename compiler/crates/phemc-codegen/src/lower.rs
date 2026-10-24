//! Lowers a markup tree to Twig source.

use phemc_analyze::{is_bound_type, Class, FileInfo, Param, Prop, Registry};
use phemc_syntax::diag::codes;
use phemc_syntax::markup::{Attr, AttrKind, AttrValue, Element, ExprPart, Node, StrPart, TwigExpr};
use phemc_syntax::{Diagnostic, Span};
use std::collections::HashSet;

use crate::{has_variable, php_str};

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
    /// The class of the method of the markup, if any: `phem:bind` binds one of its #[State] properties.
    pub class: Option<&'a Class>,
    /// The source of the function or the method of the markup: its local variables.
    pub scope_text: &'a str,
    /// The parameters of the function of the markup: its props.
    pub params: &'a [Param],
    /// The bound value of the open `<select phem:bind>`: its options get `selected`.
    bound_select: Option<String>,
}

impl<'a> Lowerer<'a> {
    pub fn new(info: &'a FileInfo, reg: &'a Registry) -> Self {
        Self { info, reg, diags: vec![], slots: vec![], slots_defined: HashSet::new(), title_scopes: 0, components: 0, class: None, scope_text: "", params: &[], bound_select: None }
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

        self.check_timing(e);
        let bind = self.bind(e);

        out.push('<');
        out.push_str(&e.name);
        if e.attrs.iter().any(|a| matches!(a.kind, AttrKind::Spread(_))) {
            self.merged_attrs(&e.attrs, out);
        } else {
            for a in &e.attrs {
                self.dom_attr(a, out);
            }
        }

        if let Some(b) = &bind {
            out.push_str(&b.attrs);
        }

        if name == "option" {
            if let Some(bound) = self.bound_select.clone() {
                self.option_selected(e, &bound, out);
            }
        }

        let void = phemc_syntax::markup::VOID_ELEMENTS.contains(&name.as_str());
        out.push('>');
        if void {
            return;
        }

        if let Some(BoundField { text: Some(text), .. }) = &bind {
            // A bound `<textarea>`: its text is the value.
            out.push_str(text);
            out.push_str("</");
            out.push_str(&e.name);
            out.push('>');
            return;
        }

        let select = bind.as_ref().and_then(|b| b.select.clone());
        let outer_select = if select.is_some() { std::mem::replace(&mut self.bound_select, select) } else { None };

        let scope = usize::from(name == "head" || name == "svg");
        self.title_scopes += scope;
        self.nodes(&e.children, out);
        self.title_scopes -= scope;
        if bind.as_ref().is_some_and(|b| b.select.is_some()) {
            self.bound_select = outer_select;
        }

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
                // See bind().
                AttrKind::Plain { name, .. } if name == BIND => {}
                AttrKind::Plain { name, name_span, value } => {
                    if TIMINGS.contains(&name.as_str()) {
                        match value {
                            AttrValue::Expr(x) => {
                                let c = self.expr_code(x);
                                self.check_timing_value(name, &c, x.span);
                                hash.push(format!("{}: ({c})", php_str(name)));
                            }
                            _ => self.timing_not_expr(name, *name_span),
                        }

                        continue;
                    }

                    let event = event_name(name);
                    // A handler, `phem:loading` or `phem:progress`: all take a function.
                    let takes_fn = event.is_some() || name == LOADING || name == PROGRESS;
                    if let Some(event) = &event {
                        self.check_event(name, event, *name_span);
                    }

                    let v = match value {
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            if takes_fn {
                                self.check_handler(name, &c, x.span);
                                format!("({})", handler_code(&c))
                            } else {
                                format!("({c})")
                            }
                        }
                        _ if takes_fn => {
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

    /// `phem:debounce` and `phem:throttle`: not both on one element, and only on an element with a
    /// handler (or a spread, which can give one).
    fn check_timing(&mut self, e: &Element) {
        let mut found: Option<&str> = None;
        let mut has_handler = false;
        for a in &e.attrs {
            match &a.kind {
                AttrKind::Spread(_) => has_handler = true,
                AttrKind::Plain { name, name_span, .. } => {
                    if event_name(name).is_some() || name == LIVE {
                        has_handler = true;
                    } else if TIMINGS.contains(&name.as_str()) {
                        if let Some(other) = found {
                            self.error(codes::BAD_ATTRIBUTE, format!("`{name}` and `{other}` cannot be on the same element"), *name_span).help =
                                Some("keep one of them".into());
                        }

                        found = Some(name);
                    }
                }
            }
        }

        if let (Some(name), false) = (found, has_handler) {
            let span = e.attrs.iter().find_map(|a| match &a.kind {
                AttrKind::Plain { name: n, name_span, .. } if n == name => Some(*name_span),
                _ => None,
            });

            self.diags.push(
                Diagnostic::warning(codes::TIMING_WITHOUT_HANDLER, format!("`{name}` does nothing: the element has no handler"), span.unwrap_or(e.name_span))
                    .with_help("put it on the element with the handler, for example `<input onChange={{ search }} phem:debounce={{ 300 }} />`"),
            );
        }
    }

    /// The value of `phem:debounce` and `phem:throttle`: milliseconds, or `'blur'` for `phem:debounce`.
    /// Only a literal can be checked here. The runtime checks the other values.
    fn check_timing_value(&mut self, name: &str, code: &str, span: Span) {
        let c = code.trim();
        let blur = name == DEBOUNCE;
        let ok = if c.starts_with('-') {
            !c[1..].trim().starts_with(|ch: char| ch.is_ascii_digit())
        } else if c.starts_with(|ch: char| ch.is_ascii_digit()) {
            c.chars().all(|ch| ch.is_ascii_digit())
        } else if c.starts_with('\'') || c.starts_with('"') {
            blur && (c == "'blur'" || c == "\"blur\"")
        } else {
            true
        };

        if !ok {
            let help = if blur { "write a number of milliseconds (`{{ 300 }}`), or `{{ 'blur' }}`" } else { "write a number of milliseconds: `{{ 300 }}`" };
            self.error(codes::BAD_ATTRIBUTE, format!("`{name}` takes a number of milliseconds{}", if blur { " or 'blur'" } else { "" }), span).help = Some(help.into());
        }
    }

    fn timing_not_expr(&mut self, name: &str, span: Span) {
        self.error(codes::BAD_ATTRIBUTE, format!("`{name}` takes an expression"), span).help = Some(format!("write `{name}={{{{ 300 }}}}`"));
    }

    /// `phem:bind={{ query }}` on an `<input>`, a `<textarea>` or a `<select>`: the name of a
    /// #[State] property of the class, with a type that takes the value of the field (E033). Or a
    /// path in an array property: `filters.status`, `rows[key].name`. Or a `Bound` prop: the
    /// property of a parent component.
    fn bind(&mut self, e: &Element) -> Option<BoundField> {
        let mut found = None;
        let mut live = None;
        for a in &e.attrs {
            if let AttrKind::Plain { name, name_span, value } = &a.kind {
                if name == BIND {
                    found = Some((*name_span, value));
                } else if name == LIVE {
                    live = Some(*name_span);
                }
            }
        }

        let Some((span, value)) = found else {
            if let Some(span) = live {
                self.diags.push(
                    Diagnostic::warning(codes::LIVE_WITHOUT_BIND, "`phem:live` does nothing: the element has no `phem:bind`", span)
                        .with_help("put it on a bound field, for example `<input phem:bind={{ query }} phem:live />`"),
                );
            }

            return None;
        };

        let tag = e.name.to_ascii_lowercase();
        if !matches!(tag.as_str(), "input" | "textarea" | "select") {
            self.error(codes::INVALID_BIND, format!("`phem:bind` is for a form field, not a `<{}>`", e.name), span).help =
                Some("put it on an `<input>`, a `<textarea>` or a `<select>`".into());
            return None;
        }

        let target = self.bind_target(value, span)?;
        let value_span = if let AttrValue::Expr(x) = value { x.span } else { span };

        // The attributes that phem:bind sets.
        let attr = |n: &str| {
            e.attrs.iter().find_map(|a| match &a.kind {
                AttrKind::Plain { name, name_span, value } if name.eq_ignore_ascii_case(n) => Some((*name_span, value)),
                _ => None,
            })
        };
        let static_text = |v: &AttrValue| match v {
            AttrValue::Quoted { parts, raw, .. } if parts.iter().all(|p| matches!(p, StrPart::Lit(_))) => Some(raw.to_ascii_lowercase()),
            _ => None,
        };

        let type_attr = attr("type");
        let input_type = match type_attr {
            None => Some("text".to_string()),
            Some((_, v)) => static_text(v),
        };

        let kind = match tag.as_str() {
            "input" => match input_type.as_deref() {
                Some("checkbox") => Some(FieldValue::Checkbox),
                Some(_) => Some(FieldValue::Scalar),
                None => None,
            },
            "select" if attr("multiple").is_some() => Some(FieldValue::List),
            _ => Some(FieldValue::Scalar),
        };

        let toggles = matches!(input_type.as_deref(), Some("checkbox" | "radio"));
        let owned = match tag.as_str() {
            "input" if toggles => Some("checked"),
            "input" if input_type.is_some() => Some("value"),
            _ => None,
        };

        if let Some((s, _)) = owned.and_then(attr) {
            self.error(codes::INVALID_BIND, format!("`{}` and `phem:bind` cannot be on the same element", owned.unwrap()), s).help =
                Some("`phem:bind` sets it from the property: remove it".into());
        }

        if tag == "textarea" && e.children.iter().any(|n| !n.is_blank()) {
            self.error(codes::INVALID_BIND, "a bound `<textarea>` has no text: `phem:bind` sets it", e.name_span).help = Some("remove the text of the `<textarea>`".into());
        }

        // A value in an array has no declared type: the server converts to the type of the current
        // value. The type of the property of a Bound prop is in the parent: the server checks it.
        if let (Some(kind), BindTarget::State { code, prop: Some(p), path: false, .. }) = (kind, &target) {
            if !kind.accepts(&p.ty) {
                self.error(codes::INVALID_BIND, format!("this field gives {}, but `${code}` is `{}`", kind.describe(), p.ty.trim()), value_span).help = Some(match kind {
                    FieldValue::Scalar => "use a `string`, `int`, `float` or backed enum property".into(),
                    FieldValue::Checkbox => "use a `bool` property (or an `array` for a group of checkboxes)".into(),
                    FieldValue::List => "use an `array` property".into(),
                });
            }
        }

        let ty = match type_attr {
            None => "null".to_string(),
            Some((_, AttrValue::Expr(t))) => self.expr_code(t),
            Some((_, AttrValue::Quoted { parts, .. })) => self.quoted_expr(parts, span),
            Some((_, AttrValue::None)) => "null".to_string(),
        };

        // The value of a checkbox or a radio button: the value that it gives when checked.
        let option = match attr("value") {
            Some((_, AttrValue::Expr(v))) if tag == "input" => format!("({})", self.expr_code(v)),
            Some((_, AttrValue::Quoted { parts, .. })) if tag == "input" => self.quoted_expr(parts, span),
            _ => "null".to_string(),
        };

        // `this`: the component of the property. The markup can render in the slot of another
        // component (`<Document>`), so the current component is not always the owner. A Bound
        // prop has its owner.
        let (target, code, owner) = match target {
            BindTarget::State { target, code, .. } => (target, code, "this"),
            BindTarget::Bound(name) => (name.clone(), name, "null"),
        };

        Some(BoundField {
            attrs: format!(" {{{{ phem_bind({target}, {code}, {}, {ty}, {option}, {owner}) }}}}", php_str(&tag)),
            text: (tag == "textarea").then(|| format!("{{{{ phem_bind_value({code}) }}}}")),
            select: (tag == "select").then(|| code.clone()),
        })
    }

    /// The value of `phem:bind`, on a form field or on the tag of a component: a #[State] property
    /// of the class, a path in an array property, or a `Bound` prop of the component.
    fn bind_target(&mut self, value: &AttrValue, span: Span) -> Option<BindTarget> {
        let help = "write the name of a #[State] property: `phem:bind={{ query }}`";
        let AttrValue::Expr(x) = value else {
            self.error(codes::INVALID_BIND, "`phem:bind` takes the name of a #[State] property", span).help = Some(help.into());
            return None;
        };

        let code = self.expr_code(x);
        let Some((root, path)) = bind_path(&code) else {
            self.error(codes::INVALID_BIND, "`phem:bind` takes the name of a #[State] property, or a path in it, not an expression", x.span).help =
                Some(format!("{help}, or a path in an array property: `phem:bind={{{{ filters.status }}}}`, `phem:bind={{{{ rows[key].name }}}}`"));
            return None;
        };

        if self.is_bound_prop(&root) {
            if !path.is_empty() {
                self.error(codes::INVALID_BIND, format!("`{root}` is a Bound prop: a path in it is not supported"), x.span).help =
                    Some(format!("bind the prop itself: `phem:bind={{{{ {root} }}}}`, and give the path in the parent"));
                return None;
            }

            return Some(BindTarget::Bound(root));
        }

        let Some(class) = self.class else {
            self.error(codes::INVALID_BIND, "`phem:bind` needs a class component: a function component has no state", span).help =
                Some(format!("bind a #[State] property of a class component, or declare a `Bound ${root}` prop and bind it in the parent: `phem:bind={{{{ query }}}}`"));
            return None;
        };

        if has_variable(self.scope_text, &root) {
            self.error(codes::INVALID_BIND, format!("`{root}` is a local variable, not a #[State] property"), x.span).help =
                Some(format!("rename the variable `${root}`: in markup it hides the property"));
            return None;
        }

        let prop = class.all_props().find(|p| p.name == root && !p.is_static);
        match prop {
            Some(p) if !p.is_state => {
                self.error(codes::INVALID_BIND, format!("`{root}` is not a #[State] property"), x.span).help =
                    Some(format!("add `#[State]` to `${root}`: the browser keeps only the state"));
                return None;
            }
            Some(p) if !path.is_empty() && !FieldValue::List.accepts(&p.ty) => {
                self.error(codes::INVALID_BIND, format!("a path needs an array property, but `${root}` is `{}`", p.ty.trim()), x.span).help =
                    Some(format!("bind the property itself (`phem:bind={{{{ {root} }}}}`), or make it an `array`"));
                return None;
            }
            None if !class.open => {
                let decl = if path.is_empty() { format!("#[State] public string ${root} = '';") } else { format!("#[State] public array ${root} = [];") };
                self.error(codes::INVALID_BIND, format!("`{}` has no property `{root}`", class.name), x.span).help = Some(format!("declare `{decl}`"));
                return None;
            }
            _ => {}
        }

        // The property, or a list for a path: `['rows', (key), 'name']`. The render evaluates the keys.
        let target = if path.is_empty() {
            php_str(&root)
        } else {
            let mut items = vec![php_str(&root)];
            items.extend(path.iter().map(|s| match s {
                PathSegment::Name(n) if n.starts_with(|c: char| c.is_ascii_digit()) => n.clone(),
                PathSegment::Name(n) => php_str(n),
                PathSegment::Key(k) => format!("({k})"),
            }));
            format!("[{}]", items.join(", "))
        };

        Some(BindTarget::State { target, code, prop: prop.cloned(), path: !path.is_empty() })
    }

    /// Whether a name is a prop of type `Bound` of the component of the markup.
    fn is_bound_prop(&self, name: &str) -> bool {
        let info = self.info;
        match self.class {
            Some(c) => c.all_props().any(|p| p.name == name && p.is_prop() && is_bound_type(info, &p.ty)),
            None => self.params.iter().any(|p| p.name == name && !p.variadic && is_bound_type(info, &p.ty)),
        }
    }

    /// `selected` for an `<option>` of a bound `<select>`: its `value`, or its text.
    fn option_selected(&mut self, e: &Element, bound: &str, out: &mut String) {
        let value = e.attrs.iter().find_map(|a| match &a.kind {
            AttrKind::Plain { name, value, .. } if name == "value" => Some(value),
            _ => None,
        });

        let v = match value {
            Some(AttrValue::Expr(x)) => format!("({})", self.expr_code(x)),
            Some(AttrValue::Quoted { parts, .. }) => self.quoted_expr(parts, e.name_span),
            Some(AttrValue::None) => "''".to_string(),
            None if e.children.iter().all(|n| matches!(n, Node::Text(_))) => {
                let text: String = e.children.iter().map(|n| if let Node::Text(t) = n { t.text.as_str() } else { "" }).collect();
                php_str(&text.split_whitespace().collect::<Vec<_>>().join(" "))
            }
            None => {
                self.error(codes::INVALID_BIND, "an `<option>` of a bound `<select>` needs a `value`", e.name_span).help =
                    Some("write `value={{ item.id }}`: the text of the option is not static".into());
                return;
            }
        };

        out.push_str(&format!(" {{{{ phem_selected({bound}, {v}) }}}}"));
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

                if name == BIND {
                    // See bind().
                    return;
                }

                if name == LIVE {
                    match value {
                        AttrValue::None => out.push_str(&format!("{ws}data-phem-live")),
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            out.push_str(&format!("{ws}{{{{ phem_attr('data-phem-live', ({c}) is same as(true)) }}}}"));
                        }
                        AttrValue::Quoted { .. } => {
                            self.error(codes::BAD_ATTRIBUTE, "`phem:live` takes no text", *name_span).help = Some("write `phem:live`, or `phem:live={{ live }}` with a bool".into());
                        }
                    }

                    return;
                }

                if name == LOADING || name == PROGRESS {
                    match value {
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            self.check_handler(name, &c, x.span);
                            let c = handler_code(&c);
                            out.push_str(&format!("{ws}{{{{ phem_{}({c}) }}}}", &name[5..]));
                        }
                        _ => self.handler_not_function(name, *name_span),
                    }

                    return;
                }

                if TIMINGS.contains(&name.as_str()) {
                    match value {
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            self.check_timing_value(name, &c, x.span);
                            out.push_str(&format!("{ws}{{{{ phem_timing('{}', {c}) }}}}", &name[5..]));
                        }
                        _ => self.timing_not_expr(name, *name_span),
                    }

                    return;
                }

                if let Some(event) = event_name(name) {
                    self.check_event(name, &event, *name_span);
                    match value {
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            self.check_handler(name, &c, x.span);
                            let c = handler_code(&c);
                            out.push_str(&format!("{ws}{{{{ phem_on('{event}', {c}) }}}}"));
                        }
                        _ => self.handler_not_function(name, *name_span),
                    }

                    return;
                }

                match value {
                    AttrValue::None => out.push_str(&format!("{ws}{html_name}")),
                    // A URL from an expression goes through phem_attr(), which drops a `javascript:` URL.
                    AttrValue::Quoted { parts, .. } if is_url_attr(html_name) && parts.iter().any(|p| matches!(p, StrPart::Expr(_))) => {
                        let c = self.quoted_expr(parts, a.span);
                        out.push_str(&format!("{ws}{{{{ phem_attr('{html_name}', {c}) }}}}"));
                    }
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
                (None, Some(s)) if phemc_analyze::is_builtin(s) => format!("import it: `use {s};`"),
                (None, Some(s)) => format!("import it: `use function {s};` (or `use {s};` for a class component)"),
                (None, None) => format!("declare `#[Component] function {short}()` in a .phpm file, or check the `use` imports"),
            };

            self.error(codes::UNKNOWN_COMPONENT, format!("unknown component `<{}>` (resolved to `{fqcn}`)", e.name), e.name_span).help = Some(help);
            return;
        };

        // A page renders at its URL, with its document: it has no props, and it is not a tag.
        if def.page {
            self.error(codes::PAGE_AS_TAG, format!("`<{}>` is a page, and a page cannot be a tag", e.name), e.name_span).help =
                Some("move the markup that both need to a component, and render that component in the page and here".into());
            return;
        }

        let mut props = Vec::new();
        let mut key: Option<String> = None;
        let mut has_spread = false;
        let mut given = HashSet::new();
        let mut bind = None;
        let mut has_bind = false;
        for a in &e.attrs {
            match &a.kind {
                AttrKind::Plain { name, name_span, value } if name == BIND => {
                    has_bind = true;
                    bind = self.bind_target(value, *name_span).map(|t| (t, *name_span));
                }
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

                    if def.contexts.iter().any(|c| &c.name == name) {
                        self.error(codes::UNKNOWN_PROP, format!("`{name}` of `<{}>` is a context, not a prop", e.name), *name_span).help =
                            Some("a component above gives it: render the tag inside a component with `#[Provide]` for its type".into());
                    } else if !def.rest && !def.open && !def.props.iter().any(|p| p == name) {
                        let list = if def.props.is_empty() { "none".to_string() } else { def.props.join(", ") };
                        self.error(codes::UNKNOWN_PROP, format!("`<{}>` has no prop `{name}`", e.name), *name_span).help =
                            Some(format!("its props are: {list}"));
                    }

                    given.insert(name.clone());
                    props.push(format!("{}: {v}", php_str(name)));
                }
            }
        }

        // `phem:bind={{ query }}`: the Bound prop of the child gets the property.
        if let Some((target, span)) = bind {
            match def.bound.as_slice() {
                [] => {
                    self.error(codes::INVALID_BIND, format!("`<{}>` has no `Bound` prop for `phem:bind`", e.name), span).help =
                        Some(format!("declare a `Bound $value` prop in `{}`, and bind its field: `<input phem:bind={{{{ value }}}} />`", def.fqcn));
                }
                [name] if given.contains(name) => {
                    self.error(codes::INVALID_BIND, format!("`{name}` and `phem:bind` cannot be on the same component: `phem:bind` gives `{name}`"), span).help =
                        Some(format!("remove `{name}`"));
                }
                [name] => {
                    let v = match target {
                        BindTarget::State { target, code, .. } => format!("phem_bound({target}, {code}, this)"),
                        BindTarget::Bound(b) => format!("({b})"),
                    };

                    given.insert(name.clone());
                    props.push(format!("{}: {v}", php_str(name)));
                }
                many => {
                    self.error(codes::INVALID_BIND, format!("`<{}>` has more than one `Bound` prop ({}): `phem:bind` gives only one", e.name, many.join(", ")), span).help =
                        Some(format!("keep one `Bound` prop in `{}`", def.fqcn));
                }
            }
        }

        // An invalid `phem:bind` has its own error: no "needs the prop" for the Bound props.
        if has_bind {
            given.extend(def.bound.iter().cloned());
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
                if !def.slots.contains(r) {
                    self.error(codes::UNKNOWN_PROP, format!("`<{}>` needs the prop `{r}`", e.name), e.name_span);
                } else if r == "children" {
                    self.error(codes::UNKNOWN_PROP, format!("`<{}>` needs the slot `children`", e.name), e.name_span).help =
                        Some(format!("put the content between `<{0}>` and `</{0}>`", e.name));
                } else {
                    self.error(codes::UNKNOWN_PROP, format!("`<{}>` needs the slot `{r}`", e.name), e.name_span).help =
                        Some(format!("add `<{0}:{r}>...</{0}:{r}>` in `<{0}>`", e.name));
                }
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

/// `phem:loading={{ save }}`: the element shows only while a `save` event runs.
const LOADING: &str = "phem:loading";

/// `phem:progress={{ save }}`: the element shows the upload progress of a `save` event with files.
const PROGRESS: &str = "phem:progress";

/// The attributes with a URL that the browser opens or sends a form to (see `Attributes::URLS` of the runtime).
const URL_ATTRS: &[&str] = &["action", "formaction", "href", "src", "xlink:href"];

fn is_url_attr(name: &str) -> bool {
    URL_ATTRS.iter().any(|u| u.eq_ignore_ascii_case(name))
}

/// `phem:bind={{ query }}`: the field shows a #[State] property, and the browser sends its new
/// value with the next event. `phem:live` sends each change at once.
const BIND: &str = "phem:bind";
const LIVE: &str = "phem:live";

/// What `phem:bind` binds (see `Lowerer::bind_target()`).
enum BindTarget {
    /// A #[State] property of the class, or a path in it: the PHP target (`'query'`,
    /// `['rows', (key), 'name']`), the Twig code of the value, the property (None in an open
    /// class), and whether it is a path.
    State { target: String, code: String, prop: Option<Prop>, path: bool },
    /// A `Bound` prop of the component: the property of a parent component.
    Bound(String),
}

/// The lowered parts of a bound field (see `Lowerer::bind()`).
struct BoundField {
    /// The attributes: ` {{ phem_bind(...) }}`.
    attrs: String,
    /// The text of a `<textarea>`.
    text: Option<String>,
    /// The bound value of a `<select>`, for the `selected` of its options.
    select: Option<String>,
}

/// What a form field gives to a bound property.
#[derive(Clone, Copy, PartialEq)]
enum FieldValue {
    /// A string: a text input, a radio button, a `<select>`, a `<textarea>`.
    Scalar,
    /// A bool, or a list of strings for a group: a checkbox.
    Checkbox,
    /// A list of strings: a `<select multiple>`.
    List,
}

impl FieldValue {
    fn describe(self) -> &'static str {
        match self {
            FieldValue::Scalar => "a string (or a number, or a backed enum)",
            FieldValue::Checkbox => "a bool (or a list of strings for a group of checkboxes)",
            FieldValue::List => "a list of strings",
        }
    }

    /// Whether a PHP property type takes the value. No type and `mixed` take any value.
    fn accepts(self, ty: &str) -> bool {
        let ty = ty.trim();
        if ty.is_empty() {
            return true;
        }

        ty.split('|').any(|t| {
            let t = t.trim().trim_matches(|c| c == '(' || c == ')').trim_start_matches('?').trim().to_ascii_lowercase();
            let list = matches!(t.as_str(), "array" | "iterable");
            let bool = matches!(t.as_str(), "bool" | "true" | "false");
            let builtin = list || bool || matches!(t.as_str(), "null" | "object" | "callable" | "void" | "never" | "self" | "static");
            t == "mixed"
                || match self {
                    FieldValue::Scalar => matches!(t.as_str(), "string" | "int" | "float") || !builtin,
                    FieldValue::Checkbox => bool || list,
                    FieldValue::List => list,
                }
        })
    }
}

/// A segment of a bound path (see `bind_path()`).
enum PathSegment {
    /// A static key: `.status`, or `.0` for an index.
    Name(String),
    /// A key that the render evaluates: `[key]`, with the Twig expression in it.
    Key(String),
}

/// The property and the segments of a bound path: `query`, `filters.status`, `rows[key].name`.
/// None for another expression.
fn bind_path(code: &str) -> Option<(String, Vec<PathSegment>)> {
    let is_word = |c: char| c.is_ascii_alphanumeric() || c == '_';
    let s = code.trim();
    let end = s.find(|c: char| !is_word(c)).unwrap_or(s.len());
    let root = &s[..end];
    if !root.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_') {
        return None;
    }

    let mut path = Vec::new();
    let mut rest = &s[end..];
    while let Some(c) = rest.chars().next() {
        if c == '.' {
            let r = &rest[1..];
            let end = r.find(|c: char| !is_word(c)).unwrap_or(r.len());
            let name = &r[..end];
            let index = !name.is_empty() && name.chars().all(|c| c.is_ascii_digit()) && (name == "0" || !name.starts_with('0'));
            let valid = index || name.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_');
            if !valid {
                return None;
            }

            path.push(PathSegment::Name(name.to_string()));
            rest = &r[end..];
        } else if c == '[' {
            let close = closing_bracket(rest)?;
            let key = rest[1..close].trim();
            if key.is_empty() {
                return None;
            }

            path.push(PathSegment::Key(key.to_string()));
            rest = &rest[close + 1..];
        } else {
            return None;
        }
    }

    Some((root.to_string(), path))
}

/// The index of the `]` that closes the `[` at the start of `s`, after the nested brackets and the strings.
fn closing_bracket(s: &str) -> Option<usize> {
    let mut depth = 0usize;
    let mut quote = None;
    let mut escaped = false;
    for (i, c) in s.char_indices() {
        if let Some(q) = quote {
            if escaped {
                escaped = false;
            } else if c == '\\' {
                escaped = true;
            } else if c == q {
                quote = None;
            }
            continue;
        }

        match c {
            '\'' | '"' => quote = Some(c),
            '[' | '(' | '{' => depth += 1,
            ']' | ')' | '}' => {
                depth = depth.checked_sub(1)?;
                if depth == 0 {
                    return (c == ']').then_some(i);
                }
            }
            _ => {}
        }
    }

    None
}

/// `phem:debounce={{ 300 }}` and `phem:throttle={{ 300 }}`: the browser waits before it sends the
/// events of the element. `phem:debounce={{ 'blur' }}` sends on blur only.
const DEBOUNCE: &str = "phem:debounce";
const TIMINGS: &[&str] = &[DEBOUNCE, "phem:throttle"];

/// The events of `event_name()` that the `phem` client listens to. The runtime makes `focus`
/// and `blur` the `focusin` and `focusout` events.
const LISTENED_EVENTS: &[&str] = &["click", "dblclick", "input", "change", "submit", "keydown", "keyup", "focus", "blur", "focusin", "focusout"];

/// `onClick` → `click`, `onDoubleClick` → `dblclick`.
/// The Twig code of a handler. A member path (`tabs.select`) is the method of the object as a
/// closure (`phem_method(tabs, 'select')`): in Twig, `tabs.select` calls the method.
fn handler_code(code: &str) -> String {
    let ident = |s: &str| s.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_') && s.chars().all(|c| c.is_ascii_alphanumeric() || c == '_');
    match code.rsplit_once('.') {
        Some((object, member)) if ident(member) && object.split('.').all(ident) => format!("phem_method({object}, {})", php_str(member)),
        _ => code.to_string(),
    }
}

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
