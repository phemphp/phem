//! The scan of the `.phem` files for the catalog and the examples: the declarations, with the
//! members that the classes inherit, and the component tags of the markup.

use phemc_analyze::{EnumDecl, FileInfo};
use phemc_codegen::SourceInput;
use phemc_syntax::markup::{AttrKind, AttrValue, ExprPart, Node, StrPart};
use phemc_syntax::php::Lexed;
use phemc_syntax::Span;
use std::collections::HashMap;

/// A scanned file.
pub struct Scanned {
    pub input: SourceInput,
    pub info: FileInfo,
    pub lexed: Option<Lexed>,
    /// A file of the examples of a package.
    pub example: bool,
}

/// Scans the files: (file, is an example). A file that does not lex has no declarations.
pub fn scan(inputs: Vec<(SourceInput, bool)>) -> Vec<Scanned> {
    let mut out: Vec<Scanned> = inputs
        .into_iter()
        .map(|(input, example)| {
            let lexed = phemc_syntax::php::lex(&input.text).ok();
            let mut info = lexed.as_ref().map_or_else(FileInfo::default, |l| phemc_analyze::scan(&input.rel, &input.text, l));
            info.path = input.path.clone();

            Scanned { input, info, lexed, example }
        })
        .collect();

    phemc_analyze::resolve_inheritance(out.iter_mut().map(|s| &mut s.info));

    out
}

/// The enums by FQCN (lowercase): the catalog shows the cases of an enum prop.
pub fn enums<'a>(files: impl IntoIterator<Item = &'a FileInfo>) -> HashMap<String, EnumDecl> {
    files.into_iter().flat_map(|f| &f.enums).map(|e| (e.fqn.to_ascii_lowercase(), e.clone())).collect()
}

/// The FQCN of the component tags in the markup literals of a span of the file, in the order of
/// the source, once each.
pub fn tags_in(file: &Scanned, span: Span) -> Vec<String> {
    let Some(lexed) = &file.lexed else { return vec![] };

    let mut names = Vec::new();
    for m in lexed.markups.iter().filter(|m| m.span.start >= span.start && m.span.end <= span.end) {
        walk(&m.root, &mut names);
    }

    let mut out: Vec<String> = Vec::new();
    for name in names {
        let fqcn = file.info.resolve_component(&name);
        if !out.contains(&fqcn) {
            out.push(fqcn);
        }
    }

    out
}

fn walk(node: &Node, out: &mut Vec<String>) {
    match node {
        Node::Element(e) => {
            // A slot tag (`<Card.footer>`) is not a component.
            if e.is_component() && !e.name.contains('.') {
                out.push(e.name.clone());
            }

            for a in &e.attrs {
                match &a.kind {
                    AttrKind::Plain { value: AttrValue::Expr(x), .. } | AttrKind::Spread(x) => expr(&x.parts, out),
                    AttrKind::Plain { value: AttrValue::Quoted { parts, .. }, .. } => {
                        for p in parts {
                            if let StrPart::Expr(x) = p {
                                expr(&x.parts, out);
                            }
                        }
                    }
                    AttrKind::Plain { .. } => {}
                }
            }

            e.children.iter().for_each(|c| walk(c, out));
        }
        Node::Fragment(f) => f.children.iter().for_each(|c| walk(c, out)),
        Node::Output(o) => expr(&o.expr.parts, out),
        Node::Block(b) => b.branches.iter().flat_map(|br| &br.children).for_each(|c| walk(c, out)),
        _ => {}
    }
}

fn expr(parts: &[ExprPart], out: &mut Vec<String>) {
    for p in parts {
        if let ExprPart::Markup(n) = p {
            walk(n, out);
        }
    }
}
