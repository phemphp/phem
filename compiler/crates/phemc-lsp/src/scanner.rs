//! A small, tolerant lexer for `.phem` files, for the editor. It follows the rules of the
//! TextMate grammar: a markup literal starts where a PHP (or Twig) expression starts, and it ends
//! when its root element closes. Unlike the compiler lexer, it accepts a file that the user types:
//! the text can stop anywhere.
//!
//! [`scan`] reads all the text and returns:
//! - the context of the end of the text (the cursor, when the text is the part of the document
//!   before the cursor);
//! - the masks: the byte ranges that are not PHP code (strings, comments, and markup literals);
//! - the delimiters: the byte ranges of the Twig delimiters in markup (`{{`, `}}`, `{%`, `%}`).

use phemc_syntax::php::{OPEN_TAG, PHP_OPEN_TAG};

/// Where the end of the text is.
#[derive(Clone, Debug, PartialEq)]
pub enum Context {
    /// PHP code, or a PHP string or comment.
    Php { string: bool, comment: bool },
    /// Text in an element.
    Text,
    /// An HTML or a Twig comment in markup.
    Comment,
    /// `<name`: the name of an open tag. `start` is the offset of the name.
    TagName { partial: String, start: usize },
    /// The attributes of a tag. `partial` is the attribute name at the end, or empty.
    Attr { tag: String, attrs: Vec<String>, partial: String },
    /// A quoted attribute value. `quote` is the offset of the quote.
    AttrValue { tag: String, attr: Option<String>, quote: usize },
    /// `</name`. `partial` is `None` after the name (`</name ...`).
    CloseTag { partial: Option<String>, start: usize, expected: Option<String> },
    /// The content of `<script>` or `<style>`.
    Raw,
    Twig(TwigContext),
}

/// The end of the text is in Twig code.
#[derive(Clone, Debug, PartialEq)]
pub struct TwigContext {
    /// `{{` or `{%`.
    pub open: &'static str,
    /// The offset after the delimiter.
    pub start: usize,
    /// The start of the markup literal.
    pub literal: Option<usize>,
    /// The offset of the quote, in a string.
    pub quote: Option<usize>,
    /// The tag and the attribute of a value: `name={{ ...`.
    pub tag: Option<String>,
    pub attr: Option<String>,
}

impl TwigContext {
    pub fn in_string(&self) -> bool {
        self.quote.is_some()
    }
}

#[derive(Debug, Default)]
pub struct Scan {
    pub context: Option<Context>,
    pub masks: Vec<(usize, usize)>,
    pub delimiters: Vec<(usize, usize)>,
}

pub fn scan(text: &str) -> Scan {
    let mut s = Scanner { t: text.as_bytes(), i: 0, out: Scan::default(), literal: None };
    let _ = s.php();
    s.out
}

/// The text with all the masks replaced by spaces. The offsets and the line breaks do not change.
pub fn mask_text(text: &str, masks: &[(usize, usize)]) -> Vec<u8> {
    let mut code = text.as_bytes().to_vec();
    for &(start, end) in masks {
        for c in &mut code[start.min(text.len())..end.min(text.len())] {
            if *c != b'\n' {
                *c = b' ';
            }
        }
    }

    code
}

/// The masked code of a text: strings, comments, and markup are spaces.
pub fn code_of(text: &str) -> Vec<u8> {
    mask_text(text, &scan(text).masks)
}

/// A markup literal can start after one of these characters or words.
const START_CHARS: &[u8] = b"([,=>?:!.|&";
const START_WORDS: &[&str] = &["return", "yield", "echo", "print", "and", "or", "xor", "not", "in"];

/// The scan stops: the end of the text is in the context of [`Scan::context`].
struct Stop;

type Step<T = ()> = Result<T, Stop>;

/// The last character or word of code.
#[derive(Clone, PartialEq)]
enum Last {
    None,
    Char(u8),
    Word(String),
    Value,
}

impl Last {
    fn starts_expression(&self) -> bool {
        match self {
            Last::Char(c) => START_CHARS.contains(c),
            Last::Word(w) => START_WORDS.contains(&w.as_str()),
            _ => false,
        }
    }
}

pub(crate) fn is_word_start(c: u8) -> bool {
    c.is_ascii_alphabetic() || c == b'_' || c >= 0x80
}

pub(crate) fn is_word_char(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_' || c >= 0x80
}

