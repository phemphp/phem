//! Docblocks: the `/** ... */` block before a declaration, and its summary and tags. The
//! language server (hover, docblock types) and the component catalog of `phemc` read them the
//! same way.

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
    /// The text before the tags, in one line.
    pub summary: String,
    /// The text before the tags, by paragraph (a blank line ends a paragraph). The first one is
    /// the short summary of the declaration.
    pub paragraphs: Vec<String>,
    /// The words of the `@aliases` tags (`@aliases modal, lightbox`): other names that a search
    /// finds the declaration with.
    pub aliases: Vec<String>,
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
    let mut paragraph: Vec<String> = Vec::new();
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
            if in_tags || line == "/**" || line == "*/" {
                continue;
            }

            if line.is_empty() {
                end_paragraph(&mut tags.paragraphs, &mut paragraph);
            } else {
                summary.push(line.to_string());
                paragraph.push(line.to_string());
            }
            continue;
        };

        in_tags = true;
        end_paragraph(&mut tags.paragraphs, &mut paragraph);
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
            // A comma ends an alias: an alias can have more than one word (`popup window`).
            "aliases" => tags.aliases.extend(rest.split(',').map(str::trim).filter(|a| !a.is_empty()).map(String::from)),
            _ => {}
        }
    }

    end_paragraph(&mut tags.paragraphs, &mut paragraph);
    tags.summary = summary.join(" ").trim().to_string();

    tags
}

/// Adds the lines of a paragraph, in one line, to the paragraphs.
fn end_paragraph(paragraphs: &mut Vec<String>, lines: &mut Vec<String>) {
    if !lines.is_empty() {
        paragraphs.push(lines.join(" "));
        lines.clear();
    }
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

#[cfg(test)]
mod tests {
    use super::*;

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

    #[test]
    fn reads_the_paragraphs_and_the_aliases() {
        let doc = "/**\n * A modal dialog.\n * On a controller.\n *\n * The popup is native.\n *\n * @aliases modal, lightbox\n * @aliases popup window\n */";
        let tags = doc_tags(doc);

        assert_eq!(tags.summary, "A modal dialog. On a controller. The popup is native.");
        assert_eq!(tags.paragraphs, ["A modal dialog. On a controller.", "The popup is native."]);
        assert_eq!(tags.aliases, ["modal", "lightbox", "popup window"]);
        assert_eq!(doc_tags("/** One line. */").paragraphs, ["One line."]);
    }

    #[test]
    fn reads_a_docblock_before_attributes() {
        let text = "<?php
/** The doc. */
#[A(1)]
final class X {}
";
        assert_eq!(doc_before(text, text.find("final").unwrap()), "/** The doc. */");
        assert_eq!(doc_before("/** a */ $x; class", 17), "");
    }
}
