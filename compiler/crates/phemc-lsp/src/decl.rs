//! The declarations of a PHP or `.phpm` file, for the editor: the namespace, the `use` imports,
//! the functions, the classes, and the constants. For components, also the props and the slots.
//! The parser is tolerant: the file can be incomplete, because the user types it.

use crate::scanner::{code_of, is_word_char, is_word_start};

const MODIFIERS: &[&str] = &["public", "protected", "private", "readonly", "static", "final", "abstract", "var"];

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Tok {
    Punct,
    Var,
    Id,
}

#[derive(Clone, Debug)]
pub struct Token {
    pub t: Tok,
    pub v: String,
    pub start: usize,
    pub end: usize,
}

impl Token {
    pub fn is(&self, v: &str) -> bool {
        self.v == v
    }

    pub fn is_word(&self, w: &str) -> bool {
        self.t == Tok::Id && self.v.eq_ignore_ascii_case(w)
    }
}

/// Splits masked code into tokens. The offsets are relative to `code`.
pub fn tokenize(code: &[u8]) -> Vec<Token> {
    let mut tokens = Vec::new();
    let mut i = 0;
    let word_end = |mut k: usize| {
        while k < code.len() && is_word_char(code[k]) {
            k += 1;
        }

        k
    };

    while i < code.len() {
        let c = code[i];
        if c.is_ascii_whitespace() {
            i += 1;
            continue;
        }

        let start = i;
        let (t, end) = if code[i..].starts_with(b"#[") {
            (Tok::Punct, i + 2)
        } else if c == b'$' && code.get(i + 1).is_some_and(|&n| is_word_start(n)) {
            (Tok::Var, word_end(i + 2))
        } else if is_word_start(c) || (c == b'\\' && code.get(i + 1).is_some_and(|&n| is_word_start(n))) {
            let mut k = word_end(if c == b'\\' { i + 2 } else { i + 1 });
            while code.get(k) == Some(&b'\\') && code.get(k + 1).is_some_and(|&n| is_word_start(n)) {
                k = word_end(k + 2);
            }

            if code.get(k) == Some(&b'\\') {
                k += 1;
            }

            (Tok::Id, k)
        } else if code[i..].starts_with(b"...") {
            (Tok::Punct, i + 3)
        } else if code[i..].starts_with(b"?->") {
            (Tok::Punct, i + 3)
        } else if code[i..].starts_with(b"::") || code[i..].starts_with(b"=>") || code[i..].starts_with(b"->") {
            (Tok::Punct, i + 2)
        } else {
            let len = std::str::from_utf8(&code[i..]).ok().and_then(|s| s.chars().next()).map_or(1, char::len_utf8);
            (Tok::Punct, i + len)
        };

        tokens.push(Token { t, v: String::from_utf8_lossy(&code[start..end]).into_owned(), start, end });
        i = end;
    }

    tokens
}

pub fn join_name(namespace: &str, name: &str) -> String {
    if namespace.is_empty() {
        name.to_string()
    } else {
        format!("{namespace}\\{name}")
    }
}

pub fn short_name(fqn: &str) -> &str {
    fqn.rsplit('\\').next().unwrap_or(fqn)
}

pub fn namespace_of(fqn: &str) -> &str {
    fqn.rfind('\\').map_or("", |i| &fqn[..i])
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum UseKind {
    Class,
    Function,
    Const,
}

impl UseKind {
    fn parse(s: &str) -> Option<Self> {
        match s.to_ascii_lowercase().as_str() {
            "function" => Some(UseKind::Function),
            "const" => Some(UseKind::Const),
            _ => None,
        }
    }

    pub fn keyword(self) -> &'static str {
        match self {
            UseKind::Class => "use",
            UseKind::Function => "use function",
            UseKind::Const => "use const",
        }
    }
}

#[derive(Clone, Debug)]
pub struct UseItem {
    pub kind: UseKind,
    pub fqn: String,
    pub alias: String,
}

#[derive(Clone, Debug)]
pub struct UseGroup {
    pub prefix: String,
    pub open: usize,
    pub close: Option<usize>,
}

#[derive(Clone, Debug)]
pub struct UseStmt {
    pub kind: UseKind,
    pub start: usize,
    pub end: usize,
    pub items: Vec<UseItem>,
    pub group: Option<UseGroup>,
}

#[derive(Clone, Debug, Default)]
pub struct Attribute {
    pub name: String,
    /// The text between the parentheses, or empty.
    pub args: String,
}

#[derive(Clone, Debug, Default)]
pub struct Param {
    pub name: String,
    pub ty: String,
    pub attrs: Vec<String>,
    pub modifiers: Vec<String>,
    pub variadic: bool,
    pub by_ref: bool,
    pub promoted: bool,
    pub default: Option<String>,
    pub offset: usize,
}

#[derive(Clone, Debug)]
pub struct Function {
    pub name: String,
    pub fqn: String,
    pub params: Vec<Param>,
    pub return_type: String,
    pub attrs: Vec<String>,
    pub attributes: Vec<Attribute>,
    pub doc: String,
    pub offset: usize,
    pub start: usize,
    pub body: Option<(usize, usize)>,
    pub end: usize,
}

#[derive(Clone, Debug)]
pub struct Property {
    pub name: String,
    pub ty: String,
    pub modifiers: Vec<String>,
    pub visibility: String,
    pub is_static: bool,
    pub attrs: Vec<String>,
    /// `#[Prop(rest: true)]`.
    pub rest: bool,
    pub doc: String,
    pub default: Option<String>,
    pub promoted: bool,
    pub offset: usize,
}

#[derive(Clone, Debug)]
pub struct Method {
    pub name: String,
    pub params: Vec<Param>,
    pub return_type: String,
    pub visibility: String,
    pub is_static: bool,
    pub doc: String,
    pub offset: usize,
    pub start: usize,
    pub body: Option<(usize, usize)>,
    pub end: usize,
}

