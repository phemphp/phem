//! Hint H001: a variable that only copies a property for the markup (`$lines = $this->lines;`).
//! The markup reads the properties of the class, also the private ones, so the copy is not
//! necessary. The quick fix deletes the copy, and renames the markup uses of a renamed copy
//! (`$rows = $this->lines;`: `rows` becomes `lines`). The PHP part is in
//! `phemc_analyze::copies()`.

use crate::classes::ClassNames;
use crate::names::{self, Source};
use crate::vars::{self, FreeVar};
use phemc_analyze::{Class, Copy, FileInfo, MarkupScope};
use phemc_syntax::diag::codes;
use phemc_syntax::php::Lexed;
use phemc_syntax::{Diagnostic, Fix, Span};
use std::collections::{BTreeMap, HashSet};

const HELP: &str = "markup reads the properties of the class, also the private ones";

/// The markups of a method, walked once for all its copies.
struct Markups {
    uses: Vec<FreeVar>,
    /// The names that a Twig tag binds.
    bound: HashSet<String>,
}

pub(crate) fn check(info: &FileInfo, src: &str, lexed: &Lexed, class_names: &ClassNames) -> Vec<Diagnostic> {
    // The markups of each method.
    let mut by_method: BTreeMap<(usize, usize), Vec<usize>> = BTreeMap::new();
    for (index, scope) in info.markup_scopes.iter().enumerate() {
        if let MarkupScope::Method(ci, mi) = scope {
            by_method.entry((*ci, *mi)).or_default().push(index);
        }
    }

    let mut diags = Vec::new();
    for ((ci, mi), indexes) in by_method {
        let class = &info.classes[ci];
        let method = &class.methods[mi];

        // A markup before the copy does not read it.
        let first_markup = indexes.iter().map(|&m| lexed.markups[m].span.start).min().unwrap_or(0);
        let copies: Vec<Copy> = phemc_analyze::copies(class, method, src, lexed).into_iter().filter(|c| c.stmt.end <= first_markup).collect();
        if copies.is_empty() {
            continue;
        }

        let markups = Markups {
            uses: indexes.iter().flat_map(|&m| vars::uses(info, &lexed.markups[m].root)).collect(),
            bound: indexes.iter().flat_map(|&m| vars::bound_names(&lexed.markups[m].root)).collect(),
        };

        for copy in copies {
            let mut d = Diagnostic::hint(codes::COPIED_PROPERTY, format!("`${}` copies `$this->{}`: use `{}` in the markup", copy.var, copy.prop, copy.prop), copy.stmt)
                .with_label("a copy of a property")
                .with_help(HELP)
                .unnecessary();
            if let Some(fix) = fix(&copy, &markups, method.span.text(src), class, class_names) {
                d = d.with_fix(fix);
            }

            diags.push(d);
        }
    }

    diags
}

/// The quick fix, or `None` when a renamed use can read another value or cannot be renamed.
fn fix(copy: &Copy, markups: &Markups, method: &str, class: &Class, class_names: &ClassNames) -> Option<Fix> {
    let mut edits = vec![(copy.line, String::new())];
    if copy.var != copy.prop {
        // The new name must read the property: not a Twig binding, a PHP variable or a class.
        let reads_property = matches!(names::source(&copy.prop, true, &HashSet::new(), method, Some(class), class_names), Source::Property(_));
        if !reads_property || markups.bound.contains(&copy.prop) {
            return None;
        }

        for u in markups.uses.iter().filter(|u| u.name == copy.var) {
            if !u.renamable || !u.strict {
                return None;
            }

            edits.push((Span::new(u.offset, u.offset + copy.var.len()), copy.prop.clone()));
        }
    }

    Some(Fix { title: format!("Read `{}` in the markup", copy.prop), edits })
}
