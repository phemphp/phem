//! Free variables of a markup literal, with Twig scopes (`for` and `set` bindings).

use mamuc_syntax::markup::{AttrKind, AttrValue, ExprPart, Node, StrPart, TwigExpr};
use mamuc_twig_scan::{expr_free_vars, tag_vars};

/// A free variable and its absolute byte offset in the source.
#[derive(Clone, Debug)]
pub struct FreeVar {
    pub name: String,
    pub offset: usize,
    /// False for a name in the head of a tag that is not a core Twig tag: it can be a keyword
    /// of the tag, so it is not an error when PHP does not define it.
    pub strict: bool,
}

pub fn free_vars(root: &Node) -> Vec<FreeVar> {
    let mut out = Vec::new();
    let mut bound = Vec::new();
    node(root, &mut bound, &mut out);
    // Keep the first use of each name. It is strict when one of its uses is strict.
    let strict: std::collections::HashSet<String> = out.iter().filter(|v| v.strict).map(|v| v.name.clone()).collect();
    let mut seen = std::collections::HashSet::new();
    out.retain(|v| seen.insert(v.name.clone()));
    for v in &mut out {
        v.strict = strict.contains(&v.name);
    }
    out
}

fn push(out: &mut Vec<FreeVar>, bound: &[String], name: String, offset: usize, strict: bool) {
    if !bound.contains(&name) {
        out.push(FreeVar { name, offset, strict });
    }
}

fn expr(x: &TwigExpr, bound: &mut Vec<String>, out: &mut Vec<FreeVar>) {
    // Offsets are exact only for expressions without markup.
    let exact = !x.has_markup();
    for p in &x.parts {
        match p {
            ExprPart::Code(c) => {
                for n in expr_free_vars(c) {
                    let off = if exact { x.span.start + n.offset } else { x.span.start };
                    push(out, bound, n.name, off, true);
                }
            }
            ExprPart::Markup(m) => node(m, bound, out),
        }
    }
}

fn children(nodes: &[Node], bound: &mut Vec<String>, out: &mut Vec<FreeVar>) {
    // `{% set %}` binds for the following siblings (and their descendants) only.
    let mark = bound.len();
    for n in nodes {
        node(n, bound, out);
    }
    bound.truncate(mark);
}

fn node(n: &Node, bound: &mut Vec<String>, out: &mut Vec<FreeVar>) {
    match n {
        Node::Element(e) => {
            for a in &e.attrs {
                match &a.kind {
                    AttrKind::Spread(x) => expr(x, bound, out),
                    AttrKind::Plain { value: AttrValue::Expr(x), .. } => expr(x, bound, out),
                    AttrKind::Plain { value: AttrValue::Quoted { parts, .. }, .. } => {
                        for p in parts {
                            if let StrPart::Expr(x) = p {
                                expr(x, bound, out);
                            }
                        }
                    }
                    AttrKind::Plain { value: AttrValue::None, .. } => {}
                }
            }
            children(&e.children, bound, out);
        }
        Node::Fragment(f) => children(&f.children, bound, out),
        Node::Output(o) => expr(&o.expr, bound, out),
        Node::Tag(t) => {
            let tv = tag_vars(&t.name, &t.head);
            for u in tv.used {
                push(out, bound, u.name, t.head_span.start + u.offset, !tv.guessed);
            }
            if t.name == "set" {
                bound.extend(tv.bound);
            }
        }
        Node::Block(b) => {
            for (i, br) in b.branches.iter().enumerate() {
                let tv = tag_vars(&br.tag.name, &br.tag.head);
                for u in tv.used {
                    push(out, bound, u.name, br.tag.head_span.start + u.offset, !tv.guessed);
                }
                let mark = bound.len();
                // The loop variables exist only in the loop body (not in `{% else %}`).
                if br.tag.name == "for" && i == 0 {
                    bound.extend(tv.bound.iter().cloned());
                }
                children(&br.children, bound, out);
                bound.truncate(mark);
                if br.tag.name == "set" {
                    bound.extend(tv.bound);
                }
            }
        }
        Node::Text(_) | Node::HtmlComment(_) | Node::Verbatim(_) | Node::TwigComment(_) => {}
    }
}
