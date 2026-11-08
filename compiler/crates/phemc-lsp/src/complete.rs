//! The completion engine. It returns plain items; the server turns them into LSP items.

use crate::classes::{Index, Member, MemberKind};
use crate::data;
use crate::decl::{short_name, ComponentKind, Prop, UseKind};
use crate::imports::{import_for, resolve_component, Insert};
use crate::project::{FileComponent, Symbol, SymbolKind};
use crate::scanner::{scan, Context, TwigContext};
use crate::scope::{chain_start, Scope};
use crate::twig::{AttrKind, TwigEnv, VarSource};
use phemc_syntax::php::OPEN_TAG;
use regex::Regex;
use std::path::Path;
use std::sync::LazyLock;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ItemKind {
    Class,
    Function,
    Constant,
    Module,
    Property,
    Element,
    Method,
    Field,
    Variable,
    Keyword,
    Value,
    EnumMember,
}

#[derive(Clone, Debug)]
pub struct Item {
    pub label: String,
    pub kind: ItemKind,
    pub detail: String,
    pub documentation: Option<String>,
    /// The plain text to insert, when there is no snippet.
    pub insert: Option<String>,
    pub snippet: Option<String>,
    /// The byte range that the item replaces.
    pub range: (usize, usize),
    /// Other edits, for example a `use` import.
    pub edits: Vec<Insert>,
    pub sort_text: String,
    pub filter_text: Option<String>,
    /// Open the completion again after the item (a namespace part).
    pub retrigger: bool,
}

impl Item {
    fn new(label: impl Into<String>, kind: ItemKind, range: (usize, usize), sort_text: impl Into<String>) -> Self {
        Item {
            label: label.into(),
            kind,
            detail: String::new(),
            documentation: None,
            insert: None,
            snippet: None,
            range,
            edits: vec![],
            sort_text: sort_text.into(),
            filter_text: None,
            retrigger: false,
        }
    }

    fn detail(mut self, detail: impl Into<String>) -> Self {
        self.detail = detail.into();
        self
    }

    fn doc(mut self, doc: Option<String>) -> Self {
        self.documentation = doc;
        self
    }

    fn insert(mut self, text: impl Into<String>) -> Self {
        self.insert = Some(text.into());
        self
    }

    fn snippet(mut self, text: impl Into<String>) -> Self {
        self.snippet = Some(text.into());
        self
    }

    fn filter(mut self, text: impl Into<String>) -> Self {
        self.filter_text = Some(text.into());
        self
    }
}

const EVENTS: &[&str] = &["onClick", "onDoubleClick", "onChange", "onInput", "onSubmit", "onKeyDown", "onKeyUp", "onFocus", "onBlur"];

/// The Phem attributes of an HTML element: name, snippet, detail, documentation.
const PHEM_ATTRIBUTES: &[(&str, &str, &str, &str)] = &[
    ("phem:loading", "phem:loading={{ ${1} }}", "Phem: show while a handler runs", "The element shows only while an event of the handler runs: `<span phem:loading={{ save }}>Saving…</span>`."),
    ("phem:progress", "phem:progress={{ ${1} }}", "Phem: show the upload progress of a handler", "The element shows only while the files of an event of the handler upload. Its `--phem-progress` CSS property (and the `value` of a `<progress>`) is the progress: `<progress phem:progress={{ save }} max=\"100\"></progress>`."),
    ("phem:debounce", "phem:debounce={{ ${1:300} }}", "Phem: wait before an event is sent", "Milliseconds: the browser sends an event of the element only when no new event comes for that time. `'blur'`: send on blur only. `0`: no debounce (text inputs have 150 ms by default)."),
    ("phem:bind", "phem:bind={{ ${1} }}", "Phem: bind a field to a #[State] property", "The field shows the property, and the next event sends its new value: `<input phem:bind={{ query }} />`."),
    ERROR_ATTRIBUTE,
    ("phem:live", "phem:live", "Phem: send each change of a bound field", "With `phem:bind`: each change sends an event with no handler, with the debounce of the field."),
    ("phem:throttle", "phem:throttle={{ ${1:300} }}", "Phem: send at most one event per interval", "Milliseconds: the browser sends the first event at once, then at most one event each interval, and always the last one."),
];

/// `phem:error`: on an HTML element, and on the tag of a component with an `id` prop.
const ERROR_ATTRIBUTE: (&str, &str, &str, &str) = (
    "phem:error",
    "phem:error={{ ${1} }}",
    "Phem: show the validation error of a bound property",
    "The element renders only when the property has a validation error, with the id of the error and its message: `<p phem:error={{ email }} />`. On a component, it gives the `id` and the `children` props.",
);

/// Escapes text for a snippet.
pub fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        if matches!(c, '\\' | '$' | '}') {
            out.push('\\');
        }

        out.push(c);
    }

    out
}

fn is_word_byte(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_'
}

/// The length of the word that starts at `offset`.
fn word_after(text: &str, offset: usize) -> usize {
    text.as_bytes()[offset.min(text.len())..].iter().take_while(|&&c| is_word_byte(c)).count()
}

