//! PHP lexer for `.phpm` files.
//!
//! The lexer knows enough PHP to find markup literals safely: strings, heredoc/nowdoc,
//! comments, attributes, and operators. A `<` starts a markup literal when it comes where an
//! expression starts and a letter or `>` follows it.

use crate::diag::codes;
use crate::markup::{self, Node};
use crate::{Diagnostic, Span};

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum TokKind {
    OpenTag,
    CloseTag,
    InlineHtml,
    Ws,
    Comment,
    /// `$name`
    Var,
    /// A name, a keyword, or a qualified name (`Foo\Bar`, `\Foo`).
    Ident,
    Number,
    Str,
    Op,
    /// `#[`
    AttrOpen,
    /// A markup literal; the value is an index into [`Lexed::markups`].
    Markup(usize),
}

#[derive(Clone, Copy, Debug)]
pub struct Token {
    pub kind: TokKind,
    pub span: Span,
}

impl Token {
    pub fn text<'a>(&self, src: &'a str) -> &'a str {
        self.span.text(src)
    }

    pub fn is_trivia(&self) -> bool {
        matches!(self.kind, TokKind::Ws | TokKind::Comment)
    }
}

#[derive(Clone, Debug)]
pub struct MarkupLiteral {
    pub root: Node,
    pub span: Span,
}

#[derive(Clone, Debug, Default)]
pub struct Lexed {
    pub tokens: Vec<Token>,
    pub markups: Vec<MarkupLiteral>,
}

const OPS: &[&str] = &[
    "**=", "...", "<=>", "===", "!==", "<<=", ">>=", "??=", "?->", "->", "=>", "::", "??", "**", "==", "!=", "<>",
    "<=", ">=", "<<", ">>", "&&", "||", "++", "--", "+=", "-=", "*=", "/=", ".=", "%=", "&=", "|=", "^=", "|>", "+",
    "-", "*", "/", "%", "=", "<", ">", "!", ".", ",", ";", ":", "?", "(", ")", "[", "]", "{", "}", "&", "|", "^",
    "~", "@", "$", "\\",
];

/// Tokens after which an expression starts (so a `<` there starts markup).
const EXPR_START_OPS: &[&str] = &["(", "[", ",", "=", "=>", "?", ":", "??", "&&", "||", "!", ".", "??="];
const EXPR_START_WORDS: &[&str] = &["return", "yield", "echo", "print", "and", "or", "xor"];

fn is_ident_start(c: u8) -> bool {
    c.is_ascii_alphabetic() || c == b'_' || c >= 0x80
}

fn is_ident_char(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_' || c >= 0x80
}

pub fn lex(src: &str) -> Result<Lexed, Diagnostic> {
    Lexer { src, b: src.as_bytes(), pos: 0, out: Lexed::default() }.run()
}

struct Lexer<'a> {
    src: &'a str,
    b: &'a [u8],
    pos: usize,
    out: Lexed,
}

impl<'a> Lexer<'a> {
    fn peek_at(&self, n: usize) -> Option<u8> {
        self.b.get(self.pos + n).copied()
    }

    fn starts_with(&self, s: &str) -> bool {
        self.b[self.pos..].starts_with(s.as_bytes())
    }

    fn push(&mut self, kind: TokKind, start: usize) {
        self.out.tokens.push(Token { kind, span: Span::new(start, self.pos) });
    }

    fn last_significant(&self) -> Option<&Token> {
        self.out.tokens.iter().rev().find(|t| !t.is_trivia())
    }

    fn run(mut self) -> Result<Lexed, Diagnostic> {
        self.inline_html()?;
        while self.pos < self.b.len() {
            self.token()?;
        }
        self.check_single_roots()?;
        Ok(self.out)
    }

