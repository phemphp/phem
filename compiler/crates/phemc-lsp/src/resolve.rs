//! The symbol at an offset, for go to definition and hover.

use crate::classes::{Found, Index, Member, MemberKind};
use crate::complete::rel;
use crate::data;
use crate::decl::{param_text, ComponentKind, UseKind};
use crate::imports::resolve_component;
use crate::project::{FileComponent, SymbolKind};
use crate::scanner::{scan, Context, TwigContext};
use crate::scope::{chain_start, ends_with_word, trim_end, Scope};
use crate::twig::{AttrKind, TwigEnv, VarSource};
use crate::types::{doc_tags, resolve_name};
use regex::Regex;
use std::path::{Path, PathBuf};
use std::sync::LazyLock;

macro_rules! re {
    ($name:ident, $re:expr) => {
        static $name: LazyLock<Regex> = LazyLock::new(|| Regex::new($re).unwrap());
    };
}

re!(ROUTE_CALL, r"\b(path|url)\s*\(\s*$");
re!(WORD_END, r"[A-Za-z_]\w*$");
re!(TAG_HEAD, r"^[-~]?\s*$");
re!(FILTER_HEAD, r"\|\s*$");
re!(TEST_HEAD, r"(?:^|[^\w.])is\s+(?:not\s+)?$");
re!(CHAIN_HEAD, r"([A-Za-z_][\w.]*(?:\[[^\]]*\])*(?:\s*\|\s*\w+)*)\s*\.\s*$");
re!(RANGE_HEAD, r"\.\.\s*$");

const EVENT_DOC: &str = "Phem event: the handler runs on the server. It receives a `Phem\\Event`.";

/// A place in a file.
#[derive(Clone, Debug, PartialEq)]
pub struct Location {
    pub file: PathBuf,
    pub offset: usize,
}

#[derive(Clone, Debug)]
pub struct Symbol {
    /// The byte range of the symbol at the cursor.
    pub range: (usize, usize),
    pub location: Option<Location>,
    /// Markdown.
    pub hover: Option<String>,
}

fn code(lang: &str, text: &str) -> String {
    format!("```{lang}\n{text}\n```")
}

/// The symbol at `offset` of the current source of `index`.
pub fn symbol_at(index: &Index, offset: usize) -> Option<Symbol> {
    let text = index.current.text.as_str();
    let mut offset = offset.min(text.len());
    while !text.is_char_boundary(offset) {
        offset -= 1;
    }

    Resolver { index, text, offset }.run()
}

struct Resolver<'a, 'p> {
    index: &'a Index<'p>,
    text: &'a str,
    offset: usize,
}

fn is_word(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_'
}

impl Resolver<'_, '_> {
    fn root(&self) -> &Path {
        self.index.root()
    }

    fn file(&self) -> &Path {
        &self.index.current.file
    }

    fn defined_in(&self, file: Option<&Path>) -> String {
        file.map_or(String::new(), |f| format!("\n\nDefined in `{}`", rel(self.root(), f)))
    }

    fn location(&self, file: &Path, offset: usize) -> Option<Location> {
        Some(Location { file: file.to_path_buf(), offset })
    }

    fn components(&self) -> Vec<FileComponent> {
        self.index.project.components(self.file(), &self.index.current.parsed)
    }

    fn run(&self) -> Option<Symbol> {
        let t = self.text.as_bytes();
        // A tag or an attribute name can have `-` (aria-label); a PHP or Twig name cannot
        // (`$a->b`, `i-1`).
        let extend = |f: fn(u8) -> bool| self.offset + t[self.offset..].iter().take_while(|&&c| f(c)).count();
        let start = self.offset - t[..self.offset].iter().rev().take_while(|&&c| is_word(c) || matches!(c, b'$' | b'\\' | b'-')).count();
        let mut end = extend(|c| is_word(c) || c == b'-');
        if start == end {
            return None;
        }

        let mut context = scan(&self.text[..end]).context?;
        if matches!(context, Context::Php { .. } | Context::Twig(_)) {
            end = extend(is_word);
            context = scan(&self.text[..end]).context?;
        }

        match context {
            Context::TagName { partial, start } => self.tag(&partial, start, end),
            Context::CloseTag { partial: Some(partial), start, .. } => self.tag(&partial, start, end),
            Context::Attr { tag, partial, .. } => self.attr(&tag, &partial, end),
            Context::Twig(ctx) => self.twig(&ctx, end),
            Context::Php { string: false, comment: false } => self.php(end),
            _ => None,
        }
    }