fn prop_snippet(prop: &Prop, n: usize) -> String {
    let t = prop.ty.strip_prefix('?').unwrap_or(&prop.ty);
    if t.eq_ignore_ascii_case("string") {
        format!("{}=\"${{{n}}}\"", esc(&prop.name))
    } else {
        format!("{}={{{{ ${{{n}}} }}}}", esc(&prop.name))
    }
}

/// The path of a file, relative to the project root.
pub fn rel(root: &Path, file: &Path) -> String {
    file.strip_prefix(root).unwrap_or(file).display().to_string()
}

/// The documentation of a member: its signature and its summary.
pub fn member_doc(m: &Member, root: &Path) -> String {
    let mut lines = vec!["```php".to_string(), m.signature.trim().to_string(), "```".to_string()];
    if !m.doc.is_empty() {
        lines.extend([String::new(), m.doc.clone()]);
    }

    lines.extend([String::new(), format!("Defined in `{}`", rel(root, &m.file))]);

    lines.join("\n")
}

fn component_doc(comp: &FileComponent, root: &Path) -> String {
    let mut lines = vec!["```php".to_string(), comp.signature.clone(), "```".to_string()];
    if let Some(doc) = comp.doc {
        lines.extend([String::new(), doc.to_string()]);
    }

    if let Some(file) = &comp.file {
        lines.extend([String::new(), format!("Defined in `{}`", rel(root, file))]);
    }

    if !comp.slots.is_empty() {
        lines.extend([String::new(), format!("Slots: {}", comp.slots.iter().map(|s| format!("`{s}`")).collect::<Vec<_>>().join(", "))]);
    }

    lines.join("\n")
}

macro_rules! re {
    ($name:ident, $re:expr) => {
        static $name: LazyLock<Regex> = LazyLock::new(|| Regex::new($re).unwrap());
    };
}

