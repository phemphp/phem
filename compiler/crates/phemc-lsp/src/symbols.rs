//! The outline of a `.phpm` file: the namespace, the classes and their members, and the
//! functions. A component, a page, a prop, a slot, and a state property get a detail text.

use crate::decl::{has_attr, is_html_type, is_prop, Parsed};
use crate::project::{routes_of, Route};
use std::path::Path;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum OutlineKind {
    Namespace,
    Class,
    Interface,
    Trait,
    Enum,
    Function,
    Method,
    Constructor,
    Property,
    Constant,
    EnumMember,
}

#[derive(Clone, Debug)]
pub struct Outline {
    pub name: String,
    pub detail: String,
    pub kind: OutlineKind,
    pub range: (usize, usize),
    pub selection: (usize, usize),
    pub children: Vec<Outline>,
}

fn symbol(name: &str, detail: String, kind: OutlineKind, start: usize, end: usize, offset: usize, children: Vec<Outline>) -> Outline {
    Outline {
        name: name.to_string(),
        detail,
        kind,
        range: (start, end.max(offset + name.len())),
        selection: (offset, offset + name.len()),
        children,
    }
}

fn page_detail(routes: &[Route], fqn: &str) -> String {
    let paths: Vec<&str> = routes.iter().filter(|r| r.fqn == fqn).map(|r| r.path.as_str()).collect();
    if paths.is_empty() {
        "component".into()
    } else {
        format!("page {}", paths.join(", "))
    }
}

fn typed(base: &str, ty: &str) -> String {
    if ty.is_empty() {
        base.to_string()
    } else {
        format!("{base}: {ty}")
    }
}

pub fn document_symbols(parsed: &Parsed, text: &str) -> Vec<Outline> {
    let routes = routes_of(parsed, Path::new(""));
    let is_component = |fqn: &str| parsed.components.iter().any(|c| c.fqn == fqn);
    let mut list = Vec::new();

    for f in &parsed.functions {
        let comp = is_component(&f.fqn);
        let children = if comp {
            f.params
                .iter()
                .map(|p| {
                    let detail = if p.variadic {
                        "rest props".into()
                    } else if p.name == "children" {
                        "children".into()
                    } else if is_html_type(&p.ty) {
                        "slot".into()
                    } else {
                        typed("prop", &p.ty)
                    };

                    symbol(&p.name, detail, OutlineKind::Property, p.offset, p.offset + p.name.len() + 1, p.offset + 1, vec![])
                })
                .collect()
        } else {
            vec![]
        };

        let detail = if comp { page_detail(&routes, &f.fqn) } else { String::new() };
        list.push(symbol(&f.name, detail, OutlineKind::Function, f.start, f.end, f.offset, children));
    }

    for cls in &parsed.classes {
        let comp = is_component(&cls.fqn);
        let mut children = Vec::new();
        for c in &cls.constants {
            let kind = if c.is_case { OutlineKind::EnumMember } else { OutlineKind::Constant };
            children.push(symbol(&c.name, String::new(), kind, c.offset, c.offset + c.name.len(), c.offset, vec![]));
        }

        for p in &cls.properties {
            let detail = if comp && is_prop(p) {
                if p.rest {
                    "rest props".into()
                } else {
                    let base = if p.name == "children" {
                        "children"
                    } else if is_html_type(&p.ty) {
                        "slot"
                    } else {
                        "prop"
                    };

                    typed(base, &p.ty)
                }
            } else if comp && has_attr(&p.attrs, "State") && !p.is_static {
                typed("state", &p.ty)
            } else {
                p.ty.clone()
            };

            children.push(symbol(&p.name, detail, OutlineKind::Property, p.offset, p.offset + p.name.len() + 1, p.offset + 1, vec![]));
        }

        for m in &cls.methods {
            let kind = if m.name.eq_ignore_ascii_case("__construct") { OutlineKind::Constructor } else { OutlineKind::Method };
            children.push(symbol(&m.name, m.return_type.clone(), kind, m.start, m.end, m.offset, vec![]));
        }

        let kind = match cls.kind.as_str() {
            "interface" => OutlineKind::Interface,
            "trait" => OutlineKind::Trait,
            "enum" => OutlineKind::Enum,
            _ => OutlineKind::Class,
        };

        let detail = if comp { page_detail(&routes, &cls.fqn) } else { String::new() };
        list.push(symbol(&cls.name, detail, kind, cls.start, cls.end, cls.offset, children));
    }

    list.sort_by_key(|s| s.range.0);
    match parsed.namespace_start {
        Some(start) if !parsed.namespace.is_empty() => {
            vec![symbol(&parsed.namespace, String::new(), OutlineKind::Namespace, start, text.len(), start, list)]
        }
        _ => list,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::decl::parse;

    const SOURCE: &str = r"<?phpm
namespace App\Ui;

use Phem\Attribute\{Component, Prop, State};
use Phem\Html;
use Symfony\Component\Routing\Attribute\Route;

#[Component]
function Card(string $title, ?Html $footer = null, ?Html $children = null, mixed ...$rest)
{
    return <div>{{ title }}</div>;
}

#[Route('/counter')]
#[Component]
final class Counter
{
    public const MAX = 10;

    #[Prop] public string $label = 'Clicked';
    #[Prop(rest: true)] public array $attrs = [];
    #[State] public int $count = 0;

    public function __construct(private readonly \App\Repository\TodoRepository $todos)
    {
    }

    public function increment(): void
    {
        ++$this->count;
    }

    public function render()
    {
        return <button onClick={{ increment }}>{{ label }}</button>;
    }
}
";

    #[test]
    fn the_outline_has_the_namespace_the_components_and_the_members() {
        let symbols = document_symbols(&parse(SOURCE), SOURCE);
        let ns = &symbols[0];
        assert_eq!((ns.name.as_str(), ns.kind), ("App\\Ui", OutlineKind::Namespace));
        let (card, counter) = (&ns.children[0], &ns.children[1]);

        assert_eq!((card.name.as_str(), card.detail.as_str()), ("Card", "component"));
        let details: Vec<_> = card.children.iter().map(|c| (c.name.as_str(), c.detail.as_str())).collect();
        assert_eq!(details, [("title", "prop: string"), ("footer", "slot"), ("children", "children"), ("rest", "rest props")]);
        assert_eq!(&SOURCE[card.selection.0..card.selection.1], "Card");

        assert_eq!((counter.kind, counter.detail.as_str()), (OutlineKind::Class, "page /counter"));
        let details: Vec<_> = counter.children.iter().map(|c| (c.name.as_str(), c.kind, c.detail.as_str())).collect();
        assert_eq!(
            details,
            [
                ("MAX", OutlineKind::Constant, ""),
                ("label", OutlineKind::Property, "prop: string"),
                ("attrs", OutlineKind::Property, "rest props"),
                ("count", OutlineKind::Property, "state: int"),
                ("todos", OutlineKind::Property, "\\App\\Repository\\TodoRepository"),
                ("__construct", OutlineKind::Constructor, ""),
                ("increment", OutlineKind::Method, "void"),
                ("render", OutlineKind::Method, ""),
            ]
        );
        let label = &counter.children[1];
        assert_eq!(&SOURCE[label.selection.0..label.selection.1], "label");
        let increment = &counter.children[6];
        assert_eq!(&SOURCE[increment.range.0..increment.range.1], "public function increment(): void\n    {\n        ++$this->count;\n    }");
    }
}
