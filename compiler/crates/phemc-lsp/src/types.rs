//! PHP types for the editor: native types, docblock types, and name resolution.

use crate::decl::{Parsed, UseKind};
use crate::imports::imports_of;

/// A resolved type. The value is not known when a field is `None`.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Type {
    /// An object of a class.
    pub fqn: Option<String>,
    /// The items of a list (`array`, `iterable`), or of an iterable class (`Collection<int, Foo>`).
    pub item: Option<Box<Type>>,
    /// An array shape: the keys of an array literal.
    pub keys: Option<Vec<Key>>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Key {
    pub name: String,
    pub offset: Option<usize>,
    pub ty: Option<Type>,
    pub doc: Option<&'static str>,
}

impl Type {
    pub fn class(fqn: impl Into<String>) -> Self {
        Type { fqn: Some(fqn.into()), ..Default::default() }
    }

    pub fn list(item: Option<Type>) -> Self {
        Type { item: item.map(Box::new), ..Default::default() }
    }

    pub fn item(&self) -> Option<Type> {
        self.item.as_deref().cloned()
    }
}

const BUILTIN: &[&str] = &[
    "int", "integer", "float", "double", "string", "bool", "boolean", "array", "mixed", "void", "null", "false", "true", "callable",
    "iterable", "object", "never", "resource", "scalar", "numeric", "list", "non-empty-list", "non-empty-array", "array-key",
    "class-string", "non-empty-string", "positive-int", "negative-int", "non-negative-int", "literal-string", "callable-string",
];
const ITERABLE: &[&str] = &["array", "list", "non-empty-list", "non-empty-array", "iterable"];

pub fn is_builtin(name: &str) -> bool {
    BUILTIN.contains(&name.to_ascii_lowercase().as_str())
}

/// The context of a type in a declaration: the file, the class for `self`, the class for
/// `static` and `$this` (the called class; `self` when it is not set), and the parent class.
#[derive(Clone, Copy)]
pub struct TypeCtx<'a> {
    pub parsed: &'a Parsed,
    pub self_: Option<&'a str>,
    pub static_: Option<&'a str>,
    pub parent: Option<&'a str>,
}

impl<'a> TypeCtx<'a> {
    pub fn file(parsed: &'a Parsed) -> Self {
        TypeCtx { parsed, self_: None, static_: None, parent: None }
    }
}

/// Splits `a|b<c|d>|e` at the top level.
pub fn split_top(ty: &str, seps: &[char]) -> Vec<String> {
    let mut parts = Vec::new();
    let mut depth = 0i32;
    let mut start = 0;
    for (i, c) in ty.char_indices() {
        match c {
            '<' | '(' | '{' => depth += 1,
            '>' | ')' | '}' => depth -= 1,
            _ if depth == 0 && seps.contains(&c) => {
                parts.push(ty[start..i].trim().to_string());
                start = i + c.len_utf8();
            }
            _ => {}
        }
    }

    parts.push(ty[start..].trim().to_string());
    parts.retain(|p| !p.is_empty());
    parts
}

/// The base name and the generic arguments of `Foo<A, B>`.
fn generic(part: &str) -> (&str, Vec<String>) {
    match part.find('<') {
        Some(lt) if part.ends_with('>') => (&part[..lt], split_top(&part[lt + 1..part.len() - 1], &[','])),
        _ => (part, vec![]),
    }
}

/// The fully qualified name of a class name in a file. `\Foo` is a fully qualified name.
pub fn resolve_name(parsed: &Parsed, name: &str) -> Option<String> {
    if name.is_empty() {
        return None;
    }

    if let Some(fqn) = name.strip_prefix('\\') {
        return Some(fqn.to_string());
    }

    if is_builtin(name) {
        return None;
    }

    let (first, rest) = match name.split_once('\\') {
        Some((f, r)) => (f, Some(r)),
        None => (name, None),
    };

    if let Some(imported) = imports_of(parsed, UseKind::Class).find(|i| i.alias.eq_ignore_ascii_case(first)) {
        return Some(match rest {
            Some(r) => format!("{}\\{r}", imported.fqn),
            None => imported.fqn.clone(),
        });
    }

    Some(crate::decl::join_name(&parsed.namespace, name))
}

