//! Markup literal parser.
//!
//! A markup literal is one element or fragment. Its content is HTML-like markup mixed with
//! Twig regions (`{{ }}`, `{% %}`, `{# #}`). Twig block tags (`if`, `for`, ...) become tree
//! nodes, so that each branch must contain balanced elements.

use crate::diag::codes;
use crate::{Diagnostic, Span};

pub const VOID_ELEMENTS: &[&str] = &[
    "area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "source", "track", "wbr",
];

const RAW_TEXT_ELEMENTS: &[&str] = &["script", "style"];

/// Twig tags that have a body and an `end<tag>` closing tag.
const BLOCK_TAGS: &[&str] = &[
    "if", "for", "with", "apply", "autoescape", "block", "embed", "cache", "guard", "sandbox", "macro",
    "spaceless", "filter", "set",
];

#[derive(Clone, Debug)]
pub enum Node {
    Element(Element),
    Fragment(Fragment),
    /// Plain text, kept as written (it may contain HTML entities).
    Text(Text),
    /// An HTML comment `<!-- -->`, kept as written.
    HtmlComment(Text),
    /// `{{ expr }}`
    Output(Output),
    /// `{# comment #}`
    TwigComment(Span),
    /// A Twig tag without a body, like `{% set x = 1 %}` or `{% do ... %}`.
    Tag(Tag),
    /// A Twig tag with a body, like `{% if %}...{% else %}...{% endif %}`.
    Block(Block),
    /// `{% verbatim %}...{% endverbatim %}`, kept as written.
    Verbatim(Text),
}

impl Node {
    pub fn span(&self) -> Span {
        match self {
            Node::Element(e) => e.span,
            Node::Fragment(f) => f.span,
            Node::Text(t) | Node::HtmlComment(t) | Node::Verbatim(t) => t.span,
            Node::Output(o) => o.span,
            Node::TwigComment(s) => *s,
            Node::Tag(t) => t.span,
            Node::Block(b) => b.span,
        }
    }

    /// True for text that contains only whitespace.
    pub fn is_blank(&self) -> bool {
        matches!(self, Node::Text(t) if t.text.trim().is_empty())
    }
}

#[derive(Clone, Debug)]
pub struct Text {
    pub text: String,
    pub span: Span,
}

#[derive(Clone, Debug)]
pub struct Element {
    pub name: String,
    pub name_span: Span,
    pub attrs: Vec<Attr>,
    pub children: Vec<Node>,
    pub self_closing: bool,
    /// The whole element, from `<` to the end of the closing tag.
    pub span: Span,
    /// The start tag, from `<` to `>`.
    pub start_tag: Span,
}

impl Element {
    /// A capitalized name (or a qualified name) is a component.
    pub fn is_component(&self) -> bool {
        is_component_name(&self.name)
    }
}

pub fn is_component_name(name: &str) -> bool {
    name.starts_with(|c: char| c.is_ascii_uppercase()) || name.contains('\\')
}

#[derive(Clone, Debug)]
pub struct Fragment {
    pub children: Vec<Node>,
    pub span: Span,
}

#[derive(Clone, Debug)]
pub struct Attr {
    pub kind: AttrKind,
    pub span: Span,
    /// The number of newlines between the previous token and this attribute.
    pub newlines_before: usize,
    /// The whitespace between the previous token and this attribute, as written.
    pub ws_before: String,
}

#[derive(Clone, Debug)]
pub enum AttrKind {
    Plain { name: String, name_span: Span, value: AttrValue },
    /// `{{ ...expr }}`
    Spread(TwigExpr),
}

#[derive(Clone, Debug)]
pub enum AttrValue {
    /// A bare attribute: `disabled`.
    None,
    /// `"text {{ expr }}"`. `raw` is the text between the quotes.
    Quoted { parts: Vec<StrPart>, raw: String, quote: char },
    /// `{{ expr }}`
    Expr(TwigExpr),
}

#[derive(Clone, Debug)]
pub enum StrPart {
    Lit(String),
    Expr(TwigExpr),
}

/// A Twig expression. It can contain markup literals (`{{ ok ? <A /> : <B /> }}`).
#[derive(Clone, Debug)]
pub struct TwigExpr {
    pub parts: Vec<ExprPart>,
    /// The expression text, without the delimiters.
    pub span: Span,
}

