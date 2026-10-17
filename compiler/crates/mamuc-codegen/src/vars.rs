//! Free variables of a markup literal, with Twig scopes (`for` and `set` bindings).

use mamuc_syntax::markup::{AttrKind, AttrValue, ExprPart, Node, StrPart, TwigExpr};
use mamuc_twig_scan::{expr_free_vars, tag_vars};

/// A free variable and its absolute byte offset in the source.
#[derive(Clone, Debug)]
pub struct FreeVar {
    pub name: String,
    pub offset: usize,
    /// False for a name in the head of a tag that is not a core Twig tag: it can be a keyword
    /// of the tag, so it is not an error when PHP does not define it. Also false for a name
    /// that a `{% set %}` can bind (see `Scope::maybe`).
    pub strict: bool,
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

pub fn free_vars(root: &Node) -> Vec<FreeVar> {
    let mut out = Vec::new();
    let mut scope = Scope::default();
    node(root, &mut scope, &mut out);
    // Keep the first use of each name. It is strict when one of its uses is strict.
    let strict: std::collections::HashSet<String> = out.iter().filter(|v| v.strict).map(|v| v.name.clone()).collect();
    let mut seen = std::collections::HashSet::new();
    out.retain(|v| seen.insert(v.name.clone()));
    for v in &mut out {
        v.strict = strict.contains(&v.name);
    }
    out
}

fn push(out: &mut Vec<FreeVar>, scope: &Scope, name: String, offset: usize, strict: bool) {
    if !scope.bound.contains(&name) {
        let strict = strict && !scope.maybe.contains(&name);
        out.push(FreeVar { name, offset, strict });
    }
}

fn expr(x: &TwigExpr, scope: &mut Scope, out: &mut Vec<FreeVar>) {
    // Offsets are exact only for expressions without markup.
    let exact = !x.has_markup();
    for p in &x.parts {
        match p {
            ExprPart::Code(c) => {
                for n in expr_free_vars(c) {
                    let off = if exact { x.span.start + n.offset } else { x.span.start };
                    push(out, scope, n.name, off, true);
                }
            }
            ExprPart::Markup(m) => node(m, scope, out),
        }
    }
}

/// The Twig tags with their own scope: a `{% set %}` in their body does not bind after them.
/// Other tags (`if`, `apply`, `autoescape`) and HTML elements share the scope of their parent.
const SCOPED_TAGS: &[&str] = &["for", "with", "block", "embed", "macro"];

fn children(nodes: &[Node], scope: &mut Scope, out: &mut Vec<FreeVar>) {
    for n in nodes {
        node(n, scope, out);
    }
}

/// Nodes with their own scope: a `{% set %}` binds for the following nodes in it only.
fn scoped(nodes: &[Node], scope: &mut Scope, out: &mut Vec<FreeVar>) {
    let mark = scope.mark();
    children(nodes, scope, out);
    scope.truncate(mark);
}

fn node(n: &Node, scope: &mut Scope, out: &mut Vec<FreeVar>) {
    match n {
        Node::Element(e) => {
            for a in &e.attrs {
                match &a.kind {
                    AttrKind::Spread(x) => expr(x, scope, out),
                    AttrKind::Plain { value: AttrValue::Expr(x), .. } => expr(x, scope, out),
                    AttrKind::Plain { value: AttrValue::Quoted { parts, .. }, .. } => {
                        for p in parts {
                            if let StrPart::Expr(x) = p {
                                expr(x, scope, out);
                            }
                        }
                    }
                    AttrKind::Plain { value: AttrValue::None, .. } => {}
                }
            }
            // The children of a component are slots: blocks with `{% with _mamu_caller only %}`.
            if e.is_component() {
                scoped(&e.children, scope, out);
            } else {
                children(&e.children, scope, out);
            }
        }
        Node::Fragment(f) => children(&f.children, scope, out),
        Node::Output(o) => expr(&o.expr, scope, out),
        Node::Tag(t) => {
            let tv = tag_vars(&t.name, &t.head);
            for u in tv.used {
                push(out, scope, u.name, t.head_span.start + u.offset, !tv.guessed);
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
                    push(out, scope, u.name, br.tag.head_span.start + u.offset, !tv.guessed);
                }
                let mark = scope.mark();
                // The loop variables exist only in the loop body (not in `{% else %}`).
                if br.tag.name == "for" && i == 0 {
                    scope.bound.extend(tv.bound.iter().cloned());
                }
                let start = scope.mark();
                children(&br.children, scope, out);
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
