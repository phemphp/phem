//! The references of a declaration: the tags of a component, the calls of a function, and the
//! calls and the Twig handlers of a method, in the `.phem` files of a project. Each name with the
//! name of the declaration is a candidate: it is a reference when the resolver of the definition
//! ([`symbol_at`]) resolves it to the declaration.

use crate::classes::{Index, Source};
use crate::decl::Parsed;
use crate::project::{routes_of, Project};
use crate::resolve::{symbol_at, Location};
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::rc::Rc;

/// A declaration of a function (a function component too), a class, or a method.
#[derive(Clone, Debug, PartialEq)]
pub struct Declaration {
    pub name: String,
    /// The place of the name.
    pub location: Location,
}

impl Declaration {
    /// The byte range of the name.
    pub fn range(&self) -> (usize, usize) {
        (self.location.offset, self.location.offset + self.name.len())
    }
}

/// A name that resolves to a declaration.
#[derive(Clone, Debug, PartialEq)]
pub struct Reference {
    pub file: PathBuf,
    pub range: (usize, usize),
}

/// The declarations of a parsed file, in the order of the file.
pub fn declarations(parsed: &Parsed, file: &Path) -> Vec<Declaration> {
    let at = |name: &str, offset| Declaration { name: name.to_string(), location: Location { file: file.to_path_buf(), offset } };

    let mut list: Vec<Declaration> = parsed.functions.iter().map(|f| at(&f.name, f.offset)).collect();
    for cls in &parsed.classes {
        list.push(at(&cls.name, cls.offset));
        list.extend(cls.methods.iter().map(|m| at(&m.name, m.offset)));
    }

    list.sort_by_key(|d| d.location.offset);
    list
}

/// The declarations that get a usage count. A page has no count (the router renders it), and
/// `render()` and the magic methods have no count (Phem and PHP call them).
pub fn counted(parsed: &Parsed, file: &Path) -> Vec<Declaration> {
    let pages: Vec<String> = routes_of(parsed, file).into_iter().map(|r| r.fqn).collect();
    let page_offsets: Vec<usize> = parsed
        .functions
        .iter()
        .map(|f| (&f.fqn, f.offset))
        .chain(parsed.classes.iter().map(|c| (&c.fqn, c.offset)))
        .filter(|(fqn, _)| pages.contains(fqn))
        .map(|(_, offset)| offset)
        .collect();

    declarations(parsed, file)
        .into_iter()
        .filter(|d| !page_offsets.contains(&d.location.offset) && !d.name.eq_ignore_ascii_case("render") && !d.name.starts_with("__"))
        .collect()
}

/// The declaration with its name at `offset` of a parsed file.
pub fn declaration_at(parsed: &Parsed, file: &Path, offset: usize) -> Option<Declaration> {
    declarations(parsed, file).into_iter().find(|d| (d.range().0..=d.range().1).contains(&offset))
}

/// The declaration at `offset` of the current source: the declaration with its name there, or
/// the declaration of the symbol there.
pub fn target_at(index: &Index, offset: usize) -> Option<Declaration> {
    let current = &index.current;
    if let Some(d) = declaration_at(&current.parsed, &current.file, offset) {
        return Some(d);
    }

    let location = symbol_at(index, offset)?.location?;
    let source = index.source(&location.file)?;

    declarations(&source.parsed, &location.file).into_iter().find(|d| d.location == location)
}

/// The `.phem` sources of a project, where the references are.
pub struct Usages<'p> {
    project: &'p Project,
    sources: Vec<Rc<Source>>,
    open: Rc<HashMap<PathBuf, Rc<Source>>>,
}

impl<'p> Usages<'p> {
    /// The `.phem` files of the project. The `open` texts replace the files on disk.
    pub fn load(project: &'p Project, open: &HashMap<PathBuf, String>) -> Self {
        let sources: Vec<Rc<Source>> = project
            .phem
            .keys()
            .filter_map(|file| {
                let text = match open.get(file) {
                    Some(t) => t.clone(),
                    None => std::fs::read_to_string(file).ok()?,
                };

                Some(Source::new(file.clone(), text))
            })
            .collect();

        Self::new(project, sources)
    }

    pub fn new(project: &'p Project, mut sources: Vec<Rc<Source>>) -> Self {
        sources.sort_by(|a, b| a.file.cmp(&b.file));
        let open = Rc::new(sources.iter().map(|s| (s.file.clone(), s.clone())).collect());

        Usages { project, sources, open }
    }

    /// The index of a request on one of the sources.
    pub fn index(&self, file: &Path) -> Option<Index<'p>> {
        let source = self.open.get(file)?;

        Some(Index::new(self.project, source.clone()).with_open(self.open.clone()))
    }

    /// The references of each declaration, in the order of the files.
    pub fn find(&self, targets: &[Declaration]) -> Vec<Vec<Reference>> {
        let mut found = vec![Vec::new(); targets.len()];
        let mut names: HashMap<String, Vec<usize>> = HashMap::new();
        for (i, t) in targets.iter().enumerate() {
            names.entry(t.name.to_ascii_lowercase()).or_default().push(i);
        }

        for source in &self.sources {
            let index = Index::new(self.project, source.clone()).with_open(self.open.clone());
            for (start, end) in words(&source.text) {
                let Some(list) = names.get(&source.text[start..end].to_ascii_lowercase()) else { continue };
                if !is_usage(source, start, end) {
                    continue;
                }

                let Some(symbol) = symbol_at(&index, start) else { continue };
                let Some(location) = symbol.location else { continue };
                for &i in list.iter().filter(|&&i| targets[i].location == location) {
                    found[i].push(Reference { file: source.file.clone(), range: symbol.range });
                }
            }
        }

        found
    }
}

/// The byte ranges of the names (`[A-Za-z_][A-Za-z0-9_]*`) of a text.
fn words(text: &str) -> Vec<(usize, usize)> {
    let t = text.as_bytes();
    let is_word = |c: u8| c.is_ascii_alphanumeric() || c == b'_';

    let mut list = Vec::new();
    let mut i = 0;
    while i < t.len() {
        if !is_word(t[i]) {
            i += 1;
            continue;
        }

        let start = i;
        while i < t.len() && is_word(t[i]) {
            i += 1;
        }

        if !t[start].is_ascii_digit() {
            list.push((start, i));
        }
    }

    list
}

/// Whether a name is a usage. An import, a closing tag (`</Name>`), and a slot tag
/// (`<Name:slot>`) are not usages: they go with a usage, or they only make a usage possible.
fn is_usage(source: &Source, start: usize, end: usize) -> bool {
    let t = source.text.as_bytes();
    if source.parsed.uses.iter().any(|u| u.start <= start && end <= u.end) {
        return false;
    }

    // The start of a qualified name: `\App\Ui\Common\Card`.
    let name_start = start - t[..start].iter().rev().take_while(|&&c| c.is_ascii_alphanumeric() || c == b'_' || c == b'\\').count();
    let before = &t[..name_start];
    if before.ends_with(b"</") {
        return false;
    }

    let slot = t.get(end) == Some(&b':') && t.get(end + 1) != Some(&b':');

    !(before.ends_with(b"<") && slot)
}
