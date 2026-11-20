//! The component catalog: the components of the app and of the installed packages, with their
//! docblocks, their props, and their parts. All the data comes from the sources.
//!
//! A family is the components of one file. Its root has the name of the file (`Dialog` in
//! `Dialog.phem`). A part goes inside the components of its `#[Context]` types: the anatomy of
//! the root is the tree of these links in the family.

use crate::markdown::sentence;
use crate::scanned::Scanned;
use phemc_analyze::{ComponentDef, EnumDecl, Kind};
use phemc_syntax::doc::{doc_before, doc_tags};
use serde::Serialize;
use serde_json::{json, Value};
use std::collections::HashMap;

/// A component of the catalog.
#[derive(Clone, Debug, Serialize)]
pub struct Component {
    pub fqcn: String,
    /// The tag: the short name.
    pub name: String,
    /// The Composer package, or `None` for the app.
    pub package: Option<String>,
    /// The path of the file from the project root.
    pub path: String,
    /// The root of the family (the short name).
    pub family: String,
    /// A function component (`use function`), or a class.
    pub function: bool,
    /// The docblock, by paragraph.
    pub doc: Vec<String>,
    /// The words of `@aliases`.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub aliases: Vec<String>,
    pub props: Vec<Prop>,
    /// The `#[Context]` properties or parameters: the component goes inside these components.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub contexts: Vec<ContextUse>,
    /// The types of the contexts that the component provides (`#[Provide]`).
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub provides: Vec<String>,
    /// The key of the file in the build: the components of a file are a family.
    #[serde(skip)]
    file: String,
}

#[derive(Clone, Debug, Serialize)]
pub struct Prop {
    pub name: String,
    /// The type, as written.
    #[serde(rename = "type")]
    pub ty: String,
    /// The default value, as written, or `None` for a prop with no default.
    pub default: Option<String>,
    pub required: bool,
    /// The values of an enum prop: the values of a backed enum, or the names of the cases.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub values: Vec<String>,
    /// The rest prop: it takes the other attributes.
    #[serde(skip_serializing_if = "std::ops::Not::not")]
    pub rest: bool,
    /// The summary of the docblock of a `#[Prop]` property.
    #[serde(skip_serializing_if = "String::is_empty")]
    pub doc: String,
}

#[derive(Clone, Debug, Serialize)]
pub struct ContextUse {
    /// The FQCN of the type.
    #[serde(rename = "type")]
    pub ty: String,
    pub required: bool,
}

impl Component {
    fn new(def: &ComponentDef, file: &Scanned, enums: &HashMap<String, EnumDecl>) -> Self {
        let tags = doc_tags(&doc_before(&file.input.text, def.decl));
        let props = def
            .inputs
            .iter()
            .map(|p| {
                let values = def.types.get(&p.name).and_then(|t| enums.get(&t.to_ascii_lowercase())).map_or_else(Vec::new, |e| e.values.clone().unwrap_or_else(|| e.cases.clone()));
                // A property has its own docblock. The docblock before a parameter is the one of
                // its function.
                let doc = match def.kind == Kind::Class && !p.span.is_empty() {
                    true => sentence(&doc_tags(&doc_before(&file.input.text, p.span.start)).summary),
                    false => String::new(),
                };

                Prop { name: p.name.clone(), ty: p.ty.clone(), default: p.default.clone(), required: def.required.contains(&p.name), values, rest: p.rest, doc }
            })
            .collect();

        Component {
            name: short(&def.fqcn).to_string(),
            fqcn: def.fqcn.clone(),
            package: file.input.package.clone(),
            path: def.path.clone(),
            family: String::new(),
            function: def.kind != Kind::Class,
            doc: tags.paragraphs,
            aliases: tags.aliases,
            props,
            contexts: def.contexts.iter().map(|c| ContextUse { ty: c.ty.clone(), required: c.required }).collect(),
            provides: def.provides.clone(),
            file: def.file.clone(),
        }
    }

    /// The first sentence of the docblock.
    pub fn summary(&self) -> String {
        self.doc.first().map(|p| sentence(p)).unwrap_or_default()
    }

