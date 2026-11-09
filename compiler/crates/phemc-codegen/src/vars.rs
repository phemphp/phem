//! Free variables of a markup literal, with Twig scopes (`for` and `set` bindings).

use phemc_analyze::FileInfo;
use phemc_syntax::markup::{AttrKind, AttrValue, ExprPart, Node, StrPart, TwigExpr};
use phemc_twig_scan::{expr_free_vars, split_call, tag_vars};
use std::collections::HashSet;

/// A free variable and its absolute byte offset in the source.
#[derive(Clone, Debug)]
pub struct FreeVar {
    pub name: String,
    pub offset: usize,
    /// False for a name in the head of a tag that is not a core Twig tag: it can be a keyword
    /// of the tag, so it is not an error when PHP does not define it. Also false for a name
    /// that a `{% set %}` can bind (see `Scope::maybe`).
    pub strict: bool,
    /// True when the text at `offset` is the name alone, so a quick fix can rename it. False in
    /// an expression with markup in it (the offset is the start of the expression), and for a
    /// key of a Twig hash with no value (`{ rows }`), where the name is the key too.
    pub renamable: bool,
}

/// The names that Twig binds at a point of the markup.
#[derive(Default)]
struct Scope {
    /// Bound for sure: by `for`, or by a `{% set %}` that always runs before this point.
    bound: Vec<String>,
    /// Bound by a `{% set %}` in only some branches of an `if`. A use is still free (the PHP
    /// value shows when the set does not run), but not strict.
    maybe: Vec<String>,
}

impl Scope {
    fn mark(&self) -> (usize, usize) {
        (self.bound.len(), self.maybe.len())
    }

    fn truncate(&mut self, (bound, maybe): (usize, usize)) {
        self.bound.truncate(bound);
        self.maybe.truncate(maybe);
    }
}

pub fn free_vars(info: &FileInfo, root: &Node) -> Vec<FreeVar> {
    let mut out = uses(info, root);

    // Keep the first use of each name. It is strict when one of its uses is strict.
    let strict: HashSet<String> = out.iter().filter(|v| v.strict).map(|v| v.name.clone()).collect();
    let mut seen = HashSet::new();
    out.retain(|v| seen.insert(v.name.clone()));
    for v in &mut out {
        v.strict = strict.contains(&v.name);
    }

    out
}

/// Each use of a free variable of the markup, in source order. A name can be in the list more
/// than once.
pub fn uses(info: &FileInfo, root: &Node) -> Vec<FreeVar> {
    let mut out = Vec::new();
    let mut scope = Scope::default();
    node(info, root, &mut scope, &mut out);

    out
}

/// The names that a Twig tag of the markup binds (`for`, `set`, `with`, `macro`), at any point.
pub fn bound_names(root: &Node) -> HashSet<String> {
    let mut out = HashSet::new();
    bound_in(root, &mut out);

    out
}

fn bound_in(n: &Node, out: &mut HashSet<String>) {
    let in_expr = |x: &TwigExpr, out: &mut HashSet<String>| {
        for p in &x.parts {
            if let ExprPart::Markup(m) = p {
                bound_in(m, out);
            }
        }
    };

    match n {
        Node::Element(e) => {
            for a in &e.attrs {
                match &a.kind {
                    AttrKind::Spread(x) | AttrKind::Plain { value: AttrValue::Expr(x), .. } => in_expr(x, out),
                    AttrKind::Plain { value: AttrValue::Quoted { parts, .. }, .. } => {
                        for p in parts {
                            if let StrPart::Expr(x) = p {
                                in_expr(x, out);
                            }
                        }
                    }
                    AttrKind::Plain { value: AttrValue::None, .. } => {}
                }
            }

            e.children.iter().for_each(|c| bound_in(c, out));
        }
        Node::Fragment(f) => f.children.iter().for_each(|c| bound_in(c, out)),
        Node::Output(o) => in_expr(&o.expr, out),
        Node::Tag(t) => out.extend(tag_vars(&t.name, &t.head).bound),
        Node::Block(b) => {
            for br in &b.branches {
                out.extend(tag_vars(&br.tag.name, &br.tag.head).bound);
                br.children.iter().for_each(|c| bound_in(c, out));
            }
        }
        Node::Text(_) | Node::HtmlComment(_) | Node::Verbatim(_) | Node::TwigComment(_) => {}
    }
}

fn push(out: &mut Vec<FreeVar>, scope: &Scope, name: String, offset: usize, strict: bool, renamable: bool) {
    if !scope.bound.contains(&name) {
        let strict = strict && !scope.maybe.contains(&name);
        out.push(FreeVar { name, offset, strict, renamable });
    }
}

fn expr(info: &FileInfo, x: &TwigExpr, scope: &mut Scope, out: &mut Vec<FreeVar>) {
    // Offsets are exact only for expressions without markup.
    let exact = !x.has_markup();
    for p in &x.parts {
        match p {
            ExprPart::Code(c) => {
                for n in expr_free_vars(c) {
                    let off = if exact { x.span.start + n.offset } else { x.span.start };
                    push(out, scope, n.name, off, true, exact && !n.shorthand);
                }
            }
            ExprPart::Markup(m) => node(info, m, scope, out),
        }
    }
}

