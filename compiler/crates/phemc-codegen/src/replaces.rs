//! `#[Component(replaces: Button::class)]`: a component of the app replaces a component in all
//! the tags of the project, in the app and in the packages. Inside the replacement, a tag of the
//! original stays the original, so the replacement can wrap it.
//!
//! E016: the replacement accepts each prop and each slot of the original, and requires no prop
//! that the original does not require. E017: a replacement that the compiler cannot use (in a
//! package, of a page, of a built-in component, of a replacement, or a second one).

use phemc_analyze::{Class, ComponentDef, FileInfo, Registry};
use phemc_syntax::diag::codes;
use phemc_syntax::{Diagnostic, Span};
use std::collections::{HashMap, HashSet};

/// The valid replacements of the project (see `check()`).
#[derive(Debug)]
pub(crate) struct Replacements {
    /// The replacement of each replaced component, by FQCN.
    by_original: HashMap<String, String>,
    /// The markup owners where a tag of the original stays the original: (owner in lowercase,
    /// original).
    keeps: HashSet<(String, String)>,
}

impl Replacements {
    /// The component that a tag of `fqcn` renders in the markup of `owner`: its replacement,
    /// except in the replacement itself, and in its parent classes and traits up to the original.
    pub(crate) fn resolve<'a>(&'a self, fqcn: &'a str, owner: Option<&str>) -> &'a str {
        match self.by_original.get(fqcn) {
            Some(r) if !owner.is_some_and(|o| self.keeps.contains(&(o.to_ascii_lowercase(), fqcn.to_string()))) => r,
            _ => fqcn,
        }
    }
}

/// E010, E016 and E017 for the replacements of the project, and the valid replacements. `files`
/// has each file and whether it is a file of a package. Returns (file, diagnostic).
pub(crate) fn check(reg: &Registry, files: &[(&FileInfo, bool)]) -> (Replacements, Vec<(String, Diagnostic)>) {
    let classes: HashMap<String, &Class> = files.iter().flat_map(|(info, _)| &info.classes).map(|c| (c.fqn.to_ascii_lowercase(), c)).collect();
    let mut replacements = Replacements { by_original: HashMap::new(), keeps: HashSet::new() };
    let mut out = Vec::new();
    for (info, package) in files {
        let found = info
            .functions
            .iter()
            .filter(|f| f.component_kind.is_some())
            .filter_map(|f| f.replaces.as_ref().map(|r| (f.fqn.as_str(), r, f.name_span)))
            .chain(info.classes.iter().filter(|c| c.is_component).filter_map(|c| c.replaces.as_ref().map(|r| (c.fqn.as_str(), r, c.name_span))));

        for (fqcn, replaces, name_span) in found {
            let error = |message: String, help: &str| (info.rel.clone(), Diagnostic::error(codes::INVALID_REPLACEMENT, message, replaces.span).with_help(help));

            if *package {
                out.push(error(
                    format!("a package cannot replace a component, and `{fqcn}` is in a package"),
                    "only the app replaces a component: a theme package extends the classes of a base package",
                ));
                continue;
            }

            if replaces.fqcn.is_empty() {
                out.push(error("the compiler cannot read the value of `replaces`".into(), "give the class name of the component: `replaces: Button::class`"));
                continue;
            }

            let Some(original) = reg.get(&replaces.fqcn) else {
                out.push((
                    info.rel.clone(),
                    Diagnostic::error(codes::UNKNOWN_COMPONENT, format!("`{fqcn}` replaces an unknown component `{}`", replaces.fqcn), replaces.span)
                        .with_help("import the component of the package: `use Acme\\Ui\\Button as UiButton;`, then `replaces: UiButton::class`"),
                ));
                continue;
            };

            if let Some(message) = invalid(reg, fqcn, original) {
                out.push(error(message, "replace a component that a tag renders, with one replacement for each component"));
                continue;
            }

            if let Some(first) = replacements.by_original.get(&original.fqcn) {
                out.push(error(format!("`{first}` already replaces `{}`", original.fqcn), "keep one replacement: it can render the other one"));
                continue;
            }

            if let Some(def) = reg.get(fqcn) {
                out.extend(mismatches(def, original, name_span).into_iter().map(|d| (info.rel.clone(), d)));
            }

            replacements.keeps.extend(owners(&classes, fqcn, &original.fqcn).into_iter().map(|o| (o, original.fqcn.clone())));
            replacements.by_original.insert(original.fqcn.clone(), fqcn.to_string());
        }
    }

    (replacements, out)
}