    /// The text up to the next `<?phpm` open tag. A `.phpm` file opens PHP only with `<?phpm`: any
    /// other `<?php...` tag is an error, because the file would otherwise be copied out as HTML.
    fn inline_html(&mut self) -> Result<(), Diagnostic> {
        let start = self.pos;
        match self.src[self.pos..].find("<?php") {
            Some(rel) => {
                self.pos += rel;
                if self.pos > start {
                    self.push(TokKind::InlineHtml, start);
                }
                let tag_start = self.pos;
                let tag_end = tag_start + "<?phpm".len();
                if !self.starts_with("<?phpm") || self.b.get(tag_end).is_some_and(|&c| is_ident_char(c)) {
                    return Err(Diagnostic::error(
                        codes::PLAIN_OPEN_TAG,
                        "a .phpm file opens PHP with `<?phpm`",
                        Span::new(tag_start, tag_start + "<?php".len()),
                    )
                    .with_help("write `<?phpm` instead of `<?php`"));
                }
                self.pos = tag_end;
                self.push(TokKind::OpenTag, tag_start);
            }
            None => {
                self.pos = self.b.len();
                if self.pos > start {
                    self.push(TokKind::InlineHtml, start);
                }
            }
        }
        Ok(())
    }

    fn token(&mut self) -> Result<(), Diagnostic> {
        let start = self.pos;
        let c = self.b[self.pos];

        if c.is_ascii_whitespace() {
            while matches!(self.peek_at(0), Some(c) if c.is_ascii_whitespace()) {
                self.pos += 1;
            }
            self.push(TokKind::Ws, start);
            return Ok(());
        }
        if self.starts_with("#[") {
            self.pos += 2;
            self.push(TokKind::AttrOpen, start);
            return Ok(());
        }
        if c == b'#' || self.starts_with("//") {
            while let Some(c) = self.peek_at(0) {
                if c == b'\n' || self.starts_with("?>") {
                    break;
                }
                self.pos += 1;
            }
            self.push(TokKind::Comment, start);
            return Ok(());
        }
        if self.starts_with("/*") {
            let end = self.src[self.pos + 2..]
                .find("*/")
                .map(|i| self.pos + 2 + i + 2)
                .ok_or_else(|| Diagnostic::error(codes::UNCLOSED, "unclosed comment", Span::new(start, start + 2)))?;
            self.pos = end;
            self.push(TokKind::Comment, start);
            return Ok(());
        }
        if self.starts_with("?>") {
            self.pos += 2;
            self.push(TokKind::CloseTag, start);
            return self.inline_html();
        }
        if c == b'$' && matches!(self.peek_at(1), Some(n) if is_ident_start(n)) {
            self.pos += 1;
            while matches!(self.peek_at(0), Some(c) if is_ident_char(c)) {
                self.pos += 1;
            }
            self.push(TokKind::Var, start);
            return Ok(());
        }
        if is_ident_start(c) || (c == b'\\' && matches!(self.peek_at(1), Some(n) if is_ident_start(n))) {
            self.ident();
            self.push(TokKind::Ident, start);
            return Ok(());
        }
        if c.is_ascii_digit() || (c == b'.' && matches!(self.peek_at(1), Some(n) if n.is_ascii_digit())) {
            self.number();
            self.push(TokKind::Number, start);
            return Ok(());
        }
        if c == b'\'' || c == b'"' || c == b'`' {
            self.string(c)?;
            self.push(TokKind::Str, start);
            return Ok(());
        }
        if self.starts_with("<<<") {
            self.heredoc()?;
            self.push(TokKind::Str, start);
            return Ok(());
        }
        if c == b'<' && matches!(self.peek_at(1), Some(n) if n.is_ascii_alphabetic() || n == b'>') && self.at_expr_start() {
            let (root, end) = markup::parse_literal(self.src, self.pos)?;
            self.pos = end;
            let index = self.out.markups.len();
            self.out.markups.push(MarkupLiteral { root, span: Span::new(start, end) });
            self.push(TokKind::Markup(index), start);
            return Ok(());
        }
        for op in OPS {
            if self.starts_with(op) {
                self.pos += op.len();
                self.push(TokKind::Op, start);
                return Ok(());
            }
        }
        let ch = self.src[self.pos..].chars().next().unwrap();
        Err(Diagnostic::error(codes::SYNTAX, format!("unexpected character `{ch}`"), Span::new(start, start + ch.len_utf8())))
    }