/// The function of a handler call (`onClick={{ remove(item) }}`): Twig reads `remove(` as a
/// Twig function, but the call compiles to `phem_call(remove, [item])` (see lower.rs).
fn handler_callee(attr: &str, x: &TwigExpr, scope: &Scope, out: &mut Vec<FreeVar>) {
    let is_handler = attr.strip_prefix("on").is_some_and(|r| r.starts_with(|c: char| c.is_ascii_uppercase())) || attr == "phem:loading" || attr == "phem:progress";
    if !is_handler || x.has_markup() {
        return;
    }

    let code = x.code();
    let Some(call) = split_call(&code) else { return };
    if call.callee.contains('.') {
        // `tabs.select(…)`: `tabs` is a free variable of the expression.
        return;
    }

    let offset = code.len() - code.trim_start().len();
    push(out, scope, call.callee, x.span.start + offset, true, true);
}

/// The Twig tags with their own scope: a `{% set %}` in their body does not bind after them.
/// Other tags (`if`, `apply`, `autoescape`) and HTML elements share the scope of their parent.
const SCOPED_TAGS: &[&str] = &["for", "with", "block", "embed", "macro"];

fn children(info: &FileInfo, nodes: &[Node], scope: &mut Scope, out: &mut Vec<FreeVar>) {
    for n in nodes {
        node(info, n, scope, out);
    }
}

/// Nodes with their own scope: a `{% set %}` binds for the following nodes in it only.
fn scoped(info: &FileInfo, nodes: &[Node], scope: &mut Scope, out: &mut Vec<FreeVar>) {
    let mark = scope.mark();
    children(info, nodes, scope, out);
    scope.truncate(mark);
}

fn node(info: &FileInfo, n: &Node, scope: &mut Scope, out: &mut Vec<FreeVar>) {
    match n {
        Node::Element(e) => {
            for a in &e.attrs {
                match &a.kind {
                    AttrKind::Spread(x) => expr(info, x, scope, out),
                    AttrKind::Plain { name, value: AttrValue::Expr(x), .. } => {
                        handler_callee(name, x, scope, out);
                        expr(info, x, scope, out);
                    }
                    AttrKind::Plain { value: AttrValue::Quoted { parts, .. }, .. } => {
                        for p in parts {
                            if let StrPart::Expr(x) = p {
                                expr(info, x, scope, out);
                            }
                        }
                    }
                    AttrKind::Plain { value: AttrValue::None, .. } => {}
                }
            }

            // The children of a component are slots: blocks with `{% with _phem_caller only %}`.
            // The children of `<Dynamic>` are in the markup of its parent.
            if e.is_component() && !info.is_dynamic(&e.name) {
                scoped(info, &e.children, scope, out);
            } else {
                children(info, &e.children, scope, out);
            }
        }
        Node::Fragment(f) => children(info, &f.children, scope, out),
        Node::Output(o) => expr(info, &o.expr, scope, out),
        Node::Tag(t) => {
            let tv = tag_vars(&t.name, &t.head);
            for u in tv.used {
                push(out, scope, u.name, t.head_span.start + u.offset, !tv.guessed, !u.shorthand);
            }

            if t.name == "set" {
                scope.bound.extend(tv.bound);
            }
        }
        Node::Block(b) => {
            let shares_scope = !SCOPED_TAGS.contains(&b.name());
            // One branch always runs, unless it is an `if` without `else`.
            let exhaustive = b.name() != "if" || b.branches.last().is_some_and(|br| br.tag.name == "else");
            // The names that a `{% set %}` binds in each branch of a tag without a scope, and
            // the names that a branch can bind.
            let mut set_in_branches: Vec<Vec<String>> = Vec::new();
            let mut maybe = Vec::new();
            for (i, br) in b.branches.iter().enumerate() {
                let tv = tag_vars(&br.tag.name, &br.tag.head);
                for u in tv.used {
                    push(out, scope, u.name, br.tag.head_span.start + u.offset, !tv.guessed, !u.shorthand);
                }

                let mark = scope.mark();
                // The loop variables exist only in the loop body (not in `{% else %}`).
                if br.tag.name == "for" && i == 0 {
                    scope.bound.extend(tv.bound.iter().cloned());
                }

                let start = scope.mark();
                children(info, &br.children, scope, out);
                if shares_scope {
                    set_in_branches.push(scope.bound.drain(start.0..).collect());
                    maybe.extend(scope.maybe.drain(start.1..));
                }

                scope.truncate(mark);
                if br.tag.name == "set" {
                    scope.bound.extend(tv.bound);
                }
            }

            // A name that each branch sets binds after the tag. A name that only some branches
            // set can bind: Twig does not know which branch runs.
            for name in set_in_branches.iter().flatten() {
                if exhaustive && set_in_branches.iter().all(|s| s.contains(name)) {
                    scope.bound.push(name.clone());
                } else {
                    maybe.push(name.clone());
                }
            }

            scope.maybe.extend(maybe);
        }
        Node::Text(_) | Node::HtmlComment(_) | Node::Verbatim(_) | Node::TwigComment(_) => {}
    }
}
