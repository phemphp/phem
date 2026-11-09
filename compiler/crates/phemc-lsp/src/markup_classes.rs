//! Class names in markup, as phemc reads them: a capitalized name that the file imports, or a
//! component or an enum of its namespace in the `.phem` files (`OrderStatus.Cancelled`,
//! `path(ProductPage)`). A name that Twig binds (`{% for Item in %}`) is the variable. See
//! `phemc_codegen::classes`.

use crate::classes::{Found, Index};
use crate::decl::{ComponentKind, UseKind};
use crate::imports::imports_of;
use crate::project::SymbolKind;
use crate::twig::{TwigEnv, VarSource};
use crate::types::resolve_name;

/// A class (`Class.NAME`, `path(Page)`) or a function page (`path(TeamPage)`).
pub enum MarkupClass {
    Class(String, Found),
    Function(Found),
}

/// The class or the function page of a name of the markup.
pub fn markup_class(index: &Index, env: &TwigEnv, name: &str) -> Option<MarkupClass> {
    if !name.starts_with(|c: char| c.is_ascii_uppercase()) || env.variable(name).is_some_and(|v| matches!(v.kind, VarSource::Twig(_))) {
        return None;
    }

    let parsed = &index.current.parsed;
    if let Some(f) = imports_of(parsed, UseKind::Function).find(|i| i.alias.eq_ignore_ascii_case(name)) {
        return index.find(SymbolKind::Function, &f.fqn).map(MarkupClass::Function);
    }

    let imported = imports_of(parsed, UseKind::Class).any(|i| i.alias.eq_ignore_ascii_case(name));
    let fqn = resolve_name(parsed, name)?;
    if let Some(found) = index.find(SymbolKind::Class, &fqn) {
        // Without an import, phemc knows only the components and the enums of the `.phem` files.
        if imported || (in_sources(&found) && (found.class().kind == "enum" || is_component(index, &found.class().fqn, ComponentKind::Class))) {
            return Some(MarkupClass::Class(fqn, found));
        }
    }

    // A function component of the namespace of the file (a function page).
    index.find(SymbolKind::Function, &fqn).filter(|f| in_sources(f) && is_component(index, &fqn, ComponentKind::Function)).map(MarkupClass::Function)
}

fn in_sources(found: &Found) -> bool {
    found.source.file.extension().is_some_and(|e| e == "phem")
}

/// A component of the project with this FQCN and kind. The list of the components is built only
/// for a name that the file does not import.
fn is_component(index: &Index, fqn: &str, kind: ComponentKind) -> bool {
    index.project.components(&index.current.file, &index.current.parsed).iter().any(|c| c.kind == kind && c.fqn.eq_ignore_ascii_case(fqn))
}
