//! Lowers a markup tree to Twig source.

use phemc_analyze::{is_bound_type, Class, ComponentDef, Enums, FileInfo, Kind, Param, Prop, Registry};
use phemc_syntax::diag::codes;
use phemc_syntax::markup::{Attr, AttrKind, AttrValue, Block, Element, ExprPart, Node, StrPart, TwigExpr};
use phemc_syntax::{Diagnostic, Span};
use std::collections::HashSet;

use crate::classes::ClassNames;
use crate::replaces::Replacements;
use crate::php_str;
use phemc_analyze::has_variable;

pub struct Lowerer<'a> {
    pub info: &'a FileInfo,
    pub reg: &'a Registry,
    /// The enums of the project: a literal value of an enum prop is one of its cases.
    enums: &'a Enums,
    replacements: &'a Replacements,
    /// The class names of the markup (`OrderStatus.Cancelled`, `path(ProductPage)`).
    classes: ClassNames<'a>,
    /// The component of the function or the class of the markup, if any: a tag of the component
    /// that it replaces stays the original.
    pub owner: Option<&'a str>,
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
    /// The number of `<Dynamic>` elements with children: each one captures them in its own variable.
    dynamics: usize,
    /// The number of elements with `phem:error`: each one keeps its error in its own variable.
    errors: usize,
    /// While the attributes of a bound field lower: phem_bind() writes its `aria-describedby`.
    skip_described_by: bool,
    /// The variables that the Twig tags around the current node bind (`{% for x in %}`, `{% set x %}`).
    twig_bound: Vec<String>,
    /// The number of open `{% for %}` bodies.
    loops: usize,
    /// While an item of a feed lowers: a feed element in it has the same id in each item.
    in_feed_item: bool,
    /// The feeds of the markup that have an element: a second element has the same id.
    feeds: HashSet<String>,
}

impl<'a> Lowerer<'a> {
    pub fn new(info: &'a FileInfo, reg: &'a Registry, enums: &'a Enums, replacements: &'a Replacements) -> Self {
        Self { info, reg, enums, replacements, classes: ClassNames::new(info, reg, enums), owner: None, diags: vec![], slots: vec![], slots_defined: HashSet::new(), title_scopes: 0, components: 0, class: None, scope_text: "", params: &[], bound_select: None, dynamics: 0, errors: 0, skip_described_by: false, twig_bound: vec![], loops: 0, in_feed_item: false, feeds: HashSet::new() }
    }

    pub fn lower_root(&mut self, node: &Node, slots: Vec<String>) -> String {
        self.slots = slots;
        self.slots_defined.clear();
        self.twig_bound.clear();
        self.feeds.clear();
        let mut out = String::new();
        self.node(node, &mut out);
        out
    }

    fn error(&mut self, code: &'static str, msg: impl Into<String>, span: Span) -> &mut Diagnostic {
        self.diags.push(Diagnostic::error(code, msg, span));
        self.diags.last_mut().unwrap()
    }