#[derive(Clone, Debug)]
pub enum ExprPart {
    Code(String),
    Markup(Box<Node>),
}

impl TwigExpr {
    pub fn has_markup(&self) -> bool {
        self.parts.iter().any(|p| matches!(p, ExprPart::Markup(_)))
    }

    /// The code of an expression without markup.
    pub fn code(&self) -> String {
        self.parts
            .iter()
            .map(|p| match p {
                ExprPart::Code(c) => c.as_str(),
                ExprPart::Markup(_) => "",
            })
            .collect()
    }
}

#[derive(Clone, Debug)]
pub struct Output {
    pub expr: TwigExpr,
    pub span: Span,
    /// The whole `{{ ... }}` text, as written.
    pub raw: String,
}

#[derive(Clone, Debug)]
pub struct Tag {
    pub name: String,
    /// The text after the tag name, trimmed.
    pub head: String,
    pub head_span: Span,
    pub span: Span,
    /// The whole `{% ... %}` text, as written.
    pub raw: String,
}

#[derive(Clone, Debug)]
pub struct Block {
    /// One branch for each of `if` / `elseif` / `else` (or `for` / `else`).
    pub branches: Vec<Branch>,
    pub close: Tag,
    pub span: Span,
}

impl Block {
    pub fn name(&self) -> &str {
        &self.branches[0].tag.name
    }
}

#[derive(Clone, Debug)]
pub struct Branch {
    pub tag: Tag,
    pub children: Vec<Node>,
}

/// What ended a list of children.
enum Term {
    Close { name: String, span: Span },
    Tag(Tag),
}

/// Parses the markup literal that starts at `pos` (a `<`). Returns the root node and the end offset.
pub fn parse_literal(src: &str, pos: usize) -> Result<(Node, usize), Diagnostic> {
    let mut p = Parser { src, b: src.as_bytes(), pos };
    let node = p.parse_root()?;
    Ok((node, p.pos))
}

struct Parser<'a> {
    src: &'a str,
    b: &'a [u8],
    pos: usize,
}

impl<'a> Parser<'a> {
    fn peek(&self) -> Option<u8> {
        self.b.get(self.pos).copied()
    }

    fn peek_at(&self, n: usize) -> Option<u8> {
        self.b.get(self.pos + n).copied()
    }

    fn starts_with(&self, s: &str) -> bool {
        self.b[self.pos..].starts_with(s.as_bytes())
    }

    fn eof(&self) -> bool {
        self.pos >= self.b.len()
    }

    fn err(&self, code: &'static str, msg: impl Into<String>, span: Span) -> Diagnostic {
        Diagnostic::error(code, msg, span)
    }

    fn skip_ws(&mut self) -> usize {
        let mut newlines = 0;
        while let Some(c) = self.peek() {
            if c == b'\n' {
                newlines += 1;
            } else if !c.is_ascii_whitespace() {
                break;
            }
            self.pos += 1;
        }
        newlines
    }

    fn parse_root(&mut self) -> Result<Node, Diagnostic> {
        if self.starts_with("<>") {
            self.parse_fragment()
        } else {
            self.parse_element()
        }
    }

    fn parse_fragment(&mut self) -> Result<Node, Diagnostic> {
        let start = self.pos;
        self.pos += 2;
        let (children, term) = self.parse_children()?;
        match term {
            Some(Term::Close { name, .. }) if name.is_empty() => {}
            Some(Term::Close { name, span }) => {
                return Err(self
                    .err(codes::MISMATCHED_CLOSE, format!("expected `</>`, found `</{name}>`"), span)
                    .with_label("this closing tag does not match")
                    .with_help("a fragment `<>` closes with `</>`"))
            }
            Some(Term::Tag(tag)) => return Err(self.misplaced_tag(&tag, "<>", Span::new(start, start + 2))),
            None => {
                return Err(self
                    .err(codes::UNCLOSED, "unclosed fragment `<>`", Span::new(start, start + 2))
                    .with_label("opened here")
                    .with_help("close it with `</>`"))
            }
        }
        Ok(Node::Fragment(Fragment { children, span: Span::new(start, self.pos) }))
    }