#[derive(Clone, Debug)]
pub struct ClassConst {
    pub name: String,
    /// `const` or `case`.
    pub is_case: bool,
    pub visibility: String,
    pub doc: String,
    pub offset: usize,
}

#[derive(Clone, Debug)]
pub struct Class {
    /// `class`, `interface`, `trait`, or `enum`.
    pub kind: String,
    pub name: String,
    pub fqn: String,
    pub attrs: Vec<String>,
    pub attributes: Vec<Attribute>,
    pub doc: String,
    pub extends: Vec<String>,
    pub implements: Vec<String>,
    pub traits: Vec<String>,
    pub properties: Vec<Property>,
    pub methods: Vec<Method>,
    pub constants: Vec<ClassConst>,
    pub offset: usize,
    pub start: usize,
    pub body_start: Option<usize>,
    pub end: usize,
}

#[derive(Clone, Debug)]
pub struct Constant {
    pub fqn: String,
}

#[derive(Clone, Debug)]
pub struct Prop {
    pub name: String,
    pub ty: String,
    pub default: Option<String>,
    pub required: bool,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ComponentKind {
    Function,
    Class,
}

#[derive(Clone, Debug)]
pub struct Component {
    pub kind: ComponentKind,
    pub name: String,
    pub fqn: String,
    pub page: bool,
    pub props: Vec<Prop>,
    pub slots: Vec<String>,
    pub children: bool,
    pub rest: bool,
    pub offset: Option<usize>,
    /// The declaration, from its first token to its end: a tag of the component that it replaces
    /// stays the original inside it.
    pub span: Option<(usize, usize)>,
    /// The component that this component replaces in the tags (`#[Component(replaces: ...)]`).
    pub replaces: Option<String>,
    pub signature: String,
    pub doc: Option<&'static str>,
}

#[derive(Clone, Debug, Default)]
pub struct Parsed {
    pub namespace: String,
    /// The offset of the name in `namespace X;`.
    pub namespace_start: Option<usize>,
    /// The offset after `namespace X;`.
    pub namespace_end: Option<usize>,
    /// The offset after `<?phpm` (or `<?php` in a PHP class).
    pub open_tag_end: Option<usize>,
    pub uses: Vec<UseStmt>,
    pub functions: Vec<Function>,
    pub classes: Vec<Class>,
    pub constants: Vec<Constant>,
    pub components: Vec<Component>,
}

pub fn parse(text: &str) -> Parsed {
    let tokens = tokenize(&code_of(text));
    let mut p = Parser { text, tokens, k: 0, namespace: String::new(), out: Parsed::default() };
    if let Some(i) = text.find("<?php") {
        let m = text[i + 5..].starts_with('m') && !text.as_bytes().get(i + 6).is_some_and(|&c| is_word_char(c));
        if m || !text.as_bytes().get(i + 5).is_some_and(|&c| is_word_char(c)) {
            p.out.open_tag_end = Some(i + if m { 6 } else { 5 });
        }
    }

    p.parse();
    let mut parsed = p.out;
    parsed.components = components(&parsed);
    parsed
}

struct Parser<'a> {
    text: &'a str,
    tokens: Vec<Token>,
    k: usize,
    namespace: String,
    out: Parsed,
}

fn closer(open: &str) -> &'static str {
    match open {
        "(" => ")",
        "[" | "#[" => "]",
        _ => "}",
    }
}

fn visibility_of(modifiers: &[String]) -> String {
    modifiers.iter().find(|m| matches!(m.as_str(), "public" | "protected" | "private")).cloned().unwrap_or_else(|| "public".into())
}

fn compact(s: &str) -> String {
    s.chars().filter(|c| !c.is_whitespace()).collect()
}