    pub fn package_name(&self) -> &str {
        self.package.as_deref().unwrap_or("app")
    }

    pub fn is_root(&self) -> bool {
        self.family == self.name
    }

    fn namespace(&self) -> &str {
        self.fqcn.rsplit_once('\\').map_or("", |(ns, _)| ns)
    }
}

/// The last segment of a FQCN.
pub fn short(fqcn: &str) -> &str {
    fqcn.rsplit('\\').next().unwrap_or(fqcn)
}

#[derive(Clone, Debug, Default)]
pub struct Catalog {
    /// The components of the app, then of the packages by name, in the order of the files.
    pub components: Vec<Component>,
}

impl Catalog {
    /// The components of the files that are not examples. A page is not a component of the
    /// catalog.
    pub fn build(files: &[Scanned], enums: &HashMap<String, EnumDecl>) -> Self {
        let mut components = Vec::new();
        for file in files.iter().filter(|f| !f.example) {
            let mut family: Vec<Component> = phemc_analyze::components(&file.info).iter().filter(|d| !d.page).map(|d| Component::new(d, file, enums)).collect();
            family.sort_by_key(|c| def_offset(file, &c.fqcn));

            let stem = file.input.rel.rsplit('/').next().unwrap_or_default().trim_end_matches(".phem");
            let root = family.iter().find(|c| c.name == stem).or_else(|| family.iter().find(|c| !c.provides.is_empty())).or(family.first()).map(|c| c.name.clone()).unwrap_or_default();
            for c in &mut family {
                c.family = root.clone();
            }

            components.extend(family);
        }

        components.sort_by(|a, b| a.package.cmp(&b.package));

        Catalog { components }
    }

    /// The components with a name: a FQCN, or a short name (case-insensitive), in a package.
    pub fn find(&self, name: &str, package: Option<&str>) -> Vec<&Component> {
        let name = name.trim_start_matches('\\');
        let in_package = |c: &&Component| package.is_none_or(|p| c.package_name() == p);
        let exact: Vec<&Component> = self.components.iter().filter(in_package).filter(|c| c.fqcn.eq_ignore_ascii_case(name)).collect();
        if !exact.is_empty() {
            return exact;
        }

        self.components.iter().filter(in_package).filter(|c| c.name.eq_ignore_ascii_case(name)).collect()
    }

    /// The components of the family of a component.
    pub fn family_of<'a>(&'a self, c: &'a Component) -> impl Iterator<Item = &'a Component> {
        self.components.iter().filter(move |o| o.file == c.file)
    }

    pub fn by_fqcn(&self, fqcn: &str) -> Option<&Component> {
        self.components.iter().find(|c| c.fqcn.eq_ignore_ascii_case(fqcn))
    }

    /// The name of a type: the short name of a component, or the FQCN.
    fn type_name(&self, fqcn: &str) -> String {
        self.by_fqcn(fqcn).map_or_else(|| fqcn.to_string(), |c| c.name.clone())
    }

    /// The parts of the family that go directly inside a component.
    fn parts_inside<'a>(&'a self, c: &'a Component) -> impl Iterator<Item = &'a Component> {
        self.family_of(c).filter(move |o| o.fqcn != c.fqcn && o.contexts.iter().any(|x| x.ty.eq_ignore_ascii_case(&c.fqcn)))
    }

    /// The anatomy of the root of a family: the tree of the parts, and the other parts.
    pub fn anatomy(&self, root: &Component) -> Anatomy {
        let mut placed = vec![root.fqcn.clone()];
        let tree = self.tree(root, &mut vec![root.fqcn.clone()], &mut placed);
        let others = self.family_of(root).filter(|c| !placed.contains(&c.fqcn)).map(|c| c.name.clone()).collect();

        Anatomy { tree, others }
    }

    fn tree(&self, node: &Component, path: &mut Vec<String>, placed: &mut Vec<String>) -> Tree {
        let mut parts = Vec::new();
        for part in self.parts_inside(node) {
            if path.contains(&part.fqcn) {
                continue;
            }

            if !placed.contains(&part.fqcn) {
                placed.push(part.fqcn.clone());
            }

            path.push(part.fqcn.clone());
            parts.push(self.tree(part, path, placed));
            path.pop();
        }

        Tree { name: node.name.clone(), parts }
    }