    /// `<Name`, `<Name:slot`, `</Name`, `<div`.
    fn tag(&self, name: &str, start: usize, end: usize) -> Option<Symbol> {
        if name.starts_with(|c: char| c.is_ascii_lowercase()) && !name.contains(':') {
            let (doc, _) = data::element(name)?;
            return Some(Symbol { range: (start, end), location: None, hover: Some(format!("{}\n\n{doc}", code("html", &format!("<{name}>")))) });
        }

        let colon = name.find(':');
        let comp_name = colon.map_or(name, |c| &name[..c]);
        let components = self.components();
        let plain: Vec<_> = components.iter().map(|c| c.component.clone()).collect();
        let found = resolve_component(&self.index.current.parsed, &plain, comp_name)?;
        let comp = components.iter().find(|c| c.fqn == found.fqn)?;
        if let Some(colon) = colon.filter(|c| self.offset > start + c) {
            let slot = &name[colon + 1..];
            let location = self.prop_decl(comp, slot).and_then(|offset| self.location(comp.file.as_ref()?, offset));
            let hover = format!("{}\n\nSlot of `{}`{}", code("php", &format!("?Html ${slot}")), comp.fqn, self.defined_in(comp.file.as_deref()));
            return Some(Symbol { range: (start + colon + 1, end), location, hover: Some(hover) });
        }

        let range = (start, start + comp_name.len());

        // A tag of a replaced component renders the replacement, except in the replacement itself.
        let hover = match self.replacement(&components, comp) {
            Some(r) if !self.inside(r, &comp.fqn) => {
                let location = r.file.as_ref().zip(r.offset).and_then(|(f, o)| self.location(f, o));
                let hover = format!("{}\n\n---\n\nReplaces `{}`:\n\n{}", self.component_hover(r), comp.fqn, self.component_hover(comp));
                return Some(Symbol { range, location, hover: Some(hover) });
            }
            Some(r) => format!("{}\n\nReplaced by `{}` in the other tags", self.component_hover(comp), r.fqn),
            None => self.component_hover(comp),
        };

        let location = comp.file.as_ref().zip(comp.offset).and_then(|(f, o)| self.location(f, o));
        Some(Symbol { range, location, hover: Some(hover) })
    }