    fn read_name(&mut self) -> String {
        let start = self.pos;
        while let Some(c) = self.peek() {
            if c.is_ascii_alphanumeric() || matches!(c, b'-' | b'_' | b'.' | b':' | b'\\') || c >= 0x80 {
                self.pos += 1;
            } else {
                break;
            }
        }
        self.src[start..self.pos].to_string()
    }

    fn parse_element(&mut self) -> Result<Node, Diagnostic> {
        let start = self.pos;
        self.pos += 1; // <
        let name_start = self.pos;
        let name = self.read_name();
        let name_span = Span::new(name_start, self.pos);
        if name.is_empty() {
            return Err(self.err(codes::SYNTAX, "expected a tag name after `<`", Span::new(start, start + 1)));
        }
        let (attrs, self_closing) = self.parse_attrs(&name, start)?;
        let start_tag = Span::new(start, self.pos);
        let lower = name.to_ascii_lowercase();

        if self_closing {
            return Ok(Node::Element(Element {
                name,
                name_span,
                attrs,
                children: vec![],
                self_closing,
                span: start_tag,
                start_tag,
            }));
        }
        if !is_component_name(&name) && VOID_ELEMENTS.contains(&lower.as_str()) {
            return Err(self
                .err(codes::VOID_NOT_CLOSED, format!("the void element `<{name}>` must be self-closed"), start_tag)
                .with_label("not closed")
                .with_help(format!("write `<{name} ... />`")));
        }

        let children = if !is_component_name(&name) && RAW_TEXT_ELEMENTS.contains(&lower.as_str()) {
            self.parse_raw_text(&name, start_tag)?
        } else {
            let (children, term) = self.parse_children()?;
            match term {
                Some(Term::Close { name: close, .. }) if close == name => {}
                Some(Term::Close { name: close, span }) => {
                    return Err(self
                        .err(codes::MISMATCHED_CLOSE, format!("expected `</{name}>`, found `</{close}>`"), span)
                        .with_label(format!("`<{name}>` is still open")))
                }
                Some(Term::Tag(tag)) => return Err(self.misplaced_tag(&tag, &format!("<{name}>"), start_tag)),
                None => {
                    return Err(self
                        .err(codes::UNCLOSED, format!("unclosed element `<{name}>`"), start_tag)
                        .with_label("opened here")
                        .with_help(format!("close it with `</{name}>`, or self-close it: `<{name} />`")))
                }
            }
            children
        };

        Ok(Node::Element(Element {
            name,
            name_span,
            attrs,
            children,
            self_closing,
            span: Span::new(start, self.pos),
            start_tag,
        }))
    }

    fn misplaced_tag(&self, tag: &Tag, open: &str, open_span: Span) -> Diagnostic {
        let (l, c) = crate::span::line_col(self.src, open_span.start);
        self.err(
            codes::MISMATCHED_CLOSE,
            format!("`{{% {} %}}` closes a Twig block, but `{open}` (line {l}, column {c}) is still open", tag.name),
            tag.span,
        )
        .with_label("unexpected Twig tag")
        .with_help("each branch of a Twig block must contain complete elements")
    }

    fn parse_raw_text(&mut self, name: &str, start_tag: Span) -> Result<Vec<Node>, Diagnostic> {
        let close = format!("</{name}");
        let start = self.pos;
        let Some(rel) = self.src[start..].find(&close) else {
            return Err(self.err(codes::UNCLOSED, format!("unclosed element `<{name}>`"), start_tag));
        };
        self.pos = start + rel;
        let text = Text { text: self.src[start..self.pos].to_string(), span: Span::new(start, self.pos) };
        self.pos += close.len();
        self.skip_ws();
        if self.peek() != Some(b'>') {
            return Err(self.err(codes::SYNTAX, "expected `>`", Span::new(self.pos, self.pos + 1)));
        }
        self.pos += 1;
        Ok(if text.text.is_empty() { vec![] } else { vec![Node::Text(text)] })
    }