    /// The `use` statements of a family.
    pub fn imports(&self, c: &Component) -> String {
        let family: Vec<&Component> = self.family_of(c).collect();
        let mut out: Vec<String> = family.iter().filter(|c| !c.function).map(|c| format!("use {};", c.fqcn)).collect();
        let functions: Vec<&str> = family.iter().filter(|c| c.function).map(|c| c.name.as_str()).collect();
        match functions.as_slice() {
            [] => {}
            [one] => out.push(format!("use function {}\\{one};", c.namespace())),
            many => out.push(format!("use function {}\\{{{}}};", c.namespace(), many.join(", "))),
        }

        out.join(" ")
    }

    /// The components that a component goes inside: the types of its contexts.
    pub fn inside(&self, c: &Component) -> Vec<String> {
        c.contexts.iter().map(|x| format!("{}{}", self.type_name(&x.ty), if x.required { "" } else { " (optional)" })).collect()
    }

    /// The context of a component for the search: `DialogPopup — part of Dialog (acme/ui), goes
    /// inside Dialog, DialogViewport`.
    pub fn context_of(&self, c: &Component) -> String {
        let mut out = match c.is_root() {
            true => format!("{} — component ({})", c.name, c.package_name()),
            false => format!("{} — part of {} ({})", c.name, c.family, c.package_name()),
        };

        let inside: Vec<String> = c.contexts.iter().map(|x| self.type_name(&x.ty)).collect();
        if !inside.is_empty() {
            out.push_str(&format!(", goes inside {}", inside.join(", ")));
        }

        if !c.aliases.is_empty() {
            out.push_str(&format!(", also: {}", c.aliases.join(", ")));
        }

        out
    }

    /// `phemc components`: the families of each package, one line each.
    pub fn summary_md(&self, guides: &[(String, Vec<String>)]) -> String {
        let mut out = String::from("# Components\n\nRead a component with `phemc components <name>`, and its examples with `phemc examples <name>`.\n");
        let mut package: Option<&str> = None;

        for c in self.components.iter().filter(|c| c.is_root()) {
            if package != Some(c.package_name()) {
                package = Some(c.package_name());
                out.push_str(&format!("\n## {}\n\n", c.package_name()));
                if let Some((_, sections)) = guides.iter().find(|(p, _)| p == c.package_name()) {
                    let keys: Vec<String> = sections.iter().map(|s| format!("`{s}`")).collect();
                    out.push_str(&format!("Guide: `phemc docs {}`{}\n\n", c.package_name(), if keys.is_empty() { String::new() } else { format!(" ({})", keys.join(", ")) }));
                }
            }

            let parts: Vec<String> = self.family_of(c).filter(|p| !p.is_root()).map(|p| p.name.clone()).collect();
            let parts = if parts.is_empty() { String::new() } else { format!(" Parts: {}.", parts.join(", ")) };
            out.push_str(&format!("- `{}` {}{parts}\n", c.name, c.summary()));
        }

        out
    }

    pub fn summary_json(&self) -> Value {
        let families = self.components.iter().filter(|c| c.is_root()).map(|c| {
            let parts: Vec<&str> = self.family_of(c).filter(|p| !p.is_root()).map(|p| p.name.as_str()).collect();
            json!({"name": c.name, "fqcn": c.fqcn, "package": c.package_name(), "summary": c.summary(), "parts": parts})
        });

        Value::Array(families.collect())
    }