re!(NAME_ONLY, r"^[\w:\\.\-]*([ \t]+[\w{]|\s*/?>)");
re!(USE_PART, r"(?i)^\s*use\s+(?:(function|const)\s+)?\\?((?:\w+\\)*)(\w*)$");
re!(USE_KIND_ONLY, r"(?i)^\s*use\s+(function|const)$");
re!(USE_GROUP, r"(?i)^\s*use\s+(?:(function|const)\s+)?\\?((?:\w+\\)+)\{(?:[^}]*,)?\s*(?:(function|const)\s+)?((?:\w+\\)*)(\w*)$");
re!(MEMBER_ACCESS, r"(->|\?->|::)\s*(\$?\w*)$");
re!(VARIABLE, r"(?:^|[^\w\\$])\$(\w*)$");
re!(QUALIFIED, r"(?:^|[^\w\\$])\\((?:\w+\\)*)(\w*)$");
re!(NAME, r"(?:^|[^\w\\$])([A-Za-z_]\w*)$");
re!(AFTER_ACCESS, r"(->|::|\?->)\s*$");
re!(DECLARING_WORDS, r"(?i)\b(namespace|function|class|interface|trait|enum|const|fn|as|goto|case)\s+$");
re!(USE_START, r"(?i)^\s*use\s");
re!(CLASS_NAME, r"^\\?[A-Za-z_][\w\\]*$");
re!(DEBOUNCE_VALUE, r#"^\s*(['"]?)(\w*)$"#);
re!(TWIG_TAG_START, r"^[-~]?\s*(\w*)$");
re!(TWIG_FILTER, r"\|\s*\w*$");
re!(TWIG_TEST, r"(?:^|[^\w.])is\s+(?:not\s+)?\w*$");
re!(TWIG_DOT, r"([A-Za-z_][\w.]*(?:\[[^\]]*\])*(?:\s*\|\s*\w+)*)\s*\.\s*\w*$");
re!(TWIG_RANGE, r"\.\.\s*\w*$");
re!(TWIG_NEW_VAR, r"^[-~]?\s*(for\s+(\w+\s*,\s*)?|set\s+)\w*$");
re!(TWIG_AFTER_VALUE, r"[^\w\s(\[{,:?~+\-*/%<>=!|]\s*\w*$");
re!(TWIG_CLOSE, r"^[ \t]*([-~]?)%\}|^\}");
re!(ROUTE_CALL, r"\b(path|url)\s*\(\s*$");

pub struct Completer<'a, 'p> {
    index: &'a Index<'p>,
    text: &'a str,
    offset: usize,
    trigger: Option<char>,
}

/// The completion items at `offset` of the current source of `index`. `trigger` is the character
/// that started the completion, if any.
pub fn complete(index: &Index, offset: usize, trigger: Option<char>) -> Vec<Item> {
    let text = index.current.text.as_str();
    let mut offset = offset.min(text.len());
    while !text.is_char_boundary(offset) {
        offset -= 1;
    }

    Completer { index, text, offset, trigger }.run()
}

impl<'a, 'p> Completer<'a, 'p> {
    fn root(&self) -> &Path {
        self.index.root()
    }

    fn file(&self) -> &Path {
        &self.index.current.file
    }

    fn after_word(&self) -> usize {
        self.offset + word_after(self.text, self.offset)
    }

    /// The start and the end of the word at the cursor, with the word characters of `chars`.
    fn word(&self, chars: impl Fn(u8) -> bool) -> (usize, usize) {
        let b = self.text.as_bytes();
        let before = b[..self.offset].iter().rev().take_while(|&&c| chars(c)).count();
        let after = b[self.offset..].iter().take_while(|&&c| chars(c)).count();
        (self.offset - before, self.offset + after)
    }

    fn components(&self) -> Vec<FileComponent> {
        self.index.project.components(self.file(), &self.index.current.parsed)
    }

    fn symbols(&self, kind: SymbolKind) -> Vec<Symbol> {
        self.index.project.all_symbols(kind, self.file(), &self.index.current.parsed)
    }

    /// `0`: no import needed, `1`: a project symbol, `2`: a vendor symbol.
    fn rank(&self, needs_import: bool, file: Option<&Path>) -> char {
        if !needs_import {
            return '0';
        }

        match file.and_then(|f| f.strip_prefix(self.root()).ok()) {
            Some(r) if !r.starts_with("vendor") => '1',
            _ => '2',
        }
    }

    /// A trigger character is also an operator. It opens the list only in its own context: `->`,
    /// `{%`, `</`, `<Tag`, and `$` in PHP.
    fn trigger_fits(&self, context: &Context) -> bool {
        let mut from = self.offset.saturating_sub(2);
        while !self.text.is_char_boundary(from) {
            from -= 1;
        }

        let before = &self.text[from..self.offset];
        match self.trigger {
            Some('>') => before.ends_with("->"),
            Some('%') => before.ends_with("{%"),
            Some('/') => matches!(context, Context::CloseTag { .. }),
            Some('<') => matches!(context, Context::TagName { .. }),
            Some('$') => matches!(context, Context::Php { .. }),
            _ => true,
        }
    }

    fn run(&self) -> Vec<Item> {
        let Some(context) = scan(&self.text[..self.offset]).context else { return vec![] };
        if !self.trigger_fits(&context) {
            return vec![];
        }

        match &context {
            Context::TagName { partial, start } if partial.contains(':') => self.slots(partial, *start),
            Context::TagName { partial, start } => {
                let mut items = self.tags(*start);
                items.extend(self.elements(partial, *start));
                items
            }
            Context::CloseTag { partial: Some(_), start, expected: Some(expected) } => self.close_tag(*start, expected),
            Context::Attr { tag, attrs, partial } => self.attrs(tag, attrs, partial),
            Context::AttrValue { tag, attr: Some(attr), quote } => self.attr_values(tag, attr, *quote),
            Context::Twig(ctx) => self.twig(ctx),
            Context::Php { string: false, comment: false } => self.php(),
            _ => vec![],
        }
    }

    fn name_only(&self, end: usize) -> bool {
        NAME_ONLY.is_match(&self.text[end..])
    }

    /// `<Name`: the component tags.
    fn tags(&self, start: usize) -> Vec<Item> {
        let end = self.after_word();
        // The tag has attributes or an end already: complete only the name.
        let name_only = self.name_only(end);
        let components = self.components();
        let parsed = &self.index.current.parsed;
        let plain: Vec<_> = components.iter().map(|c| c.component.clone()).collect();

        let mut items = Vec::new();
        let mut seen: Vec<&str> = Vec::new();
        for comp in &components {
            // A page is not a tag (E056).
            if seen.contains(&comp.fqn.as_str()) || comp.page {
                continue;
            }

            seen.push(&comp.fqn);

            let mut name = comp.name.clone();
            let mut edits = vec![];
            if resolve_component(parsed, &plain, &comp.name).is_none_or(|r| r.fqn != comp.fqn) {
                let kind = if comp.kind == ComponentKind::Class { UseKind::Class } else { UseKind::Function };
                let imp = import_for(self.text, parsed, kind, &comp.fqn);
                name = imp.name;
                edits = imp.edit.into_iter().collect();
            }

            let mut snippet = esc(&name);
            if !name_only {
                for (n, prop) in comp.props.iter().filter(|p| p.required).enumerate() {
                    snippet += &format!(" {}", prop_snippet(prop, n + 1));
                }
                snippet += &if comp.children || !comp.slots.is_empty() { format!(">$0</{}>", esc(&name)) } else { " />$0".to_string() };
            }

            let kind = if comp.kind == ComponentKind::Class { ItemKind::Class } else { ItemKind::Function };
            let sort = format!("{}{name}", self.rank(!edits.is_empty(), comp.file.as_deref()));
            let mut item = Item::new(name, kind, (start, end), sort)
                .detail(comp.fqn.clone())
                .doc(Some(component_doc(comp, self.root())))
                .snippet(snippet);
            item.edits = edits;
            items.push(item);
        }

        items
    }

    /// `<name`: the HTML elements.
    fn elements(&self, partial: &str, start: usize) -> Vec<Item> {
        if partial.starts_with(|c: char| c.is_ascii_uppercase() || c == '\\') {
            return vec![];
        }

        let end = self.after_word();
        let name_only = self.name_only(end);

        data::ELEMENTS
            .iter()
            .map(|(name, description, is_void)| {
                let snippet = if name_only {
                    name.to_string()
                } else if *is_void {
                    format!("{name}$1 />$0")
                } else {
                    format!("{name}$1>$0</{name}>")
                };

                Item::new(*name, ItemKind::Element, (start, end), format!("5{name}")).detail(*description).snippet(snippet)
            })
            .collect()
    }

    /// `<Name:slot`: the named slots.
    fn slots(&self, partial: &str, start: usize) -> Vec<Item> {
        let parent = &partial[..partial.find(':').unwrap()];
        let components: Vec<_> = self.components().into_iter().map(|c| c.component).collect();
        let Some(comp) = resolve_component(&self.index.current.parsed, &components, parent) else { return vec![] };
        let end = self.after_word();
        let name_only = Regex::new(r"^\s*/?>").unwrap().is_match(&self.text[end..]);

        comp.slots
            .iter()
            .map(|slot| {
                let tag = format!("{parent}:{slot}");
                let snippet = if name_only { esc(&tag) } else { format!("{0}>$0</{0}>", esc(&tag)) };
                Item::new(tag, ItemKind::Property, (start, end), format!("0{slot}")).detail(format!("slot of {}", comp.fqn)).snippet(snippet)
            })
            .collect()
    }

    /// `</`: the name of the open element.
    fn close_tag(&self, start: usize, expected: &str) -> Vec<Item> {
        let end = self.after_word();
        let close = if self.text[end..].trim_start().starts_with('>') { "" } else { ">" };
        vec![Item::new(expected, ItemKind::Property, (start, end), "0").detail("Close the element").insert(format!("{expected}{close}"))]
    }

    /// The props of a component, or the attributes of an HTML element.
    fn attrs(&self, tag: &str, present: &[String], partial: &str) -> Vec<Item> {
        let range = (self.offset - partial.len(), self.after_word());
        let mut items: Vec<Item> = Vec::new();
        let mut add = |name: &str, snippet: String, detail: &str, sort: String, doc: Option<&str>| {
            if !present.iter().any(|p| p == name) {
                items.push(Item::new(name, ItemKind::Property, range, sort).detail(detail).snippet(snippet).doc(doc.map(str::to_string)));
            }
        };

        let is_component = tag.trim_start_matches('\\').starts_with(|c: char| c.is_ascii_uppercase());
        if is_component && !tag.contains(':') {
            let components: Vec<_> = self.components().into_iter().map(|c| c.component).collect();
            let mut has_id = false;
            if let Some(comp) = resolve_component(&self.index.current.parsed, &components, tag) {
                has_id = comp.props.iter().any(|p| p.name == "id");
                for prop in &comp.props {
                    let detail = format!(
                        "{}${}{}{}",
                        if prop.ty.is_empty() { String::new() } else { format!("{} ", prop.ty) },
                        prop.name,
                        prop.default.as_ref().map_or(String::new(), |d| format!(" = {d}")),
                        if prop.required { " (required)" } else { "" }
                    );
                    add(&prop.name, prop_snippet(prop, 1), &detail, format!("{}{}", if prop.required { '0' } else { '1' }, prop.name), None);
                }
            }

            add("key", "key={{ ${1} }}".into(), "The key of the item in a list", "2key".into(), None);
            // `phem:error` gives the `id` of the error: a component of the message has the prop.
            if has_id {
                let (name, snippet, detail, doc) = ERROR_ATTRIBUTE;
                add(name, snippet.to_string(), detail, format!("2{name}"), Some(doc));
            }

            return items;
        }

        if !tag.starts_with(|c: char| c.is_ascii_lowercase()) {
            return items;
        }

        for event in EVENTS {
            add(event, format!("{event}={{{{ ${{1}} }}}}"), "Phem event: the handler runs on the server", format!("1{event}"), None);
        }

        for (name, snippet, detail, doc) in PHEM_ATTRIBUTES {
            add(name, snippet.to_string(), detail, format!("1{name}"), Some(doc));
        }

        add("className", "className=\"${1}\"".into(), "Alias for class", "0className".into(), None);
        if tag == "label" {
            add("htmlFor", "htmlFor=\"${1}\"".into(), "Alias for for", "0htmlFor".into(), None);
        }

        add("key", "key={{ ${1} }}".into(), "The key of the item in a list", "2key".into(), None);

        let attr_snippet = |name: &str| if data::BOOLEAN.contains(&name) { name.to_string() } else { format!("{name}=\"${{1}}\"") };
        for name in data::attributes_for(tag) {
            add(name, attr_snippet(name), &format!("<{tag}> attribute"), format!("0{name}"), None);
        }

        for (name, description) in data::GLOBAL_ATTRIBUTES {
            match *name {
                "data-" => add(name, "data-${1:name}=\"${2}\"".into(), description, format!("3{name}"), None),
                "style" => add(name, "style={{ {${1}} }}".into(), description, format!("2{name}"), None),
                _ => add(name, attr_snippet(name), description, format!("2{name}"), None),
            }
        }

        for name in data::ARIA_ATTRIBUTES {
            add(name, format!("{name}=\"${{1}}\""), "ARIA attribute", format!("4{name}"), None);
        }

        items
    }

    /// `attr="...`: the values of an enumerated HTML attribute.
    fn attr_values(&self, tag: &str, attr: &str, quote: usize) -> Vec<Item> {
        if !tag.starts_with(|c: char| c.is_ascii_lowercase()) || self.text[quote + 1..self.offset].contains(['{', '}']) {
            return vec![];
        }

        let (start, end) = self.word(|c| is_word_byte(c) || matches!(c, b'+' | b'/' | b'.' | b'-'));
        data::values_for(tag, attr)
            .iter()
            .enumerate()
            .map(|(i, value)| Item::new(*value, ItemKind::Value, (start.max(quote + 1), end), format!("{i:03}")).detail(format!("{attr} value")).insert(*value))
            .collect()
    }

    fn php(&self) -> Vec<Item> {
        let before = &self.text[..self.offset];
        // The text of the current statement, up to the cursor.
        let open = before.rfind(OPEN_TAG).map_or(0, |i| i + OPEN_TAG.len());
        let stmt_start = [before.rfind(';').map_or(0, |i| i + 1), before.rfind('}').map_or(0, |i| i + 1), open].into_iter().max().unwrap();
        let stmt = &before[stmt_start..];

        // use Foo\Bar; use function Foo\{bar, baz};
        if let Some(m) = USE_PART.captures(stmt) {
            if !USE_KIND_ONLY.is_match(stmt.trim_end()) {
                let kind = use_kind(m.get(1).map(|k| k.as_str()));
                return self.namespace_items(kind, &m[2], &m[3], true, "");
            }
        }

        if let Some(m) = USE_GROUP.captures(stmt) {
            let kind = use_kind(m.get(3).or(m.get(1)).map(|k| k.as_str()));
            return self.namespace_items(kind, &format!("{}{}", &m[2], &m[4]), &m[5], false, &m[2]);
        }

        let line = &before[before.rfind('\n').map_or(0, |i| i + 1)..];

        // $a->b, $a?->b, Foo::b
        if let Some(m) = MEMBER_ACCESS.captures(line) {
            return self.members(&m[1], self.offset - m[0].len(), &m[2]);
        }

        // $name: a variable.
        if let Some(m) = VARIABLE.captures(line) {
            return self.variables(&m[1]);
        }

        // \Foo\Bar: a fully qualified name.
        if let Some(m) = QUALIFIED.captures(line) {
            let mut items = self.namespace_items(SymbolKind::Class, &m[1], &m[2], false, "");
            items.extend(self.namespace_items(SymbolKind::Function, &m[1], &m[2], false, "").into_iter().filter(|i| i.kind != ItemKind::Module));
            return items;
        }

        // Foo: a class or a function, with an import on accept.
        let Some(m) = NAME.captures(line) else { return vec![] };
        let head = &line[..line.len() - m[1].len()];
        if AFTER_ACCESS.is_match(head) || DECLARING_WORDS.is_match(head) || USE_START.is_match(stmt) {
            return vec![];
        }

        self.name_items(m[1].len())
    }

    /// `$x->`, `Foo::`: the members of the type of the expression before `op`.
    fn members(&self, op: &str, op_start: usize, typed: &str) -> Vec<Item> {
        let code = self.index.current.code();
        let start = chain_start(code, op_start);
        let expr = String::from_utf8_lossy(&code[start..op_start]).trim().to_string();
        let scope = Scope::at(self.index, self.offset);
        let ty = if CLASS_NAME.is_match(&expr) { scope.class_type(&expr) } else { scope.type_of_range(start, op_start) };
        let Some(fqn) = ty.and_then(|t| t.fqn) else { return vec![] };

        let is_static = op == "::";
        let is_parent = expr.eq_ignore_ascii_case("parent");
        let is_self = ["self", "static", "parent", "$this"].iter().any(|w| expr.eq_ignore_ascii_case(w));
        let from = scope.self_.clone();
        let range = (self.offset - typed.len(), self.after_word());

        let mut items = Vec::new();
        for m in self.index.members(&fqn).iter() {
            if m.visibility == "private" && from.as_deref() != Some(m.owner.as_str()) {
                continue;
            }

            if m.visibility == "protected" && !from.as_deref().is_some_and(|f| is_self || self.index.is_a(f, &m.owner)) {
                continue;
            }

            if m.kind == MemberKind::Method && m.name.starts_with("__") && !(is_parent && m.name.eq_ignore_ascii_case("__construct")) {
                continue;
            }

            let doc = Some(member_doc(m, self.root()));
            if is_static {
                match m.kind {
                    MemberKind::Property if m.is_static => {
                        let label = format!("${}", m.name);
                        items.push(Item::new(label.clone(), ItemKind::Field, range, format!("1{}", m.name)).detail(&m.type_text).doc(doc).insert(label));
                    }
                    MemberKind::Constant => {
                        let kind = if m.enum_case { ItemKind::EnumMember } else { ItemKind::Constant };
                        items.push(Item::new(&m.name, kind, range, format!("0{}", m.name)).detail(&m.signature).doc(doc).insert(&m.name));
                    }
                    MemberKind::Method if m.is_static || is_parent || is_self => items.push(self.method_item(m, doc, range)),
                    _ => {}
                }
            } else if m.kind == MemberKind::Property && !m.is_static {
                items.push(Item::new(&m.name, ItemKind::Field, range, format!("0{}", m.name)).detail(&m.type_text).doc(doc).insert(&m.name));
            } else if m.kind == MemberKind::Method && !m.is_static {
                items.push(self.method_item(m, doc, range));
            }
        }

        if is_static && !typed.starts_with('$') {
            items.push(Item::new("class", ItemKind::Keyword, range, "2class").detail("The fully qualified class name").insert("class"));
        }

        items
    }

    fn method_item(&self, m: &Member, doc: Option<String>, range: (usize, usize)) -> Item {
        let call = self.text[range.1..].starts_with('(');
        let snippet = if call {
            m.name.clone()
        } else if m.has_params() {
            format!("{}($1)$0", m.name)
        } else {
            format!("{}()$0", m.name)
        };

        let ret = if m.type_text.is_empty() { String::new() } else { format!(": {}", m.type_text) };
        Item::new(&m.name, ItemKind::Method, range, format!("1{}", m.name)).detail(format!("({}){ret}", m.params_signature())).doc(doc).snippet(snippet)
    }

    /// `$name`: the variables of the scope.
    fn variables(&self, typed: &str) -> Vec<Item> {
        let scope = Scope::at(self.index, self.offset);
        let start = self.offset - typed.len() - 1;
        let range = (start, self.after_word());

        let mut items = Vec::new();
        for v in scope.vars.iter().filter(|v| v.offset != start) {
            let label = format!("${}", v.name);
            items.push(Item::new(&label, ItemKind::Variable, range, format!("0{}", v.name)).detail(&v.type_text).insert(&label).filter(&label));
        }

        if scope.this_type.is_some() {
            let name = scope.class.map_or(String::new(), |c| scope.source.parsed.classes[c].name.clone());
            items.push(Item::new("$this", ItemKind::Variable, range, "1this").detail(name).insert("$this").filter("$this"));
        }

        items
    }

    /// Twig code: tags, filters, tests, attributes, variables, and route names.
    fn twig(&self, ctx: &TwigContext) -> Vec<Item> {
        if ctx.attr.as_deref() == Some("phem:debounce") {
            let blur = self.debounce_blur(ctx);
            if ctx.in_string() {
                return blur;
            }

            if !blur.is_empty() {
                let mut items = blur;
                items.extend(self.twig_items(ctx));
                return items;
            }
        }

        if ctx.in_string() {
            return self.route_items(ctx);
        }

        self.twig_items(ctx)
    }

    /// `phem:debounce={{ 'blur' }}`: send the event on blur only.
    fn debounce_blur(&self, ctx: &TwigContext) -> Vec<Item> {
        let expr = &self.text[ctx.start..self.offset];
        let Some(m) = DEBOUNCE_VALUE.captures(expr) else { return vec![] };
        let quote = &m[1];
        let start = self.offset - quote.len() - m[2].len();
        let end = self.after_word();
        let close = usize::from(!quote.is_empty() && self.text[end..].starts_with(quote));

        vec![Item::new("'blur'", ItemKind::Value, (start, end + close), "0blur")
            .detail("phem:debounce value")
            .doc(Some("Send the event when the element loses the focus.".into()))
            .insert("'blur'")
            .filter(format!("{quote}blur"))]
    }

    fn twig_items(&self, ctx: &TwigContext) -> Vec<Item> {
        let expr = &self.text[ctx.start..self.offset];
        let tag_start = ctx.start - 2;
        if ctx.open == "{%" {
            if let Some(m) = TWIG_TAG_START.captures(expr) {
                let env = TwigEnv::new(self.index, ctx.literal, tag_start);
                return self.twig_tags(&m[1], &env);
            }
        }

        let range = self.word(is_word_byte);
        let item = |label: &str, kind, detail: &str, doc: Option<String>, sort: String| Item::new(label, kind, range, sort).detail(detail).doc(doc);

        if TWIG_FILTER.is_match(expr) {
            return data::FILTERS.iter().map(|(name, sig, doc)| item(name, ItemKind::Function, sig, Some(doc.to_string()), format!("0{name}")).insert(*name)).collect();
        }

        if TWIG_TEST.is_match(expr) {
            return data::TESTS.iter().map(|(name, snippet, doc)| item(name, ItemKind::Function, "test", Some(doc.to_string()), format!("0{name}")).snippet(*snippet)).collect();
        }

        let env = TwigEnv::new(self.index, ctx.literal, tag_start);
        if let Some(m) = TWIG_DOT.captures(expr).filter(|_| !TWIG_RANGE.is_match(expr)) {
            let Some(ty) = env.type_of(&m[1]) else { return vec![] };
            return env
                .attributes(&ty)
                .into_iter()
                .map(|a| {
                    let doc = a.member.as_ref().map(|m| member_doc(m, self.root()));
                    let detail = match (&a.kind, &a.member) {
                        (AttrKind::Key, _) => a.key.as_ref().and_then(|k| k.doc).unwrap_or("key").to_string(),
                        (_, Some(m)) if a.getter => format!("{}(){}", m.name, if m.type_text.is_empty() { String::new() } else { format!(": {}", m.type_text) }),
                        (_, Some(m)) => m.type_text.clone(),
                        _ => String::new(),
                    };

                    if a.kind == AttrKind::Method {
                        let snippet = if a.required > 0 { format!("{}($1)$0", a.name) } else { a.name.clone() };
                        item(&a.name, ItemKind::Method, &detail, doc, format!("1{}", a.name)).snippet(snippet)
                    } else {
                        item(&a.name, ItemKind::Field, &detail, doc, format!("0{}", a.name)).insert(&a.name)
                    }
                })
                .collect();
        }

        // A new variable name: {% for x in, {% set x
        if ctx.open == "{%" && TWIG_NEW_VAR.is_match(expr) {
            return vec![];
        }

        let trimmed = expr.trim_start();
        let trimmed = trimmed.strip_prefix(['-', '~']).unwrap_or(trimmed);
        if TWIG_AFTER_VALUE.is_match(&format!(" {trimmed}")) {
            return vec![];
        }

        let mut items = Vec::new();
        for v in env.variables() {
            let kind = match v.kind {
                VarSource::Method => ItemKind::Method,
                VarSource::Property => ItemKind::Field,
                _ => ItemKind::Variable,
            };

            let detail = match &v.kind {
                VarSource::Method => format!("handler{}", if v.type_text.is_empty() { String::new() } else { format!(": {}", v.type_text) }),
                VarSource::Twig(d) => d.to_string(),
                VarSource::Param if v.type_text.is_empty() => "param".into(),
                VarSource::Local if v.type_text.is_empty() => "local".into(),
                VarSource::Property if v.type_text.is_empty() => "property".into(),
                _ => v.type_text.clone(),
            };

            let rank = match v.kind {
                VarSource::Twig(_) => '0',
                VarSource::Method => '2',
                _ => '1',
            };

            let doc = v.member.as_ref().map(|m| member_doc(m, self.root()));
            items.push(item(&v.name, kind, &detail, doc, format!("{rank}{}", v.name)).insert(&v.name));
        }

        for (name, sig, doc) in data::FUNCTIONS {
            items.push(item(name, ItemKind::Function, sig, Some(doc.to_string()), format!("3{name}")).snippet(format!("{name}($1)$0")));
        }

        for word in data::KEYWORDS {
            items.push(item(word, ItemKind::Keyword, "Twig", None, format!("4{word}")).insert(*word));
        }

        items
    }

    /// `{% name`: the tags, and the end tags of the open blocks first. An item writes the full tag,
    /// with ` %}`. It replaces the ` %}` that follows on the same line, or the `}` that the editor
    /// adds when it closes the `{`.
    fn twig_tags(&self, typed: &str, env: &TwigEnv) -> Vec<Item> {
        let start = self.offset - typed.len();
        let word_end = self.after_word();
        let close = TWIG_CLOSE.captures(&self.text[word_end..]);
        let end = close.as_ref().map_or(word_end, |c| word_end + c[0].len());
        let tail = format!(" {}%}}", close.as_ref().and_then(|c| c.get(1)).map_or("", |m| m.as_str()));

        let mut items: Vec<Item> = Vec::new();
        let mut add = |label: &str, detail: String, doc: Option<&str>, snippet: String, sort: String| {
            if !items.iter().any(|i| i.label == label) {
                items.push(Item::new(label, ItemKind::Keyword, (start, end), sort).detail(detail).doc(doc.map(str::to_string)).snippet(snippet).filter(label));
            }
        };

        for (i, tag) in env.open_tags().iter().enumerate() {
            let end_tag = format!("end{tag}");
            add(&end_tag, format!("Close the {{% {tag} %}} block"), None, format!("{end_tag}{tail}"), format!("0{i}"));
            if tag == "if" || tag == "for" {
                add("else", data::tag("else").unwrap().2.to_string(), None, format!("else{tail}"), "1else".into());
            }

            if tag == "if" {
                add("elseif", data::tag("elseif").unwrap().2.to_string(), None, format!("elseif ${{1}}{tail}"), "1elseif".into());
            }
        }

        for (name, snippet, doc, end_tag) in data::TAGS {
            if *name == "else" || *name == "elseif" {
                continue;
            }

            let detail = match end_tag {
                Some(e) => format!("{{% {name} %}} ... {{% {e} %}}"),
                None => format!("{{% {name} %}}"),
            };

            add(name, detail, Some(doc), format!("{snippet} %}}"), format!("2{name}"));
        }

        items
    }

    /// `path('...` and `url('...`: the route names of the pages.
    fn route_items(&self, ctx: &TwigContext) -> Vec<Item> {
        let quote = ctx.quote.unwrap();
        if !ROUTE_CALL.is_match(&self.text[ctx.start..quote]) {
            return vec![];
        }

        let (start, end) = self.word(|c| is_word_byte(c) || c == b'.' || c == b'-');
        self.index
            .project
            .routes(self.file(), &self.index.current.parsed)
            .into_iter()
            .map(|r| {
                let doc = format!("`{}`\n\nDefined in `{}`", r.fqn, rel(self.root(), &r.file));
                Item::new(&r.name, ItemKind::Value, (start.max(quote + 1), end), format!("0{}", r.name)).detail(&r.path).doc(Some(doc)).insert(&r.name)
            })
            .collect()
    }

    /// The items of one level of a namespace: the sub-namespaces and the symbols.
    fn namespace_items(&self, kind: SymbolKind, ns_typed: &str, segment: &str, in_use: bool, group_prefix: &str) -> Vec<Item> {
        let start = self.offset - segment.len();
        let end = self.after_word();
        let prefix = ns_typed.to_lowercase();
        let line_end = self.text[end..].find('\n').map_or(self.text.len(), |i| end + i);
        let semicolon = if in_use && self.text[end..line_end].trim().is_empty() { ";" } else { "" };

        let mut namespaces: Vec<(String, String)> = Vec::new();
        let mut items = Vec::new();
        let item_kind = match kind {
            SymbolKind::Class => ItemKind::Class,
            SymbolKind::Function => ItemKind::Function,
            SymbolKind::Const => ItemKind::Constant,
        };

        for sym in self.symbols(kind) {
            if !sym.fqn.to_lowercase().starts_with(&prefix) || sym.fqn.len() < ns_typed.len() {
                continue;
            }

            let rest = &sym.fqn[ns_typed.len()..];
            if let Some(sep) = rest.find('\\') {
                let ns = &rest[..sep];
                if !namespaces.iter().any(|(n, _)| n == ns) {
                    namespaces.push((ns.to_string(), sym.fqn[..ns_typed.len() + sep].to_string()));
                }

                continue;
            }

            if rest.is_empty() {
                continue;
            }

            let detail = sym.fqn.strip_prefix(group_prefix).unwrap_or(&sym.fqn);
            items.push(Item::new(rest, item_kind, (start, end), format!("1{rest}")).detail(detail).insert(format!("{rest}{semicolon}")));
        }

        for (ns, fqn) in namespaces {
            let mut item = Item::new(&ns, ItemKind::Module, (start, end), format!("0{ns}")).detail(fqn).insert(format!("{ns}\\"));
            item.retrigger = true;
            items.push(item);
        }

        items
    }

    /// The classes and the functions by short name, with an import.
    fn name_items(&self, typed: usize) -> Vec<Item> {
        let range = (self.offset - typed, self.after_word());
        let parsed = &self.index.current.parsed;
        let mut items = Vec::new();
        let mut add = |kind: SymbolKind, sym: &Symbol| {
            let use_kind = if kind == SymbolKind::Class { UseKind::Class } else { UseKind::Function };
            let imp = import_for(self.text, parsed, use_kind, &sym.fqn);
            let short = short_name(&sym.fqn);
            let sort = format!("{}{short}", self.rank(imp.edit.is_some() || imp.qualified, Some(&sym.file)));
            let item_kind = if kind == SymbolKind::Class { ItemKind::Class } else { ItemKind::Function };
            let mut item = Item::new(short, item_kind, range, sort).detail(&sym.fqn).insert(&imp.name).filter(short);
            item.edits = imp.edit.into_iter().collect();
            items.push(item);
        };

        for sym in self.symbols(SymbolKind::Class) {
            add(SymbolKind::Class, &sym);
        }

        let components: Vec<String> = self.components().into_iter().map(|c| c.component.fqn).collect();
        for sym in self.symbols(SymbolKind::Function) {
            if !components.contains(&sym.fqn) && sym.fqn.contains('\\') {
                add(SymbolKind::Function, &sym);
            }
        }

        items
    }
}

fn use_kind(word: Option<&str>) -> SymbolKind {
    match word.map(str::to_ascii_lowercase).as_deref() {
        Some("function") => SymbolKind::Function,
        Some("const") => SymbolKind::Const,
        _ => SymbolKind::Class,
    }
}
