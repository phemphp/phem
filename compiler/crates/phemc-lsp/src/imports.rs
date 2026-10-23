//! Name resolution and `use` statements, with the rules that phemc uses for a component tag: a
//! `use function` import, then a `use` (class) import, then the current namespace.

use crate::decl::{namespace_of, short_name, Component, Parsed, UseItem, UseKind};

pub fn imports_of(parsed: &Parsed, kind: UseKind) -> impl Iterator<Item = &UseItem> {
    parsed.uses.iter().flat_map(|u| &u.items).filter(move |i| i.kind == kind)
}

/// An edit that inserts text at an offset.
#[derive(Clone, Debug, PartialEq)]
pub struct Insert {
    pub offset: usize,
    pub text: String,
}

/// The name to write for a symbol, and the import to add.
pub struct Import {
    pub name: String,
    pub edit: Option<Insert>,
    /// The name is fully qualified, because the short name is taken.
    pub qualified: bool,
}

pub fn import_for(text: &str, parsed: &Parsed, kind: UseKind, fqn: &str) -> Import {
    let short = short_name(fqn);
    let ns = namespace_of(fqn);
    if let Some(same) = imports_of(parsed, kind).find(|i| i.fqn.eq_ignore_ascii_case(fqn)) {
        return Import { name: same.alias.clone(), edit: None, qualified: false };
    }

    if ns.eq_ignore_ascii_case(&parsed.namespace) || (kind != UseKind::Class && ns.is_empty()) {
        // The same namespace, or a global function or constant: no import.
        return Import { name: short.to_string(), edit: None, qualified: false };
    }

    if imports_of(parsed, kind).any(|i| i.alias.eq_ignore_ascii_case(short)) {
        // The short name is taken: use the fully qualified name.
        return Import { name: format!("\\{fqn}"), edit: None, qualified: true };
    }

    Import { name: short.to_string(), edit: Some(use_edit(text, parsed, kind, fqn)), qualified: false }
}

fn line_start(text: &str, offset: usize) -> usize {
    text[..offset.min(text.len())].rfind('\n').map_or(0, |i| i + 1)
}

fn line_end(text: &str, offset: usize) -> usize {
    let offset = offset.min(text.len());
    text[offset..].find('\n').map_or(text.len(), |i| offset + i)
}

/// The edit that imports `fqn`.
pub fn use_edit(text: &str, parsed: &Parsed, kind: UseKind, fqn: &str) -> Insert {
    let ns = namespace_of(fqn);
    let short = short_name(fqn);
    let statement = format!("{} {fqn};", kind.keyword());
    let same_kind: Vec<_> = parsed.uses.iter().filter(|u| u.kind == kind).collect();

    // Add the name to a group on one line: use function App\Components\{Layout, Profile};
    let group = same_kind.iter().find_map(|u| {
        let g = u.group.as_ref()?;
        let close = g.close?;
        (g.prefix.trim_end_matches('\\').eq_ignore_ascii_case(ns) && !text[g.open..close].contains('\n')).then_some((g.open, close))
    });
    if let Some((open, close)) = group {
        let mut i = close;
        while i > open && text.as_bytes()[i - 1].is_ascii_whitespace() {
            i -= 1;
        }

        let text = if text.as_bytes()[i - 1] == b',' { format!(" {short}") } else { format!(", {short}") };
        return Insert { offset: i, text };
    }

    if let Some(last) = same_kind.last() {
        // Keep the imports in alphabetical order.
        let key = fqn.to_lowercase();
        if let Some(after) = same_kind.iter().find(|u| u.items.first().map_or(String::new(), |i| i.fqn.to_lowercase()) > key) {
            return Insert { offset: line_start(text, after.start), text: format!("{statement}\n") };
        }
        return Insert { offset: line_end(text, last.end), text: format!("\n{statement}") };
    }

    if let (Some(first), Some(last)) = (parsed.uses.first(), parsed.uses.last()) {
        if kind == UseKind::Class {
            // Class imports go before the function and constant imports.
            return Insert { offset: line_start(text, first.start), text: format!("{statement}\n\n") };
        }

        if kind == UseKind::Function {
            if let Some(c) = parsed.uses.iter().find(|u| u.kind == UseKind::Const) {
                return Insert { offset: line_start(text, c.start), text: format!("{statement}\n\n") };
            }
        }

        let class_uses: Vec<_> = parsed.uses.iter().filter(|u| u.kind == UseKind::Class).collect();
        let anchor = match class_uses.last() {
            Some(c) if kind != UseKind::Const => c,
            _ => last,
        };

        return Insert { offset: line_end(text, anchor.end), text: format!("\n\n{statement}") };
    }

    if let Some(end) = parsed.namespace_end.or(parsed.open_tag_end) {
        return Insert { offset: line_end(text, end), text: format!("\n\n{statement}") };
    }

    Insert { offset: 0, text: format!("{statement}\n\n") }
}