impl Parser<'_> {
    fn peek(&self, n: usize) -> Option<&Token> {
        self.tokens.get(self.k + n)
    }

    fn peek_is(&self, n: usize, v: &str) -> bool {
        self.peek(n).is_some_and(|t| t.v == v)
    }

    fn peek_id(&self, n: usize) -> bool {
        self.peek(n).is_some_and(|t| t.t == Tok::Id)
    }

    fn next(&mut self) -> Token {
        self.k += 1;
        self.tokens[self.k - 1].clone()
    }

    fn done(&self) -> bool {
        self.k >= self.tokens.len()
    }

    /// Moves past the group that starts at the current token (`(`, `[`, `{`, `#[`).
    fn skip_group(&mut self) {
        let open = self.next().v;
        let close = closer(&open);
        let mut depth = 1;
        while !self.done() && depth > 0 {
            let v = &self.tokens[self.k].v;
            if v == &open || (close == "]" && (v == "[" || v == "#[")) {
                depth += 1;
            } else if v == close {
                depth -= 1;
            }

            self.k += 1;
        }
    }

    /// The index of the token that closes the group at token `k`.
    fn match_index(&self, k: usize) -> Option<usize> {
        let open = &self.tokens[k].v;
        let close = closer(open);
        let mut depth = 0;
        for j in k..self.tokens.len() {
            let v = &self.tokens[j].v;
            if v == open || (close == "]" && (v == "[" || v == "#[")) {
                depth += 1;
            } else if v == close {
                depth -= 1;
                if depth == 0 {
                    return Some(j);
                }
            }
        }

        None
    }

    /// Reads `#[A, B(...)]`.
    fn attribute(&mut self) -> Vec<Attribute> {
        let start_k = self.k;
        self.skip_group();

        let mut list: Vec<Attribute> = Vec::new();
        let mut depth = 0;
        for j in start_k + 1..self.k.saturating_sub(1) {
            let tok = &self.tokens[j];
            if tok.v == "(" || tok.v == "[" {
                if depth == 0 && tok.v == "(" && !list.is_empty() {
                    let end = self.match_index(j).map_or(self.text.len(), |c| self.tokens[c].start);
                    list.last_mut().unwrap().args = self.text[tok.end..end].to_string();
                }

                depth += 1;
            } else if tok.v == ")" || tok.v == "]" {
                depth -= 1;
            } else if depth == 0 && tok.t == Tok::Id {
                list.push(Attribute { name: tok.v.trim_start_matches('\\').to_string(), args: String::new() });
            }
        }

        list
    }

    /// Reads `: Type` after the parameters.
    fn return_type(&mut self) -> String {
        if !self.peek_is(0, ":") {
            return String::new();
        }

        self.k += 1;
        let (mut start, mut end) = (None, 0);
        while let Some(tok) = self.peek(0) {
            if tok.v == "{" || tok.v == ";" || tok.v == "=>" || tok.is_word("use") {
                break;
            }

            if tok.t != Tok::Id && !["?", "|", "&", "(", ")"].contains(&tok.v.as_str()) {
                break;
            }

            start.get_or_insert(tok.start);
            end = tok.end;
            self.k += 1;
        }

        start.map_or(String::new(), |s| compact(&self.text[s..end]))
    }

    /// The range of the body that starts at the current token, if it is `{`. A body that does
    /// not close (the user types in it) ends at the end of the text.
    fn body_range(&self) -> Option<(usize, usize)> {
        let tok = self.peek(0).filter(|t| t.v == "{")?;
        let end = self.match_index(self.k).map_or(self.text.len(), |c| self.tokens[c].end);
        Some((tok.start, end))
    }

    fn parse(&mut self) {
        let mut attrs: Vec<Attribute> = Vec::new();
        while let Some(tok) = self.peek(0).cloned() {
            if tok.v == "#[" {
                attrs.extend(self.attribute());
                continue;
            }

            if tok.t != Tok::Id {
                self.k += 1;
                if tok.v == ";" || tok.v == "}" {
                    attrs.clear();
                }

                continue;
            }

            let word = tok.v.to_ascii_lowercase();
            let prev = self.k.checked_sub(1).map(|p| self.tokens[p].v.clone()).unwrap_or_default();
            if word == "namespace" && (self.peek_id(1) || self.peek_is(1, "{")) {
                self.k += 1;
                let name = if self.peek_id(0) { self.next().v.trim_start_matches('\\').to_string() } else { String::new() };
                self.namespace = name.clone();
                if self.out.namespace.is_empty() {
                    self.out.namespace_start = (!name.is_empty()).then(|| self.tokens[self.k - 1].start);
                    self.out.namespace = name;
                    self.out.namespace_end = Some(self.peek(0).map_or(tok.end, |t| t.end));
                }

                continue;
            }

            if word == "use" && self.peek_id(1) {
                self.use_stmt(&tok);
                continue;
            }

            if word == "function" && self.peek_id(1) && self.peek_is(2, "(") {
                self.k += 1;
                let name = self.next();
                let params = self.params();
                let return_type = self.return_type();
                let body = self.body_range();

                self.out.functions.push(Function {
                    fqn: join_name(&self.namespace, &name.v),
                    params,
                    return_type,
                    attrs: attrs.iter().map(|a| a.name.clone()).collect(),
                    attributes: std::mem::take(&mut attrs),
                    doc: doc_before(self.text, tok.start),
                    offset: name.start,
                    start: tok.start,
                    body,
                    end: body.map_or(name.end, |b| b.1),
                    name: name.v,
                });
                continue;
            }

            // `$a->class`, `Foo::class`, and `f(class: 1)` are not declarations.
            if ["class", "interface", "trait", "enum"].contains(&word.as_str())
                && !["::", "->", "?->"].contains(&prev.as_str())
                && !prev.eq_ignore_ascii_case("new")
                && self.peek(1).is_some_and(|n| n.t == Tok::Id && !n.is_word("extends") && !n.is_word("implements"))
            {
                self.k += 1;
                let name = self.next();
                let list = std::mem::take(&mut attrs);
                self.class_like(&word, name, list, &tok);
                continue;
            }

            if word == "new" && self.peek(1).is_some_and(|t| t.is_word("class")) {
                self.k += 2;
                self.skip_to_body();
                continue;
            }

            if word == "const" && self.peek_id(1) && self.peek_is(2, "=") {
                self.k += 1;
                let name = self.next();
                self.out.constants.push(Constant { fqn: join_name(&self.namespace, &name.v) });
                continue;
            }

            if word == "define" && self.peek_is(1, "(") {
                // define('NAME', ...): the name is masked as a string, so read it from the text.
                if let Some(name) = define_name(&self.text[tok.end..]) {
                    self.out.constants.push(Constant { fqn: name.trim_start_matches('\\').to_string() });
                }
            }

            self.k += 1;
        }
    }

    /// Skips an anonymous class: its arguments, `extends`, and its body.
    fn skip_to_body(&mut self) {
        while !self.done() && !self.peek_is(0, "{") {
            if self.peek_is(0, "(") {
                self.skip_group();
            } else {
                self.k += 1;
            }
        }

        if !self.done() {
            self.skip_group();
        }
    }

    /// Reads `use A\B;`, `use function A\{B, C as D};`, and so on.
    fn use_stmt(&mut self, tok: &Token) {
        self.k += 1;
        let mut kind = UseKind::Class;
        if let Some(k) = self.peek(0).and_then(|t| UseKind::parse(&t.v)) {
            if self.peek_id(1) {
                kind = k;
                self.k += 1;
            }
        }

        let mut stmt = UseStmt { kind, start: tok.start, end: tok.end, items: vec![], group: None };
        while !self.done() {
            let t = self.next();
            if t.v == ";" {
                stmt.end = t.end;
                break;
            }

            if t.t != Tok::Id {
                continue;
            }

            if t.v.ends_with('\\') && self.peek_is(0, "{") {
                // A group: use A\{B, C};
                let prefix = t.v.trim_start_matches('\\').to_string();
                let open = self.next();
                while !self.done() && !self.peek_is(0, "}") && !self.peek_is(0, ";") {
                    let it = self.next();
                    if it.t != Tok::Id {
                        continue;
                    }

                    let mut item_kind = kind;
                    let mut name = it.v;
                    if let Some(k) = UseKind::parse(&name) {
                        if self.peek_id(0) {
                            item_kind = k;
                            name = self.next().v;
                        }
                    }

                    let mut alias = short_name(&name).to_string();
                    if self.peek(0).is_some_and(|t| t.is_word("as")) {
                        self.k += 1;
                        if !self.done() {
                            alias = self.next().v;
                        }
                    }

                    stmt.items.push(UseItem { kind: item_kind, fqn: format!("{prefix}{name}"), alias });
                }

                let close = if self.peek_is(0, "}") { Some(self.next().start) } else { None };
                stmt.group = Some(UseGroup { prefix, open: open.start, close });
                continue;
            }

            let fqn = t.v.trim_start_matches('\\').to_string();
            let mut alias = short_name(&fqn).to_string();
            if self.peek(0).is_some_and(|t| t.is_word("as")) {
                self.k += 1;
                if !self.done() {
                    alias = self.next().v;
                }
            }

            stmt.items.push(UseItem { kind, fqn, alias });
        }

        self.out.uses.push(stmt);
    }

    /// Reads the parameters of a function. The current token is `(`.
    fn params(&mut self) -> Vec<Param> {
        let mut params = Vec::new();
        self.k += 1;
        let mut current: Option<Param> = None;
        let mut type_range: Option<(usize, usize)> = None;
        let mut depth = 0;
        let mut default_start: Option<usize> = None;
        let text = self.text;
        let mut finish = |current: &mut Option<Param>, type_range: &mut Option<(usize, usize)>, default_start: &mut Option<usize>, end: usize| {
            if let Some(mut p) = current.take() {
                if !p.name.is_empty() {
                    if let Some(s) = *default_start {
                        p.default = Some(text[s..end].trim().to_string());
                    }
                    p.ty = type_range.map_or(String::new(), |(s, e)| compact(&text[s..e]));
                    params.push(p);
                }
            }

            *type_range = None;
            *default_start = None;
        };

        while let Some(tok) = self.peek(0).cloned() {
            if depth == 0 && tok.v == ")" {
                finish(&mut current, &mut type_range, &mut default_start, tok.start);
                self.k += 1;
                break;
            }

            if tok.v == "#[" {
                let list = self.attribute();
                current.get_or_insert_with(Param::default).attrs.extend(list.into_iter().map(|a| a.name));
                continue;
            }

            self.k += 1;
            if tok.v == "(" || tok.v == "[" || tok.v == "{" {
                depth += 1;
                continue;
            }

            if tok.v == ")" || tok.v == "]" || tok.v == "}" {
                depth -= 1;
                continue;
            }

            if depth > 0 || default_start.is_some() {
                if depth == 0 && tok.v == "," {
                    finish(&mut current, &mut type_range, &mut default_start, tok.start);
                }
                continue;
            }

            if tok.v == "," {
                finish(&mut current, &mut type_range, &mut default_start, tok.start);
                continue;
            }

            let p = current.get_or_insert_with(Param::default);
            if tok.t == Tok::Var {
                p.name = tok.v[1..].to_string();
                p.offset = tok.start;
            } else if tok.v == "=" {
                default_start = Some(tok.end);
            } else if tok.v == "..." {
                p.variadic = true;
            } else if tok.v == "&" {
                p.by_ref = true;
            } else if tok.t == Tok::Id && MODIFIERS.contains(&tok.v.to_ascii_lowercase().as_str()) {
                p.promoted = true;
                p.modifiers.push(tok.v.to_ascii_lowercase());
            } else if p.name.is_empty() {
                type_range = Some((type_range.map_or(tok.start, |r| r.0), tok.end));
            }
        }

        params
    }

    /// Reads a class, an interface, a trait, or an enum.
    fn class_like(&mut self, kind: &str, name: Token, attrs: Vec<Attribute>, keyword: &Token) {
        let mut cls = Class {
            kind: kind.to_string(),
            fqn: join_name(&self.namespace, &name.v),
            name: name.v.clone(),
            attrs: attrs.iter().map(|a| a.name.clone()).collect(),
            attributes: attrs,
            doc: doc_before(self.text, keyword.start),
            extends: vec![],
            implements: vec![],
            traits: vec![],
            properties: vec![],
            methods: vec![],
            constants: vec![],
            offset: name.start,
            start: keyword.start,
            body_start: None,
            end: name.end,
        };

        // extends A, implements B, C (an interface can extend more than one).
        let mut list: Option<bool> = None; // Some(true): extends, Some(false): implements
        while !self.done() && !self.peek_is(0, "{") {
            let tok = self.next();
            if tok.t != Tok::Id {
                continue;
            }

            if tok.is_word("extends") {
                list = Some(true);
            } else if tok.is_word("implements") {
                list = Some(false);
            } else if let Some(ext) = list {
                if ext { &mut cls.extends } else { &mut cls.implements }.push(tok.v);
            }
        }

        if self.done() {
            self.out.classes.push(cls);
            return;
        }

        if let Some((start, end)) = self.body_range() {
            cls.body_start = Some(start);
            cls.end = end;
        }

        self.k += 1;
        self.class_body(&mut cls);
        self.out.classes.push(cls);
    }

    fn class_body(&mut self, cls: &mut Class) {
        let mut member_attrs: Vec<String> = Vec::new();
        let mut member_rest = false;
        let mut modifiers: Vec<String> = Vec::new();
        let mut member_start: Option<usize> = None;

        macro_rules! reset {
            () => {{
                member_attrs = Vec::new();
                member_rest = false;
                modifiers = Vec::new();
                member_start = None;
            }};
        }

        while let Some(tok) = self.peek(0).cloned() {
            if tok.v == "}" {
                self.k += 1;
                return;
            }

            let start = *member_start.get_or_insert(tok.start);
            if tok.v == "#[" {
                let list = self.attribute();
                member_rest = member_rest || list.iter().any(is_rest_attr);
                member_attrs.extend(list.into_iter().map(|a| a.name));
                continue;
            }

            if tok.v == "{" {
                self.skip_group();
                reset!();
                continue;
            }

            if tok.t == Tok::Id && MODIFIERS.contains(&tok.v.to_ascii_lowercase().as_str()) {
                modifiers.push(self.next().v.to_ascii_lowercase());
                continue;
            }

            if tok.is_word("function") {
                self.k += 1;
                if self.peek_is(0, "&") {
                    self.k += 1;
                }

                if !self.peek_id(0) {
                    // `function` with no name yet (the user types it).
                    reset!();
                    continue;
                }

                let fname = self.next();
                let params = if self.peek_is(0, "(") { self.params() } else { vec![] };
                let return_type = self.return_type();
                let body = self.body_range();

                if fname.v.eq_ignore_ascii_case("__construct") {
                    for p in params.iter().filter(|p| p.promoted) {
                        cls.properties.push(Property {
                            name: p.name.clone(),
                            ty: p.ty.clone(),
                            visibility: visibility_of(&p.modifiers),
                            modifiers: p.modifiers.clone(),
                            is_static: false,
                            attrs: p.attrs.clone(),
                            rest: false,
                            doc: doc_before(self.text, p.offset),
                            default: p.default.clone(),
                            promoted: true,
                            offset: p.offset,
                        });
                    }
                }

                cls.methods.push(Method {
                    name: fname.v.clone(),
                    params,
                    return_type,
                    visibility: visibility_of(&modifiers),
                    is_static: modifiers.iter().any(|m| m == "static"),
                    doc: doc_before(self.text, start),
                    offset: fname.start,
                    start,
                    body,
                    end: body.map_or(fname.end, |b| b.1),
                });

                // Skip the body (or the `;` of an abstract method).
                while !self.done() && !self.peek_is(0, "{") && !self.peek_is(0, ";") && !self.peek_is(0, "}") {
                    self.k += 1;
                }

                if self.peek_is(0, "{") {
                    self.skip_group();
                } else if self.peek_is(0, ";") {
                    self.k += 1;
                }

                reset!();
                continue;
            }
            if tok.is_word("use") {
                // Traits: use A, B; or use A, B { ... }
                self.k += 1;
                while let Some(t) = self.peek(0) {
                    if matches!(t.v.as_str(), ";" | "{" | "}") {
                        break;
                    }
                    let t = self.next();
                    if t.t == Tok::Id {
                        cls.traits.push(t.v);
                    }
                }

                if self.peek_is(0, "{") {
                    self.skip_group();
                } else if self.peek_is(0, ";") {
                    self.k += 1;
                }

                reset!();
                continue;
            }
            if tok.is_word("const") || tok.is_word("case") {
                self.constants(cls, tok.is_word("case"), &modifiers, start);
                reset!();
                continue;
            }

            if !modifiers.is_empty() {
                let mods = std::mem::take(&mut modifiers);
                let attrs = std::mem::take(&mut member_attrs);
                self.property(cls, mods, attrs, start, member_rest);
                reset!();
                continue;
            }

            self.k += 1;
            reset!();
        }
    }

    /// Reads `const [type] A = 1, B = 2;` or `case A = 'a';`. The current token is `const` or
    /// `case`.
    fn constants(&mut self, cls: &mut Class, is_case: bool, modifiers: &[String], member_start: usize) {
        self.k += 1;
        let doc = doc_before(self.text, member_start);
        let mut expect_name = true;
        let mut last: Option<Token> = None;
        while let Some(tok) = self.peek(0).cloned() {
            if tok.v == ";" || tok.v == "}" {
                if tok.v == ";" {
                    self.k += 1;
                }

                break;
            }

            if tok.v == "(" || tok.v == "[" || tok.v == "{" {
                self.skip_group();
                continue;
            }

            self.k += 1;
            if expect_name && tok.t == Tok::Id {
                last = Some(tok);
                continue;
            }

            if expect_name && (tok.v == "=" || is_case) {
                if let Some(l) = last.take() {
                    cls.constants.push(ClassConst { name: l.v, is_case, visibility: visibility_of(modifiers), doc: doc.clone(), offset: l.start });
                    expect_name = false;
                }
            }

            if tok.v == "," {
                expect_name = true;
            }
        }

        if expect_name && is_case {
            if let Some(l) = last {
                cls.constants.push(ClassConst { name: l.v, is_case, visibility: "public".into(), doc, offset: l.start });
            }
        }
    }

    /// Reads `public ?Type $a = 1, $b;`. The current token is after the modifiers. `rest` is true
    /// after `#[Prop(rest: true)]`.
    fn property(&mut self, cls: &mut Class, modifiers: Vec<String>, attrs: Vec<String>, member_start: usize, rest: bool) {
        let doc = doc_before(self.text, member_start);
        let mut ty: Option<(usize, usize)> = None;
        let mut current: Option<Property> = None;
        let mut default_start: Option<usize> = None;
        let text = self.text;
        let finish = |cls: &mut Class, current: &mut Option<Property>, default_start: &mut Option<usize>, end: usize| {
            if let Some(mut p) = current.take() {
                if let Some(s) = *default_start {
                    p.default = Some(text[s..end].trim().to_string());
                }

                cls.properties.push(p);
            }

            *default_start = None;
        };

        while let Some(tok) = self.peek(0).cloned() {
            if tok.v == ";" || tok.v == "}" {
                finish(cls, &mut current, &mut default_start, tok.start);
                if tok.v == ";" {
                    self.k += 1;
                }

                return;
            }

            if tok.v == "{" && current.is_some() {
                // Property hooks.
                finish(cls, &mut current, &mut default_start, tok.start);
                self.skip_group();
                return;
            }

            if tok.v == "(" || tok.v == "[" || tok.v == "{" {
                self.skip_group();
                continue;
            }

            self.k += 1;
            if default_start.is_some() {
                if tok.v == "," {
                    finish(cls, &mut current, &mut default_start, tok.start);
                }

                continue;
            }

            if tok.t == Tok::Var {
                current = Some(Property {
                    name: tok.v[1..].to_string(),
                    ty: ty.map_or(String::new(), |(s, e)| compact(&text[s..e])),
                    visibility: visibility_of(&modifiers),
                    is_static: modifiers.iter().any(|m| m == "static"),
                    modifiers: modifiers.clone(),
                    attrs: attrs.clone(),
                    rest,
                    doc: doc.clone(),
                    default: None,
                    promoted: false,
                    offset: tok.start,
                });
            } else if tok.v == "=" {
                default_start = Some(tok.end);
            } else if tok.v == "," {
                finish(cls, &mut current, &mut default_start, tok.start);
            } else if current.is_none() {
                ty = Some((ty.map_or(tok.start, |r| r.0), tok.end));
            }
        }
    }
}

