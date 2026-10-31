//! The classes and the functions of a project, read on demand: a class file is parsed when the
//! completion or the definition needs its members. The cache keeps each file until its
//! modification time changes.
//!
//! An [`Index`] serves one request. Its `current` source is the open document (its text can be
//! unsaved): it replaces the file on disk. The `open` sources replace their files too. The index
//! caches the lookups of the request.

use crate::decl::{param_text, parse, Method, Param, Parsed};
use crate::project::{Project, SymbolKind};
use crate::scanner::code_of;
use crate::types::{doc_tags, resolve_name, to_type, Type, TypeCtx};
use std::cell::{OnceCell, RefCell};
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::rc::Rc;
use std::time::SystemTime;

/// A parsed file.
pub struct Source {
    pub file: PathBuf,
    pub text: String,
    pub parsed: Parsed,
    code: OnceCell<Vec<u8>>,
}

impl Source {
    pub fn new(file: PathBuf, text: String) -> Rc<Self> {
        let parsed = parse(&text);
        Rc::new(Source { file, text, parsed, code: OnceCell::new() })
    }

    /// The masked code: strings, comments, and markup are spaces.
    pub fn code(&self) -> &[u8] {
        self.code.get_or_init(|| code_of(&self.text))
    }
}

/// The parsed class files of a project.
#[derive(Default)]
pub struct ClassCache {
    files: RefCell<HashMap<PathBuf, (Option<SystemTime>, Rc<Source>)>>,
}

impl ClassCache {
    fn source(&self, file: &Path) -> Option<Rc<Source>> {
        let mtime = std::fs::metadata(file).ok()?.modified().ok();
        if let Some((m, s)) = self.files.borrow().get(file) {
            if *m == mtime && mtime.is_some() {
                return Some(s.clone());
            }
        }

        let text = std::fs::read_to_string(file).ok()?;
        let source = Source::new(file.to_path_buf(), text);
        self.files.borrow_mut().insert(file.to_path_buf(), (mtime, source.clone()));
        Some(source)
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MemberKind {
    Property,
    Method,
    Constant,
}

#[derive(Clone, Debug)]
pub struct Member {
    pub kind: MemberKind,
    pub name: String,
    pub ty: Option<Type>,
    pub type_text: String,
    pub is_static: bool,
    pub visibility: String,
    pub signature: String,
    pub doc: String,
    pub file: PathBuf,
    pub offset: usize,
    /// The class that declares the member (the class that uses the trait, for a trait member).
    pub owner: String,
    pub enum_case: bool,
    /// The parameters of a method. `None` for a `@method` tag (see `params_text`).
    pub params: Option<Vec<Param>>,
    pub params_text: String,
}

impl Member {
    /// The number of the parameters without a default value.
    pub fn required(&self) -> usize {
        self.params.as_ref().map_or(0, |ps| ps.iter().filter(|p| p.default.is_none() && !p.variadic).count())
    }

    pub fn has_params(&self) -> bool {
        self.params.as_ref().is_some_and(|p| !p.is_empty()) || !self.params_text.is_empty()
    }

    pub fn params_signature(&self) -> String {
        match &self.params {
            Some(ps) => ps.iter().map(param_text).collect::<Vec<_>>().join(", "),
            None => self.params_text.clone(),
        }
    }
}

/// A declaration in a source: the index of a class or of a function of `source.parsed`.
#[derive(Clone)]
pub struct Found {
    pub source: Rc<Source>,
    pub index: usize,
}

impl Found {
    pub fn class(&self) -> &crate::decl::Class {
        &self.source.parsed.classes[self.index]
    }

    pub fn function(&self) -> &crate::decl::Function {
        &self.source.parsed.functions[self.index]
    }
}

pub struct Index<'a> {
    pub project: &'a Project,
    pub current: Rc<Source>,
    open: Rc<HashMap<PathBuf, Rc<Source>>>,
    members: RefCell<HashMap<(String, String), Rc<Vec<Member>>>>,
}

fn method_signature(m: &Method, return_text: &str) -> String {
    let mods = if m.is_static { format!("{} static", m.visibility) } else { m.visibility.clone() };
    let params = m.params.iter().map(param_text).collect::<Vec<_>>().join(", ");
    let ret = if return_text.is_empty() { String::new() } else { format!(": {return_text}") };
    format!("{mods} function {}({params}){ret}", m.name)
}

impl<'a> Index<'a> {
    pub fn new(project: &'a Project, current: Rc<Source>) -> Self {
        Index { project, current, open: Rc::default(), members: RefCell::new(HashMap::new()) }
    }

    /// The same index, where the `open` sources replace their files on disk.
    pub fn with_open(self, open: Rc<HashMap<PathBuf, Rc<Source>>>) -> Self {
        Index { open, ..self }
    }

    pub fn root(&self) -> &Path {
        &self.project.root
    }