    fn at_expr_start(&self) -> bool {
        let Some(t) = self.last_significant() else { return false };
        let text = t.text(self.src);
        match t.kind {
            TokKind::Op => EXPR_START_OPS.contains(&text),
            TokKind::Ident => EXPR_START_WORDS.contains(&text.to_ascii_lowercase().as_str()),
            _ => false,
        }
    }

    fn ident(&mut self) {
        loop {
            if self.peek_at(0) == Some(b'\\') {
                self.pos += 1;
            }
            while matches!(self.peek_at(0), Some(c) if is_ident_char(c)) {
                self.pos += 1;
            }
            if !(self.peek_at(0) == Some(b'\\') && matches!(self.peek_at(1), Some(n) if is_ident_start(n))) {
                break;
            }
        }
    }

    fn number(&mut self) {
        if self.starts_with("0x") || self.starts_with("0X") || self.starts_with("0b") || self.starts_with("0B") {
            self.pos += 2;
            while matches!(self.peek_at(0), Some(c) if c.is_ascii_hexdigit() || c == b'_') {
                self.pos += 1;
            }
            return;
        }
        while matches!(self.peek_at(0), Some(c) if c.is_ascii_digit() || c == b'_') {
            self.pos += 1;
        }
        if self.peek_at(0) == Some(b'.') && matches!(self.peek_at(1), Some(c) if c.is_ascii_digit()) {
            self.pos += 1;
            while matches!(self.peek_at(0), Some(c) if c.is_ascii_digit() || c == b'_') {
                self.pos += 1;
            }
        }
        if matches!(self.peek_at(0), Some(b'e' | b'E'))
            && (matches!(self.peek_at(1), Some(c) if c.is_ascii_digit())
                || (matches!(self.peek_at(1), Some(b'+' | b'-')) && matches!(self.peek_at(2), Some(c) if c.is_ascii_digit())))
        {
            self.pos += 2;
            while matches!(self.peek_at(0), Some(c) if c.is_ascii_digit()) {
                self.pos += 1;
            }
        }
    }

    fn string(&mut self, q: u8) -> Result<(), Diagnostic> {
        let start = self.pos;
        self.pos += 1;
        while let Some(c) = self.peek_at(0) {
            if c == b'\\' {
                self.pos += 2;
                continue;
            }
            self.pos += 1;
            if c == q {
                return Ok(());
            }
        }
        Err(Diagnostic::error(codes::UNCLOSED, "unclosed string", Span::new(start, start + 1)))
    }

    fn heredoc(&mut self) -> Result<(), Diagnostic> {
        let start = self.pos;
        self.pos += 3;
        while matches!(self.peek_at(0), Some(b' ' | b'\t')) {
            self.pos += 1;
        }
        let quote = match self.peek_at(0) {
            Some(q @ (b'\'' | b'"')) => {
                self.pos += 1;
                Some(q)
            }
            _ => None,
        };
        let id_start = self.pos;
        while matches!(self.peek_at(0), Some(c) if is_ident_char(c)) {
            self.pos += 1;
        }
        let id = self.src[id_start..self.pos].to_string();
        if quote.is_some() {
            self.pos += 1;
        }
        let err = || Diagnostic::error(codes::UNCLOSED, format!("unclosed heredoc `{id}`"), Span::new(start, start + 3));
        if id.is_empty() {
            return Err(err());
        }
        // The closing identifier is on its own line, after optional indentation.
        let mut line_start = match self.src[self.pos..].find('\n') {
            Some(i) => self.pos + i + 1,
            None => return Err(err()),
        };
        loop {
            let rest = &self.src[line_start..];
            let trimmed = rest.trim_start_matches([' ', '\t']);
            let indent = rest.len() - trimmed.len();
            if trimmed.starts_with(id.as_str())
                && !trimmed.as_bytes().get(id.len()).is_some_and(|&c| is_ident_char(c))
            {
                self.pos = line_start + indent + id.len();
                return Ok(());
            }
            match rest.find('\n') {
                Some(i) => line_start += i + 1,
                None => return Err(err()),
            }
        }
    }