/// `('NAME'` at the start of the text after `define`.
fn define_name(rest: &str) -> Option<&str> {
    let rest = rest.trim_start().strip_prefix('(')?.trim_start();
    let quote = rest.chars().next().filter(|c| *c == '\'' || *c == '"')?;
    let body = &rest[1..];
    let end = body.find(quote)?;
    let name = &body[..end];
    let valid = name.trim_start_matches('\\').chars().next().is_some_and(|c| c.is_ascii_alphabetic() || c == '_')
        && name.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '\\');
    valid.then_some(name)
}

/// The `/** ... */` block just before a declaration, or empty. Only modifiers, attributes, and a
/// type can be between the block and `offset`.
pub fn doc_before(text: &str, offset: usize) -> String {
    let mut from = offset.saturating_sub(4000);
    while !text.is_char_boundary(from) {
        from += 1;
    }

    let before = &text[from..offset.min(text.len())];
    let Some(close) = before.rfind("*/") else { return String::new() };
    let Some(open) = before[..close].rfind("/**") else { return String::new() };

    if before[open..close].contains("*/") {
        return String::new();
    }

    let between = strip_attributes(&before[close + 2..]);
    if between.contains([';', '{', '}', '$']) {
        return String::new();
    }

    before[open..close + 2].to_string()
}