fn word_is(s: &str, words: &[&str]) -> bool {
    words.iter().any(|w| s.eq_ignore_ascii_case(w))
}

/// Turns a type string into a resolved type.
pub fn to_type(ty: &str, ctx: &TypeCtx) -> Option<Type> {
    let ty = ty.trim();
    if ty.is_empty() {
        return None;
    }

    // A conditional type: ($a is Foo ? A : B). Use the last branch.
    if ty.starts_with('(') && ty.ends_with(')') && is_conditional(&ty[1..ty.len() - 1]) {
        let branches = split_top(&ty[1..ty.len() - 1], &[':']);
        return if branches.len() > 1 { to_type(branches.last().unwrap(), ctx) } else { None };
    }

    let parts = split_top(ty.strip_prefix('?').unwrap_or(ty), &['|', '&']);
    for part in &parts {
        let part = part.strip_prefix('(').unwrap_or(part);
        let part = part.strip_suffix(')').unwrap_or(part);
        let part = part.strip_prefix('?').unwrap_or(part);
        if word_is(part, &["null", "false", "true", "void", "never", "mixed"]) {
            continue;
        }

        if let Some(item) = part.strip_suffix("[]") {
            return Some(Type::list(to_type(item, ctx)));
        }

        let (base, args) = generic(part);
        if word_is(base, ITERABLE) {
            if let Some(last) = args.last() {
                return Some(Type::list(to_type(last, ctx)));
            }
            continue;
        }

        if base.eq_ignore_ascii_case("self") {
            return ctx.self_.map(Type::class);
        }

        if base.eq_ignore_ascii_case("static") || base.eq_ignore_ascii_case("$this") {
            return ctx.static_.or(ctx.self_).map(Type::class);
        }

        if base.eq_ignore_ascii_case("parent") {
            return ctx.parent.map(Type::class);
        }

        let named = base.trim_start_matches('\\').chars().next().is_some_and(|c| c.is_ascii_alphabetic() || c == '_');
        if base.starts_with("array{") || is_builtin(base) || !named {
            continue;
        }

        let Some(fqn) = resolve_name(ctx.parsed, base) else { continue };
        let mut t = Type::class(fqn);
        if let Some(last) = args.last() {
            t.item = to_type(last, ctx).map(Box::new);
        }

        return Some(t);
    }

    None
}

/// `$a is Foo ? A : B`
fn is_conditional(inner: &str) -> bool {
    let inner = inner.trim_start();
    let Some(rest) = inner.strip_prefix('$') else { return false };
    let name_len = rest.chars().take_while(|c| c.is_alphanumeric() || *c == '_').count();
    let rest = &rest[name_len..];
    name_len > 0 && rest.starts_with(char::is_whitespace) && rest.trim_start().starts_with("is") && rest.trim_start()[2..].starts_with(char::is_whitespace)
}

/// Reads one type at the start of `s`: `array<int, Foo>`, `Foo|null`, `array{a: int}`.
pub fn read_type(s: &str) -> String {
    // Remove the spaces around `|` and `&`.
    let mut compact = String::new();
    let s = s.trim_start();
    let mut chars = s.chars().peekable();
    while let Some(c) = chars.next() {
        if c == '|' || c == '&' {
            while compact.ends_with(char::is_whitespace) {
                compact.pop();
            }

            compact.push(c);

            while chars.peek().is_some_and(|c| c.is_whitespace()) {
                chars.next();
            }
        } else {
            compact.push(c);
        }
    }

    let mut depth = 0i32;
    let mut end = compact.len();
    for (i, c) in compact.char_indices() {
        match c {
            '<' | '(' | '{' => depth += 1,
            '>' | ')' | '}' => depth -= 1,
            _ if depth == 0 && c.is_whitespace() => {
                end = i;
                break;
            }
            _ => {}
        }
    }

    compact[..end].split_whitespace().collect::<Vec<_>>().join(" ")
}