    /// `return <h1 /><p />;` — a second element right after a markup literal means two roots.
    fn check_single_roots(&self) -> Result<(), Diagnostic> {
        let toks = &self.out.tokens;
        for (i, t) in toks.iter().enumerate() {
            if let TokKind::Markup(_) = t.kind {
                let next = toks[i + 1..].iter().find(|t| !t.is_trivia());
                if let Some(n) = next {
                    let s = n.span.start;
                    if n.kind == TokKind::Op
                        && n.text(self.src) == "<"
                        && self.b.get(s + 1).is_some_and(|c| c.is_ascii_alphabetic())
                    {
                        return Err(Diagnostic::error(
                            codes::MULTIPLE_ROOTS,
                            "markup must have a single root element",
                            Span::new(t.span.start, s + 1),
                        )
                        .with_label("a second element starts here")
                        .with_help("wrap the elements in a fragment: `<>...</>`"));
                    }
                }
            }
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn kinds(src: &str) -> Vec<TokKind> {
        lex(src).unwrap().tokens.into_iter().filter(|t| !t.is_trivia()).map(|t| t.kind).collect()
    }

    #[test]
    fn detects_markup_after_return() {
        let l = lex("<?phpm function A() { return <div>{{ x }}</div>; }").unwrap();
        assert_eq!(l.markups.len(), 1);
    }

    #[test]
    fn less_than_operator_is_not_markup() {
        let l = lex("<?phpm if ($a <b) {} $x = $a < $b; $y = 1 <=> 2;").unwrap();
        assert!(l.markups.is_empty());
    }

    #[test]
    fn markup_in_arrow_function_and_arrays() {
        let l = lex("<?phpm $f = fn($p) => <li key={{ p.id }}>{{ p.t }}</li>; $a = [<A />, <B />];").unwrap();
        assert_eq!(l.markups.len(), 3);
    }

    #[test]
    fn strings_and_heredoc_hide_markup() {
        let l = lex("<?phpm $s = '<div>'; $t = \"<b>\"; $h = <<<EOT\n  <div>\n  EOT;\n $n = <<<'X'\n<p>\nX;\n").unwrap();
        assert!(l.markups.is_empty());
    }

    #[test]
    fn comments_and_attributes() {
        let k = kinds("<?phpm #[Component] // c <div>\n /* <p> */ class A {}");
        assert_eq!(k[1], TokKind::AttrOpen);
    }

    #[test]
    fn two_roots_is_an_error() {
        let e = lex("<?phpm return <h1>a</h1><p>b</p>;").unwrap_err();
        assert_eq!(e.code, codes::MULTIPLE_ROOTS);
    }

    #[test]
    fn phpm_open_tag() {
        let l = lex("<html>\n<?phpm $a = 1; ?>\n<p>\n<?phpm\n$b = 2;").unwrap();
        let tags: Vec<_> = l.tokens.iter().filter(|t| t.kind == TokKind::OpenTag).map(|t| t.span).collect();
        assert_eq!(tags, [Span::new(7, 13), Span::new(29, 35)]);
        assert_eq!(kinds("<?phpm")[0], TokKind::OpenTag);
    }

    #[test]
    fn other_open_tags_are_an_error() {
        for src in ["<?php $a = 1;", "<?phpmx $a = 1;", "<?phpm $a = 1; ?> <?php $b = 2;"] {
            let e = lex(src).unwrap_err();
            assert_eq!(e.code, codes::PLAIN_OPEN_TAG, "{src}");
            assert_eq!(e.span.text(src), "<?php", "{src}");
        }
    }
}