/// The text without the `#[...]` attributes (without nested brackets).
fn strip_attributes(s: &str) -> String {
    let mut out = String::new();
    let mut rest = s;
    while let Some(i) = rest.find("#[") {
        out.push_str(&rest[..i]);
        match rest[i..].find(']') {
            Some(j) => rest = &rest[i + j + 1..],
            None => {
                rest = &rest[i..];
                break;
            }
        }
    }

    out.push_str(rest);
    out
}

pub fn has_attr(attrs: &[String], name: &str) -> bool {
    attrs.iter().any(|a| a == name || a.ends_with(&format!("\\{name}")))
}

/// A prop of a class component: a `#[Prop]` (or `#[Path]`) property that is public, not static,
/// not promoted, and not `#[State]`.
pub fn is_prop(p: &Property) -> bool {
    (has_attr(&p.attrs, "Prop") || has_attr(&p.attrs, "Path"))
        && !p.promoted
        && p.modifiers.iter().any(|m| m == "public")
        && !p.modifiers.iter().any(|m| m == "static")
        && !has_attr(&p.attrs, "State")
}

/// `#[Prop(rest: true)]` (or `#[Prop(true)]`): the prop gets the props that no other prop takes.
fn is_rest_attr(a: &Attribute) -> bool {
    if !has_attr(std::slice::from_ref(&a.name), "Prop") {
        return false;
    }

    let args = a.args.trim();
    let value = match args.split_once(':') {
        Some((key, value)) if key.trim() == "rest" => value.trim(),
        Some(_) => return false,
        None => args,
    };

    value.eq_ignore_ascii_case("true")
}