    fn parse_attrs(&mut self, tag: &str, tag_start: usize) -> Result<(Vec<Attr>, bool), Diagnostic> {
        let mut attrs = Vec::new();
        loop {
            let ws_start = self.pos;
            let newlines_before = self.skip_ws();
            let ws_before = self.src[ws_start..self.pos].to_string();
            if self.eof() {
                return Err(self
                    .err(codes::UNCLOSED, format!("unclosed start tag `<{tag}`"), Span::new(tag_start, tag_start + 1)));
            }
            if self.starts_with("/>") {
                self.pos += 2;
                return Ok((attrs, true));
            }
            if self.peek() == Some(b'>') {
                self.pos += 1;
                return Ok((attrs, false));
            }
            let start = self.pos;
            if self.starts_with("{{") {
                let out = self.parse_output()?;
                let code = out.expr.code();
                let trimmed = code.trim_start();
                if !trimmed.starts_with("...") || out.expr.has_markup() {
                    return Err(self
                        .err(codes::BAD_ATTRIBUTE, "a Twig expression inside a tag must be a spread", out.span)
                        .with_help("write `{{ ...props }}`, or a dynamic attribute: `name={{ expr }}`"));
                }
                let lead = code.len() - trimmed.len() + 3;
                let inner = TwigExpr {
                    parts: vec![ExprPart::Code(trimmed[3..].to_string())],
                    span: Span::new(out.expr.span.start + lead, out.expr.span.end),
                };
                attrs.push(Attr { kind: AttrKind::Spread(inner), span: out.span, newlines_before, ws_before });
                continue;
            }
            if self.starts_with("{%") || self.starts_with("{#") {
                return Err(self
                    .err(codes::BAD_ATTRIBUTE, "Twig tags are not allowed inside a start tag", Span::new(start, start + 2))
                    .with_help("use a dynamic attribute: `disabled={{ isOff }}` or `class={{ ok ? 'on' : null }}`"));
            }
            while let Some(c) = self.peek() {
                if c.is_ascii_whitespace() || matches!(c, b'=' | b'>' | b'"' | b'\'' | b'{') || self.starts_with("/>") {
                    break;
                }
                self.pos += 1;
            }
            let name_span = Span::new(start, self.pos);
            let name = name_span.text(self.src).to_string();
            if name.is_empty() {
                return Err(self.err(codes::SYNTAX, "unexpected character in tag", Span::new(start, start + 1)));
            }
            let save = self.pos;
            self.skip_ws();
            let value = if self.peek() == Some(b'=') {
                self.pos += 1;
                self.skip_ws();
                self.parse_attr_value()?
            } else {
                self.pos = save;
                AttrValue::None
            };
            attrs.push(Attr {
                kind: AttrKind::Plain { name, name_span, value },
                span: Span::new(start, self.pos),
                newlines_before,
                ws_before,
            });
        }
    }

    fn parse_attr_value(&mut self) -> Result<AttrValue, Diagnostic> {
        match self.peek() {
            Some(q @ (b'"' | b'\'')) => {
                let open = self.pos;
                self.pos += 1;
                let raw_start = self.pos;
                let mut parts = Vec::new();
                let mut lit = String::new();
                loop {
                    if self.eof() {
                        return Err(self.err(codes::UNCLOSED, "unclosed attribute value", Span::new(open, open + 1)));
                    }
                    if self.starts_with("{{") {
                        if !lit.is_empty() {
                            parts.push(StrPart::Lit(std::mem::take(&mut lit)));
                        }
                        let out = self.parse_output()?;
                        parts.push(StrPart::Expr(out.expr));
                        continue;
                    }
                    let c = self.peek().unwrap();
                    if c == q {
                        break;
                    }
                    let ch = self.src[self.pos..].chars().next().unwrap();
                    lit.push(ch);
                    self.pos += ch.len_utf8();
                }
                let raw = self.src[raw_start..self.pos].to_string();
                self.pos += 1;
                if !lit.is_empty() {
                    parts.push(StrPart::Lit(lit));
                }
                Ok(AttrValue::Quoted { parts, raw, quote: q as char })
            }
            Some(b'{') if self.starts_with("{{") => Ok(AttrValue::Expr(self.parse_output()?.expr)),
            _ => {
                let start = self.pos;
                while let Some(c) = self.peek() {
                    if c.is_ascii_whitespace() || c == b'>' || self.starts_with("/>") {
                        break;
                    }
                    self.pos += 1;
                }
                if start == self.pos {
                    return Err(self.err(codes::SYNTAX, "expected an attribute value", Span::new(start, start + 1)));
                }
                let raw = self.src[start..self.pos].to_string();
                Ok(AttrValue::Quoted { parts: vec![StrPart::Lit(raw.clone())], raw, quote: '"' })
            }
        }
    }

