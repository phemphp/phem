//! Twig in markup: the variables at a cursor, the open blocks, the type of a Twig expression, and
//! the attributes of a value.
//!
//! The Twig variables of a markup literal are the PHP variables of the scope that contains it. In
//! a class, a name is a local variable, then a property (`$this->name`), then a method (a
//! handler). The Twig code before the cursor adds the variables of `{% for %}` and `{% set %}`.

use crate::classes::{Index, Member, MemberKind};
use crate::data;
use crate::scope::{Scope, VarKind};
use crate::types::{Key, Type};
use regex::Regex;
use std::path::PathBuf;
use std::sync::LazyLock;

pub fn loop_type() -> Type {
    let keys = data::LOOP.iter().map(|(name, doc)| Key { name: name.to_string(), offset: None, ty: None, doc: Some(doc) }).collect();
    Type { keys: Some(keys), ..Default::default() }
}

/// The text with the Twig comments replaced by spaces. The byte offsets do not change.
fn strip_comments(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut rest = s;
    while let Some(i) = rest.find("{#") {
        let Some(j) = rest[i + 2..].find("#}") else { break };
        out.push_str(&rest[..i]);
        let comment = &rest[i..i + 2 + j + 2];
        out.extend(comment.bytes().map(|b| if b == b'\n' { '\n' } else { ' ' }));
        rest = &rest[i + comment.len()..];
    }
    out.push_str(rest);
    out
}

static TAG_START: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"\{%[-~]?\s*(\w+)").unwrap());
static TAG: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"(?s)\{%[-~]?\s*(\w+)(.*?)[-~]?%\}").unwrap());
static SET: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"(?s)^\s*([A-Za-z_]\w*)\s*(=\s*(.*))?$").unwrap());
static FOR: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"(?s)^\s*([A-Za-z_]\w*)(?:\s*,\s*([A-Za-z_]\w*))?\s+in\s+(.*?)(?:\s+if\s+.*)?$").unwrap());

/// True when an `{% end<tag> %}` after `from` closes the tag. Nested tags with the same name need
/// their own end tag.
fn has_end_tag(text: &str, from: usize, tag: &str) -> bool {
    let end_tag = format!("end{tag}");
    let mut depth = 0;
    for m in TAG_START.captures_iter(&text[from.min(text.len())..]) {
        if &m[1] == tag {
            depth += 1;
        } else if m[1] == end_tag {
            if depth == 0 {
                return true;
            }
            depth -= 1;
        }
    }
    false
}

#[derive(Clone, Debug)]
pub struct Set {
    pub name: String,
    pub expr: String,
    pub offset: usize,
}

/// An open block: its tag (`None` for the top level), the arguments, and the `{% set %}` tags.
#[derive(Clone, Debug)]
pub struct Block {
    pub tag: Option<String>,
    pub args: String,
    pub offset: usize,
    pub sets: Vec<Set>,
}

/// The open blocks before `end` in the markup that starts at `from`. The first block is the top
/// level.
pub fn open_blocks(text: &str, from: usize, end: usize) -> Vec<Block> {
    let code = strip_comments(&text[from.min(text.len())..end.min(text.len())]);
    let mut stack = vec![Block { tag: None, args: String::new(), offset: from, sets: vec![] }];
    for m in TAG.captures_iter(&code) {
        let tag = &m[1];
        let args = &m[2];
        let offset = from + m.get(1).unwrap().start();
        if tag == "set" {
            if let Some(set) = SET.captures(args) {
                let name = set[1].to_string();
                let name_at = offset + tag.len() + args.find(name.as_str()).unwrap_or(0);
                let expr = set.get(3).map_or(String::new(), |e| e.as_str().trim().to_string());
                stack.last_mut().unwrap().sets.push(Set { name, expr, offset: name_at });
                if set.get(2).is_none() {
                    stack.push(Block { tag: Some(tag.to_string()), args: args.to_string(), offset, sets: vec![] });
                }
            }
            continue;
        }
        // A tag that is not in the table (a new Twig tag, or a tag of an extension) opens a block
        // when its end tag follows.
        let known = data::tag(tag);
        let opens = match known {
            Some(t) => t.3.is_some(),
            None => !tag.starts_with("end") && has_end_tag(text, from + m.get(0).unwrap().end(), tag),
        };
        if opens {
            stack.push(Block { tag: Some(tag.to_string()), args: args.to_string(), offset, sets: vec![] });
            continue;
        }
        if let Some(open) = tag.strip_prefix("end") {
            if let Some(i) = (1..stack.len()).rev().find(|&i| stack[i].tag.as_deref() == Some(open)) {
                stack.truncate(i);
            }
        }
    }
    stack
}

