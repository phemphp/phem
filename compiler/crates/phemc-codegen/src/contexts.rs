//! E015: a component needs a context, but no component above it provides one.
//!
//! The runtime is the source of truth: it throws when a required context has no provider. The
//! compiler reports an error only when it is sure. A use of a component is **placed** when the
//! compiler knows the parent of the component in the tree: the tag is in the markup that a
//! component returns, and not in the slot of another component tag. A use in the slot of a
//! provider of the type is **covered**. Any other use is unknown (a slot of another component,
//! markup in a prop or a variable, a Twig template): it stops the check. The error is for a
//! chain of placed uses from a page to the consumer, with no provider.

use crate::replaces::Replacements;
use phemc_analyze::{Class, FileInfo, MarkupScope, Registry};
use phemc_syntax::diag::codes;
use phemc_syntax::markup::Node;
use phemc_syntax::php::Lexed;
use phemc_syntax::{Diagnostic, Span};
use std::collections::{HashMap, HashSet};

/// A tag of a component in the markup that another component returns.
#[derive(Clone, Debug)]
pub(crate) struct Use {
    /// The component whose markup has the tag.
    owner: String,
    /// The component of the tag.
    child: String,
    /// The component in the tag, when the tag renders its replacement.
    replaced: Option<String>,
    /// The component tags around the tag, in the same markup: the tag is in their slots.
    enclosing: Vec<String>,
    rel: String,
    span: Span,
}

/// The uses of components in the markup that the components of a file return. A tag uses the
/// replacement of its component (see `Replacements`).
pub(crate) fn uses(info: &FileInfo, lexed: &Lexed, replacements: &Replacements) -> Vec<Use> {
    let mut out = Vec::new();
    for (index, m) in lexed.markups.iter().enumerate() {
        if !info.returned_markups.get(index).copied().unwrap_or(false) {
            continue;
        }

        let owner = match info.markup_scopes.get(index) {
            Some(MarkupScope::Function(fi)) if info.functions[*fi].component_kind.is_some() => info.functions[*fi].fqn.clone(),
            Some(MarkupScope::Method(ci, mi)) if info.classes[*ci].is_component && info.classes[*ci].methods[*mi].name.eq_ignore_ascii_case("render") => {
                info.classes[*ci].fqn.clone()
            }
            _ => continue,
        };

        walk(info, replacements, &owner, &m.root, &mut Vec::new(), &mut out);
    }

    out
}

fn walk(info: &FileInfo, replacements: &Replacements, owner: &str, node: &Node, enclosing: &mut Vec<String>, out: &mut Vec<Use>) {
    match node {
        Node::Element(e) if e.is_component() && !info.is_dynamic(&e.name) => {
            let tag = info.resolve_component(&e.name);
            let child = replacements.resolve(&tag, Some(owner)).to_string();
            let replaced = (child != tag).then_some(tag);
            out.push(Use { owner: owner.to_string(), child: child.clone(), replaced, enclosing: enclosing.clone(), rel: info.rel.clone(), span: e.name_span });

            let slot_prefix = format!("{}:", e.name);
            enclosing.push(child);
            for c in &e.children {
                match c {
                    // A named slot of the tag: its content is in the slot too.
                    Node::Element(s) if s.name.starts_with(&slot_prefix) => {
                        for n in &s.children {
                            walk(info, replacements, owner, n, enclosing, out);
                        }
                    }
                    other => walk(info, replacements, owner, other, enclosing, out),
                }
            }

            enclosing.pop();
        }
        Node::Element(e) => {
            for c in &e.children {
                walk(info, replacements, owner, c, enclosing, out);
            }
        }
        Node::Fragment(f) => {
            for c in &f.children {
                walk(info, replacements, owner, c, enclosing, out);
            }
        }
        Node::Block(b) => {
            for br in &b.branches {
                for c in &br.children {
                    walk(info, replacements, owner, c, enclosing, out);
                }
            }
        }
        // Markup in an expression (`{{ on ? <A /> : <B /> }}`) is not followed: unknown.
        _ => {}
    }
}

/// Where a use is, for a context type.
enum Place {
    /// The compiler knows the parent: the component that returns the markup.
    Placed,
    /// A component tag around the use provides the type.
    Covered,
    /// The compiler does not know the parent.
    Unknown,
}

struct Checker<'a> {
    reg: &'a Registry,
    classes: HashMap<String, &'a Class>,
    pages: HashSet<String>,
    /// The uses of each component, by its FQN in lowercase.
    uses: HashMap<String, Vec<&'a Use>>,
}