/// `Html`, `?Html`, `Phem\Html|null`: the type of a slot.
pub fn is_html_type(ty: &str) -> bool {
    let t = ty.trim_start_matches('?');
    let t = strip_null(t);
    let t = t.trim_start_matches('\\');
    t == "Html" || t == "Phem\\Html"
}

fn strip_null(t: &str) -> &str {
    if t.len() > 5 && t[..5].eq_ignore_ascii_case("null|") {
        &t[5..]
    } else if t.len() > 5 && t[t.len() - 5..].eq_ignore_ascii_case("|null") {
        &t[..t.len() - 5]
    } else {
        t
    }
}

fn nullable(ty: &str) -> bool {
    ty.starts_with('?') || ty.split('|').any(|p| p.eq_ignore_ascii_case("null"))
}

/// The functions and the classes of a file that are components.
fn components(parsed: &Parsed) -> Vec<Component> {
    let mut list = Vec::new();
    for f in &parsed.functions {
        // A function without `#[Component]` (or `#[Page]`) is a plain PHP function.
        if !(has_attr(&f.attrs, "Component") || has_attr(&f.attrs, "Page")) {
            continue;
        }

        let page = has_attr(&f.attrs, "Page");
        let mut comp = Component {
            kind: ComponentKind::Function,
            name: f.name.clone(),
            fqn: f.fqn.clone(),
            page,
            props: vec![],
            slots: vec![],
            children: false,
            rest: false,
            offset: Some(f.offset),
            span: Some((f.start, f.end)),
            replaces: replaced(parsed, &f.attributes),
            signature: format!("#[{}]\nfunction {}({})", if page { "Page" } else { "Component" }, f.name, f.params.iter().map(param_text).collect::<Vec<_>>().join(", ")),
            doc: None,
        };

        // A function page has no props: its parameters are the arguments of a controller.
        for p in f.params.iter().filter(|_| !page) {
            // A `#[Context]` parameter is not a prop: a component above gives it.
            if has_attr(&p.attrs, "Context") {
                continue;
            }

            if p.variadic {
                comp.rest = true;
            } else if p.name == "children" {
                comp.children = true;
            } else if is_html_type(&p.ty) {
                comp.slots.push(p.name.clone());
            } else {
                comp.props.push(Prop { name: p.name.clone(), ty: p.ty.clone(), default: p.default.clone(), required: p.default.is_none() });
            }
        }

        list.push(comp);
    }

    for c in &parsed.classes {
        if c.kind != "class" || !(has_attr(&c.attrs, "Component") || has_attr(&c.attrs, "Page")) {
            continue;
        }

        let mut comp = Component {
            kind: ComponentKind::Class,
            name: c.name.clone(),
            fqn: c.fqn.clone(),
            page: has_attr(&c.attrs, "Page") || has_attr(&c.attrs, "Route"),
            props: vec![],
            slots: vec![],
            children: false,
            rest: false,
            offset: Some(c.offset),
            span: Some((c.start, c.end)),
            replaces: replaced(parsed, &c.attributes),
            signature: format!("#[Component]\nfinal class {}", c.name),
            doc: None,
        };

        let mut lines = Vec::new();
        for p in c.properties.iter().filter(|p| is_prop(p)) {
            let decl = format!(
                "public {}${}{}",
                if p.ty.is_empty() { String::new() } else { format!("{} ", p.ty) },
                p.name,
                p.default.as_ref().map_or(String::new(), |d| format!(" = {d}"))
            );

            if p.rest {
                comp.rest = true;
                lines.push(format!("    #[Prop(rest: true)] {decl};"));
                continue;
            }

            if p.name == "children" {
                comp.children = true;
            } else if is_html_type(&p.ty) {
                comp.slots.push(p.name.clone());
            } else {
                let required = p.default.is_none() && !nullable(&p.ty) && !p.ty.is_empty();
                comp.props.push(Prop { name: p.name.clone(), ty: p.ty.clone(), default: p.default.clone(), required });
            }

            lines.push(format!("    #[Prop] {decl};"));
        }

        if !lines.is_empty() {
            comp.signature += &format!("\n{{\n{}\n}}", lines.join("\n"));
        }

        list.push(comp);
    }

    list
}