/// A step of a Twig expression chain.
#[derive(Debug, PartialEq)]
pub enum StepKind {
    Name(String),
    Attr(String),
    Key(String),
    Index,
    Filter(String),
    Call,
}

static STEP_ATTR: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\s*\.\s*([A-Za-z_]\w*|\d+)").unwrap());
static STEP_KEY: LazyLock<Regex> = LazyLock::new(|| Regex::new(r#"^\s*\[\s*(?:'([^']*)'|"([^"]*)")\s*\]"#).unwrap());
static STEP_INDEX: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\s*\[[^\]]*\]").unwrap());
static STEP_FILTER: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\s*\|\s*(\w+)(\s*\((?:[^()]|\([^()]*\))*\))?").unwrap());
static STEP_CALL: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\s*\((?:[^()]|\([^()]*\))*\)").unwrap());
static STEP_NAME: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\s*([A-Za-z_]\w*)").unwrap());

/// Splits `a.b['c'][0]|first` into steps.
pub fn steps(expr: &str) -> Option<Vec<StepKind>> {
    let m = STEP_NAME.captures(expr)?;
    let mut list = vec![StepKind::Name(m[1].to_string())];
    let mut rest = &expr[m.get(0).unwrap().end()..];
    while !rest.trim().is_empty() {
        let (step, len) = if let Some(m) = STEP_ATTR.captures(rest) {
            let name = m[1].to_string();
            (if name.starts_with(|c: char| c.is_ascii_digit()) { StepKind::Index } else { StepKind::Attr(name) }, m.get(0).unwrap().end())
        } else if let Some(m) = STEP_KEY.captures(rest) {
            (StepKind::Key(m.get(1).or(m.get(2)).unwrap().as_str().to_string()), m.get(0).unwrap().end())
        } else if let Some(m) = STEP_INDEX.find(rest) {
            (StepKind::Index, m.end())
        } else if let Some(m) = STEP_FILTER.captures(rest) {
            (StepKind::Filter(m[1].to_string()), m.get(0).unwrap().end())
        } else if let Some(m) = STEP_CALL.find(rest) {
            (StepKind::Call, m.end())
        } else {
            return None;
        };
        list.push(step);
        rest = &rest[len..];
    }
    Some(list)
}

#[derive(Clone, Debug, PartialEq)]
pub enum VarSource {
    /// A `{% for %}` or `{% set %}` variable, with its description.
    Twig(&'static str),
    Param,
    Local,
    Property,
    Method,
}

#[derive(Clone, Debug)]
pub struct TwigVar {
    pub name: String,
    pub kind: VarSource,
    pub ty: Option<Type>,
    pub type_text: String,
    pub offset: Option<usize>,
    pub file: PathBuf,
    pub member: Option<Member>,
}

#[derive(Clone, Copy, Debug, PartialEq)]
pub enum AttrKind {
    Key,
    Property,
    Method,
}

/// An attribute of a value, with the Twig rules: a key, a public property, a public method, or a
/// getter `getX()`/`isX()`/`hasX()` as `x`.
#[derive(Clone, Debug)]
pub struct Attr {
    pub name: String,
    pub kind: AttrKind,
    pub ty: Option<Type>,
    pub member: Option<Member>,
    pub key: Option<Key>,
    pub getter: bool,
    pub required: usize,
}

pub struct TwigEnv<'a, 'p> {
    pub scope: Scope<'a, 'p>,
    pub blocks: Vec<Block>,
    twig_vars: Vec<TwigVar>,
}

impl<'a, 'p> TwigEnv<'a, 'p> {
    /// `literal` is the start of the markup literal; `end` is the start of the current Twig tag.
    pub fn new(index: &'a Index<'p>, literal: Option<usize>, end: usize) -> Self {
        let scope = Scope::at(index, literal.unwrap_or(end));
        let blocks = match literal {
            Some(l) => open_blocks(&index.current.text, l, end),
            None => vec![Block { tag: None, args: String::new(), offset: 0, sets: vec![] }],
        };
        let mut env = TwigEnv { scope, blocks: blocks.clone(), twig_vars: Vec::new() };
        // The outer blocks come first, so an inner variable replaces an outer one.
        for block in &blocks {
            if block.tag.as_deref() == Some("for") {
                if let Some(m) = FOR.captures(&block.args) {
                    let list = env.type_of(&m[3]);
                    let item = m.get(2).unwrap_or(m.get(1).unwrap()).as_str();
                    let args_at = block.offset + 3;
                    let at = |name: &str| Some(args_at + block.args.find(name).unwrap_or(0));
                    env.set_var(item, VarSource::Twig("{% for %} item"), list.and_then(|l| l.item()), at(item));
                    if m.get(2).is_some() {
                        env.set_var(&m[1], VarSource::Twig("{% for %} key"), None, at(&m[1]));
                    }
                    env.set_var("loop", VarSource::Twig("The loop variable"), Some(loop_type()), Some(block.offset));
                }
            }
            for set in &block.sets {
                let ty = if set.expr.is_empty() { None } else { env.type_of(&set.expr) };
                env.set_var(&set.name, VarSource::Twig("{% set %}"), ty, Some(set.offset));
            }
        }
        env
    }

    fn set_var(&mut self, name: &str, kind: VarSource, ty: Option<Type>, offset: Option<usize>) {
        let v = TwigVar { name: name.to_string(), kind, ty, type_text: String::new(), offset, file: self.scope.source.file.clone(), member: None };
        match self.twig_vars.iter_mut().find(|o| o.name == name) {
            Some(o) => *o = v,
            None => self.twig_vars.push(v),
        }
    }

    /// The tags of the open blocks, the innermost first.
    pub fn open_tags(&self) -> Vec<String> {
        self.blocks.iter().skip(1).rev().filter_map(|b| b.tag.clone()).collect()
    }

    /// All the variables, in the order of the Phem rule.
    pub fn variables(&self) -> Vec<TwigVar> {
        let mut list: Vec<TwigVar> = Vec::new();
        let mut add = |v: TwigVar| {
            if !list.iter().any(|o| o.name == v.name) {
                list.push(v);
            }
        };
        for v in &self.twig_vars {
            add(v.clone());
        }
        let file = self.scope.source.file.clone();
        for v in &self.scope.vars {
            let kind = if v.kind == VarKind::Param { VarSource::Param } else { VarSource::Local };
            add(TwigVar { name: v.name.clone(), kind, ty: v.ty.clone(), type_text: v.type_text.clone(), offset: Some(v.offset), file: file.clone(), member: None });
        }
        if let Some(ci) = self.scope.class {
            let fqn = &self.scope.source.parsed.classes[ci].fqn;
            let members = self.scope.index.members(fqn);
            let var = |m: &Member, kind| TwigVar {
                name: m.name.clone(),
                kind,
                ty: if m.kind == MemberKind::Property { m.ty.clone() } else { None },
                type_text: m.type_text.clone(),
                offset: Some(m.offset),
                file: m.file.clone(),
                member: Some(m.clone()),
            };
            for m in members.iter().filter(|m| m.kind == MemberKind::Property && !m.is_static) {
                add(var(m, VarSource::Property));
            }
            for m in members.iter().filter(|m| m.kind == MemberKind::Method && !m.is_static && is_handler(&m.name)) {
                add(var(m, VarSource::Method));
            }
        }
        list
    }

    pub fn variable(&self, name: &str) -> Option<TwigVar> {
        self.variables().into_iter().find(|v| v.name == name)
    }

    /// The type of a Twig expression chain: `scientist.profession`, `items|first`.
    pub fn type_of(&self, expr: &str) -> Option<Type> {
        let list = steps(expr)?;
        let StepKind::Name(name) = &list[0] else { return None };
        let mut ty = self.variable(name).and_then(|v| v.ty);
        for step in &list[1..] {
            let t = ty?;
            ty = match step {
                StepKind::Attr(a) => self.attribute(&t, a).and_then(|a| a.ty),
                StepKind::Key(k) => match &t.keys {
                    Some(keys) => keys.iter().find(|e| &e.name == k).and_then(|e| e.ty.clone()),
                    None => t.item(),
                },
                StepKind::Index => t.item(),
                StepKind::Filter(f) => match f.as_str() {
                    "first" | "last" => t.item(),
                    "filter" | "sort" | "slice" | "reverse" | "merge" | "default" | "raw" | "shuffle" => Some(t),
                    _ => None,
                },
                StepKind::Call => Some(t),
                StepKind::Name(_) => None,
            };
        }
        ty
    }

    /// The attributes of a value.
    pub fn attributes(&self, ty: &Type) -> Vec<Attr> {
        if let Some(keys) = &ty.keys {
            return keys
                .iter()
                .map(|k| Attr { name: k.name.clone(), kind: AttrKind::Key, ty: k.ty.clone(), member: None, key: Some(k.clone()), getter: false, required: 0 })
                .collect();
        }
        let Some(fqn) = &ty.fqn else { return vec![] };
        let members = self.scope.index.members(fqn);
        let mut list: Vec<Attr> = Vec::new();
        for m in members.iter().filter(|m| m.kind == MemberKind::Property && !m.is_static && m.visibility == "public") {
            list.push(Attr { name: m.name.clone(), kind: AttrKind::Property, ty: m.ty.clone(), member: Some(m.clone()), key: None, getter: false, required: 0 });
        }
        for m in members.iter() {
            if m.kind != MemberKind::Method || m.is_static || m.visibility != "public" || m.name.starts_with("__") {
                continue;
            }
            let required = m.required();
            if let Some(name) = getter_name(&m.name).filter(|_| required == 0) {
                if !list.iter().any(|a| a.name == name) {
                    list.push(Attr { name, kind: AttrKind::Property, ty: m.ty.clone(), member: Some(m.clone()), key: None, getter: true, required });
                    continue;
                }
            }
            if !list.iter().any(|a| a.name == m.name) {
                list.push(Attr { name: m.name.clone(), kind: AttrKind::Method, ty: m.ty.clone(), member: Some(m.clone()), key: None, getter: false, required });
            }
        }
        list
    }

    pub fn attribute(&self, ty: &Type, name: &str) -> Option<Attr> {
        let list = self.attributes(ty);
        let exact = list.iter().position(|a| a.name == name);
        let index = exact.or_else(|| list.iter().position(|a| a.name.eq_ignore_ascii_case(name)))?;
        Some(list[index].clone())
    }
}

/// A method that the markup can call as a handler: not `render()`, `mount()`, or a magic method.
fn is_handler(name: &str) -> bool {
    !name.starts_with("__") && !name.eq_ignore_ascii_case("render") && !name.eq_ignore_ascii_case("mount")
}

/// `getFullName` → `fullName`, `isFamous` → `famous`, `hasX` → `x`.
fn getter_name(method: &str) -> Option<String> {
    let rest = ["get", "is", "has"].iter().find_map(|p| method.strip_prefix(p))?;
    let mut chars = rest.chars();
    let first = chars.next().filter(|c| c.is_ascii_uppercase())?;
    if !rest.chars().all(|c| c.is_alphanumeric() || c == '_') {
        return None;
    }
    Some(first.to_ascii_lowercase().to_string() + chars.as_str())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn splits_steps() {
        assert_eq!(
            steps("a.b['c'][0]|first").unwrap(),
            [StepKind::Name("a".into()), StepKind::Attr("b".into()), StepKind::Key("c".into()), StepKind::Index, StepKind::Filter("first".into())]
        );
        assert!(steps("1 + a").is_none());
    }

    #[test]
    fn finds_the_open_blocks() {
        let text = "<p>{% for a in b %}{% if a %}{% endif %}{# {% if x %} #}{% set c = 1 %}";
        let blocks = open_blocks(text, 0, text.len());
        assert_eq!(blocks.iter().map(|b| b.tag.clone()).collect::<Vec<_>>(), [None, Some("for".into())]);
        assert_eq!(blocks[1].sets[0].name, "c");
        assert_eq!(&text[blocks[1].sets[0].offset..blocks[1].sets[0].offset + 1], "c");
    }

    #[test]
    fn reads_getter_names() {
        assert_eq!(getter_name("getFullName").as_deref(), Some("fullName"));
        assert_eq!(getter_name("isFamous").as_deref(), Some("famous"));
        assert_eq!(getter_name("history"), None);
    }
}