    /// Parses children until a closing tag, a Twig branch/end tag, or the end of input.
    fn parse_children(&mut self) -> Result<(Vec<Node>, Option<Term>), Diagnostic> {
        let mut children = Vec::new();
        loop {
            if self.eof() {
                return Ok((children, None));
            }
            if self.starts_with("</") {
                let start = self.pos;
                self.pos += 2;
                let name = self.read_name();
                self.skip_ws();
                if self.peek() != Some(b'>') {
                    return Err(self.err(codes::SYNTAX, "expected `>` to end the closing tag", Span::new(start, self.pos)));
                }
                self.pos += 1;
                return Ok((children, Some(Term::Close { name, span: Span::new(start, self.pos) })));
            }
            if self.starts_with("<!--") {
                let start = self.pos;
                let end = self.src[start..]
                    .find("-->")
                    .map(|i| start + i + 3)
                    .ok_or_else(|| self.err(codes::UNCLOSED, "unclosed HTML comment", Span::new(start, start + 4)))?;
                self.pos = end;
                children.push(Node::HtmlComment(Text { text: self.src[start..end].to_string(), span: Span::new(start, end) }));
                continue;
            }
            if self.peek() == Some(b'<') && matches!(self.peek_at(1), Some(c) if c.is_ascii_alphabetic() || c == b'>') {
                children.push(self.parse_root()?);
                continue;
            }
            if self.starts_with("{{") {
                children.push(Node::Output(self.parse_output()?));
                continue;
            }
            if self.starts_with("{#") {
                let start = self.pos;
                let end = self.src[start..]
                    .find("#}")
                    .map(|i| start + i + 2)
                    .ok_or_else(|| self.err(codes::UNCLOSED, "unclosed Twig comment", Span::new(start, start + 2)))?;
                self.pos = end;
                children.push(Node::TwigComment(Span::new(start, end)));
                continue;
            }
            if self.starts_with("{%") {
                let tag = self.parse_tag()?;
                let n = tag.name.as_str();
                if n == "else" || n == "elseif" || n.starts_with("end") {
                    return Ok((children, Some(Term::Tag(tag))));
                }
                if n == "verbatim" {
                    children.push(self.parse_verbatim(tag)?);
                } else if is_block_tag(&tag) {
                    children.push(self.parse_block(tag)?);
                } else {
                    children.push(Node::Tag(tag));
                }
                continue;
            }
            children.push(Node::Text(self.parse_text()));
        }
    }

    fn parse_text(&mut self) -> Text {
        let start = self.pos;
        while !self.eof() {
            // Always consume at least one character (for example a `<!DOCTYPE` that is not a tag).
            if self.pos > start && (self.starts_with("{{") || self.starts_with("{%") || self.starts_with("{#")) {
                break;
            }
            if self.pos > start
                && self.peek() == Some(b'<')
                && matches!(self.peek_at(1), Some(c) if c.is_ascii_alphabetic() || c == b'>' || c == b'/' || c == b'!')
            {
                break;
            }
            let ch = self.src[self.pos..].chars().next().unwrap();
            self.pos += ch.len_utf8();
        }
        Text { text: self.src[start..self.pos].to_string(), span: Span::new(start, self.pos) }
    }

