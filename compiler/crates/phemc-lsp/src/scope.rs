//! The PHP scope at an offset: the enclosing class and function, and the variables that are set
//! before the offset, with their types. It also resolves the type of an expression chain:
//! `$a->b()->c`, `Foo::bar()`, `new Foo()`, `$this->items[0]`.

use crate::classes::{Found, Index, MemberKind, Source};
use crate::decl::{doc_before, tokenize, Param, Parsed, Tok, Token, UseKind};
use crate::imports::imports_of;
use crate::project::SymbolKind;
use crate::types::{doc_tags, resolve_name, to_type, Key, Type, TypeCtx};
use std::rc::Rc;

/// The tokens of `code[start..end]`, with offsets in `code`.
pub fn tokens_in(code: &[u8], start: usize, end: usize) -> Vec<Token> {
    let end = end.min(code.len());
    let start = start.min(end);
    tokenize(&code[start..end])
        .into_iter()
        .map(|t| Token { start: t.start + start, end: t.end + start, ..t })
        .collect()
}

/// The index of the token that closes the group at `i`.
fn match_close(tokens: &[Token], i: usize) -> Option<usize> {
    let open = tokens[i].v.as_str();
    let close = match open {
        "(" => ")",
        "[" => "]",
        _ => "}",
    };
    let mut depth = 0;
    for (j, t) in tokens.iter().enumerate().skip(i) {
        if t.v == open {
            depth += 1;
        } else if t.v == close {
            depth -= 1;
            if depth == 0 {
                return Some(j);
            }
        }
    }
    None
}