/// The component of a tag name, with the rules of phemc.
pub fn resolve_component<'a>(parsed: &Parsed, components: &'a [Component], tag: &str) -> Option<&'a Component> {
    let by_fqn = |fqn: &str| components.iter().find(|c| c.fqn.eq_ignore_ascii_case(fqn));
    if let Some(fqn) = tag.strip_prefix('\\') {
        return by_fqn(fqn);
    }

    let (first, tail) = match tag.split_once('\\') {
        Some((f, r)) => (f, format!("\\{r}")),
        None => (tag, String::new()),
    };

    for kind in [UseKind::Function, UseKind::Class] {
        for i in imports_of(parsed, kind) {
            if i.alias.eq_ignore_ascii_case(first) {
                if let Some(c) = by_fqn(&format!("{}{tail}", i.fqn)) {
                    return Some(c);
                }
            }
        }
    }

    by_fqn(&crate::decl::join_name(&parsed.namespace, tag))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::decl::parse;

    fn apply(text: &str, e: &Insert) -> String {
        format!("{}{}{}", &text[..e.offset], e.text, &text[e.offset..])
    }

    #[test]
    fn adds_imports_in_order() {
        let text = "<?phpm\nnamespace App\\Pages;\n\nuse Phem\\Html;\n\nuse function App\\Components\\Layout;\n";
        let parsed = parse(text);
        let e = import_for(text, &parsed, UseKind::Class, "App\\Repository\\TodoRepository").edit.unwrap();
        assert_eq!(apply(text, &e), text.replace("use Phem", "use App\\Repository\\TodoRepository;\nuse Phem"));
        let e = import_for(text, &parsed, UseKind::Function, "Acme\\slugify").edit.unwrap();
        assert_eq!(apply(text, &e), text.replace("use function App", "use function Acme\\slugify;\nuse function App"));
        assert!(import_for(text, &parsed, UseKind::Class, "Phem\\Html").edit.is_none());
        assert!(import_for(text, &parsed, UseKind::Class, "App\\Pages\\X").edit.is_none());
        assert!(import_for(text, &parsed, UseKind::Class, "Other\\Html").qualified);
    }

    #[test]
    fn adds_a_name_to_a_group_on_one_line() {
        let text = "<?phpm\nnamespace App;\n\nuse function App\\Components\\{Layout};\n";
        let e = import_for(text, &parse(text), UseKind::Function, "App\\Components\\Avatar").edit.unwrap();
        assert!(apply(text, &e).contains("use function App\\Components\\{Layout, Avatar};"));
    }

    #[test]
    fn adds_the_first_import_after_the_namespace() {
        let text = "<?phpm\nnamespace App\\Pages;\n\nfunction Home() {}\n";
        let e = import_for(text, &parse(text), UseKind::Function, "App\\Components\\Layout").edit.unwrap();
        assert_eq!(apply(text, &e), "<?phpm\nnamespace App\\Pages;\n\nuse function App\\Components\\Layout;\n\nfunction Home() {}\n");
    }
}