fn is_name_char(c: u8) -> bool {
    c.is_ascii_alphanumeric() || matches!(c, b'_' | b'.' | b':' | b'\\' | b'-')
}

struct Scanner<'a> {
    t: &'a [u8],
    i: usize,
    out: Scan,
    /// The start of the current markup literal.
    literal: Option<usize>,
}

impl Scanner<'_> {
    fn eof(&mut self, context: Context) -> Stop {
        self.out.context = Some(context);
        Stop
    }

    fn at(&self, n: usize) -> Option<u8> {
        self.t.get(self.i + n).copied()
    }

    fn starts_with(&self, s: &str) -> bool {
        self.t[self.i.min(self.t.len())..].starts_with(s.as_bytes())
    }

    fn text(&self, start: usize, end: usize) -> String {
        String::from_utf8_lossy(&self.t[start..end]).into_owned()
    }

    fn find(&self, needle: &str, from: usize) -> Option<usize> {
        let n = needle.as_bytes();
        self.t.get(from..)?.windows(n.len()).position(|w| w == n).map(|p| from + p)
    }

    /// Moves to the end of `close`. If `close` is not there, the scan stops.
    fn skip_to(&mut self, close: &str, context: Context) -> Step {
        match self.find(close, self.i) {
            Some(end) => {
                self.i = end + close.len();
                Ok(())
            }
            None => {
                self.i = self.t.len();
                Err(self.eof(context))
            }
        }
    }

    /// Moves past a quoted string. The current character is the quote.
    fn skip_quoted(&mut self, context: Context) -> Step {
        let quote = self.t[self.i];
        self.i += 1;
        while self.i < self.t.len() {
            let c = self.t[self.i];
            self.i += 1;
            if c == b'\\' {
                self.i += 1;
            } else if c == quote {
                return Ok(());
            }
        }

        self.i = self.t.len();
        Err(self.eof(context))
    }

    /// Runs `f` and records the range that it reads as a mask, also when the scan stops in it.
    fn mask(&mut self, start: usize, f: impl FnOnce(&mut Self) -> Step) -> Step {
        let result = f(self);
        self.out.masks.push((start, self.i.min(self.t.len())));
        result
    }

    /// True if the `<` at the current position opens a tag or a fragment.
    fn opens_tag(&self) -> bool {
        match self.at(1) {
            None => true,
            Some(c) => c == b'>' || c == b'\\' || c.is_ascii_alphabetic(),
        }
    }

    fn word_len(&self, start: impl Fn(u8) -> bool, rest: impl Fn(u8) -> bool) -> usize {
        match self.at(0) {
            Some(c) if start(c) => 1 + self.t[self.i + 1..].iter().take_while(|&&c| rest(c)).count(),
            _ => 0,
        }
    }

    fn php(&mut self) -> Step {
        let mut last = Last::None;
        let mut newline = true;

        while self.i < self.t.len() {
            let c = self.t[self.i];
            let start = self.i;

            if c == b'\n' {
                newline = true;
                self.i += 1;
                continue;
            }

            if c == b' ' || c == b'\t' || c == b'\r' {
                self.i += 1;
                continue;
            }

            // `<?phem` in a .phem file, `<?php` in the PHP classes that the project reads.
            if let Some(tag) = [OPEN_TAG, PHP_OPEN_TAG, "<?="].into_iter().find(|t| self.starts_with(t)) {
                self.i += tag.len();
                last = Last::None;
                newline = true;
                continue;
            }

            if (c == b'#' && self.at(1) != Some(b'[')) || self.starts_with("//") {
                self.mask(start, |s| match s.find("\n", s.i) {
                    Some(end) => {
                        s.i = end;
                        Ok(())
                    }
                    None => {
                        s.i = s.t.len();
                        Err(s.eof(Context::Php { string: false, comment: true }))
                    }
                })?;
                continue;
            }

            if self.starts_with("/*") {
                self.mask(start, |s| s.skip_to("*/", Context::Php { string: false, comment: true }))?;
                continue;
            }

            if c == b'\'' || c == b'"' || c == b'`' {
                self.mask(start, |s| s.skip_quoted(Context::Php { string: true, comment: false }))?;
                last = Last::Value;
                newline = false;
                continue;
            }

            if self.starts_with("<<<") {
                if let Some((header, id)) = self.heredoc_header() {
                    self.mask(start, |s| {
                        let body = s.i + header;
                        match heredoc_close(&s.t[body..], &id) {
                            Some(end) => {
                                s.i = body + end;
                                Ok(())
                            }
                            None => {
                                s.i = s.t.len();
                                Err(s.eof(Context::Php { string: true, comment: false }))
                            }
                        }
                    })?;
                    last = Last::Value;
                    newline = false;
                    continue;
                }
            }

            if c == b'<' && (newline || last.starts_expression()) && self.opens_tag() {
                self.literal = Some(start);
                self.mask(start, |s| s.markup())?;
                last = Last::Value;
                newline = false;
                continue;
            }

            newline = false;
            let n = self.word_len(is_word_start, is_word_char);
            if n > 0 {
                last = Last::Word(self.text(self.i, self.i + n).to_lowercase());
                self.i += n;
                continue;
            }

            last = Last::Char(c);
            self.i += 1;
        }

        Err(self.eof(Context::Php { string: false, comment: false }))
    }

    /// The length of `<<<"ID"\n` and the identifier, at the current position.
    fn heredoc_header(&self) -> Option<(usize, String)> {
        let t = &self.t[self.i..];
        let mut k = 3;
        while matches!(t.get(k), Some(b' ' | b'\t')) {
            k += 1;
        }

        let quote = match t.get(k) {
            Some(&q @ (b'\'' | b'"')) => {
                k += 1;
                Some(q)
            }
            _ => None,
        };

        let id_start = k;
        if !t.get(k).is_some_and(|c| c.is_ascii_alphabetic() || *c == b'_') {
            return None;
        }

        while t.get(k).is_some_and(|c| c.is_ascii_alphanumeric() || *c == b'_') {
            k += 1;
        }

        let id = String::from_utf8_lossy(&t[id_start..k]).into_owned();
        if let Some(q) = quote {
            if t.get(k) != Some(&q) {
                return None;
            }
            k += 1;
        }

        if t.get(k) == Some(&b'\r') {
            k += 1;
        }

        (t.get(k) == Some(&b'\n')).then_some((k + 1, id))
    }

    /// Reads one markup literal. The current character is the `<` of its root.
    fn markup(&mut self) -> Step {
        let mut tags: Vec<String> = Vec::new();
        loop {
            if self.i >= self.t.len() {
                return Err(self.eof(Context::Text));
            }

            let c = self.t[self.i];
            if c == b'<' {
                if self.starts_with("<!--") {
                    self.skip_to("-->", Context::Comment)?;
                    continue;
                }

                if self.at(1) == Some(b'/') {
                    self.close_tag(&mut tags)?;
                    if tags.is_empty() {
                        return Ok(());
                    }

                    continue;
                }

                if self.opens_tag() {
                    match self.open_tag()? {
                        Some(name) => {
                            let raw = name == "script" || name == "style";
                            tags.push(name.clone());
                            if raw {
                                self.raw(&name)?;
                            }
                        }
                        None if tags.is_empty() => return Ok(()),
                        None => {}
                    }

                    continue;
                }
            } else if c == b'{' {
                match self.at(1) {
                    Some(b'{') => {
                        self.delimiter();
                        self.twig("}}", None)?;
                        continue;
                    }
                    Some(b'%') => {
                        self.delimiter();
                        self.twig("%}", None)?;
                        continue;
                    }
                    Some(b'#') => {
                        self.i += 2;
                        self.skip_to("#}", Context::Comment)?;
                        continue;
                    }
                    _ => {}
                }
            }

            self.i += 1;
        }
    }

    /// Moves past a two-character Twig delimiter and records it.
    fn delimiter(&mut self) {
        self.out.delimiters.push((self.i, self.i + 2));
        self.i += 2;
    }

    fn name_at(&self) -> usize {
        self.t[self.i.min(self.t.len())..].iter().take_while(|&&c| is_name_char(c)).count()
    }

    /// Reads `<name attrs>` or `<name attrs />`. Returns the name of an open element, or `None`
    /// for a self-closing tag.
    fn open_tag(&mut self) -> Step<Option<String>> {
        self.i += 1;
        let name_start = self.i;
        let n = self.name_at();
        let name = self.text(self.i, self.i + n);
        self.i += n;
        if self.i >= self.t.len() {
            return Err(self.eof(Context::TagName { partial: name, start: name_start }));
        }

        let mut attrs: Vec<String> = Vec::new();
        loop {
            if self.i >= self.t.len() {
                return Err(self.eof(Context::Attr { tag: name, attrs, partial: String::new() }));
            }

            let c = self.t[self.i];
            if c == b'>' {
                self.i += 1;
                return Ok(Some(name));
            }

            if self.starts_with("/>") {
                self.i += 2;
                return Ok(None);
            }

            if self.starts_with("{{") {
                let attr = if self.i > 0 && self.t[self.i - 1] == b'=' { attrs.last().cloned() } else { None };
                self.delimiter();
                let value = attr.map(|a| (name.clone(), a));
                self.twig("}}", value)?;
                continue;
            }

            if self.starts_with("{#") {
                self.i += 2;
                self.skip_to("#}", Context::Comment)?;
                continue;
            }

            if c == b'"' || c == b'\'' {
                let context = Context::AttrValue { tag: name.clone(), attr: attrs.last().cloned(), quote: self.i };
                self.skip_quoted(context)?;
                continue;
            }

            let n = self.t[self.i..]
                .iter()
                .take_while(|&&c| !c.is_ascii_whitespace() && !matches!(c, b'=' | b'/' | b'>' | b'"' | b'\'' | b'{'))
                .count();
            if n > 0 {
                let attr = self.text(self.i, self.i + n);
                self.i += n;
                if self.i >= self.t.len() {
                    return Err(self.eof(Context::Attr { tag: name, attrs, partial: attr }));
                }

                attrs.push(attr);
                continue;
            }

            self.i += 1;
        }
    }

    /// Reads `</name>` and closes the open element.
    fn close_tag(&mut self, tags: &mut Vec<String>) -> Step {
        self.i += 2;
        let start = self.i;
        let n = self.name_at();
        let name = self.text(self.i, self.i + n);
        self.i += n;
        let expected = tags.last().cloned();
        if self.i >= self.t.len() {
            return Err(self.eof(Context::CloseTag { partial: Some(name), start, expected }));
        }

        self.skip_to(">", Context::CloseTag { partial: None, start, expected })?;
        tags.pop();
        Ok(())
    }

    /// Skips the content of `<script>` and `<style>`, up to the closing tag.
    fn raw(&mut self, name: &str) -> Step {
        let mut k = self.i;
        while k < self.t.len() {
            if self.t[k] == b'<' && self.t.get(k + 1) == Some(&b'/') {
                let rest = &self.t[k + 2..];
                if rest.len() >= name.len() && rest[..name.len()].eq_ignore_ascii_case(name.as_bytes()) {
                    let after = rest[name.len()..].iter().take_while(|c| c.is_ascii_whitespace()).count();
                    if rest.get(name.len() + after) == Some(&b'>') {
                        self.i = k;
                        return Ok(());
                    }
                }
            }

            k += 1;
        }

        self.i = self.t.len();
        Err(self.eof(Context::Raw))
    }

    /// Reads Twig code up to `close` (`}}` or `%}`). Twig code can contain hashes (`{a: 1}`) and
    /// markup literals (`ok ? <A /> : <B />`). `value` is the tag and the attribute of a value.
    fn twig(&mut self, close: &'static str, value: Option<(String, String)>) -> Step {
        let (tag, attr) = value.map_or((None, None), |(t, a)| (Some(t), Some(a)));
        let base = TwigContext {
            open: if close == "}}" { "{{" } else { "{%" },
            start: self.i,
            literal: self.literal,
            quote: None,
            tag,
            attr,
        };

        let mut depth = 0i32;
        let mut last = Last::Char(b'(');
        loop {
            if self.i >= self.t.len() {
                return Err(self.eof(Context::Twig(base)));
            }

            let c = self.t[self.i];
            if depth == 0 && self.starts_with(close) {
                self.delimiter();
                return Ok(());
            }

            if c.is_ascii_whitespace() {
                self.i += 1;
                continue;
            }

            if c == b'\'' || c == b'"' {
                let context = Context::Twig(TwigContext { quote: Some(self.i), ..base.clone() });
                self.skip_quoted(context)?;
                last = Last::Value;
                continue;
            }

            if c == b'<' && (last.starts_expression() || last == Last::Char(b'{') || last == Last::Char(b'~')) && self.opens_tag() {
                self.markup()?;
                last = Last::Value;
                continue;
            }

            if c == b'{' {
                depth += 1;
            } else if c == b'}' {
                depth -= 1;
            }

            let n = self.word_len(|c| c.is_ascii_alphabetic() || c == b'_', |c| c.is_ascii_alphanumeric() || c == b'_');
            if n > 0 {
                last = Last::Word(self.text(self.i, self.i + n).to_lowercase());
                self.i += n;
                continue;
            }

            last = Last::Char(c);
            self.i += 1;
        }
    }
}