/// The index after the group that opens at `i`.
fn skip(tokens: &[Token], i: usize) -> usize {
    match_close(tokens, i).map_or(tokens.len(), |c| c + 1)
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum VarKind {
    Param,
    Local,
}

#[derive(Clone, Debug)]
pub struct Var {
    pub name: String,
    pub ty: Option<Type>,
    pub type_text: String,
    pub offset: usize,
    pub kind: VarKind,
}

/// The function or the method that contains an offset.
pub struct Enclosing {
    pub class: Option<usize>,
    pub params: Vec<Param>,
    pub doc: String,
    /// The offset of the `{` of the body.
    pub body: Option<usize>,
    pub is_static: bool,
}

pub fn enclosing(parsed: &Parsed, offset: usize) -> Enclosing {
    for (ci, cls) in parsed.classes.iter().enumerate() {
        if cls.body_start.is_some_and(|b| offset > b && offset < cls.end) {
            let m = cls.methods.iter().find(|m| m.body.is_some_and(|(s, e)| offset > s && offset < e));
            return Enclosing {
                class: Some(ci),
                params: m.map(|m| m.params.clone()).unwrap_or_default(),
                doc: m.map(|m| m.doc.clone()).unwrap_or_default(),
                body: m.and_then(|m| m.body).map(|b| b.0),
                is_static: m.is_some_and(|m| m.is_static),
            };
        }
    }
    let f = parsed.functions.iter().find(|f| f.body.is_some_and(|(s, e)| offset > s && offset < e));
    Enclosing {
        class: None,
        params: f.map(|f| f.params.clone()).unwrap_or_default(),
        doc: f.map(|f| f.doc.clone()).unwrap_or_default(),
        body: f.and_then(|f| f.body).map(|b| b.0),
        is_static: false,
    }
}

pub struct Scope<'a, 'p> {
    pub index: &'a Index<'p>,
    pub source: Rc<Source>,
    pub vars: Vec<Var>,
    pub this_type: Option<Type>,
    /// The enclosing class.
    pub class: Option<usize>,
    pub self_: Option<String>,
    pub parent: Option<String>,
}

impl<'a, 'p> Scope<'a, 'p> {
    /// The scope of the current source of `index` at `offset`.
    pub fn at(index: &'a Index<'p>, offset: usize) -> Self {
        let source = index.current.clone();
        let en = enclosing(&source.parsed, offset);
        let cls = en.class.map(|c| &source.parsed.classes[c]);
        let parent = cls.filter(|c| c.kind != "interface").and_then(|c| c.extends.first()).and_then(|e| resolve_name(&source.parsed, e));
        let mut scope = Scope {
            index,
            source: source.clone(),
            vars: Vec::new(),
            this_type: None,
            class: en.class,
            self_: cls.map(|c| c.fqn.clone()),
            parent,
        };
        let Some(body) = en.body else { return scope };
        let tags = doc_tags(&en.doc);
        for p in &en.params {
            scope.param(p, tags.param(&p.name));
        }
        if let (Some(c), false) = (cls, en.is_static) {
            scope.this_type = Some(Type::class(c.fqn.clone()));
        }
        let start = body + 1;
        let tokens = tokens_in(source.code(), start, offset);
        scope.walk(&tokens);

        // /** @var Foo $x */ anywhere before the offset.
        let text = &source.text[start.min(source.text.len())..offset.min(source.text.len())];
        let mut from = 0;
        while let Some(i) = text[from..].find("@var") {
            let at = from + i;
            from = at + 4;
            let rest = &text[from..];
            let ws = rest.len() - rest.trim_start().len();
            if ws == 0 {
                continue;
            }
            let ty: String = rest[ws..].chars().take_while(|c| !c.is_whitespace()).collect();
            let after = &rest[ws + ty.len()..];
            let ws2 = after.len() - after.trim_start().len();
            if ws2 == 0 || !after[ws2..].starts_with('$') {
                continue;
            }
            let name: String = after[ws2 + 1..].chars().take_while(|c| c.is_alphanumeric() || *c == '_').collect();
            if !name.is_empty() {
                let t = to_type(&ty, &scope.ctx());
                scope.set(&name, t, start + at, &ty);
            }
        }
        scope
    }

    pub fn ctx(&self) -> TypeCtx<'_> {
        TypeCtx { parsed: &self.source.parsed, self_: self.self_.as_deref(), static_: None, parent: self.parent.as_deref() }
    }

    pub fn var(&self, name: &str) -> Option<&Var> {
        self.vars.iter().find(|v| v.name == name)
    }

    fn param(&mut self, p: &Param, doc_type: Option<&str>) {
        let type_text = doc_type.filter(|t| !t.is_empty()).unwrap_or(&p.ty).to_string();
        let mut ty = to_type(&type_text, &self.ctx()).or_else(|| to_type(&p.ty, &self.ctx()));
        if p.variadic && ty.is_some() && doc_type.is_none() {
            ty = Some(Type::list(ty));
        }
        self.put(Var { name: p.name.clone(), ty, type_text, offset: p.offset, kind: VarKind::Param });
    }

    /// Adds or replaces a variable.
    fn put(&mut self, v: Var) {
        match self.vars.iter_mut().find(|o| o.name == v.name) {
            Some(o) => *o = v,
            None => self.vars.push(v),
        }
    }

    /// Sets a variable. The first declaration keeps its offset; a known type replaces the type.
    fn set(&mut self, name: &str, ty: Option<Type>, offset: usize, type_text: &str) {
        if let Some(old) = self.vars.iter_mut().find(|v| v.name == name) {
            if ty.is_some() {
                old.ty = ty;
                if !type_text.is_empty() {
                    old.type_text = type_text.to_string();
                }
            }
            return;
        }
        self.vars.push(Var { name: name.to_string(), ty, type_text: type_text.to_string(), offset, kind: VarKind::Local });
    }

    fn walk(&mut self, tokens: &[Token]) {
        for i in 0..tokens.len() {
            let tok = &tokens[i];
            let next = tokens.get(i + 1);
            if tok.t == Tok::Var
                && next.is_some_and(|n| n.is("="))
                && tokens.get(i + 2).is_none_or(|t| !t.is("=") && !t.is(">"))
            {
                if i > 0 && ["->", "::", "?->"].contains(&tokens[i - 1].v.as_str()) {
                    continue;
                }
                let name = &tok.v[1..];
                let doc = doc_tags(&doc_before(&self.source.text, tok.start));
                let doc_type = doc.var_of(name).filter(|t| !t.is_empty()).map(str::to_string).or_else(|| (!doc.var.is_empty()).then(|| doc.var.clone()));
                let ty = match &doc_type {
                    Some(t) => to_type(t, &self.ctx()),
                    None => self.chain(tokens, i + 2, tokens.len()),
                };
                self.set(name, ty, tok.start, doc_type.as_deref().unwrap_or(""));
                continue;
            }
            if tok.is_word("foreach") && next.is_some_and(|n| n.is("(")) {
                self.foreach(tokens, i + 1);
                continue;
            }
            if tok.is_word("catch") && next.is_some_and(|n| n.is("(")) {
                let variable = tokens.iter().skip(i + 2).take(6).find(|t| t.t == Tok::Var);
                if let (Some(ty), Some(v)) = (tokens.get(i + 2).filter(|t| t.t == Tok::Id), variable) {
                    let t = to_type(&ty.v, &self.ctx());
                    self.set(&v.v[1..], t, v.start, &ty.v.clone());
                }
                continue;
            }
            if (tok.is_word("fn") || tok.is_word("function")) && next.is_some_and(|n| n.is("(")) {
                self.closure(tokens, i, tok.is_word("function"));
            }
        }
    }

    /// `foreach ($items as $item)` or `foreach ($items as $key => $item)`.
    fn foreach(&mut self, tokens: &[Token], open: usize) {
        let end = match_close(tokens, open).unwrap_or(tokens.len());
        let Some(as_) = (open + 1..end).find(|&j| tokens[j].is_word("as")) else { return };
        let list = self.chain(&tokens[..as_], open + 1, as_);
        let vars: Vec<&Token> = tokens[as_ + 1..end].iter().filter(|t| t.t == Tok::Var).collect();
        let has_key = tokens[as_ + 1..end].iter().any(|t| t.is("=>"));
        let Some(item) = vars.last() else { return };
        self.set(&item.v[1..], list.and_then(|l| l.item()), item.start, "");
        if has_key && vars.len() > 1 {
            self.set(&vars[0].v[1..], None, vars[0].start, "");
        }
    }

    /// The parameters of a closure count when the offset is in its body: the body does not close
    /// before the offset (the tokens end at the offset).
    fn closure(&mut self, tokens: &[Token], i: usize, is_function: bool) {
        let Some(close) = match_close(tokens, i + 1) else { return };
        let mut j = close + 1;
        if is_function {
            while j < tokens.len() && !tokens[j].is("{") {
                j += 1;
            }
            if j >= tokens.len() || match_close(tokens, j).is_some() {
                return;
            }
        } else {
            while j < tokens.len() && !tokens[j].is("=>") {
                j += 1;
            }
            let mut depth = 0;
            for t in tokens.iter().skip(j + 1) {
                match t.v.as_str() {
                    "(" | "[" | "{" => depth += 1,
                    ")" | "]" | "}" => {
                        depth -= 1;
                        if depth < 0 {
                            return;
                        }
                    }
                    "," | ";" if depth == 0 => return,
                    _ => {}
                }
            }
        }
        // The parameters: [Type] [&] [...] $name [= default]
        let mut ty = String::new();
        let mut k = i + 2;
        while k < close {
            let t = &tokens[k];
            if t.t == Tok::Var {
                let resolved = to_type(&ty, &self.ctx());
                self.put(Var { name: t.v[1..].to_string(), ty: resolved, type_text: std::mem::take(&mut ty), offset: t.start, kind: VarKind::Param });
                while k < close && !tokens[k].is(",") {
                    k += 1;
                }
            } else if t.t == Tok::Id || t.is("?") || t.is("|") {
                ty += &t.v;
            }
            k += 1;
        }
    }

    /// The type of a class name: `self`, `static`, `parent`, or a class.
    pub fn class_type(&self, name: &str) -> Option<Type> {
        if name.eq_ignore_ascii_case("self") || name.eq_ignore_ascii_case("static") {
            return self.self_.clone().map(Type::class);
        }
        if name.eq_ignore_ascii_case("parent") {
            return self.parent.clone().map(Type::class);
        }
        resolve_name(&self.source.parsed, name).map(Type::class)
    }

    /// The function that a call `name(...)` calls.
    pub fn find_function(&self, name: &str) -> Option<Found> {
        let parsed = &self.source.parsed;
        let mut candidates = Vec::new();
        if let Some(fqn) = name.strip_prefix('\\') {
            candidates.push(fqn.to_string());
        } else {
            let (first, rest) = match name.split_once('\\') {
                Some((f, r)) => (f, Some(r)),
                None => (name, None),
            };
            let kind = if rest.is_some() { UseKind::Class } else { UseKind::Function };
            if let Some(i) = imports_of(parsed, kind).find(|i| i.alias.eq_ignore_ascii_case(first)) {
                candidates.push(match rest {
                    Some(r) => format!("{}\\{r}", i.fqn),
                    None => i.fqn.clone(),
                });
            }
            if !parsed.namespace.is_empty() {
                candidates.push(format!("{}\\{name}", parsed.namespace));
            }
            candidates.push(name.to_string());
        }
        candidates.iter().find_map(|fqn| self.index.find(SymbolKind::Function, fqn))
    }

    fn function_type(&self, name: &str) -> Option<Type> {
        let found = self.find_function(name)?;
        let f = found.function();
        let ctx = TypeCtx::file(&found.source.parsed);
        to_type(&doc_tags(&f.doc).ret, &ctx).or_else(|| to_type(&f.return_type, &ctx))
    }

    fn member_type(&self, ty: &Type, name: &str, kind: MemberKind) -> Option<Type> {
        let fqn = ty.fqn.as_ref()?;
        self.index.member(fqn, name, Some(kind))?.ty
    }

    /// An array literal: a shape with its keys, or a list of shapes.
    fn shape(&self, tokens: &[Token], i: usize) -> Option<Type> {
        let end = match_close(tokens, i).unwrap_or(tokens.len());
        let mut keys = Vec::new();
        let mut first: Option<usize> = None;
        let mut depth = 0;
        let text = &self.source.text;
        for k in i + 1..end {
            let t = &tokens[k];
            match t.v.as_str() {
                "(" | "[" | "{" => {
                    if depth == 0 && t.is("[") && first.is_none() && keys.is_empty() {
                        first = Some(k);
                    }
                    depth += 1;
                    continue;
                }
                ")" | "]" | "}" => {
                    depth -= 1;
                    continue;
                }
                "=>" if depth == 0 => {
                    let from = tokens[k - 1].end;
                    if let Some((offset, name)) = string_before(&text[from.min(text.len())..t.start.min(text.len())]) {
                        let ty = match tokens.get(k + 1) {
                            Some(v) if v.is("[") => self.shape(tokens, k + 1),
                            _ => self.chain(tokens, k + 1, tokens.len()),
                        };
                        keys.push(Key { name, offset: Some(from + offset), ty, doc: None });
                    }
                }
                _ => {}
            }
        }
        if !keys.is_empty() {
            return Some(Type { keys: Some(keys), ..Default::default() });
        }
        first.map(|f| Type::list(self.shape(tokens, f)))
    }

    /// The type of the expression chain that starts at token `i`.
    pub fn chain(&self, tokens: &[Token], mut i: usize, end: usize) -> Option<Type> {
        let tok = tokens.get(i).filter(|_| i < end)?;
        let next_is = |i: usize, v: &str| tokens.get(i + 1).is_some_and(|t| t.is(v));
        let mut ty;
        if tok.is_word("clone") || tok.is_word("yield") || tok.is_word("await") {
            return self.chain(tokens, i + 1, end);
        }
        if tok.is_word("new") && tokens.get(i + 1).is_some_and(|t| t.t == Tok::Id) {
            ty = self.class_type(&tokens[i + 1].v);
            i += 2;
            if tokens.get(i).is_some_and(|t| t.is("(")) {
                i = skip(tokens, i);
            }
        } else if tok.is("(") {
            let close = match_close(tokens, i)?;
            ty = self.chain(tokens, i + 1, close);
            i = close + 1;
        } else if tok.t == Tok::Var {
            ty = if tok.v == "$this" { self.this_type.clone() } else { self.var(&tok.v[1..]).and_then(|v| v.ty.clone()) };
            i += 1;
        } else if tok.t == Tok::Id && next_is(i, "::") {
            ty = self.class_type(&tok.v);
            i += 1;
        } else if tok.t == Tok::Id && next_is(i, "(") {
            ty = self.function_type(&tok.v);
            i = skip(tokens, i + 1);
        } else if tok.is("[") {
            ty = self.shape(tokens, i);
            i = skip(tokens, i);
        } else {
            return None;
        }

        while i < end {
            let Some(t) = ty.as_ref() else { break };
            let tok = &tokens[i];
            let name = tokens.get(i + 1);
            let call = tokens.get(i + 2).is_some_and(|t| t.is("("));
            if (tok.is("->") || tok.is("?->")) && name.is_some_and(|n| n.t == Tok::Id) {
                let kind = if call { MemberKind::Method } else { MemberKind::Property };
                ty = self.member_type(t, &name.unwrap().v, kind);
                i = if call { skip(tokens, i + 2) } else { i + 2 };
            } else if tok.is("::") && name.is_some() {
                let name = name.unwrap();
                if name.t == Tok::Var {
                    ty = self.member_type(t, &name.v[1..], MemberKind::Property);
                    i += 2;
                } else if name.t == Tok::Id && call {
                    ty = self.member_type(t, &name.v, MemberKind::Method);
                    i = skip(tokens, i + 2);
                } else if name.t == Tok::Id && !name.is_word("class") {
                    ty = self.member_type(t, &name.v, MemberKind::Constant);
                    i += 2;
                } else {
                    return None;
                }
            } else if tok.is("[") {
                ty = match &t.keys {
                    Some(keys) => {
                        let key = key_access(&self.source.text[tok.start.min(self.source.text.len())..]);
                        key.and_then(|k| keys.iter().find(|e| e.name == k)).and_then(|e| e.ty.clone())
                    }
                    None => t.item(),
                };
                i = skip(tokens, i);
            } else {
                break;
            }
        }
        ty
    }

    /// The type of the PHP expression in `[start, end)` of the source.
    pub fn type_of_range(&self, start: usize, end: usize) -> Option<Type> {
        let tokens = tokens_in(self.source.code(), start, end);
        self.chain(&tokens, 0, tokens.len())
    }
}