impl Checker<'_> {
    /// Whether a component provides the type. When the compiler cannot see the class hierarchy
    /// of the provided type, it can be a subtype: it provides the type, unless the type is a
    /// class in the sources (its subclasses are in the sources too).
    fn provides(&self, component: &str, ty: &str) -> bool {
        let Some(def) = self.reg.get(component) else { return true };
        def.provides.iter().any(|p| self.is_subtype(p, ty))
    }

    fn is_subtype(&self, provided: &str, ty: &str) -> bool {
        let maybe = !self.classes.contains_key(&ty.to_ascii_lowercase());
        let mut queue = vec![provided.to_string()];
        let mut seen = HashSet::new();

        while let Some(name) = queue.pop() {
            if name.eq_ignore_ascii_case(ty) {
                return true;
            }

            if !seen.insert(name.to_ascii_lowercase()) {
                continue;
            }

            match self.classes.get(&name.to_ascii_lowercase()) {
                Some(c) => queue.extend(c.parent.iter().chain(&c.interfaces).cloned()),
                None if maybe => return true,
                None => {}
            }
        }

        false
    }

    fn place(&self, u: &Use, ty: &str) -> Place {
        if u.enclosing.is_empty() {
            Place::Placed
        } else if u.enclosing.iter().any(|c| self.provides(c, ty)) {
            Place::Covered
        } else {
            Place::Unknown
        }
    }

    /// A page that renders the component through placed uses only, with no provider of the type
    /// on the chain. The component does not provide the type.
    fn page_without(&self, component: &str, ty: &str, seen: &mut HashSet<String>) -> Option<String> {
        if !seen.insert(component.to_ascii_lowercase()) {
            return None;
        }

        if self.pages.contains(&component.to_ascii_lowercase()) {
            return Some(component.to_string());
        }

        for u in self.uses.get(&component.to_ascii_lowercase()).into_iter().flatten() {
            if matches!(self.place(u, ty), Place::Placed) && !self.provides(&u.owner, ty) {
                if let Some(page) = self.page_without(&u.owner, ty, seen) {
                    return Some(page);
                }
            }
        }

        None
    }
}

/// E015 for each use of a component with a required context, when a chain of placed uses from a
/// page gets to it with no provider. Returns (file, diagnostic).
pub(crate) fn check(reg: &Registry, infos: &[&FileInfo], uses: &[Use]) -> Vec<(String, Diagnostic)> {
    let classes = infos.iter().flat_map(|i| i.classes.iter()).map(|c| (c.fqn.to_ascii_lowercase(), c)).collect();
    let pages = infos
        .iter()
        .flat_map(|i| i.routes.iter().filter(|r| r.is_component).map(|r| &r.fqn).chain(i.error_pages.iter().filter(|e| e.is_function).map(|e| &e.fqn)))
        .map(|fqn| fqn.to_ascii_lowercase())
        .collect();
    let mut by_child: HashMap<String, Vec<&Use>> = HashMap::new();
    for u in uses {
        by_child.entry(u.child.to_ascii_lowercase()).or_default().push(u);
    }

    let checker = Checker { reg, classes, pages, uses: by_child };

    let mut out = Vec::new();
    for def in reg.values() {
        for need in def.contexts.iter().filter(|n| n.required) {
            let ty_short = short(&need.ty);
            let help = format!("render `<{}>` inside a component with `#[Provide]` for `{ty_short}`, or give `${}` a default value", short(&def.fqcn), need.name);

            if checker.pages.contains(&def.fqcn.to_ascii_lowercase()) {
                out.push((
                    def.file.clone(),
                    Diagnostic::error(codes::MISSING_CONTEXT, format!("the page `{}` needs a `{ty_short}` context, but a page has no component above it", short(&def.fqcn)), need.span)
                        .with_help(format!("provide `{ty_short}` in the page, with `#[Provide]`, or give `${}` a default value", need.name)),
                ));
                continue;
            }

            for u in checker.uses.get(&def.fqcn.to_ascii_lowercase()).into_iter().flatten() {
                if !matches!(checker.place(u, &need.ty), Place::Placed) || checker.provides(&u.owner, &need.ty) {
                    continue;
                }

                let Some(page) = checker.page_without(&u.owner, &need.ty, &mut HashSet::new()) else { continue };

                let label = if page.eq_ignore_ascii_case(&u.owner) {
                    format!("the page `{}` renders it with no `{ty_short}` above", short(&page))
                } else {
                    format!("`{}` renders it, and the page `{}` renders `{}` with no `{ty_short}` above", short(&u.owner), short(&page), short(&u.owner))
                };

                // The tag renders the replacement of the app: the message names it, because the
                // fix is in the replacement or above the tag.
                let (message, help) = match &u.replaced {
                    Some(original) => (
                        format!("`<{}>` renders `{}`, the replacement of `{original}`, and it needs a `{ty_short}` context, but no component above it provides one", short(original), def.fqcn),
                        format!("give `${}` of `{}` a default value, or render the tag inside a component with `#[Provide]` for `{ty_short}`", need.name, def.fqcn),
                    ),
                    None => (format!("`<{}>` needs a `{ty_short}` context, but no component above it provides one", short(&def.fqcn)), help.clone()),
                };

                out.push((u.rel.clone(), Diagnostic::error(codes::MISSING_CONTEXT, message, u.span).with_label(label).with_help(help)));
            }
        }
    }

    out
}

fn short(fqcn: &str) -> &str {
    fqcn.rsplit('\\').next().unwrap_or(fqcn)
}