    fn parse_block(&mut self, open: Tag) -> Result<Node, Diagnostic> {
        let name = open.name.clone();
        let start = open.span.start;
        let mut branches = vec![Branch { tag: open, children: vec![] }];
        loop {
            let (children, term) = self.parse_children()?;
            branches.last_mut().unwrap().children = children;
            match term {
                Some(Term::Tag(tag)) if tag.name == format!("end{name}") => {
                    return Ok(Node::Block(Block { branches, span: Span::new(start, tag.span.end), close: tag }));
                }
                Some(Term::Tag(tag))
                    if (name == "if" && (tag.name == "elseif" || tag.name == "else"))
                        || (name == "for" && tag.name == "else") =>
                {
                    branches.push(Branch { tag, children: vec![] });
                }
                Some(Term::Tag(tag)) => {
                    return Err(self
                        .err(codes::MISMATCHED_CLOSE, format!("unexpected `{{% {} %}}` in a `{{% {name} %}}` block", tag.name), tag.span)
                        .with_help(format!("close the block with `{{% end{name} %}}`")))
                }
                Some(Term::Close { name: close, span }) => {
                    return Err(self
                        .err(codes::MISMATCHED_CLOSE, format!("`</{close}>` closes an element that was opened outside this `{{% {name} %}}` block"), span)
                        .with_help("each branch of a Twig block must contain complete elements"))
                }
                None => {
                    return Err(self
                        .err(codes::UNCLOSED, format!("unclosed Twig block `{{% {name} %}}`"), branches[0].tag.span)
                        .with_help(format!("close it with `{{% end{name} %}}`")))
                }
            }
        }
    }

    fn parse_verbatim(&mut self, open: Tag) -> Result<Node, Diagnostic> {
        let start = open.span.start;
        let mut search = self.pos;
        loop {
            let Some(rel) = self.src[search..].find("{%") else {
                return Err(self.err(codes::UNCLOSED, "unclosed `{% verbatim %}`", open.span));
            };
            self.pos = search + rel;
            let tag = self.parse_tag()?;
            if tag.name == "endverbatim" {
                let end = self.pos;
                return Ok(Node::Verbatim(Text { text: self.src[start..end].to_string(), span: Span::new(start, end) }));
            }
            search = self.pos;
        }
    }

    fn parse_tag(&mut self) -> Result<Tag, Diagnostic> {
        let start = self.pos;
        self.pos += 2;
        if matches!(self.peek(), Some(b'-' | b'~')) {
            self.pos += 1;
        }
        let inner_start = self.pos;
        let end = self.scan_twig_end(b'%', start)?;
        let inner = &self.src[inner_start..end.0];
        let trimmed = inner.trim_start();
        let lead = inner.len() - trimmed.len();
        let name_len = trimmed.find(|c: char| !(c.is_ascii_alphanumeric() || c == '_')).unwrap_or(trimmed.len());
        let name = trimmed[..name_len].to_string();
        let head_raw = &trimmed[name_len..];
        let head = head_raw.trim().to_string();
        let head_start = inner_start + lead + name_len + (head_raw.len() - head_raw.trim_start().len());
        self.pos = end.1;
        if name.is_empty() {
            return Err(self.err(codes::SYNTAX, "expected a Twig tag name", Span::new(start, self.pos)));
        }
        Ok(Tag {
            name,
            head_span: Span::new(head_start, head_start + head.len()),
            head,
            span: Span::new(start, self.pos),
            raw: self.src[start..self.pos].to_string(),
        })
    }

    fn parse_output(&mut self) -> Result<Output, Diagnostic> {
        let start = self.pos;
        self.pos += 2;
        if matches!(self.peek(), Some(b'-' | b'~')) {
            self.pos += 1;
        }
        let expr = self.parse_expr(start)?;
        Ok(Output { expr, span: Span::new(start, self.pos), raw: self.src[start..self.pos].to_string() })
    }

    /// Finds the end of a Twig region (`%}` or `}}`) at depth 0, outside strings.
    /// Returns (end of the inner text, position after the delimiter).
    fn scan_twig_end(&mut self, close: u8, open: usize) -> Result<(usize, usize), Diagnostic> {
        let mut depth = 0i32;
        while !self.eof() {
            let c = self.peek().unwrap();
            match c {
                b'\'' | b'"' => self.skip_string(c)?,
                b'{' | b'(' | b'[' => {
                    depth += 1;
                    self.pos += 1;
                }
                b')' | b']' => {
                    depth -= 1;
                    self.pos += 1;
                }
                b'}' if close == b'}' && depth == 0 && self.peek_at(1) == Some(b'}') => {
                    return Ok((self.pos, self.pos + 2));
                }
                b'}' => {
                    depth -= 1;
                    self.pos += 1;
                }
                b'-' | b'~' if depth == 0 && self.peek_at(1) == Some(close) && self.peek_at(2) == Some(b'}') => {
                    return Ok((self.pos, self.pos + 3));
                }
                _ if c == close && depth == 0 && self.peek_at(1) == Some(b'}') => {
                    return Ok((self.pos, self.pos + 2));
                }
                _ => self.pos += 1,
            }
        }
        let what = if close == b'%' { "`{% ... %}`" } else { "`{{ ... }}`" };
        Err(self.err(codes::UNCLOSED, format!("unclosed Twig {what}"), Span::new(open, open + 2)))
    }