    /// Twig code with its class names replaced (see `classes`). `base` is its offset in the
    /// source, and `bound` the names that it binds.
    /// An attribute can lower more than once (the value of a bound field): its diagnostics show once.
    fn twig(&mut self, code: &str, base: usize, bound: &[String]) -> String {
        let bound = [self.twig_bound.as_slice(), bound].concat();
        let mut diags = Vec::new();
        let code = self.classes.rewrite(code, base, &bound, &mut diags);

        for d in diags {
            if !self.diags.iter().any(|e| e.code == d.code && e.span == d.span) {
                self.diags.push(d);
            }
        }

        code
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
            Node::Tag(t) => {
                // `{% set x = 1 %}` binds `x` until the end of the markup.
                let bound = phemc_twig_scan::tag_vars(&t.name, &t.head).bound;
                let raw = self.twig(&t.raw, t.span.start, &bound);
                self.twig_bound.extend(bound);
                out.push_str(&raw);
            }
            Node::Fragment(f) => self.nodes(&f.children, out),
            Node::Block(b) => self.block(b, true, out),
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
                        // The default can read a class: `{{ children ?? path(HomePage) }}`.
                        let start = o.expr.span.start + o.expr.code().find(code).unwrap_or(0);
                        let code = self.twig(code, start, &[]);
                        out.push_str(&format!("{{% block {name} %}}{{{{ {code} }}}}{{% endblock %}}"));
                    } else {
                        out.push_str(&format!("{{{{ block('{name}') }}}}"));
                    }
                    return;
                }

                let raw = self.twig(&o.raw, o.span.start, &[]);
                out.push_str(&raw);
            }
            Node::Element(e) if e.is_component() => self.component(e, out),
            Node::Element(e) => self.element(e, out),
        }
    }

    /// A Twig block: `{% if %}`, `{% for %}`. `keys`: W002 for the elements of a `{% for %}`.
    fn block(&mut self, b: &Block, keys: bool, out: &mut String) {
        let is_for = b.name() == "for";
        if is_for && keys {
            self.check_keys(&b.branches[0].children);
        }

        // The variables of a `{% for %}` exist only in its body. A `{% set x %}` block
        // binds `x` after it.
        let bound = self.twig_bound.len();
        for (i, br) in b.branches.iter().enumerate() {
            let tag_bound = phemc_twig_scan::tag_vars(&br.tag.name, &br.tag.head).bound;
            let raw = self.twig(&br.tag.raw, br.tag.span.start, &tag_bound);
            out.push_str(&raw);
            self.twig_bound.extend(tag_bound);

            // The body of a loop renders more than once: a feed element in it has the same id each time.
            let body = usize::from(is_for && i == 0);
            self.loops += body;
            self.nodes(&br.children, out);
            self.loops -= body;
        }

        if is_for {
            self.twig_bound.truncate(bound);
        }

        out.push_str(&b.close.raw);
    }

    /// The children of an element. The loop of an invalid `phem:feed` has its own error, so it
    /// gets no W002: phem_feed_item() gives the identity of an item.
    fn children(&mut self, e: &Element, out: &mut String) {
        if plain_attr(e, FEED).is_none() {
            self.nodes(&e.children, out);
            return;
        }

        for n in &e.children {
            match n {
                Node::Block(b) => self.block(b, false, out),
                n => self.node(n, out),
            }
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
                    .with_help("put the `<title>` in the `<head>` of the document component, and give the title through a prop, for example `<Document title=\"Home\">`"),
            );
        }

        self.check_timing(e);
        let error = self.error_of(e, &name);
        let bind = self.bind(e);
        let feed = self.feed(e);

        if let Some(err) = &error {
            out.push_str(&err.open);
        }

        // phem_bind() writes the `aria-describedby` of a bound field, with the id of its error.
        // With a spread, the value comes from the merged attributes, and the last group of
        // phem_attrs() removes it and gives the id of the error: a later value replaces an earlier one.
        let mut described_by = bind.as_ref().and_then(|b| b.described_by.clone());
        out.push('<');
        out.push_str(&e.name);
        if e.attrs.iter().any(|a| matches!(a.kind, AttrKind::Spread(_))) {
            let mut last = Vec::new();
            if bind.is_some() {
                last.push(format!("{}: null", php_str(DESCRIBED_BY)));
            }

            if let Some(err) = &error {
                last.push(format!("'id': {}.id", err.var));
            }

            let groups = self.merged_attrs(&e.attrs, &last, out);
            if bind.is_some() {
                described_by = Some(format!("(phem_props({})[{}] ?? null)", groups.join(", "), php_str(DESCRIBED_BY)));
            }
        } else {
            self.skip_described_by = bind.is_some();
            for a in &e.attrs {
                self.dom_attr(a, out);
            }

            self.skip_described_by = false;
            if let Some(err) = &error {
                out.push_str(&format!(" id=\"{{{{ {}.id }}}}\"", err.var));
            }
        }

        if let Some(f) = &feed {
            out.push_str(&format!("{{{{ phem_feed({}) }}}}", f.name));
        }

        if let Some(b) = &bind {
            let extra = described_by.map_or(String::new(), |d| format!(", {d}"));
            out.push_str(&format!(" {{{{ {}{extra}) }}}}", b.call));
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
        match (&error, &feed) {
            (Some(err), _) => out.push_str(&format!("{{{{ {}.message }}}}", err.var)),
            (None, Some(f)) => self.feed_items(f, out),
            (None, None) => self.children(e, out),
        }

        self.title_scopes -= scope;
        if bind.as_ref().is_some_and(|b| b.select.is_some()) {
            self.bound_select = outer_select;
        }

        out.push_str("</");
        out.push_str(&e.name);
        out.push('>');

        if error.is_some() {
            out.push_str("{% endif %}");
        }
    }

    /// `phem:error={{ email }}` on an element (`tag`) or on the tag of a component: the error of
    /// a bound #[State] property, or of a `Bound` prop (E061). The element renders only when the
    /// field has an error: `{% set _phem_error_0 = phem_error('email', this) %}{% if _phem_error_0 %}`.
    /// It gets the id of the error and the message, so it has no `id` and no content of its own.
    fn error_of(&mut self, e: &Element, tag: &str) -> Option<ErrorElement> {
        let (span, value) = plain_attr(e, ERROR)?;

        let component = e.is_component();
        let what = if component { format!("`<{}>`", e.name) } else { "the element".to_string() };
        let mut valid = true;

        if !component && phemc_syntax::markup::VOID_ELEMENTS.contains(&tag) {
            self.error(codes::INVALID_ERROR, format!("`phem:error` needs an element with content, not a `<{}>`", e.name), span).help =
                Some("put it on the element of the message: `<p phem:error={{ email }} />`".into());
            valid = false;
        }

        for a in &e.attrs {
            if let AttrKind::Plain { name, name_span, .. } = &a.kind {
                if name == "id" {
                    self.error(codes::INVALID_ERROR, format!("`id` and `phem:error` cannot be on the same element: `phem:error` gives {what} the id of the error"), *name_span).help =
                        Some("remove the `id`: `phem:bind` writes the same id in the `aria-describedby` of the field".into());
                    valid = false;
                } else if name == BIND {
                    self.error(codes::INVALID_ERROR, "`phem:bind` and `phem:error` cannot be on the same element", *name_span).help =
                        Some("put `phem:error` on the element of the message, next to the field".into());
                    valid = false;
                }
            }
        }

        if e.children.iter().any(|n| !n.is_blank() && !matches!(n, Node::TwigComment(_))) {
            self.error(codes::INVALID_ERROR, format!("{what} has `phem:error`, so its content is the message of the error"), e.name_span).help =
                Some("remove the content: `<p phem:error={{ email }} />`".into());
            valid = false;
        }

        let target = self.bind_target(value, span, ERROR, codes::INVALID_ERROR)?;
        let (target, owner) = match target {
            BindTarget::State { target, code, .. } => {
                if let Some(class) = self.class {
                    if !class.open && !class.all_props().any(phemc_analyze::is_validation_prop) {
                        self.error(codes::INVALID_ERROR, format!("`phem:error` shows a validation error, but `{}` has no `Validation` property", class.name), span).help =
                            Some(format!("declare `#[State] public Validation $validation;`: it validates `${}` while the user types", code.split(['.', '[']).next().unwrap_or(&code)));
                        valid = false;
                    }
                }

                (target, "this")
            }
            BindTarget::Bound(name) => (name, "null"),
        };

        if !valid {
            return None;
        }

        let var = format!("_phem_error_{}", self.errors);
        self.errors += 1;
        let open = format!("{{% set {var} = phem_error({target}, {owner}) %}}{{% if {var} %}}");

        Some(ErrorElement { var, open })
    }

    /// `phem:feed={{ messages }}` on the container of a list: a `Feed` property of the class
    /// component (E065). Its content is one `{% for %}` loop on the feed, and each item is one
    /// element (E066). phem_feed() gives the container the id of the feed, so it has no `id` and
    /// no spread, and it renders one time.
    fn feed<'e>(&mut self, e: &'e Element) -> Option<FeedList<'e>> {
        let (span, value) = plain_attr(e, FEED)?;

        let mut valid = true;
        for a in &e.attrs {
            match &a.kind {
                AttrKind::Spread(_) => {
                    self.error(codes::INVALID_FEED, "a spread and `phem:feed` cannot be on the same element", a.span).help =
                        Some("write the attributes of the feed element one by one: `phem:feed` sets the id of the element".into());
                    valid = false;
                }
                AttrKind::Plain { name, name_span, .. } if name == "id" => {
                    self.error(codes::INVALID_FEED, "`id` and `phem:feed` cannot be on the same element: `phem:feed` gives the element the id of the feed", *name_span).help =
                        Some("remove the `id`".into());
                    valid = false;
                }
                AttrKind::Plain { name, name_span, .. } if name == ERROR || name == BIND => {
                    self.error(codes::INVALID_FEED, format!("`{name}` and `phem:feed` cannot be on the same element"), *name_span).help =
                        Some("put the feed in its own element: the browser keeps its content".into());
                    valid = false;
                }
                _ => {}
            }
        }

        // The id of a feed element is the fiber id and the name of the feed: the element renders one time.
        if self.in_feed_item || self.loops > 0 {
            let place = if self.in_feed_item { "an item of a feed" } else { "a `{% for %}` loop" };
            self.error(codes::INVALID_FEED, format!("a `phem:feed` element in {place} renders more than one time, with the same id"), span).help =
                Some("put the feed element outside the loop: a component renders one element for each feed".into());
            valid = false;
        }

        let name = self.feed_name(value, span);
        if let Some(name) = &name {
            if !self.feeds.insert(name.clone()) {
                self.error(codes::INVALID_FEED, format!("the feed `{name}` has a second element, with the same id"), span).help =
                    Some("render one element for the feed: hide it with CSS or the `hidden` attribute when it must not show".into());
                valid = false;
            }
        }

        let body = self.feed_loop(e, name.as_deref());
        if !valid {
            return None;
        }

        Some(FeedList { name: name?, body: body? })
    }

    /// The value of `phem:feed`: the name of a `Feed` property of the class component. Phem sets
    /// a new feed on each new instance, so the property is public, not static, not readonly, not
    /// promoted and not nullable, and has no attribute (see `ComponentMetadata::feedProperties()`).
    fn feed_name(&mut self, value: &AttrValue, span: Span) -> Option<String> {
        let help = "write the name of a `Feed` property: `phem:feed={{ messages }}`";
        let AttrValue::Expr(x) = value else {
            self.error(codes::INVALID_FEED, "`phem:feed` takes the name of a `Feed` property", span).help = Some(help.into());
            return None;
        };

        let code = self.expr_code(x);
        let name = match bind_path(&code) {
            Some((root, path)) if path.is_empty() => root,
            _ => {
                self.error(codes::INVALID_FEED, "`phem:feed` takes the name of a `Feed` property, not an expression", x.span).help = Some(help.into());
                return None;
            }
        };

        let Some(class) = self.class else {
            self.error(codes::INVALID_FEED, "`phem:feed` needs a class component: a function component has no `Feed` property", span).help =
                Some(format!("move the markup to a class component, and declare `public Feed ${name};`"));
            return None;
        };

        if has_variable(self.scope_text, &name) || self.twig_bound.contains(&name) {
            self.error(codes::INVALID_FEED, format!("`{name}` is a local variable, not a `Feed` property"), x.span).help =
                Some(format!("rename the variable `{name}`: in markup it hides the property"));
            return None;
        }

        match class.all_props().find(|p| p.name == name) {
            Some(p) if p.class_ty.as_deref() != Some(phemc_analyze::FEED) => {
                self.error(codes::INVALID_FEED, format!("`phem:feed` needs a `Feed` property, but `${name}` is `{}`", p.ty.trim()), x.span).help =
                    Some(format!("declare `public Feed ${name};`: the items of a feed stay in the browser"));
                None
            }
            Some(p) => match feed_property_problem(p) {
                Some(problem) => {
                    self.error(codes::INVALID_FEED, format!("the feed `${name}` {problem}: Phem sets a new feed on each new instance"), x.span).help =
                        Some(format!("declare `public Feed ${name};`, with no attribute"));
                    None
                }
                None => Some(name),
            },
            None if !class.open => {
                self.error(codes::INVALID_FEED, format!("`{}` has no property `{name}`", class.name), x.span).help = Some(format!("declare `public Feed ${name};`"));
                None
            }
            None => Some(name),
        }
    }

    /// The content of a `phem:feed` element: one `{% for item in feed %}` loop on the feed (`name`,
    /// when it is valid), with no `{% else %}`, and one element in its body (see `check_item()`).
    fn feed_loop<'e>(&mut self, e: &'e Element, name: Option<&str>) -> Option<FeedLoop<'e>> {
        let feed = name.unwrap_or("messages");
        let example = format!("`<{0} phem:feed={{{{ {feed} }}}}>{{% for item in {feed} %}}<li>…</li>{{% endfor %}}</{0}>`", e.name);
        let skipped = |n: &Node| n.is_blank() || matches!(n, Node::TwigComment(_) | Node::HtmlComment(_));

        let mut found = None;
        for n in e.children.iter().filter(|n| !skipped(n)) {
            match n {
                Node::Block(b) if b.name() == "for" && found.is_none() => found = Some(b),
                _ => {
                    self.error(codes::FEED_CONTENT, "the content of a `phem:feed` element is only one `{% for %}` loop", n.span()).help =
                        Some(format!("move the other content out of the `<{}>`: {example}", e.name));
                    return None;
                }
            }
        }

        let Some(block) = found else {
            self.error(codes::FEED_CONTENT, format!("`<{}>` has `phem:feed`, but no `{{% for %}}` loop on the feed", e.name), e.name_span).help =
                Some(format!("render the items in a loop: {example}"));
            return None;
        };

        let tag = &block.branches[0].tag;
        let mut valid = true;
        if let Some(other) = block.branches.get(1) {
            self.error(codes::FEED_CONTENT, "the loop of a feed has no `{% else %}`", other.tag.span).help =
                Some(format!("the browser keeps the items of a feed: show the empty state outside the `<{}>`", e.name));
            valid = false;
        }

        // One name: a request renders only the new and the changed items, so a key or a position
        // in the loop is not the place of the item in the list.
        let names = phemc_twig_scan::tag_vars("for", &tag.head).bound;
        match (phemc_twig_scan::split_for(&tag.head), names.as_slice()) {
            (Some((_, seq)), [_]) => {
                if let Some(name) = name.filter(|n| *n != seq) {
                    self.error(codes::FEED_CONTENT, format!("the loop of a feed reads the feed `{name}`, not `{seq}`"), tag.head_span).help =
                        Some(format!("write `{{% for item in {name} %}}`: the server renders the new items of the feed with the same loop"));
                    valid = false;
                }
            }
            (Some(_), [_, _, ..]) => {
                self.error(codes::FEED_CONTENT, "the loop of a feed has one name: the item", tag.head_span).help =
                    Some(format!("write `{{% for item in {feed} %}}`: a request renders only the new and the changed items, so a key in the loop is not the place of the item in the list"));
                valid = false;
            }
            _ => {
                self.error(codes::FEED_CONTENT, format!("the loop of a feed is `{{% for item in {feed} %}}`"), tag.span).help =
                    Some("write the name of the item and the feed".into());
                valid = false;
            }
        }

        let body = &block.branches[0].children;
        let mut roots = body.iter().filter(|n| !skipped(n));
        let root = match (roots.next(), roots.next()) {
            (Some(n @ Node::Element(item)), None) => {
                if let Some((id, _)) = plain_attr(item, "id") {
                    self.error(codes::FEED_CONTENT, "the element of an item of a feed has no `id`: Phem gives it the id of the item", id).help =
                        Some("remove the `id`: the streams of the feed find the item by its own id".into());
                    valid = false;
                }

                valid &= self.check_item(n);

                Some(n)
            }
            (first, second) => {
                let span = second.or(first).map_or(tag.span, Node::span);
                self.error(codes::FEED_CONTENT, "each item of a feed is one element", span).help =
                    Some("put the content of an item in one element: `{% for message in messages %}<li>…</li>{% endfor %}`".into());
                None
            }
        };

        if !valid {
            return None;
        }

        // The newlines that the lowering drops: before the element of an item, and after it.
        let root = root?;
        let at = body.iter().position(|n| std::ptr::eq(n, root)).unwrap_or(0);
        let outer = e.children.iter().position(|n| matches!(n, Node::Block(b) if std::ptr::eq(b, block))).unwrap_or(0);
        let before = e.children[..outer].iter().chain(&body[..at]).map(newlines).sum::<usize>() + tag.raw.matches('\n').count();
        let after = body[at + 1..].iter().chain(&e.children[outer + 1..]).map(newlines).sum::<usize>() + block.close.raw.matches('\n').count();

        Some(FeedLoop { item: names[0].clone(), root, lines: (before, after) })
    }

    /// E066: a request renders only the new and the changed items of a feed, outside the shape of
    /// the page. So an item does not read the `loop` of the feed loop, and it renders no class or
    /// function component (they keep their state and their props in the page), only template
    /// components.
    fn check_item(&mut self, item: &Node) -> bool {
        let mut loops = Vec::new();
        let mut components = Vec::new();
        self.item_uses(item, true, &mut loops, &mut components);

        if let Some(span) = loops.first() {
            self.error(codes::FEED_CONTENT, "an item of a feed reads `loop`: a request renders only the new and the changed items, so `loop` is not the place of the item in the list", *span).help =
                Some("read the value from the item (`{{ message.createdAt }}`), or use CSS (`:nth-child()`, `counter()`)".into());
        }

        for (tag, kind, span) in &components {
            self.error(codes::FEED_CONTENT, format!("an item of a feed renders the {kind} component `<{tag}>`: it keeps its state and its props in the page, and an item is not in the page"), *span).help =
                Some("write its markup in the item, or use a template component (a function that only returns markup)".into());
        }

        loops.is_empty() && components.is_empty()
    }

    /// The uses of `loop` (when `loop` is the loop of the feed), and the class and function
    /// components, in the markup of an item.
    fn item_uses(&self, n: &Node, feed_loop: bool, loops: &mut Vec<Span>, components: &mut Vec<(String, &'static str, Span)>) {
        let read = |code: &str, span: Span, loops: &mut Vec<Span>| {
            if feed_loop && phemc_twig_scan::reads_loop(code) {
                loops.push(span);
            }
        };

        match n {
            Node::Element(e) => {
                for a in &e.attrs {
                    match &a.kind {
                        AttrKind::Spread(x) | AttrKind::Plain { value: AttrValue::Expr(x), .. } => read(&x.code(), x.span, loops),
                        AttrKind::Plain { value: AttrValue::Quoted { parts, .. }, .. } => {
                            for p in parts {
                                if let StrPart::Expr(x) = p {
                                    read(&x.code(), x.span, loops);
                                }
                            }
                        }
                        AttrKind::Plain { value: AttrValue::None, .. } => {}
                    }
                }

                // `<Dynamic>` is an element of the item, and an unknown component has its own error.
                let fqcn = self.info.resolve_component(&e.name);
                if let Some(def) = self.reg.get(&fqcn).filter(|_| e.is_component() && fqcn != phemc_analyze::DYNAMIC) {
                    match def.kind {
                        Kind::Class => components.push((e.name.clone(), "class", e.name_span)),
                        Kind::Function => components.push((e.name.clone(), "function", e.name_span)),
                        Kind::Template => {}
                    }
                }

                for c in &e.children {
                    self.item_uses(c, feed_loop, loops, components);
                }
            }
            Node::Fragment(f) => {
                for c in &f.children {
                    self.item_uses(c, feed_loop, loops, components);
                }
            }
            Node::Output(o) => read(&o.expr.code(), o.expr.span, loops),
            Node::Tag(t) => read(&t.head, t.head_span, loops),
            Node::Block(b) => {
                for (i, br) in b.branches.iter().enumerate() {
                    read(&br.tag.head, br.tag.head_span, loops);

                    // The body of an inner loop has its own `loop`.
                    let own = b.name() == "for" && i == 0;
                    for c in &br.children {
                        self.item_uses(c, feed_loop && !own, loops, components);
                    }
                }
            }
            Node::Text(_) | Node::HtmlComment(_) | Node::Verbatim(_) | Node::TwigComment(_) => {}
        }
    }

    /// The items of a feed: `{% for message in phem_feed_items(messages) %}{% apply
    /// phem_feed_item(messages, message) %}<li>…</li>{% endapply %}{% endfor %}{{ phem_feed_end(messages) }}`.
    /// No whitespace between the items: the browser adds and removes them. The newlines of the
    /// source go in the tags, so Twig error lines stay close.
    fn feed_items(&mut self, f: &FeedList, out: &mut String) {
        let (name, item) = (&f.name, &f.body.item);
        let (before, after) = f.body.lines;
        out.push_str(&format!("{{% for {item} in phem_feed_items({name}) %}}{{% apply phem_feed_item({name}, {item}){} %}}", "\n".repeat(before)));

        let bound = self.twig_bound.len();
        self.twig_bound.push(item.clone());
        let in_item = std::mem::replace(&mut self.in_feed_item, true);
        self.node(f.body.root, out);
        self.in_feed_item = in_item;
        self.twig_bound.truncate(bound);

        out.push_str(&format!("{{% endapply{} %}}{{% endfor %}}{{{{ phem_feed_end({name}) }}}}", "\n".repeat(after)));
    }

    fn expr_code(&mut self, x: &TwigExpr) -> String {
        if x.has_markup() {
            self.error(codes::MARKUP_IN_EXPRESSION, "markup is not allowed in an attribute value", x.span)
                .help = Some("assign the markup to a PHP variable first, or use a slot: `<Card:header>...</Card:header>`".into());
            return "null".into();
        }

        let code = x.code();
        self.twig(&code, x.span.start, &[]).trim().to_string()
    }

    /// E018: a literal value of a backed enum prop (`variant="outline"`) is the value of a case.
    /// The runtime converts it to the case. An enum outside the sources is checked only there.
    /// A bare attribute gives `true`, which is not a case either.
    fn check_enum_value(&mut self, def: &ComponentDef, tag: &str, prop: &str, value: &AttrValue, span: Span) {
        let Some(values) = def.types.get(prop).and_then(|t| self.enums.get(&t.to_ascii_lowercase())).and_then(|e| e.values.as_ref()) else { return };
        let value = match value {
            AttrValue::None => "true",
            AttrValue::Quoted { parts, .. } => match parts.as_slice() {
                [] => "",
                [StrPart::Lit(v)] => v.as_str(),
                _ => return,
            },
            AttrValue::Expr(_) => return,
        };

        if values.iter().any(|v| v == value) {
            return;
        }

        self.error(codes::UNKNOWN_ENUM_VALUE, format!("`<{tag}>` has no {prop} `{value}`"), span).help = Some(format!("its values are: {}", values.join(", ")));
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

    /// A quoted `class` value with an expression as a Twig list, one class (or one part of a
    /// class) in each item: `"btn btn-{{ size }} {{ extra }}"` → `['btn', 'btn-' ~ (size), (extra)]`.
    /// So an expression can give a list or a ClassList, and the runtime merges it.
    fn class_list_expr(&mut self, parts: &[StrPart], span: Span) -> String {
        let mut items: Vec<String> = Vec::new();
        let mut item: Vec<String> = Vec::new();
        let flush = |item: &mut Vec<String>, items: &mut Vec<String>| {
            if !item.is_empty() {
                items.push(item.join(" ~ "));
                item.clear();
            }
        };

        for p in parts {
            match p {
                StrPart::Lit(s) => {
                    if s.contains("{%") || s.contains("{#") {
                        self.error(codes::BAD_ATTRIBUTE, "Twig tags are not allowed in this attribute value", span)
                            .help = Some("use an expression: `name={{ ok ? 'a' : 'b' }}`".into());
                    }

                    if s.starts_with(char::is_whitespace) {
                        flush(&mut item, &mut items);
                    }

                    let words: Vec<&str> = s.split_whitespace().collect();
                    for (i, w) in words.iter().enumerate() {
                        if i > 0 {
                            flush(&mut item, &mut items);
                        }

                        item.push(php_str(w));
                    }

                    if s.ends_with(char::is_whitespace) {
                        flush(&mut item, &mut items);
                    }
                }
                StrPart::Expr(x) => {
                    let c = self.expr_code(x);
                    item.push(format!("({c})"));
                }
            }
        }

        flush(&mut item, &mut items);

        format!("[{}]", items.join(", "))
    }

    /// The attributes of an element with a spread, in one call: `<a x="1" {{ ...rest }} y={{ v }}>`
    /// → `phem_attrs({'x': '1'}, rest, {'y': (v)})`. A later value replaces an earlier one, as in React.
    /// `last`: the entries of a last group, after the attributes of the markup. Returns the
    /// groups of the markup.
    fn merged_attrs(&mut self, attrs: &[Attr], last: &[String], out: &mut String) -> Vec<String> {
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
                // See bind(), error_of() and feed().
                AttrKind::Plain { name, .. } if name == BIND || name == ERROR || name == FEED => {}
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
                        AttrValue::Quoted { parts, .. } if is_class_attr(name) && has_expr(parts) => self.class_list_expr(parts, a.span),
                        AttrValue::Quoted { parts, .. } => self.quoted_expr(parts, a.span),
                    };

                    hash.push(format!("{}: {v}", php_str(name)));
                }
            }
        }

        if !hash.is_empty() {
            args.push(format!("{{{}}}", hash.join(", ")));
        }

        let mut all = args.clone();
        if !last.is_empty() {
            all.push(format!("{{{}}}", last.join(", ")));
        }

        let ws = attrs.first().map_or(" ", |a| if a.ws_before.is_empty() { " " } else { a.ws_before.as_str() });
        out.push_str(&format!("{ws}{{{{ phem_attrs({}) }}}}", all.join(", ")));

        args
    }

    /// The checks of a handler: a function, or a call with arguments (`onClick={{ remove(item) }}`).
    fn check_handler(&mut self, name: &str, code: &str, span: Span) {
        match phemc_twig_scan::split_call(code) {
            Some(call) => {
                self.check_reserved_handler(name, &call.callee, span);
                self.check_handler_call(name, &call, span);
            }
            None => self.check_reserved_handler(name, code.trim(), span),
        }
    }

    /// E047: the arguments of a handler call go to the first parameters of the method. A call
    /// with named arguments, with more arguments than the method has parameters, with an
    /// argument for an `Event` parameter, or with a literal of another type never runs. Only a
    /// method of the class in the sources: a variable or a prop can be any function.
    fn check_handler_call(&mut self, name: &str, call: &phemc_twig_scan::Call, span: Span) {
        if let Some((arg, _)) = call.args.iter().find(|(a, _)| named_argument(a).is_some()) {
            let param = named_argument(arg).unwrap_or_default();
            self.error(codes::INVALID_HANDLER_CALL, format!("`{name}` gives the named argument `{param}`"), span).help =
                Some("give the arguments in the order of the parameters of the handler".into());
            return;
        }

        let Some(method) = self.handler_method(&call.callee) else {
            return;
        };

        let method = method.clone();
        let params: Vec<_> = method.params.iter().filter(|p| !p.variadic).collect();
        let variadic = method.params.iter().any(|p| p.variadic);
        if call.args.len() > params.len() && !variadic {
            let has = match params.len() {
                1 => "1 parameter".to_string(),
                n => format!("{n} parameters"),
            };

            self.error(codes::INVALID_HANDLER_CALL, format!("`{}()` has {has}, but `{name}` gives it {} arguments", method.name, call.args.len()), span).help =
                Some(format!("remove the extra arguments, or add parameters to `{}()`", method.name));
            return;
        }

        for ((arg, _), param) in call.args.iter().zip(&params) {
            let class_ty = self.info.class_type(&param.ty);
            if class_ty.as_deref().is_some_and(|c| c.trim_start_matches('\\') == "Phem\\Event") {
                self.error(codes::INVALID_HANDLER_CALL, format!("`{name}` gives the argument `{arg}` to the `Event` parameter `${}` of `{}()`", param.name, method.name), span).help =
                    Some(format!("put the parameters of the arguments first: `{}(…, Event ${})`", method.name, param.name));
                return;
            }

            if let Some(kind) = literal_kind(arg) {
                if !literal_fits(kind, &param.ty, class_ty.is_some()) {
                    self.error(codes::INVALID_HANDLER_CALL, format!("`{name}` gives {kind} `{arg}` to the parameter `{} ${}` of `{}()`", param.ty, param.name, method.name), span).help =
                        Some(format!("give a value of the type `{}`", param.ty));
                    return;
                }
            }
        }
    }

    /// The method of the class component that a handler name runs: not a variable, a prop or a
    /// property of the same name.
    fn handler_method(&self, ident: &str) -> Option<&phemc_analyze::Func> {
        let class = self.class?;
        if self.is_value(ident) {
            return None;
        }

        class.methods.iter().find(|m| m.name.eq_ignore_ascii_case(ident))
    }

    /// Whether a name in the markup is a value: a PHP or Twig variable, a parameter, or a prop
    /// or property of the class.
    fn is_value(&self, ident: &str) -> bool {
        has_variable(self.scope_text, ident)
            || self.twig_bound.iter().any(|v| v == ident)
            || self.params.iter().any(|p| p.name == ident)
            || self.class.is_some_and(|c| c.all_props().any(|p| p.name == ident))
    }

    /// E046: `mount()` and `render()` of a class component are not handlers: Phem calls them
    /// itself. The names are reserved, so the method can also come from a parent outside the
    /// sources. A PHP or Twig variable, a prop or a property of the same name is a value.
    fn check_reserved_handler(&mut self, name: &str, ident: &str, span: Span) {
        if !self.class.is_some_and(|c| c.is_component || c.abstract_component) {
            return;
        }

        let Some(method) = RESERVED_METHODS.iter().find(|m| m.eq_ignore_ascii_case(ident)) else {
            return;
        };

        if self.is_value(ident) {
            return;
        }

        self.error(codes::RESERVED_HANDLER, format!("`{name}` cannot run `{method}()`: Phem calls it itself"), span).help =
            Some(format!("move the code into a method with another name, and pass it: `{name}={{{{ reload }}}}`"));
    }

    /// W003: the `phem` client listens only to some events (`DomEvents::LISTENED` of the runtime).
    fn check_event(&mut self, name: &str, event: &str, span: Span) {
        if !crate::EVENTS.iter().any(|e| event_name(e).as_deref() == Some(event)) {
            self.diags.push(
                Diagnostic::warning(codes::UNKNOWN_EVENT, format!("`{name}` never runs: the browser does not send `{event}` events"), span)
                    .with_help(format!("use one of: {}", crate::EVENTS.join(", "))),
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
    /// path in an array or an object property: `filters.status`, `rows[key].name`, `draft.name`.
    /// Or a `Bound` prop: the property of a parent component.
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

        let target = self.bind_target(value, span, BIND, codes::INVALID_BIND)?;
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

        if let Some((s, _)) = attr(INVALID) {
            self.error(codes::INVALID_BIND, "`aria-invalid` and `phem:bind` cannot be on the same element", s).help =
                Some("`phem:bind` sets it when the field has a validation error: remove it".into());
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

        // The ids of the markup: the runtime adds the id of the validation error after them.
        let described_by = match attr(DESCRIBED_BY) {
            Some((_, AttrValue::Expr(v))) => Some(format!("({})", self.expr_code(v))),
            Some((_, AttrValue::Quoted { parts, .. })) => Some(self.quoted_expr(parts, span)),
            _ => None,
        };

        // `this`: the component of the property. The markup can render in the slot of another
        // component (`<Document>`), so the current component is not always the owner. A Bound
        // prop has its owner.
        let (target, code, owner) = match target {
            BindTarget::State { target, code, .. } => (target, code, "this"),
            BindTarget::Bound(name) => (name.clone(), name, "null"),
        };

        Some(BoundField {
            call: format!("phem_bind({target}, {code}, {}, {ty}, {option}, {owner}", php_str(&tag)),
            described_by,
            text: (tag == "textarea").then(|| format!("{{{{ phem_bind_value({code}) }}}}")),
            select: (tag == "select").then(|| code.clone()),
        })
    }

    /// The value of `phem:bind` or `phem:error` (`d`), on an element or on the tag of a
    /// component: a #[State] property of the class, a path in an array property, or a `Bound`
    /// prop of the component. The errors have the code of the directive.
    fn bind_target(&mut self, value: &AttrValue, span: Span, d: &str, code: &'static str) -> Option<BindTarget> {
        let help = format!("write the name of a #[State] property: `{d}={{{{ query }}}}`");
        let AttrValue::Expr(x) = value else {
            self.error(code, format!("`{d}` takes the name of a #[State] property"), span).help = Some(help);
            return None;
        };

        let value_code = self.expr_code(x);
        let Some((root, path)) = bind_path(&value_code) else {
            self.error(code, format!("`{d}` takes the name of a #[State] property, or a path in it, not an expression"), x.span).help =
                Some(format!("{help}, or a path in an array property: `{d}={{{{ filters.status }}}}`, `{d}={{{{ rows[key].name }}}}`"));
            return None;
        };

        if self.is_bound_prop(&root) {
            if !path.is_empty() {
                self.error(code, format!("`{root}` is a Bound prop: a path in it is not supported"), x.span).help =
                    Some(format!("use the prop itself: `{d}={{{{ {root} }}}}`, and give the path in the parent"));
                return None;
            }

            return Some(BindTarget::Bound(root));
        }

        let Some(class) = self.class else {
            self.error(code, format!("`{d}` needs a class component: a function component has no state"), span).help =
                Some(format!("use a #[State] property of a class component, or declare a `Bound ${root}` prop and bind it in the parent: `phem:bind={{{{ query }}}}`"));
            return None;
        };

        if has_variable(self.scope_text, &root) {
            self.error(code, format!("`{root}` is a local variable, not a #[State] property"), x.span).help =
                Some(format!("rename the variable `${root}`: in markup it hides the property"));
            return None;
        }

        let prop = class.all_props().find(|p| p.name == root && !p.is_static);
        match prop {
            Some(p) if !p.is_state => {
                self.error(code, format!("`{root}` is not a #[State] property"), x.span).help =
                    Some(format!("add `#[State]` to `${root}`: the browser keeps only the state"));
                return None;
            }
            Some(p) if !path.is_empty() && !takes_path(&p.ty) => {
                self.error(code, format!("a path needs an array or an object property, but `${root}` is `{}`", p.ty.trim()), x.span).help =
                    Some(format!("use the property itself (`{d}={{{{ {root} }}}}`), or make it an `array` or a draft class"));
                return None;
            }
            None if !class.open => {
                let decl = if path.is_empty() { format!("#[State] public string ${root} = '';") } else { format!("#[State] public array ${root} = [];") };
                self.error(code, format!("`{}` has no property `{root}`", class.name), x.span).help = Some(format!("declare `{decl}`"));
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

        Some(BindTarget::State { target, code: value_code, prop: prop.cloned(), path: !path.is_empty() })
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

                if name == BIND || name == ERROR || name == FEED || (self.skip_described_by && name.eq_ignore_ascii_case(DESCRIBED_BY)) {
                    // See bind(), error_of() and feed().
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
                    // A class from an expression can be a list or a ClassList: phem_class() merges it.
                    AttrValue::Quoted { parts, .. } if html_name == "class" && has_expr(parts) => {
                        let c = self.class_list_expr(parts, a.span);
                        out.push_str(&format!("{ws}{{{{ phem_class({c}) }}}}"));
                    }
                    // A URL from an expression goes through phem_attr(), which drops a `javascript:` URL.
                    AttrValue::Quoted { parts, .. } if is_url_attr(html_name) && has_expr(parts) => {
                        let c = self.quoted_expr(parts, a.span);
                        out.push_str(&format!("{ws}{{{{ phem_attr('{html_name}', {c}) }}}}"));
                    }
                    AttrValue::Quoted { raw, quote, .. } => out.push_str(&format!("{ws}{html_name}={quote}{raw}{quote}")),
                    AttrValue::Expr(x) => {
                        let c = self.expr_code(x);
                        if html_name == "style" {
                            out.push_str(&format!("{ws}{{{{ phem_style({c}) }}}}"));
                        } else if html_name == "class" {
                            out.push_str(&format!("{ws}{{{{ phem_class({c}) }}}}"));
                        } else {
                            out.push_str(&format!("{ws}{{{{ phem_attr('{html_name}', {c}) }}}}"));
                        }
                    }
                }
            }
        }
    }

    fn component(&mut self, e: &Element, out: &mut String) {
        let fqcn = self.info.resolve_component(&e.name);
        let Some(def) = self.reg.get(&fqcn).cloned() else {
            let short = fqcn.rsplit('\\').next().unwrap_or(&fqcn).to_string();
            let similar: Vec<&str> = self.reg.keys().filter(|k| k.rsplit('\\').next() == Some(short.as_str())).map(|s| s.as_str()).collect();
            let plain = self.info.functions.iter().find(|f| f.fqn.eq_ignore_ascii_case(&fqcn));
            let help = match (plain, similar.first()) {
                (Some(f), _) => format!("add `#[Component]` before `function {}()`, and `use {};`", f.name, phemc_analyze::ATTR_COMPONENT),
                (None, Some(s)) if phemc_analyze::is_builtin(s) => format!("import it: `use {s};`"),
                (None, Some(s)) => format!("import it: `use function {s};` (or `use {s};` for a class component)"),
                (None, None) => format!("declare `#[Component] function {short}()` in a .phem file, or check the `use` imports"),
            };

            self.error(codes::UNKNOWN_COMPONENT, format!("unknown component `<{}>` (resolved to `{fqcn}`)", e.name), e.name_span).help = Some(help);
            return;
        };

        if fqcn == phemc_analyze::DYNAMIC {
            self.dynamic(e, out);
            return;
        }

        // A page renders at its URL, with its document: it has no props, and it is not a tag.
        if def.page {
            self.error(codes::PAGE_AS_TAG, format!("`<{}>` is a page, and a page cannot be a tag", e.name), e.name_span).help =
                Some("move the markup that both need to a component, and render that component in the page and here".into());
            return;
        }

        let error = self.error_of(e, "");

        // With a spread, the groups of props go to phem_props(): `{'a': 1}, rest, {'b': 2}`.
        let mut groups: Vec<String> = Vec::new();
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
                    bind = self.bind_target(value, *name_span, BIND, codes::INVALID_BIND).map(|t| (t, *name_span));
                }
                // See error_of().
                AttrKind::Plain { name, .. } if name == ERROR => {}
                AttrKind::Plain { name, name_span, .. } if name == FEED => {
                    self.error(codes::INVALID_FEED, format!("`phem:feed` is for an element, not the tag of a component `<{}>`", e.name), *name_span).help =
                        Some(format!("put it on the element of the list in `{}`: `<ul phem:feed={{{{ messages }}}}>`", def.fqcn));
                }
                AttrKind::Spread(x) => {
                    has_spread = true;
                    if !props.is_empty() {
                        groups.push(format!("{{{}}}", props.join(", ")));
                        props.clear();
                    }

                    groups.push(self.expr_code(x));
                }
                AttrKind::Plain { name, name_span, value } => {
                    let v = match value {
                        AttrValue::None => "true".to_string(),
                        AttrValue::Expr(x) => {
                            let c = self.expr_code(x);
                            // A handler prop with a call (`onPick={{ pickDay(d.key) }}`) gets the
                            // handler with its arguments, as an `on*` attribute of an element.
                            if event_name(name).is_some() && phemc_twig_scan::split_call(&c).is_some() {
                                self.check_handler(name, &c, x.span);
                                format!("({})", handler_code(&c))
                            } else {
                                format!("({c})")
                            }
                        }
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
                    } else {
                        self.check_enum_value(&def, &e.name, name, value, a.span);
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

        // `phem:error={{ email }}`: the child gets the id of the error and its message. An invalid
        // `phem:error` has its own error: no "needs the prop" for them.
        if e.attrs.iter().any(|a| matches!(&a.kind, AttrKind::Plain { name, .. } if name == ERROR)) {
            given.insert("id".to_string());
            given.insert("children".to_string());
        }

        if let Some(err) = &error {
            let span = e.name_span;
            if !def.rest && !def.open && !def.props.iter().any(|p| p == "id") {
                self.error(codes::INVALID_ERROR, format!("`<{}>` has no prop `id` for `phem:error`", e.name), span).help =
                    Some(format!("add a `string $id` parameter to `{}`, and put it on the element of the message", def.fqcn));
            }

            if !def.open && !def.slots.iter().any(|s| s == "children") {
                self.error(codes::INVALID_ERROR, format!("`<{}>` has no `children` for the message of `phem:error`", e.name), span).help =
                    Some(format!("add a `?Html $children = null` parameter to `{}`", def.fqcn));
            }

            props.push(format!("'id': {}.id", err.var));
            props.push(format!("'children': {}.html", err.var));
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

        // The tag renders the replacement of the component. The checks above use the original:
        // its props are the props that the caller knows.
        let mut head = format!("component {}", php_str(self.replacements.resolve(&fqcn, self.owner)));
        if has_spread {
            // A later value replaces an earlier one, and two values of `className` merge when one
            // of them is a ClassList.
            if !props.is_empty() {
                groups.push(format!("{{{}}}", props.join(", ")));
            }

            head.push_str(&format!(" with phem_props({})", groups.join(", ")));
        } else if !props.is_empty() {
            head.push_str(&format!(" with {{{}}}", props.join(", ")));
        }

        if let Some(k) = key {
            head.push_str(&format!(" key {k}"));
        }

        head.push_str(" only");

        if let Some(err) = &error {
            out.push_str(&err.open);
        }

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

        if error.is_some() {
            out.push_str("{% endif %}");
        }
    }

    /// `<Dynamic tag={{ tag }}>`: an element whose tag name is a value. It is an element in the
    /// template, not a component with a fiber, so the id of its component goes on it, as on a
    /// static element. Its attributes go through phem_attrs(), as with a spread. The tag is
    /// checked once, in a variable, and the children render before the element, in a variable:
    /// phem_end_tag() refuses children for a void element.
    ///
    /// `{% set _phem_tag_0 = phem_tag(tag) %}{% set _phem_content_0 %}...{% endset %}<{{ _phem_tag_0 }} {{ phem_attrs(...) }}>{{ phem_end_tag(_phem_tag_0, _phem_content_0) }}`
    fn dynamic(&mut self, e: &Element, out: &mut String) {
        let mut tag = None;
        let mut has_tag = false;
        let mut attrs = Vec::new();
        for a in &e.attrs {
            match &a.kind {
                AttrKind::Plain { name, name_span, .. } if name == "tag" && has_tag => {
                    self.error(codes::BAD_ATTRIBUTE, "`<Dynamic>` has two `tag` attributes", *name_span).help = Some("keep one `tag`".into());
                }
                AttrKind::Plain { name, name_span, value } if name == "tag" => {
                    has_tag = true;
                    tag = self.dynamic_tag(value, *name_span, a.span);
                }
                AttrKind::Plain { name, name_span, .. } if name == BIND => {
                    self.error(codes::INVALID_BIND, "`phem:bind` is for a form field, not a `<Dynamic>`", *name_span).help =
                        Some("write the field as a static element: `<input phem:bind={{ query }} />`".into());
                }
                AttrKind::Plain { name, name_span, .. } if name == ERROR => {
                    self.error(codes::INVALID_ERROR, "`phem:error` is not supported on a `<Dynamic>`", *name_span).help =
                        Some("write the message as a static element: `<p phem:error={{ email }} />`".into());
                }
                AttrKind::Plain { name, name_span, .. } if name == FEED => {
                    self.error(codes::INVALID_FEED, "`phem:feed` is not supported on a `<Dynamic>`", *name_span).help =
                        Some("write the list as a static element: `<ul phem:feed={{ messages }}>`".into());
                }
                _ => attrs.push(a.clone()),
            }
        }

        if !has_tag {
            self.error(codes::UNKNOWN_PROP, format!("`<{}>` needs the prop `tag`", e.name), e.name_span).help =
                Some(format!("give the name of the element: `<{} tag={{{{ tag }}}}>`", e.name));
        }

        let Some(tag) = tag else { return };

        self.check_timing(e);

        // The tag, before the children: a tag that is not valid renders no children.
        let n = self.dynamics;
        self.dynamics += 1;
        let var = format!("_phem_tag_{n}");
        out.push_str(&format!("{{% set {var} = phem_tag({tag}) %}}"));

        // The children, before the element.
        let mut content = None;
        if e.children.iter().any(|c| !c.is_blank() && !matches!(c, Node::TwigComment(_))) {
            let content_var = format!("_phem_content_{n}");
            out.push_str(&format!("{{% set {content_var} %}}"));
            self.children(e, out);
            out.push_str("{% endset %}");
            content = Some(content_var);
        }

        out.push_str(&format!("<{{{{ {var} }}}}"));
        if !attrs.is_empty() {
            self.merged_attrs(&attrs, &[], out);
        }

        out.push('>');
        match content {
            Some(content_var) => out.push_str(&format!("{{{{ phem_end_tag({var}, {content_var}) }}}}")),
            None => out.push_str(&format!("{{{{ phem_end_tag({var}) }}}}")),
        }
    }

    /// The `tag` of `<Dynamic>` as a Twig expression. phemc checks a literal name, and the
    /// runtime checks the other values (see `Phem\Dom\DynamicTag`).
    fn dynamic_tag(&mut self, value: &AttrValue, name_span: Span, span: Span) -> Option<String> {
        match value {
            AttrValue::Expr(x) => Some(format!("({})", self.expr_code(x))),
            AttrValue::Quoted { parts, .. } if has_expr(parts) => Some(self.quoted_expr(parts, span)),
            AttrValue::Quoted { parts, .. } => {
                let name: String = parts.iter().map(|p| if let StrPart::Lit(s) = p { s.as_str() } else { "" }).collect();
                if let Some((reason, help)) = dynamic_tag_error(&name) {
                    self.error(codes::BAD_ATTRIBUTE, reason, span).help = Some(help);
                    return None;
                }

                Some(php_str(&name))
            }
            AttrValue::None => {
                self.error(codes::BAD_ATTRIBUTE, "`tag` takes the name of an element", name_span).help = Some("write `tag=\"a\"` or `tag={{ tag }}`".into());
                None
            }
        }
    }

    /// `{{ c ? <A /> : <B /> }}` → `{% if c %}...{% else %}...{% endif %}`; `{{ c and <A /> }}` → `{% if c %}...{% endif %}`.
    fn markup_expr(&mut self, x: &TwigExpr) -> Result<String, Diagnostic> {
        let mut code = String::new();
        let mut marks: Vec<&Node> = Vec::new();
        let mut offset = x.span.start;
        for p in &x.parts {
            match p {
                ExprPart::Code(c) => {
                    let lowered = self.twig(c, offset, &[]);
                    code.push_str(&lowered);
                    offset += c.len();
                }
                ExprPart::Markup(n) => {
                    code.push_str(&format!("\u{1}{}\u{2}", marks.len()));
                    marks.push(n);
                    offset = n.span().end;
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

/// The methods of a class component that Phem calls itself: they are not handlers (E046).
const RESERVED_METHODS: &[&str] = &["mount", "render"];

/// `phem:loading={{ save }}`: the element shows only while a `save` event runs.
const LOADING: &str = "phem:loading";

/// `phem:progress={{ save }}`: the element shows the upload progress of a `save` event with files.
const PROGRESS: &str = "phem:progress";

/// The attributes with a URL that the browser opens or sends a form to (see `Attributes::URLS` of the runtime).
const URL_ATTRS: &[&str] = &["action", "formaction", "href", "src", "xlink:href"];

/// Why a literal `tag` of `<Dynamic>` is not valid, with a help, or None (the check of
/// `Phem\Dom\TagName` and `Phem\Dom\DynamicTag`).
fn dynamic_tag_error(name: &str) -> Option<(String, String)> {
    let mut chars = name.chars();
    let valid = chars.next().is_some_and(|c| c.is_ascii_alphabetic()) && chars.all(|c| c.is_ascii_alphanumeric() || c == '-');
    if !valid {
        return Some((format!("`{name}` is not a valid tag name"), "write the name of an HTML element: letters, digits and `-`, for example `tag=\"a\"`".into()));
    }

    let lower = name.to_ascii_lowercase();
    if lower.starts_with("phem-") {
        return Some((format!("`<{lower}>` is an element of Phem"), "give a name that does not start with `phem-`".into()));
    }

    if UNSAFE_TAGS.contains(&lower.as_str()) {
        return Some((format!("`<Dynamic>` cannot render a `<{lower}>`"), format!("write the `<{lower}>` as static markup")));
    }

    None
}

/// The elements that a tag from a value cannot make (see `DynamicTag::UNSAFE` of the runtime):
/// the text of `<script>` and `<style>` runs or applies with no escape, and the attributes of the
/// others load a document or a resource (`<iframe srcdoc>`, `<object data>`, `<base href>`).
const UNSAFE_TAGS: &[&str] = &["base", "embed", "frame", "frameset", "iframe", "link", "meta", "object", "script", "style"];

fn is_url_attr(name: &str) -> bool {
    URL_ATTRS.iter().any(|u| u.eq_ignore_ascii_case(name))
}

/// `class` and `className`: a value with an expression is a list of classes (see `Lowerer::class_list_expr()`).
fn is_class_attr(name: &str) -> bool {
    name == "class" || name == "className"
}

fn has_expr(parts: &[StrPart]) -> bool {
    parts.iter().any(|p| matches!(p, StrPart::Expr(_)))
}

/// `phem:bind={{ query }}`: the field shows a #[State] property, and the browser sends its new
/// value with the next event. `phem:live` sends each change at once.
const BIND: &str = "phem:bind";
const LIVE: &str = "phem:live";

/// `phem:error={{ email }}`: the element renders only when the bound property has a validation
/// error, with the id of the error and its message. `phem:bind` sets `aria-invalid` and adds the
/// id to `aria-describedby`.
const ERROR: &str = "phem:error";
const INVALID: &str = "aria-invalid";
const DESCRIBED_BY: &str = "aria-describedby";

/// `phem:feed={{ messages }}`: the element is the container of a `Feed`, a list whose items live
/// only in the browser. The server renders only the new items.
const FEED: &str = "phem:feed";

/// What `phem:bind` binds (see `Lowerer::bind_target()`).
enum BindTarget {
    /// A #[State] property of the class, or a path in it: the PHP target (`'query'`,
    /// `['rows', (key), 'name']`), the Twig code of the value, the property (None in an open
    /// class), and whether it is a path.
    State { target: String, code: String, prop: Option<Prop>, path: bool },
    /// A `Bound` prop of the component: the property of a parent component.
    Bound(String),
}

/// The lowered parts of an element with `phem:error` (see `Lowerer::error_of()`).
struct ErrorElement {
    /// The Twig variable of the error: `_phem_error_0`.
    var: String,
    /// The Twig code before the element: the error, and the `{% if %}`.
    open: String,
}

/// A valid `phem:feed` element (see `Lowerer::feed()`).
struct FeedList<'e> {
    /// The name of the `Feed` property.
    name: String,
    body: FeedLoop<'e>,
}

/// The loop of a valid `phem:feed` element (see `Lowerer::feed_loop()`).
struct FeedLoop<'e> {
    /// The name of the item: `message`.
    item: String,
    /// The element of an item.
    root: &'e Node,
    /// The newlines of the source before the element of an item, and after it.
    lines: (usize, usize),
}

/// The newlines of a node that the lowering of a feed drops: its text, or its tag.
fn newlines(n: &Node) -> usize {
    match n {
        Node::Text(t) | Node::HtmlComment(t) | Node::Verbatim(t) => t.text.matches('\n').count(),
        Node::Tag(t) => t.raw.matches('\n').count(),
        _ => 0,
    }
}

/// Why a `Feed` property cannot be a feed (see `ComponentMetadata::feedProperties()`), or None.
fn feed_property_problem(p: &Prop) -> Option<String> {
    let nullable = p.ty.trim().starts_with('?') || p.ty.split('|').any(|t| t.trim().eq_ignore_ascii_case("null"));
    let problem = if !p.is_public {
        "is not public"
    } else if p.is_static {
        "is static"
    } else if p.is_readonly {
        "is readonly"
    } else if p.is_promoted {
        "is a promoted constructor parameter"
    } else if nullable {
        "is nullable"
    } else if p.is_state {
        "has `#[State]`"
    } else if p.is_context {
        "has `#[Context]`"
    } else {
        return p.prop_attr.map(|a| format!("has `#[{a}]`"));
    };

    Some(problem.to_string())
}

/// The lowered parts of a bound field (see `Lowerer::bind()`).
struct BoundField {
    /// The call with no `aria-describedby` and no `)`: `phem_bind('query', query, 'input', null, null, this`.
    call: String,
    /// The `aria-describedby` of the markup, for `phem_bind()`.
    described_by: Option<String>,
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

/// Whether a PHP property type takes a bound path: an array (`filters.status`), or an object of
/// a class (`draft.name`, see Phem\State\StateObjects). phemc does not see the class, so the
/// server checks the names of the path. No type and `mixed` take a path.
fn takes_path(ty: &str) -> bool {
    FieldValue::List.accepts(ty)
        || ty.split('|').any(|t| {
            let t = t.trim().trim_matches(|c| c == '(' || c == ')').trim_start_matches('?').trim();
            !t.is_empty() && !phemc_analyze::is_builtin_type(t)
        })
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
/// The attribute of an element with this name, and its value: `phem:feed={{ messages }}`.
fn plain_attr<'e>(e: &'e Element, name: &str) -> Option<(Span, &'e AttrValue)> {
    e.attrs.iter().find_map(|a| match &a.kind {
        AttrKind::Plain { name: n, name_span, value } if n == name => Some((*name_span, value)),
        _ => None,
    })
}

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

/// The Twig code of a handler. A member path (`tabs.select`) is the method of the object as a
/// closure (`phem_method(tabs, 'select')`): in Twig, `tabs.select` calls the method. A call
/// (`remove(item)`) is the handler with its arguments (`phem_call(remove, [item])`): it does not
/// run during the render.
fn handler_code(code: &str) -> String {
    match phemc_twig_scan::split_call(code) {
        Some(call) => {
            let args: Vec<&str> = call.args.iter().map(|(a, _)| a.as_str()).collect();
            format!("phem_call({}, [{}])", member_code(&call.callee), args.join(", "))
        }
        None => member_code(code),
    }
}

/// A member path as a closure (`phem_method(tabs, 'select')`), or the code as it is.
fn member_code(code: &str) -> String {
    let ident = |s: &str| s.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_') && s.chars().all(|c| c.is_ascii_alphanumeric() || c == '_');
    match code.rsplit_once('.') {
        Some((object, member)) if ident(member) && object.split('.').all(ident) => format!("phem_method({object}, {})", php_str(member)),
        _ => code.to_string(),
    }
}

/// The name of a named argument (`reason: r`, `reason = r`).
fn named_argument(arg: &str) -> Option<&str> {
    let end = arg.find(|c: char| !(c.is_ascii_alphanumeric() || c == '_'))?;
    let rest = arg[end..].trim_start();
    let named = end > 0 && arg.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_') && ((rest.starts_with(':') && !rest.starts_with("::")) || (rest.starts_with('=') && !rest.starts_with("==") && !rest.starts_with("=>")));

    named.then(|| &arg[..end])
}

/// The kind of a literal argument (`'no_stock'`, `3`, `true`, `null`), or none for another value.
fn literal_kind(arg: &str) -> Option<&'static str> {
    let arg = arg.trim();
    match arg {
        "true" | "false" | "TRUE" | "FALSE" => Some("the bool"),
        "null" | "none" | "NULL" | "NONE" => Some("null"),
        _ if (arg.starts_with('\'') && arg.ends_with('\'') || arg.starts_with('"') && arg.ends_with('"')) && arg.len() >= 2 && !arg.contains("#{") => Some("the string"),
        _ if arg.parse::<i64>().is_ok() => Some("the int"),
        _ if arg.parse::<f64>().is_ok() && arg.contains('.') => Some("the float"),
        _ => None,
    }
}

/// Whether a literal fits a parameter type, with `declare(strict_types=1)`. Only a single type is
/// checked: a union, `mixed` or no type accepts any literal.
fn literal_fits(kind: &str, ty: &str, is_class: bool) -> bool {
    let ty = ty.trim();
    if ty.is_empty() || ty.contains('|') || ty.contains('&') {
        return true;
    }

    let nullable = ty.starts_with('?');
    let base = ty.trim_start_matches('?').to_ascii_lowercase();
    if base == "mixed" {
        return true;
    }

    if kind == "null" {
        return nullable || base == "null";
    }

    if is_class {
        return false;
    }

    match base.as_str() {
        "int" => kind == "the int",
        "float" => kind == "the int" || kind == "the float",
        "string" => kind == "the string",
        "bool" => kind == "the bool",
        "true" | "false" => kind == "the bool",
        _ => true,
    }
}

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
        assert_eq!(event_name("onContextMenu").as_deref(), Some("contextmenu"));
        assert_eq!(event_name("onPointerDown").as_deref(), Some("pointerdown"));
        assert_eq!(event_name("one"), None);
    }
}