/// The quoted string at the end of `s` (before `=>`): its offset in `s` and its content.
fn string_before(s: &str) -> Option<(usize, String)> {
    let s = s.trim_end();
    let quote = s.chars().last().filter(|c| *c == '\'' || *c == '"')?;
    let body = &s[..s.len() - 1];
    let b = body.as_bytes();
    let mut k = body.len();
    while k > 0 {
        k -= 1;
        if b[k] == quote as u8 && (k == 0 || b[k - 1] != b'\\') {
            return Some((k, body[k + 1..].to_string()));
        }
    }
    None
}

/// `['key']` at the start of `s`: the key.
fn key_access(s: &str) -> Option<String> {
    let s = s.trim_start().strip_prefix('[')?.trim_start();
    let quote = s.chars().next().filter(|c| *c == '\'' || *c == '"')?;
    let end = s[1..].find(quote)?;
    let rest = s[1 + end + 1..].trim_start();
    rest.starts_with(']').then(|| s[1..1 + end].to_string())
}

/// The start of the expression chain that ends at `end` in the masked code: `$this->a->b(1)` in
/// `x = $this->a->b(1)`.
pub fn chain_start(code: &[u8], end: usize) -> usize {
    let mut i = end.min(code.len());
    loop {
        while i > 0 && code[i - 1].is_ascii_whitespace() {
            i -= 1;
        }
        let Some(&c) = i.checked_sub(1).and_then(|k| code.get(k)) else { return i };
        if c == b')' || c == b']' {
            let open = if c == b')' { b'(' } else { b'[' };
            let mut depth = 0;
            let mut j = i;
            let mut found = false;
            while j > 0 {
                j -= 1;
                if code[j] == c {
                    depth += 1;
                } else if code[j] == open {
                    depth -= 1;
                    if depth == 0 {
                        found = true;
                        break;
                    }
                }
            }
            if !found {
                return i;
            }
            i = j;
            continue;
        }
        let word = code[..i].iter().rev().take(256).take_while(|&&c| c == b'$' || c == b'\\' || c.is_ascii_alphanumeric() || c == b'_').count();
        if word == 0 {
            return i;
        }
        let w = &code[i - word..i];
        i -= word;
        let before = trim_end(&code[..i]);
        if w.eq_ignore_ascii_case(b"new") {
            return i;
        }
        if before.ends_with(b"->") || before.ends_with(b"::") {
            i = before.len() - if before.ends_with(b"?->") { 3 } else { 2 };
            continue;
        }
        if ends_with_word(before, b"new") {
            return before.len() - 3;
        }
        return i;
    }
}

pub fn trim_end(s: &[u8]) -> &[u8] {
    let n = s.iter().rev().take_while(|c| c.is_ascii_whitespace()).count();
    &s[..s.len() - n]
}

/// True if `s` ends with the word `w` (case-insensitive), not a part of a longer word.
pub fn ends_with_word(s: &[u8], w: &[u8]) -> bool {
    s.len() >= w.len()
        && s[s.len() - w.len()..].eq_ignore_ascii_case(w)
        && (s.len() == w.len() || !matches!(s[s.len() - w.len() - 1], b'a'..=b'z' | b'A'..=b'Z' | b'0'..=b'9' | b'_'))
}