    fn skip_string(&mut self, q: u8) -> Result<(), Diagnostic> {
        let start = self.pos;
        self.pos += 1;
        let mut interp = 0i32;
        while let Some(c) = self.peek() {
            if c == b'\\' {
                self.pos += 2;
                continue;
            }
            if q == b'"' && c == b'#' && self.peek_at(1) == Some(b'{') {
                interp += 1;
                self.pos += 2;
                continue;
            }
            if interp > 0 && c == b'}' {
                interp -= 1;
            } else if interp == 0 && c == q {
                self.pos += 1;
                return Ok(());
            }
            self.pos += 1;
        }
        Err(self.err(codes::UNCLOSED, "unclosed string", Span::new(start, start + 1)))
    }

    /// Parses a `{{ }}` expression body (after `{{`), with markup detection.
    fn parse_expr(&mut self, open: usize) -> Result<TwigExpr, Diagnostic> {
        let inner_start = self.pos;
        let mut parts: Vec<ExprPart> = Vec::new();
        let mut code_start = self.pos;
        let mut depth = 0i32;
        loop {
            if self.eof() {
                return Err(self.err(codes::UNCLOSED, "unclosed Twig `{{ ... }}`", Span::new(open, open + 2)));
            }
            let c = self.peek().unwrap();
            let end_here = (c == b'}' && depth == 0 && self.peek_at(1) == Some(b'}'))
                || (matches!(c, b'-' | b'~') && depth == 0 && self.peek_at(1) == Some(b'}') && self.peek_at(2) == Some(b'}'));
            if end_here {
                let inner_end = self.pos;
                parts.push(ExprPart::Code(self.src[code_start..inner_end].to_string()));
                self.pos += if c == b'}' { 2 } else { 3 };
                parts.retain(|p| !matches!(p, ExprPart::Code(s) if s.is_empty()));
                return Ok(TwigExpr { parts, span: Span::new(inner_start, inner_end) });
            }
            match c {
                b'\'' | b'"' => self.skip_string(c)?,
                b'{' | b'(' | b'[' => {
                    depth += 1;
                    self.pos += 1;
                }
                b'}' | b')' | b']' => {
                    depth -= 1;
                    self.pos += 1;
                }
                b'<' if matches!(self.peek_at(1), Some(n) if n.is_ascii_alphabetic() || n == b'>')
                    && allows_markup(&self.src[code_start..self.pos], parts.is_empty()) =>
                {
                    parts.push(ExprPart::Code(self.src[code_start..self.pos].to_string()));
                    let node = self.parse_root()?;
                    parts.push(ExprPart::Markup(Box::new(node)));
                    code_start = self.pos;
                }
                _ => self.pos += 1,
            }
        }
    }
}

fn is_block_tag(tag: &Tag) -> bool {
    if !BLOCK_TAGS.contains(&tag.name.as_str()) {
        return false;
    }
    match tag.name.as_str() {
        // `{% set x = 1 %}` has no body; `{% set x %}...{% endset %}` has one.
        "set" => !tag.head.contains('='),
        // `{% block name 'short' %}` has no body.
        "block" => tag.head.split_whitespace().count() <= 1,
        _ => true,
    }
}

