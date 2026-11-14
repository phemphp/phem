//! A slot prop with markup is a slot tag: `<Card footer={{ <em>Saved</em> }} />` is
//! `<Card><Card.footer>{{ <em>Saved</em> }}</Card.footer></Card>`.
//!
//! The pass changes the markup trees once, before the checks and the lowering, so each walk of
//! the tree (the contexts, the variables, the feeds, `phem:error`, the slots) sees one form. Only
//! an `Html` slot of a known component becomes a tag. Markup in another prop stays in the
//! attribute, and the lowering reports E031 for it. The component of an open class can have props
//! that the compiler does not see, so its markup props also stay attributes.

use phemc_analyze::{FileInfo, Registry, DYNAMIC};
use phemc_syntax::markup::{AttrKind, AttrValue, Element, ExprPart, Node, Output};
use phemc_syntax::php::Lexed;
use phemc_syntax::Span;

pub(crate) fn desugar(info: &FileInfo, reg: &Registry, lexed: &mut Lexed) {
    for m in &mut lexed.markups {
        node(info, reg, &mut m.root);
    }
}

fn node(info: &FileInfo, reg: &Registry, n: &mut Node) {
    match n {
        Node::Element(e) => {
            if e.is_component() {
                move_slot_props(info, reg, e);
            }

            // Markup in the other attributes: its error comes from the lowering, but its tags
            // can have slot props too.
            for a in &mut e.attrs {
                if let AttrKind::Plain { value: AttrValue::Expr(x), .. } | AttrKind::Spread(x) = &mut a.kind {
                    markups(info, reg, &mut x.parts);
                }
            }

            for c in &mut e.children {
                node(info, reg, c);
            }
        }
        Node::Fragment(f) => {
            for c in &mut f.children {
                node(info, reg, c);
            }
        }
        Node::Block(b) => {
            for br in &mut b.branches {
                for c in &mut br.children {
                    node(info, reg, c);
                }
            }
        }
        Node::Output(o) => markups(info, reg, &mut o.expr.parts),
        _ => {}
    }
}

fn markups(info: &FileInfo, reg: &Registry, parts: &mut [ExprPart]) {
    for p in parts {
        if let ExprPart::Markup(m) = p {
            node(info, reg, m);
        }
    }
}

/// Moves each `name={{ <markup> }}` of an `Html` slot to a `<Tag.name>` child.
fn move_slot_props(info: &FileInfo, reg: &Registry, e: &mut Element) {
    let fqcn = info.resolve_component(&e.name);
    let Some(def) = reg.get(&fqcn).filter(|d| !d.open && fqcn != DYNAMIC) else { return };

    let is_slot_prop = |kind: &AttrKind| matches!(kind, AttrKind::Plain { name, value: AttrValue::Expr(x), .. } if x.has_markup() && def.slots.contains(name));
    if !e.attrs.iter().any(|a| is_slot_prop(&a.kind)) {
        return;
    }

    // The newlines before a moved attribute go to the next attribute: the Twig tag keeps the line
    // count of the source tag.
    let mut kept = Vec::with_capacity(e.attrs.len());
    let mut carry = 0;
    for mut a in std::mem::take(&mut e.attrs) {
        if !is_slot_prop(&a.kind) {
            a.newlines_before += std::mem::take(&mut carry);
            kept.push(a);
            continue;
        }

        let AttrKind::Plain { name, name_span, value: AttrValue::Expr(x) } = a.kind else { unreachable!() };
        carry += a.newlines_before;

        // The `{{ ... }}` of the value.
        let span = Span::new(x.span.start.saturating_sub(2), x.span.end + 2);
        let output = Node::Output(Output { expr: x, span, raw: String::new() });
        e.children.push(Node::Element(Element {
            name: format!("{}.{name}", e.name),
            name_span,
            attrs: vec![],
            children: vec![output],
            self_closing: false,
            span: a.span,
            start_tag: a.span,
            from_prop: true,
        }));
    }

    e.attrs = kept;
}