/// The component that `#[Component(replaces: ...)]` replaces: the named argument `replaces`, or
/// the first argument with no name. The value is `Name::class` or a string.
fn replaced(parsed: &Parsed, attributes: &[Attribute]) -> Option<String> {
    let attr = attributes.iter().find(|a| has_attr(std::slice::from_ref(&a.name), "Component"))?;
    let value = attr.args.split(',').map(str::trim).enumerate().find_map(|(i, arg)| {
        let named = arg.find(':').filter(|&c| !arg[c + 1..].starts_with(':') && arg[..c].trim().bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_'));
        match named {
            Some(c) if arg[..c].trim().eq_ignore_ascii_case("replaces") => Some(arg[c + 1..].trim()),
            None if i == 0 => Some(arg),
            _ => None,
        }
    })?;

    // `self::class`, `static::class` and `parent::class` are not a class name (E017).
    if let Some(name) = value.strip_suffix("::class").map(str::trim) {
        return crate::types::resolve_name(parsed, name).filter(|_| !["self", "static", "parent"].iter().any(|w| name.eq_ignore_ascii_case(w)));
    }

    let unquoted = value.strip_prefix('\'').and_then(|v| v.strip_suffix('\'')).or_else(|| value.strip_prefix('"').and_then(|v| v.strip_suffix('"')))?;
    Some(unquoted.replace("\\\\", "\\").trim_start_matches('\\').to_string()).filter(|f| !f.is_empty())
}

pub fn param_text(p: &Param) -> String {
    let mut s = String::new();
    if !p.ty.is_empty() {
        s += &p.ty;
        s.push(' ');
    }

    if p.by_ref {
        s.push('&');
    }

    if p.variadic {
        s += "...";
    }

    s += &format!("${}", p.name);
    if let Some(d) = &p.default {
        s += &format!(" = {d}");
    }

    s
}

/// The components of the runtime: they have no source file.
pub fn builtin_components() -> Vec<Component> {
    vec![Component {
        kind: ComponentKind::Class,
        name: "Root".into(),
        fqn: "Phem\\Root".into(),
        page: false,
        props: vec![Prop { name: "tag".into(), ty: "string".into(), default: Some("'div'".into()), required: false }],
        slots: vec![],
        children: true,
        rest: true,
        offset: None,
        span: None,
        replaces: None,
        signature: "final class Root\n{\n    public string $tag = 'div';\n    public ?Html $children = null;\n}".into(),
        doc: Some("The root element of the page, in the document: it keeps the state of the page and gets its events. The other attributes go to the element."),
    }, Component {
        kind: ComponentKind::Class,
        name: "Suspense".into(),
        fqn: "Phem\\Suspense".into(),
        page: false,
        props: vec![Prop { name: "lazy".into(), ty: "bool".into(), default: Some("false".into()), required: false }],
        slots: vec!["fallback".into(), "error".into()],
        children: true,
        rest: false,
        offset: None,
        span: None,
        replaces: None,
        signature: "final class Suspense\n{\n    public bool $lazy = false;\n    public ?Html $children = null;\n    public ?Html $fallback = null;\n    public ?Html $error = null;\n}".into(),
        doc: Some("Content that loads after the page: the page shows the fallback, and the response of the page streams the children after the page (or, with `lazy`, the browser loads them with an event when the fallback comes into view). When the children throw, the error slot shows."),
    }, Component {
        kind: ComponentKind::Class,
        name: "ErrorBoundary".into(),
        fqn: "Phem\\ErrorBoundary".into(),
        page: false,
        props: vec![],
        slots: vec!["fallback".into()],
        children: true,
        rest: false,
        offset: None,
        span: None,
        replaces: None,
        signature: "final class ErrorBoundary\n{\n    public ?Html $children = null;\n    public ?Html $fallback = null;\n}".into(),
        doc: Some("Catches the render errors of the children: when the render of the children throws, the fallback shows in place of them, only for this boundary. Each render tries the children again. An error in an event handler is not caught: the event fails."),
    }, Component {
        kind: ComponentKind::Class,
        name: "Dynamic".into(),
        fqn: "Phem\\Dynamic".into(),
        page: false,
        props: vec![Prop { name: "tag".into(), ty: "string".into(), default: None, required: true }],
        slots: vec![],
        children: true,
        rest: true,
        offset: None,
        span: None,
        replaces: None,
        signature: "final class Dynamic\n{\n    public string $tag;\n    public ?Html $children = null;\n}".into(),
        doc: Some("An element whose tag name is a value: `<Dynamic tag={{ tag }}>`. The other attributes and the handlers go to the element. It is an element, not a component: when it is the root of a component, the id of the component goes on it. A void element has no children. The tag cannot start with `phem-`, and it cannot be `script`, `style`, or an element whose attributes load a document or a resource (`iframe`, `object`, `embed`, `base`, `link`, `meta`)."),
    }]
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn arrow_class_and_named_arguments_are_not_declarations() {
        let parsed = parse("<?phpm\nfunction A($p) { return $p->class; }\nfunction B() { f(class: 1); $o->enum instanceof X; }\nfunction C() { return <p />; }\n");
        assert_eq!(parsed.functions.iter().map(|f| f.name.as_str()).collect::<Vec<_>>(), ["A", "B", "C"]);
        assert!(parsed.classes.is_empty());
    }

    #[test]
    fn promoted_properties_and_properties_without_prop_are_not_props() {
        let src = "<?phpm\nnamespace App\\Component;\n\nuse Phem\\Attribute\\{Component, Prop};\n\n#[Component]\nfinal class List1\n{\n    #[Prop] public int $limit = 3;\n    public int $page = 1;\n\n    public function __construct(private readonly ScientistRepository $repo)\n    {\n    }\n}\n";
        let parsed = parse(src);
        assert_eq!(parsed.components[0].props.iter().map(|p| p.name.as_str()).collect::<Vec<_>>(), ["limit"]);
    }

    #[test]
    fn context_parameters_and_properties_are_not_props() {
        let src = "<?phpm\nnamespace App\\Component;\n\nuse Phem\\Attribute\\{Component, Context, Prop};\n\n#[Component]\nfunction Tab(string $name, #[Context] Tabs $tabs, #[Context] ?Theme $theme = null) { return <p />; }\n\n#[Component]\nfinal class Panel\n{\n    #[Prop] public string $name;\n    #[Context] public Tabs $tabs;\n}\n";
        let parsed = parse(src);
        for comp in &parsed.components {
            assert_eq!(comp.props.iter().map(|p| (p.name.as_str(), p.required)).collect::<Vec<_>>(), [("name", true)], "{}", comp.name);
        }
    }

    #[test]
    fn the_rest_prop_of_a_class_component_is_not_a_named_prop() {
        let src = "<?phpm\nnamespace App\\Component;\n\nuse Phem\\Attribute\\{Component, Prop};\n\n#[Component]\nfinal class Link\n{\n    #[Prop] public string $label = '';\n    #[Prop(rest: true)] public array $attrs = [];\n    #[Prop(rest: false)] public string $title = '';\n\n    public function render()\n    {\n        return <a {{ ...attrs }}>{{ label }}</a>;\n    }\n}\n";
        let link = &parse(src).components[0];
        assert_eq!(link.props.iter().map(|p| p.name.as_str()).collect::<Vec<_>>(), ["label", "title"]);
        assert!(link.rest);
        assert!(link.signature.contains("#[Prop(rest: true)] public array $attrs = [];"));
    }

    #[test]
    fn a_page_class_is_a_component() {
        let src = "<?phpm\nnamespace App\\Page;\n\n#[Page('/home')]\nfinal class Home {}\n\n#[Route('/plain')]\nfinal class Plain {}\n\n#[Page('/function')]\n#[Component]\nfunction Fn() { return <p />; }\n\n#[Page('/bare')]\nfunction Bare() { return <p />; }\n\n#[Route('/routed')]\n#[Component]\nfunction Routed() { return <p />; }\n";
        let parsed = parse(src);
        let find = |name: &str| parsed.components.iter().find(|c| c.name == name);
        assert!(find("Home").unwrap().page);
        assert!(find("Plain").is_none(), "#[Route] without #[Component]");
        assert!(find("Fn").unwrap().page, "a function page");
        assert!(find("Bare").unwrap().page, "#[Page] needs no #[Component]");
        assert!(!find("Routed").unwrap().page, "the Symfony #[Route] cannot mark a function");
    }

    #[test]
    fn reads_use_statements_and_groups() {
        let parsed = parse("<?phpm\nnamespace App;\n\nuse A\\B as C;\nuse function X\\{f, g as h};\n");
        assert_eq!(parsed.namespace, "App");
        let items: Vec<_> = parsed.uses.iter().flat_map(|u| &u.items).map(|i| (i.kind, i.fqn.as_str(), i.alias.as_str())).collect();
        assert_eq!(items, [(UseKind::Class, "A\\B", "C"), (UseKind::Function, "X\\f", "f"), (UseKind::Function, "X\\g", "h")]);
        assert!(parsed.uses[1].group.as_ref().unwrap().close.is_some());
    }

    #[test]
    fn reads_a_docblock_before_attributes() {
        let text = "<?php\n/** The doc. */\n#[A(1)]\nfinal class X {}\n";
        assert_eq!(parse(text).classes[0].doc, "/** The doc. */");
        assert_eq!(doc_before("/** a */ $x; class", 17), "");
    }
}