    /// The replacement of a component, with the rules of the compiler (E017): one replacement, in
    /// the app, of a component that is not a built-in, a page, a replacement or itself.
    fn replacement<'c>(&self, components: &'c [FileComponent], original: &FileComponent) -> Option<&'c FileComponent> {
        if original.file.is_none() || original.page || original.replaces.is_some() {
            return None;
        }

        let found: Vec<&FileComponent> = components
            .iter()
            .filter(|c| c.replaces.as_deref() == Some(original.fqn.as_str()) && c.fqn != original.fqn && !c.page)
            .filter(|c| c.file.as_deref().is_some_and(|f| self.index.project.package_file(f).is_none()))
            .collect();

        match found[..] {
            [r] => Some(r),
            _ => None,
        }
    }

    /// Whether the cursor is in the markup of a replacement: in its declaration, or in a parent
    /// class or a trait of it, up to the original (as in the compiler).
    fn inside(&self, replacement: &FileComponent, original: &str) -> bool {
        if replacement.file.as_deref() == Some(self.file()) && replacement.span.is_some_and(|(start, end)| (start..end).contains(&self.offset)) {
            return true;
        }

        let here = self.index.current.parsed.classes.iter().find(|c| (c.start..c.end).contains(&self.offset));
        here.is_some_and(|c| self.index.inherits(&replacement.fqn, &c.fqn, original))
    }

    fn component_hover(&self, comp: &FileComponent) -> String {
        let mut lines = vec![code("php", &comp.signature)];
        if let Some(doc) = comp.doc {
            lines.extend([String::new(), doc.to_string()]);
        }

        if comp.page {
            let routes: Vec<_> = self.index.project.routes(self.file(), &self.index.current.parsed).into_iter().filter(|r| r.fqn == comp.fqn).collect();
            let list = routes.iter().map(|r| format!("`{}` (`{}`)", r.path, r.name)).collect::<Vec<_>>().join(", ");
            lines.extend([String::new(), if list.is_empty() { "Page".into() } else { format!("Page: {list}") }]);
        }

        if !comp.slots.is_empty() {
            lines.extend([String::new(), format!("Slots: {}", comp.slots.iter().map(|s| format!("`{s}`")).collect::<Vec<_>>().join(", "))]);
        }

        lines.join("\n") + &self.defined_in(comp.file.as_deref())
    }

    /// The offset of the parameter or the property of a prop (or a slot) of a component.
    fn prop_decl(&self, comp: &FileComponent, name: &str) -> Option<usize> {
        let source = self.index.source(comp.file.as_ref()?)?;
        match comp.kind {
            ComponentKind::Class => {
                let cls = source.parsed.classes.iter().find(|c| c.fqn == comp.fqn)?;
                cls.properties.iter().find(|p| p.name == name).map(|p| p.offset)
            }
            ComponentKind::Function => {
                let f = source.parsed.functions.iter().find(|f| f.fqn == comp.fqn)?;
                f.params.iter().find(|p| p.name == name).map(|p| p.offset)
            }
        }
    }

    /// An attribute name: a prop of a component, or an HTML attribute.
    fn attr(&self, tag: &str, name: &str, end: usize) -> Option<Symbol> {
        let range = (end - name.len(), end);
        if tag.trim_start_matches('\\').starts_with(|c: char| c.is_ascii_uppercase()) && !tag.contains(':') {
            let components = self.components();
            let plain: Vec<_> = components.iter().map(|c| c.component.clone()).collect();
            let found = resolve_component(&self.index.current.parsed, &plain, tag)?;
            let comp = components.iter().find(|c| c.fqn == found.fqn)?;
            let prop = comp.props.iter().find(|p| p.name == name)?;
            let location = self.prop_decl(comp, name).and_then(|o| self.location(comp.file.as_ref()?, o));
            let text = format!(
                "{}${}{}",
                if prop.ty.is_empty() { String::new() } else { format!("{} ", prop.ty) },
                prop.name,
                prop.default.as_ref().map_or(String::new(), |d| format!(" = {d}"))
            );
            let hover = format!(
                "{}\n\nProp of `{}`{}{}",
                code("php", &text),
                comp.fqn,
                if prop.required { " (required)" } else { "" },
                self.defined_in(comp.file.as_deref())
            );
            return Some(Symbol { range, location, hover: Some(hover) });
        }

        if name.len() > 2 && name.starts_with("on") && name.as_bytes()[2].is_ascii_uppercase() {
            return Some(Symbol { range, location: None, hover: Some(format!("{}\n\n{EVENT_DOC}", code("html", name))) });
        }

        let doc = data::global_attribute(name).map(str::to_string).or_else(|| match name {
            "className" => Some("Alias for class".into()),
            "htmlFor" => Some("Alias for for".into()),
            _ => data::attributes_for(tag).contains(&name).then(|| format!("<{tag}> attribute")),
        })?;

        Some(Symbol { range, location: None, hover: Some(format!("{}\n\n{doc}", code("html", name))) })
    }

    fn twig(&self, ctx: &TwigContext, end: usize) -> Option<Symbol> {
        let t = self.text;
        if let Some(quote) = ctx.quote {
            // path('route_name')
            if !ROUTE_CALL.is_match(&t[ctx.start..quote]) {
                return None;
            }

            let q = t.as_bytes()[quote] as char;
            let close = t[quote + 1..].find(q).map_or(end, |i| quote + 1 + i);
            let name = &t[quote + 1..close];
            let route = self.index.project.routes(self.file(), &self.index.current.parsed).into_iter().find(|r| r.name == name)?;
            let hover = format!("{}\n\nThe route of `{}`{}", code("php", &format!("#[Page('{}')]", route.path)), route.fqn, self.defined_in(Some(&route.file)));
            return Some(Symbol { range: (quote + 1, quote + 1 + name.len()), location: self.location(&route.file, route.offset), hover: Some(hover) });
        }

        let expr = &t[ctx.start..end];
        let word = WORD_END.find(expr)?.as_str();
        let range = (end - word.len(), end);
        let head = &expr[..expr.len() - word.len()];

        if ctx.open == "{%" && TAG_HEAD.is_match(head) {
            let tag = data::tag(word);
            // Each end tag is `end` and the name of the tag that it closes.
            let opener = word.strip_prefix("end").filter(|o| tag.is_none() && !o.is_empty() && o.as_bytes()[0].is_ascii_alphanumeric());
            let doc = match (tag, opener) {
                (Some(t), _) => t.2.to_string(),
                (None, Some(o)) => format!("The end of the {{% {o} %}} block"),
                _ => return None,
            };

            return Some(Symbol { range, location: None, hover: Some(format!("{}\n\n{doc}", code("twig", &format!("{{% {word} %}}")))) });
        }

        if FILTER_HEAD.is_match(head) {
            let f = data::filter(word)?;
            return Some(Symbol { range, location: None, hover: Some(format!("{}\n\n{}", code("twig", &format!("|{}", f.1)), f.2)) });
        }

        if TEST_HEAD.is_match(head) {
            let test = data::test(word)?;
            return Some(Symbol { range, location: None, hover: Some(format!("{}\n\n{}", code("twig", &format!("is {word}")), test.2)) });
        }

        let env = TwigEnv::new(self.index, ctx.literal, ctx.start - 2);
        if let Some(m) = CHAIN_HEAD.captures(head).filter(|_| !RANGE_HEAD.is_match(head)) {
            let ty = env.type_of(&m[1])?;
            let attr = env.attribute(&ty, word)?;
            if attr.kind == AttrKind::Key {
                let key = attr.key?;
                let hover = format!("{}\n\n{}", code("twig", word), key.doc.unwrap_or("Array key"));
                return Some(Symbol { range, location: key.offset.and_then(|o| self.location(self.file(), o)), hover: Some(hover) });
            }

            return Some(self.member_result(&attr.member?, range));
        }

        let call = t[end..].trim_start().starts_with('(');
        let v = env.variable(word);
        if call && v.is_none() {
            if let Some(f) = data::function(word) {
                return Some(Symbol { range, location: None, hover: Some(format!("{}\n\n{}", code("twig", f.1), f.2)) });
            }
        }

        let Some(v) = v else {
            let f = data::function(word)?;
            return Some(Symbol { range, location: None, hover: Some(format!("{}\n\n{}", code("twig", f.1), f.2)) });
        };

        if let Some(m) = &v.member {
            return Some(self.member_result(m, range));
        }

        let kind = match &v.kind {
            VarSource::Twig(d) => d.to_string(),
            VarSource::Param => "Parameter".into(),
            VarSource::Local => "Local variable".into(),
            VarSource::Property => "property".into(),
            VarSource::Method => "method".into(),
        };

        let ty = if v.type_text.is_empty() { String::new() } else { format!("{} ", v.type_text) };
        Some(Symbol { range, location: v.offset.and_then(|o| self.location(&v.file, o)), hover: Some(format!("{}\n\n{kind}", code("php", &format!("{ty}${}", v.name)))) })
    }

    fn member_result(&self, m: &Member, range: (usize, usize)) -> Symbol {
        let doc = if m.doc.is_empty() { String::new() } else { format!("\n\n{}", m.doc) };
        let hover = format!("{}{doc}\n\n`{}`{}", code("php", m.signature.trim()), m.owner, self.defined_in(Some(&m.file)));
        Symbol { range, location: self.location(&m.file, m.offset), hover: Some(hover) }
    }

    fn class_result(&self, fqn: &str, range: (usize, usize)) -> Option<Symbol> {
        let found = self.index.find(SymbolKind::Class, fqn)?;
        let cls = found.class();
        let mut sig = format!("{} {}", cls.kind, cls.fqn);
        if !cls.extends.is_empty() {
            sig += &format!(" extends {}", cls.extends.join(", "));
        }

        if !cls.implements.is_empty() {
            sig += &format!(" implements {}", cls.implements.join(", "));
        }

        let summary = doc_tags(&cls.doc).summary;
        let summary = if summary.is_empty() { String::new() } else { format!("\n\n{summary}") };
        let hover = format!("{}{summary}{}", code("php", &sig), self.defined_in(Some(&found.source.file)));
        Some(Symbol { range, location: self.location(&found.source.file, cls.offset), hover: Some(hover) })
    }

    fn function_result(&self, found: &Found, range: (usize, usize)) -> Symbol {
        let f = found.function();
        let tags = doc_tags(&f.doc);
        let ret = if tags.ret.is_empty() { &f.return_type } else { &tags.ret };
        let ret = if ret.is_empty() { String::new() } else { format!(": {ret}") };
        let params = f.params.iter().map(param_text).collect::<Vec<_>>().join(", ");
        let summary = if tags.summary.is_empty() { String::new() } else { format!("\n\n{}", tags.summary) };
        let hover = format!("{}{summary}{}", code("php", &format!("function {}({params}){ret}", f.fqn)), self.defined_in(Some(&found.source.file)));
        Symbol { range, location: self.location(&found.source.file, f.offset), hover: Some(hover) }
    }

    fn php(&self, end: usize) -> Option<Symbol> {
        let masked = self.index.current.code();
        let start = end - masked[..end].iter().rev().take_while(|&&c| is_word(c) || c == b'\\').count();
        let is_var = start > 0 && masked[start - 1] == b'$';
        let name = &self.text[start..end];
        if name.is_empty() {
            return None;
        }

        let range = (if is_var { start - 1 } else { start }, end);
        let scope = Scope::at(self.index, start);
        let before = trim_end(&masked[..range.0]);
        let call = masked[end..].iter().find(|c| !c.is_ascii_whitespace()) == Some(&b'(');

        // $a->b, $a?->b, A::b, A::$b
        if before.ends_with(b"->") || before.ends_with(b"::") {
            let op_len = if before.ends_with(b"?->") { 3 } else { 2 };
            let op_start = before.len() - op_len;
            let is_static = before.ends_with(b"::");
            let expr_start = chain_start(masked, op_start);
            let expr = String::from_utf8_lossy(&masked[expr_start..op_start]).trim().to_string();
            if is_static && name.eq_ignore_ascii_case("class") {
                return None;
            }

            let simple = expr.trim_start_matches('\\').starts_with(|c: char| c.is_ascii_alphabetic() || c == '_')
                && expr.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '\\');
            let ty = if simple { scope.class_type(&expr) } else { scope.type_of_range(expr_start, op_start) };
            let fqn = ty?.fqn?;
            let kind = if is_var {
                MemberKind::Property
            } else if call {
                MemberKind::Method
            } else if is_static {
                MemberKind::Constant
            } else {
                MemberKind::Property
            };

            let m = self.index.member(&fqn, name, Some(kind))?;
            return Some(self.member_result(&m, range));
        }

        if is_var {
            if name == "this" {
                let cls = scope.self_.clone()?;
                return self.class_result(&cls, range);
            }

            let v = scope.var(name)?;
            let ty = if v.type_text.is_empty() { String::new() } else { format!("{} ", v.type_text) };
            let kind = if v.kind == crate::scope::VarKind::Param { "Parameter" } else { "Local variable" };
            return Some(Symbol { range, location: self.location(self.file(), v.offset), hover: Some(format!("{}\n\n{kind}", code("php", &format!("{ty}${}", v.name)))) });
        }

        // A name in a use statement: use Foo\Bar; use function Foo\bar;
        let stmt = self.index.current.parsed.uses.iter().find(|u| u.start <= start && u.end >= end);
        if let Some(stmt) = stmt {
            let bare = name.trim_start_matches('\\');
            let item = stmt.items.iter().find(|i| i.fqn.ends_with(bare) || i.alias == name)?;
            if item.kind == UseKind::Function {
                let found = self.index.find(SymbolKind::Function, &item.fqn)?;
                return Some(self.function_result(&found, range));
            }

            return self.class_result(&item.fqn, range);
        }

        if ["function", "class", "interface", "trait", "enum", "const", "namespace"].iter().any(|w| ends_with_word(before, w.as_bytes())) {
            return None;
        }

        if call && !ends_with_word(before, b"new") {
            let found = scope.find_function(name)?;
            return Some(self.function_result(&found, range));
        }

        if name.eq_ignore_ascii_case("self") || name.eq_ignore_ascii_case("static") {
            let cls = scope.self_.clone()?;
            return self.class_result(&cls, range);
        }

        if name.eq_ignore_ascii_case("parent") {
            let parent = scope.parent.clone()?;
            return self.class_result(&parent, range);
        }

        let fqn = resolve_name(&self.index.current.parsed, name)?;
        self.class_result(&fqn, range)
    }
}