    /// The source of a file: the current source, an open source, or the file on disk.
    pub fn source(&self, file: &Path) -> Option<Rc<Source>> {
        if file == self.current.file {
            return Some(self.current.clone());
        }

        if let Some(source) = self.open.get(file) {
            return Some(source.clone());
        }

        self.project.classes.source(file)
    }

    /// Finds a declaration of a class or of a function.
    pub fn find(&self, kind: SymbolKind, fqn: &str) -> Option<Found> {
        if fqn.is_empty() {
            return None;
        }

        let pick = |source: &Rc<Source>| {
            let index = match kind {
                SymbolKind::Class => source.parsed.classes.iter().position(|d| d.fqn.eq_ignore_ascii_case(fqn)),
                _ => source.parsed.functions.iter().position(|d| d.fqn.eq_ignore_ascii_case(fqn)),
            };

            index.map(|index| Found { source: source.clone(), index })
        };

        if let Some(found) = pick(&self.current) {
            return Some(found);
        }

        let file = self.project.file_of(kind, fqn)?;
        pick(&self.source(&file)?)
    }

    /// The parent class of a class declaration.
    pub fn parent_of(found: &Found) -> Option<String> {
        let cls = found.class();
        if cls.kind == "interface" {
            return None;
        }

        cls.extends.first().and_then(|e| resolve_name(&found.source.parsed, e))
    }

    /// All the members of a class: its own, then those of its traits, its parents, and its
    /// interfaces. A member of a class hides a member with the same name in a parent.
    pub fn members(&self, fqn: &str) -> Rc<Vec<Member>> {
        self.members_called(fqn, fqn)
    }

    /// The members of a class. `called` is the class for the `static` types.
    fn members_called(&self, fqn: &str, called: &str) -> Rc<Vec<Member>> {
        let key = (fqn.to_lowercase(), called.to_lowercase());
        if let Some(m) = self.members.borrow().get(&key) {
            return m.clone();
        }

        let list = Rc::new(self.collect(fqn, called, &mut Vec::new()));
        self.members.borrow_mut().insert(key, list.clone());
        list
    }