/// Markup can start a Twig (sub)expression: at the start, or after `?`, `:`, `(`, `,`, `[`, `=`, `and`, `or`, `not`.
fn allows_markup(code_before: &str, at_start: bool) -> bool {
    let t = code_before.trim_end();
    if t.is_empty() {
        return at_start || code_before.is_empty();
    }
    if t.ends_with(['?', ':', '(', ',', '[', '=']) {
        return true;
    }
    for word in ["and", "or", "not"] {
        if let Some(rest) = t.strip_suffix(word) {
            if rest.is_empty() || !rest.ends_with(|c: char| c.is_alphanumeric() || c == '_' || c == '.') {
                return true;
            }
        }
    }
    false
}

#[cfg(test)]
mod tests {
    use super::*;

    fn parse(s: &str) -> Node {
        let (n, end) = parse_literal(s, 0).unwrap_or_else(|d| panic!("{}: {}", d.code, d.message));
        assert_eq!(end, s.len(), "did not consume all input");
        n
    }

    fn parse_err(s: &str) -> Diagnostic {
        parse_literal(s, 0).expect_err("expected an error")
    }

    #[test]
    fn element_with_attributes() {
        let Node::Element(e) = parse(r#"<img className="avatar" src={{ user.imageUrl }} alt="Photo of {{ user.name }}" disabled />"#) else {
            panic!()
        };
        assert_eq!(e.name, "img");
        assert!(e.self_closing);
        assert_eq!(e.attrs.len(), 4);
        match &e.attrs[1].kind {
            AttrKind::Plain { name, value: AttrValue::Expr(x), .. } => {
                assert_eq!(name, "src");
                assert_eq!(x.code().trim(), "user.imageUrl");
            }
            _ => panic!(),
        }
        match &e.attrs[2].kind {
            AttrKind::Plain { value: AttrValue::Quoted { parts, .. }, .. } => assert_eq!(parts.len(), 2),
            _ => panic!(),
        }
    }

    #[test]
    fn nested_hash_in_attribute() {
        let Node::Element(e) = parse("<li style={{ {color: a ? 'x' : 'y'} }}>t</li>") else { panic!() };
        match &e.attrs[0].kind {
            AttrKind::Plain { value: AttrValue::Expr(x), .. } => assert_eq!(x.code().trim(), "{color: a ? 'x' : 'y'}"),
            _ => panic!(),
        }
    }

    #[test]
    fn twig_blocks_and_fragment() {
        let Node::Fragment(f) = parse("<>{% for p in ps %}<li>{{ p }}</li>{% else %}<li>none</li>{% endfor %}</>") else { panic!() };
        let Node::Block(b) = &f.children[0] else { panic!() };
        assert_eq!(b.name(), "for");
        assert_eq!(b.branches.len(), 2);
    }

    #[test]
    fn markup_in_ternary() {
        let Node::Element(e) = parse("<div>{{ ok ? <A /> : <B x={{ 1 }} /> }}</div>") else { panic!() };
        let Node::Output(o) = &e.children[0] else { panic!() };
        assert_eq!(o.expr.parts.iter().filter(|p| matches!(p, ExprPart::Markup(_))).count(), 2);
    }

    #[test]
    fn less_than_is_not_markup() {
        let Node::Element(e) = parse("<p>{{ a < b ? 'x' : 'y' }}</p>") else { panic!() };
        let Node::Output(o) = &e.children[0] else { panic!() };
        assert!(!o.expr.has_markup());
    }

    #[test]
    fn spread_attribute() {
        let Node::Element(e) = parse("<Card {{ ...props }} />") else { panic!() };
        assert!(matches!(&e.attrs[0].kind, AttrKind::Spread(x) if x.code().trim() == "props"));
    }

    #[test]
    fn errors() {
        assert_eq!(parse_err("<div><br></div>").code, codes::VOID_NOT_CLOSED);
        assert_eq!(parse_err("<div><span></div>").code, codes::MISMATCHED_CLOSE);
        assert_eq!(parse_err("<div>{% if a %}<p>{% endif %}</p></div>").code, codes::MISMATCHED_CLOSE);
        assert_eq!(parse_err("<div>text").code, codes::UNCLOSED);
        assert_eq!(parse_err("<div {% if a %}x{% endif %}></div>").code, codes::BAD_ATTRIBUTE);
    }

    #[test]
    fn script_raw_text() {
        let Node::Element(e) = parse("<script>if (a < b) { x() }</script>") else { panic!() };
        assert_eq!(e.children.len(), 1);
    }
}