    /// `phemc components <name>`: one component.
    pub fn detail_md(&self, c: &Component, examples: &[(String, String)]) -> String {
        let mut out = format!("# {} — {} ({})\n\n", c.name, c.fqcn, c.package_name());
        for p in &c.doc {
            out.push_str(p);
            out.push_str("\n\n");
        }

        out.push_str(&format!("Import: `{}`\nSource: {}\n", self.imports(c), c.path));
        if !c.is_root() {
            out.push_str(&format!("Part of: {}\n", c.family));
        }

        if !c.aliases.is_empty() {
            out.push_str(&format!("Also called: {}\n", c.aliases.join(", ")));
        }

        out.push_str(&props_md(&c.props));

        let inside = self.inside(c);
        if !inside.is_empty() || !c.provides.is_empty() {
            out.push_str("\n## Context\n\n");
            if !inside.is_empty() {
                out.push_str(&format!("Goes inside: {}\n", inside.join(", ")));
            }

            if !c.provides.is_empty() {
                let provides: Vec<String> = c.provides.iter().map(|p| self.type_name(p)).collect();
                out.push_str(&format!("Provides: {} (the parts inside get it)\n", provides.join(", ")));
            }
        }

        if c.is_root() {
            let anatomy = self.anatomy(c);
            if !anatomy.tree.parts.is_empty() || !anatomy.others.is_empty() {
                out.push_str(&format!("\n## Anatomy\n\n{}", anatomy.md()));
            }
        }

        if !examples.is_empty() {
            out.push_str("\n## Examples\n\n");
            for (key, title) in examples {
                out.push_str(&format!("- `{key}` {title}\n"));
            }
        }

        out
    }

    pub fn detail_json(&self, c: &Component, examples: &[(String, String)]) -> Value {
        let mut v = serde_json::to_value(c).unwrap_or_default();
        v["import"] = json!(self.imports(c));
        if c.is_root() {
            v["anatomy"] = serde_json::to_value(self.anatomy(c)).unwrap_or_default();
        }

        v["examples"] = Value::Array(examples.iter().map(|(key, title)| json!({"key": key, "title": title})).collect());

        v
    }
}

/// The offset of the declaration of a component in its file: the order of the family.
fn def_offset(file: &Scanned, fqcn: &str) -> usize {
    let f = file.info.functions.iter().find(|f| f.fqn == fqcn).map(|f| f.span.start);
    let c = file.info.classes.iter().find(|c| c.fqn == fqcn).map(|c| c.span.start);

    f.or(c).unwrap_or(usize::MAX)
}

fn props_md(props: &[Prop]) -> String {
    if props.is_empty() {
        return String::new();
    }

    let with_doc = props.iter().any(|p| !p.doc.is_empty());
    let mut out = String::from(if with_doc { "\n## Props\n\n| Prop | Type | Default | Description |\n|---|---|---|---|\n" } else { "\n## Props\n\n| Prop | Type | Default |\n|---|---|---|\n" });

    for p in props {
        let (name, ty) = match p.rest {
            true => (format!("...{}", p.name), "the other attributes".to_string()),
            false if p.values.is_empty() => (p.name.clone(), p.ty.clone()),
            false => (p.name.clone(), format!("{}: {}", p.ty, p.values.join(", "))),
        };

        let default = match (&p.default, p.required) {
            (Some(d), _) => d.clone(),
            (None, true) => "required".to_string(),
            (None, false) => String::new(),
        };

        let row = [name, ty, default].into_iter().chain(with_doc.then(|| p.doc.clone())).map(|c| c.replace('|', "\\|")).collect::<Vec<_>>().join(" | ");
        out.push_str(&format!("| {row} |\n"));
    }

    out
}

/// The anatomy of a family.
#[derive(Clone, Debug, Serialize)]
pub struct Anatomy {
    pub tree: Tree,
    /// The parts that have no context of the family.
    pub others: Vec<String>,
}

#[derive(Clone, Debug, Serialize)]
pub struct Tree {
    pub name: String,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub parts: Vec<Tree>,
}

impl Anatomy {
    pub fn md(&self) -> String {
        let mut out = format!("```\n{}\n", self.tree.name);
        lines(&self.tree.parts, "", &mut out);
        out.push_str("```\n");

        if !self.others.is_empty() {
            out.push_str(&format!("Other parts: {}\n", self.others.join(", ")));
        }

        out
    }
}

fn lines(parts: &[Tree], indent: &str, out: &mut String) {
    for (i, p) in parts.iter().enumerate() {
        let last = i + 1 == parts.len();
        out.push_str(&format!("{indent}{}{}\n", if last { "└─ " } else { "├─ " }, p.name));
        lines(&p.parts, &format!("{indent}{}", if last { "   " } else { "│  " }), out);
    }
}