    fn collect(&self, fqn: &str, called: &str, seen: &mut Vec<String>) -> Vec<Member> {
        if fqn.is_empty() || seen.contains(&fqn.to_lowercase()) {
            return vec![];
        }

        seen.push(fqn.to_lowercase());
        let Some(found) = self.find(SymbolKind::Class, fqn) else { return vec![] };
        let cls = found.class();
        let source = &found.source;
        let parent = Self::parent_of(&found);
        let ctx = TypeCtx { parsed: &source.parsed, self_: Some(&cls.fqn), static_: Some(called), parent: parent.as_deref() };

        let mut list: Vec<Member> = Vec::new();
        let add = |list: &mut Vec<Member>, m: Member| {
            let same = |o: &Member| o.kind == m.kind && if m.kind == MemberKind::Property { o.name == m.name } else { o.name.eq_ignore_ascii_case(&m.name) };
            if !list.iter().any(same) {
                list.push(m);
            }
        };

        let base = |kind, name: &str| Member {
            kind,
            name: name.to_string(),
            ty: None,
            type_text: String::new(),
            is_static: false,
            visibility: "public".into(),
            signature: String::new(),
            doc: String::new(),
            file: source.file.clone(),
            offset: cls.offset,
            owner: cls.fqn.clone(),
            enum_case: false,
            params: None,
            params_text: String::new(),
        };

        let ctor_tags = cls.methods.iter().find(|m| m.name.eq_ignore_ascii_case("__construct")).map(|m| doc_tags(&m.doc));
        for p in &cls.properties {
            let tags = doc_tags(&p.doc);
            let promoted = if p.promoted { ctor_tags.as_ref().and_then(|t| t.param(&p.name)).unwrap_or("") } else { "" };
            let type_text = [tags.var.as_str(), promoted, p.ty.as_str()].into_iter().find(|t| !t.is_empty()).unwrap_or("").to_string();
            let signature = format!(
                "{} {}${}{}",
                p.modifiers.join(" "),
                if p.ty.is_empty() { String::new() } else { format!("{} ", p.ty) },
                p.name,
                p.default.as_ref().map_or(String::new(), |d| format!(" = {d}"))
            );

            add(&mut list, Member {
                ty: to_type(&type_text, &ctx).or_else(|| to_type(&p.ty, &ctx)),
                type_text,
                is_static: p.is_static,
                visibility: p.visibility.clone(),
                signature,
                doc: tags.summary,
                offset: p.offset,
                ..base(MemberKind::Property, &p.name)
            });
        }

        for m in &cls.methods {
            let tags = doc_tags(&m.doc);
            let type_text = if tags.ret.is_empty() { m.return_type.clone() } else { tags.ret.clone() };
            add(&mut list, Member {
                ty: to_type(&type_text, &ctx).or_else(|| to_type(&m.return_type, &ctx)),
                signature: method_signature(m, &type_text),
                type_text,
                params: Some(m.params.clone()),
                is_static: m.is_static,
                visibility: m.visibility.clone(),
                doc: tags.summary,
                offset: m.offset,
                ..base(MemberKind::Method, &m.name)
            });
        }

        for c in &cls.constants {
            add(&mut list, Member {
                ty: c.is_case.then(|| Type::class(cls.fqn.clone())),
                type_text: if c.is_case { cls.name.clone() } else { String::new() },
                is_static: true,
                visibility: c.visibility.clone(),
                enum_case: c.is_case,
                signature: if c.is_case { format!("case {}", c.name) } else { format!("const {}", c.name) },
                doc: doc_tags(&c.doc).summary,
                offset: c.offset,
                ..base(MemberKind::Constant, &c.name)
            });
        }

        if cls.kind == "enum" {
            for (name, ty) in [("name", "string"), ("value", "int|string")] {
                add(&mut list, Member { type_text: ty.into(), signature: format!("public readonly {ty} ${name}"), ..base(MemberKind::Property, name) });
            }
        }

        // The @property and @method tags of the class docblock.
        if !cls.doc.is_empty() {
            let doc_start = source.text[..cls.start.min(source.text.len())].rfind(&cls.doc).unwrap_or(0);
            let tags = doc_tags(&cls.doc);
            for p in &tags.properties {
                add(&mut list, Member {
                    ty: to_type(&p.ty, &ctx),
                    type_text: p.ty.clone(),
                    signature: format!("@property {} ${}", p.ty, p.name),
                    offset: doc_start + p.offset,
                    ..base(MemberKind::Property, &p.name)
                });
            }

            for m in &tags.methods {
                add(&mut list, Member {
                    ty: to_type(&m.ty, &ctx),
                    type_text: m.ty.clone(),
                    params_text: m.params.clone(),
                    is_static: m.is_static,
                    signature: format!("@method {}{} {}({})", if m.is_static { "static " } else { "" }, m.ty, m.name, m.params),
                    offset: doc_start + m.offset,
                    ..base(MemberKind::Method, &m.name)
                });
            }
        }

        // PHP copies the trait members into the class: a private member of a trait is a private
        // member of the class.
        for t in &cls.traits {
            if let Some(t) = resolve_name(&source.parsed, t) {
                for m in self.collect(&t, called, seen) {
                    add(&mut list, Member { owner: cls.fqn.clone(), ..m });
                }
            }
        }

        if let Some(parent) = &parent {
            for m in self.collect(parent, called, seen) {
                if m.visibility != "private" {
                    add(&mut list, m);
                }
            }
        }

        let interfaces = if cls.kind == "interface" { &cls.extends } else { &cls.implements };
        for i in interfaces {
            if let Some(i) = resolve_name(&source.parsed, i) {
                for m in self.collect(&i, called, seen) {
                    add(&mut list, m);
                }
            }
        }

        list
    }

    /// One member by name. `kind` `None` is any kind.
    pub fn member(&self, fqn: &str, name: &str, kind: Option<MemberKind>) -> Option<Member> {
        self.members(fqn)
            .iter()
            .find(|m| kind.is_none_or(|k| m.kind == k) && if m.kind == MemberKind::Property { m.name == name } else { m.name.eq_ignore_ascii_case(name) })
            .cloned()
    }

    /// True if `fqn` extends or uses `ancestor` (a parent class or a trait in the sources), up to
    /// `stop`: the classes above `stop` do not count.
    pub fn inherits(&self, fqn: &str, ancestor: &str, stop: &str) -> bool {
        let mut queue = vec![fqn.to_string()];
        let mut seen = Vec::new();
        while let Some(c) = queue.pop() {
            if c.eq_ignore_ascii_case(stop) || seen.contains(&c.to_lowercase()) {
                continue;
            }

            if c.eq_ignore_ascii_case(ancestor) {
                return true;
            }

            seen.push(c.to_lowercase());
            if let Some(found) = self.find(SymbolKind::Class, &c) {
                queue.extend(Self::parent_of(&found));
                queue.extend(found.class().traits.iter().filter_map(|t| resolve_name(&found.source.parsed, t)));
            }
        }

        false
    }

    /// True if `fqn` is `ancestor` or extends it (for the protected members).
    pub fn is_a(&self, fqn: &str, ancestor: &str) -> bool {
        let mut seen = Vec::new();
        let mut current = Some(fqn.to_string());
        while let Some(c) = current {
            if c.eq_ignore_ascii_case(ancestor) {
                return true;
            }

            if seen.contains(&c.to_lowercase()) {
                return false;
            }

            seen.push(c.to_lowercase());
            current = self.find(SymbolKind::Class, &c).and_then(|f| Self::parent_of(&f));
        }

        false
    }
}