#[derive(Clone, Debug, Default)]
pub struct DocProperty {
    pub name: String,
    pub ty: String,
    /// The offset in the docblock.
    pub offset: usize,
}

#[derive(Clone, Debug, Default)]
pub struct DocMethod {
    pub name: String,
    pub ty: String,
    pub is_static: bool,
    pub params: String,
    pub offset: usize,
}

#[derive(Clone, Debug, Default)]
pub struct DocTags {
    pub summary: String,
    pub var: String,
    pub ret: String,
    pub params: Vec<(String, String)>,
    pub vars: Vec<(String, String)>,
    pub properties: Vec<DocProperty>,
    pub methods: Vec<DocMethod>,
}

impl DocTags {
    pub fn param(&self, name: &str) -> Option<&str> {
        self.params.iter().rev().find(|(n, _)| n == name).map(|(_, t)| t.as_str())
    }

    pub fn var_of(&self, name: &str) -> Option<&str> {
        self.vars.iter().rev().find(|(n, _)| n == name).map(|(_, t)| t.as_str())
    }
}

/// The first `$name` in `s`: the name and its offset (at the `$`).
fn dollar_name(s: &str) -> Option<(usize, &str)> {
    let mut from = 0;
    while let Some(i) = s[from..].find('$') {
        let start = from + i;
        let len = s[start + 1..].chars().take_while(|c| c.is_alphanumeric() || *c == '_').map(char::len_utf8).sum::<usize>();
        if len > 0 {
            return Some((start, &s[start + 1..start + 1 + len]));
        }

        from = start + 1;
    }

    None
}

/// Reads the tags of a docblock. The offsets are relative to the start of the docblock.
pub fn doc_tags(doc: &str) -> DocTags {
    let mut tags = DocTags::default();
    if doc.is_empty() {
        return tags;
    }

    let mut summary = Vec::new();
    let mut in_tags = false;
    let mut line_start = 0;
    for raw in doc.split('\n') {
        let start = line_start;
        line_start += raw.len() + 1;

        // ` * text */` → `text`
        let mut line = raw.trim_start_matches([' ', '\t']);
        let mut offset = start + (raw.len() - line.len());
        if let Some(l) = line.strip_prefix("/**") {
            line = l;
            offset += 3;
        } else if line.starts_with('*') && !line.starts_with("*/") {
            line = &line[1..];
            offset += 1;
        }

        if let Some(l) = line.strip_prefix([' ', '\t']) {
            line = l;
            offset += 1;
        }

        let line = line.trim_end();
        let line = line.strip_suffix("*/").unwrap_or(line).trim_end();
        let Some(tag) = line.strip_prefix('@') else {
            if !in_tags && !line.is_empty() && line != "/**" && line != "*/" {
                summary.push(line.to_string());
            }
            continue;
        };

        in_tags = true;
        let name_len = tag.chars().take_while(|c| c.is_alphanumeric() || *c == '_' || *c == '-').count();
        let name = &tag[..name_len];
        let name = name.strip_prefix("phpstan-").or_else(|| name.strip_prefix("psalm-")).unwrap_or(name);
        let rest = tag[name_len..].trim_start();
        let ty = read_type(rest);
        let rest_offset = offset + 1 + name_len + (tag[name_len..].len() - rest.len());

        match name {
            "var" => {
                if let Some((_, v)) = dollar_name(rest) {
                    tags.vars.push((v.to_string(), if ty.starts_with('$') { String::new() } else { ty.clone() }));
                }

                if !ty.starts_with('$') {
                    tags.var = ty;
                }
            }
            "return" => tags.ret = ty,
            "param" => {
                if let Some((_, v)) = dollar_name(rest) {
                    if !ty.starts_with('$') {
                        tags.params.push((v.to_string(), ty));
                    }
                }
            }
            "property" | "property-read" | "property-write" => {
                if let Some((at, v)) = dollar_name(rest) {
                    tags.properties.push(DocProperty {
                        name: v.to_string(),
                        ty: if ty.starts_with('$') { String::new() } else { ty },
                        offset: rest_offset + at,
                    });
                }
            }
            "method" => {
                if let Some(m) = doc_method(rest) {
                    tags.methods.push(DocMethod { offset: rest_offset + m.offset, ..m });
                }
            }
            _ => {}
        }
    }

    tags.summary = summary.join(" ").trim().to_string();
    tags
}