/// The owners of the markup of a replacement, in lowercase: the replacement, and its parent
/// classes and traits in the sources, up to the original. A parent class that another component
/// also extends keeps the original in its markup for that component too.
fn owners(classes: &HashMap<String, &Class>, replacement: &str, original: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut queue = vec![replacement.to_string()];
    let mut seen = HashSet::new();
    while let Some(name) = queue.pop() {
        let lower = name.to_ascii_lowercase();
        if name.eq_ignore_ascii_case(original) || !seen.insert(lower.clone()) {
            continue;
        }

        if let Some(c) = classes.get(&lower) {
            queue.extend(c.parent.iter().chain(&c.traits).cloned());
        }

        out.push(lower);
    }

    out
}

/// Why a component cannot replace the original, or None.
fn invalid(reg: &Registry, fqcn: &str, original: &ComponentDef) -> Option<String> {
    let def = reg.get(fqcn)?;

    if original.fqcn == fqcn {
        Some(format!("`{fqcn}` cannot replace itself"))
    } else if original.file.is_empty() {
        Some(format!("`{}` is a built-in component of Phem, and the app cannot replace it", original.fqcn))
    } else if original.page || def.page {
        Some(format!("a page is not a tag, so `{fqcn}` cannot replace `{}`", original.fqcn))
    } else if let Some(first) = &original.replaces {
        Some(format!("`{}` replaces `{first}`, and a replacement cannot be replaced: replace `{first}`", original.fqcn))
    } else {
        None
    }
}

/// E016: the props and the slots of the original that the replacement does not accept, the
/// props that the replacement requires and the original does not, and the `Bound` props of the
/// original that the replacement declares with another type (`phem:bind` gives them a `Bound`).
/// The compiler does not compare the other types: PHP checks them.
fn mismatches(def: &ComponentDef, original: &ComponentDef, span: Span) -> Vec<Diagnostic> {
    // The compiler does not see all the props of a class with a parent outside the sources.
    if def.open || original.open {
        return vec![];
    }

    let mut out = Vec::new();
    let what = |name: &str| if original.slots.iter().any(|s| s == name) { "slot" } else { "prop" };

    if !def.rest {
        for name in original.props.iter().filter(|p| !def.props.contains(p)) {
            out.push(
                Diagnostic::error(codes::REPLACEMENT_MISMATCH, format!("`{}` does not accept the {} `{name}` of `{}`", def.fqcn, what(name), original.fqcn), span)
                    .with_label(format!("replaces `{}`", original.fqcn))
                    .with_help(format!("add `{name}` to `{}`, or a rest prop (`mixed ...$rest`) that gives it to the original", def.fqcn)),
            );
        }

        if original.rest {
            out.push(
                Diagnostic::error(codes::REPLACEMENT_MISMATCH, format!("`{}` accepts any prop, and `{}` has no rest prop", original.fqcn, def.fqcn), span)
                    .with_label(format!("replaces `{}`", original.fqcn))
                    .with_help(format!("add a rest prop to `{}`: `mixed ...$rest`, or `#[Prop(rest: true)] public array $attrs = [];`", def.fqcn)),
            );
        }
    }

    for name in original.bound.iter().filter(|b| def.props.contains(b) && !def.bound.contains(b)) {
        out.push(
            Diagnostic::error(codes::REPLACEMENT_MISMATCH, format!("`{}` takes the `Bound` prop `{name}` of `{}` with another type", def.fqcn, original.fqcn), span)
                .with_label(format!("replaces `{}`", original.fqcn))
                .with_help(format!("`phem:bind` on a tag of `{}` gives `{name}` a `Bound`: declare `Bound ${name}`", original.fqcn)),
        );
    }

    for name in def.required.iter().filter(|p| !original.required.contains(p)) {
        out.push(
            Diagnostic::error(codes::REPLACEMENT_MISMATCH, format!("`{}` requires the {} `{name}`, and `{}` does not", def.fqcn, if def.slots.contains(name) { "slot" } else { "prop" }, original.fqcn), span)
                .with_label(format!("replaces `{}`", original.fqcn))
                .with_help(format!("the tags of `{}` can omit `{name}`: give `{name}` a default value", original.fqcn)),
        );
    }

    out
}