/// The end of the line that closes a heredoc with `id`, in `body`: a line that starts with `id`
/// after optional indentation.
fn heredoc_close(body: &[u8], id: &str) -> Option<usize> {
    let mut line = 0;
    loop {
        let rest = &body[line..];
        let indent = rest.iter().take_while(|c| matches!(c, b' ' | b'\t')).count();
        let after = &rest[indent..];
        if after.starts_with(id.as_bytes()) && !after.get(id.len()).is_some_and(|c| c.is_ascii_alphanumeric() || *c == b'_') {
            return Some(line + indent + id.len());
        }

        line += rest.iter().position(|&c| c == b'\n')? + 1;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ctx(s: &str) -> Context {
        scan(s).context.unwrap()
    }

    #[test]
    fn finds_the_context_at_the_end_of_the_text() {
        assert_eq!(ctx("<?phem\n$a = $b < "), Context::Php { string: false, comment: false });
        assert!(matches!(ctx("<?phem\nreturn <"), Context::TagName { .. }));
        assert!(matches!(ctx("<?phem\nreturn <Lay"), Context::TagName { partial, .. } if partial == "Lay"));
        assert!(matches!(ctx("<?phem\nreturn <div>{{ ok ? <Ca"), Context::TagName { partial, .. } if partial == "Ca"));
        assert!(matches!(ctx("<?phem\nreturn <div className=\"a\">I'm <b>x</b> <Lay"), Context::TagName { .. }));
        assert!(matches!(ctx("<?phem\nreturn <Layout title=\"x\" "), Context::Attr { attrs, .. } if attrs == ["title"]));
        assert!(matches!(ctx("<?phem\nreturn <Layout style={{ {a: 1} }} "), Context::Attr { tag, .. } if tag == "Layout"));
        assert!(matches!(ctx("<?phem\nreturn <div><p></"), Context::CloseTag { expected: Some(e), .. } if e == "p"));
        assert_eq!(ctx("<?phem\n$x = <p>a</p>;\n$y = 1"), Context::Php { string: false, comment: false });
        assert_eq!(ctx("<?phem\n$x = \"<p>"), Context::Php { string: true, comment: false });
        assert!(matches!(ctx("<?phem\nreturn <ul>{% for a in b %}<li>{{ a"), Context::Twig(_)));
        assert_eq!(ctx("<?phem\nreturn <script>if (a < b) {"), Context::Raw);
    }

    fn delimiters(text: &str) -> Vec<(usize, &str)> {
        scan(text).delimiters.into_iter().map(|(s, e)| (s, &text[s..e])).collect()
    }

    #[test]
    fn records_the_twig_delimiters_in_markup() {
        let text = "<?phem\nfunction A($s)\n{\n    if ($s) { x(); }}\n    return <p a={{ s.a }}>{{ {b: 1}.b }}{% if x %}<B c={{ 1 }} />{% endif %}</p>;\n}\n";
        let at = |s: &str| text.find(s).unwrap();
        assert_eq!(
            delimiters(text),
            [
                (at("{{ s.a"), "{{"),
                (at("}}>"), "}}"),
                (at("{{ {b"), "{{"),
                (at("}}{%"), "}}"),
                (at("{% if"), "{%"),
                (at("%}<B"), "%}"),
                (at("{{ 1"), "{{"),
                (at("}} />"), "}}"),
                (at("{% endif"), "{%"),
                (at("%}</p>"), "%}"),
            ]
        );
    }

    #[test]
    fn ignores_braces_in_php_code_strings_and_comments() {
        assert!(delimiters("<?phem\n$a = '{{ x }}'; // {{ y }}\nif ($a) { if ($b) { f(); }}\n").is_empty());
    }

    #[test]
    fn masks_strings_comments_heredocs_and_markup() {
        let text = "<?phem\n$a = 'x'; # c\n$h = <<<EOT\n  <b>\n  EOT;\nreturn <p>{{ a }}</p>;\n";
        let code = String::from_utf8(code_of(text)).unwrap();
        assert_eq!(code, "<?phem\n$a =    ;    \n$h =       \n     \n     ;\nreturn               ;\n");
    }
}