/// `[static] [Type] name(params)`. The offset is the offset of the name in `rest`.
fn doc_method(rest: &str) -> Option<DocMethod> {
    let paren = rest.find('(')?;
    let close = paren + rest[paren..].find(')')?;
    let head = rest[..paren].trim_end();
    let name_len = head.chars().rev().take_while(|c| c.is_alphanumeric() || *c == '_').count();
    if name_len == 0 {
        return None;
    }

    let name = &head[head.len() - name_len..];
    let mut words: Vec<&str> = head[..head.len() - name_len].split_whitespace().collect();
    let is_static = words.first().is_some_and(|w| *w == "static");
    if is_static {
        words.remove(0);
    }

    if words.len() > 1 {
        return None;
    }

    Some(DocMethod {
        name: name.to_string(),
        ty: words.first().map_or(String::new(), |w| w.to_string()),
        is_static,
        params: rest[paren + 1..close].trim().to_string(),
        offset: head.len() - name_len,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::decl::parse;

    #[test]
    fn resolves_types() {
        let parsed = parse("<?php\nnamespace App;\nuse Foo\\Bar;\n");
        let ctx = TypeCtx { parsed: &parsed, self_: Some("App\\Me"), static_: None, parent: None };
        assert_eq!(to_type("?Bar", &ctx), Some(Type::class("Foo\\Bar")));
        assert_eq!(to_type("Baz|null", &ctx), Some(Type::class("App\\Baz")));
        assert_eq!(to_type("list<Bar>", &ctx), Some(Type::list(Some(Type::class("Foo\\Bar")))));
        assert_eq!(to_type("Bar[]", &ctx), Some(Type::list(Some(Type::class("Foo\\Bar")))));
        assert_eq!(to_type("static", &ctx), Some(Type::class("App\\Me")));
        assert_eq!(to_type("($id is int ? Bar : list<Bar>)", &ctx), Some(Type::list(Some(Type::class("Foo\\Bar")))));
        assert_eq!(to_type("int", &ctx), None);
        assert_eq!(to_type("array{a: int}", &ctx), None);
    }

    #[test]
    fn reads_doc_tags() {
        let doc = "/**\n * A scientist.\n *\n * @property string $magic\n * @method static self make(int $a)\n * @return list<Foo>\n * @param Foo | null $x the x\n */";
        let tags = doc_tags(doc);
        assert_eq!(tags.summary, "A scientist.");
        assert_eq!(tags.ret, "list<Foo>");
        assert_eq!(tags.param("x"), Some("Foo|null"));
        assert_eq!(tags.properties[0].name, "magic");
        assert_eq!(&doc[tags.properties[0].offset..tags.properties[0].offset + 6], "$magic");
        let m = &tags.methods[0];
        assert_eq!((m.name.as_str(), m.ty.as_str(), m.is_static, m.params.as_str()), ("make", "self", true, "int $a"));
        assert_eq!(&doc[m.offset..m.offset + 5], "make(");
        assert_eq!(doc_tags("/** @var Foo $x */").var_of("x"), Some("Foo"));
        assert_eq!(doc_tags("/** The name. */").summary, "The name.");
    }
}
