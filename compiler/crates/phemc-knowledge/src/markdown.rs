//! Splits a Markdown text into its sections, with the anchors of the website.

use serde::Serialize;

/// A Markdown text, split at its `##` and `###` headings.
#[derive(Clone, Debug)]
pub struct Markdown {
    pub text: String,
    /// The `#` heading, or empty.
    pub title: String,
    /// The text before the first section, without the `#` heading.
    pub intro: String,
    pub sections: Vec<Section>,
}

/// A `##` or `###` section.
#[derive(Clone, Debug, Serialize)]
pub struct Section {
    pub depth: u8,
    pub title: String,
    /// The anchor of the heading on the website (`#debounce`).
    pub anchor: String,
    /// The title of the `##` section of a `###` section.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub parent: Option<String>,
    /// The heading line and its text, up to the next heading.
    #[serde(skip)]
    pub own: (usize, usize),
    /// The heading line and its text, with the `###` sections of a `##` section.
    #[serde(skip)]
    pub whole: (usize, usize),
}

impl Markdown {
    pub fn parse(text: &str) -> Self {
        let headings = headings(text);
        let mut slugger = Slugger::default();
        let mut title = String::new();
        let mut sections: Vec<Section> = Vec::new();
        let mut parent: Option<String> = None;

        for (i, h) in headings.iter().enumerate() {
            let anchor = slugger.slug(&h.title);
            if h.depth == 1 && title.is_empty() {
                title = h.title.clone();
            }

            if !(2..=3).contains(&h.depth) {
                continue;
            }

            if h.depth == 2 {
                parent = Some(h.title.clone());
            }

            let own_end = headings.get(i + 1).map_or(text.len(), |n| n.start);
            let whole_end = headings[i + 1..].iter().find(|n| n.depth <= h.depth).map_or(text.len(), |n| n.start);
            sections.push(Section {
                depth: h.depth,
                title: h.title.clone(),
                anchor,
                parent: if h.depth == 3 { parent.clone() } else { None },
                own: (h.start, own_end),
                whole: (h.start, whole_end),
            });
        }

        let intro_start = headings.iter().find(|h| h.depth == 1).map_or(0, |h| h.end);
        let intro_end = sections.first().map_or(text.len(), |s| s.own.0).max(intro_start);

        Markdown { text: text.to_string(), title, intro: text[intro_start..intro_end].trim().to_string(), sections }
    }

    /// The section with an anchor.
    pub fn section(&self, anchor: &str) -> Option<&Section> {
        self.sections.iter().find(|s| s.anchor == anchor)
    }

    /// The text of a section, with its heading: the `###` sections of a `##` section too, or
    /// only its own text.
    pub fn text_of(&self, section: &Section, whole: bool) -> &str {
        let (start, end) = if whole { section.whole } else { section.own };

        self.text[start..end].trim()
    }

    /// The text of a section without its heading line.
    pub fn body_of(&self, section: &Section) -> &str {
        let text = self.text_of(section, false);

        text.split_once('\n').map_or("", |(_, body)| body).trim()
    }
}

struct Heading {
    depth: u8,
    title: String,
    /// The start of the heading line.
    start: usize,
    /// The end of the heading line.
    end: usize,
}

/// The ATX headings (`## Title`) out of the code blocks.
fn headings(text: &str) -> Vec<Heading> {
    let mut out = Vec::new();
    let mut fence: Option<&str> = None;
    let mut offset = 0;

    for line in text.split_inclusive('\n') {
        let start = offset;
        offset += line.len();
        let trimmed = line.trim_start();

        // A code block ends with the same fence.
        if let Some(open) = fence {
            if trimmed.trim_end().starts_with(open) && trimmed.trim_end().chars().all(|c| c == open.chars().next().unwrap()) {
                fence = None;
            }
            continue;
        }

        if trimmed.starts_with("```") || trimmed.starts_with("~~~") {
            let mark = &trimmed[..3];
            let len = trimmed.chars().take_while(|c| *c == mark.chars().next().unwrap()).count();
            fence = Some(&trimmed[..len]);
            continue;
        }

        let hashes = line.chars().take_while(|c| *c == '#').count();
        let rest = &line[hashes..];
        if !(1..=6).contains(&hashes) || !rest.starts_with([' ', '\t']) {
            continue;
        }

        let title = inline_text(rest.trim().trim_end_matches('#').trim());
        out.push(Heading { depth: hashes as u8, title, start, end: offset });
    }

    out
}

/// The text of inline Markdown, as the website renders it: no backticks, emphasis or link
/// targets.
pub fn inline_text(s: &str) -> String {
    let mut out = String::new();
    let mut rest = s;

    while let Some(c) = rest.chars().next() {
        // `[text](target)` → `text`
        if c == '[' {
            if let Some(close) = rest.find("](") {
                if let Some(end) = rest[close..].find(')') {
                    out.push_str(&inline_text(&rest[1..close]));
                    rest = &rest[close + end + 1..];
                    continue;
                }
            }
        }

        if c != '`' && c != '*' {
            out.push(c);
        }

        rest = &rest[c.len_utf8()..];
    }

    out
}

/// The anchors of the headings, as `github-slugger` makes them on the website: lowercase, no
/// punctuation, a `-` for each space, and `-1`, `-2` for the same heading again.
#[derive(Default)]
struct Slugger {
    seen: std::collections::HashMap<String, usize>,
}

impl Slugger {
    fn slug(&mut self, title: &str) -> String {
        let base: String = title
            .to_lowercase()
            .chars()
            .filter(|c| c.is_alphanumeric() || matches!(c, ' ' | '-' | '_'))
            .map(|c| if c == ' ' { '-' } else { c })
            .collect();

        let mut slug = base.clone();
        while self.seen.contains_key(&slug) {
            let n = self.seen.entry(base.clone()).or_default();
            *n += 1;
            slug = format!("{base}-{n}");
        }

        self.seen.insert(slug.clone(), 0);

        slug
    }
}

/// The first sentence of a Markdown text: the first paragraph, up to the first `. `, with no
/// heading, list mark or code block.
pub fn first_sentence(text: &str) -> String {
    let mut paragraph = Vec::new();
    let mut in_code = false;

    for line in text.lines() {
        let t = line.trim();
        if t.starts_with("```") || t.starts_with("~~~") {
            if !paragraph.is_empty() {
                break;
            }

            in_code = !in_code;
            continue;
        }

        if in_code || t.starts_with('#') || t.starts_with('|') || t.starts_with('<') {
            if !paragraph.is_empty() {
                break;
            }
            continue;
        }

        if t.is_empty() {
            if paragraph.is_empty() {
                continue;
            }
            break;
        }

        paragraph.push(t.trim_start_matches(['-', '*', '>']).trim());
    }

    sentence(&paragraph.join(" "))
}

/// The first sentence of a line of text: up to the first `. `, `: ` is not an end.
pub fn sentence(text: &str) -> String {
    let text = inline_links(text.trim());
    let end = text.match_indices(". ").map(|(i, _)| i + 1).find(|&i| !text[..i].ends_with("e.g.") && !text[..i].ends_with("i.e.")).unwrap_or(text.len());

    text[..end].trim().to_string()
}

/// A text of `max` characters at most: it ends at a word, with `…`.
pub fn clip(text: &str, max: usize) -> String {
    if text.chars().count() <= max {
        return text.to_string();
    }

    let cut: String = text.chars().take(max).collect();
    let end = cut.rfind(' ').unwrap_or(cut.len());

    format!("{}…", cut[..end].trim_end_matches([',', ';', ':']))
}

/// `[text](target)` → `text`, and the rest as written.
fn inline_links(s: &str) -> String {
    let mut out = String::new();
    let mut rest = s;

    while let Some(open) = rest.find('[') {
        let Some(close) = rest[open..].find("](").map(|i| open + i) else { break };
        let Some(end) = rest[close..].find(')').map(|i| close + i) else { break };

        out.push_str(&rest[..open]);
        out.push_str(&rest[open + 1..close]);
        rest = &rest[end + 1..];
    }

    out.push_str(rest);

    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn splits_the_sections() {
        let text = "# Events\n\nServer events. They run handlers.\n\n## Debounce\n\nWait.\n\n```php\n## not a heading\n```\n\n### With `phem:bind`\n\nBound.\n\n## Debounce\n\nAgain.\n";
        let md = Markdown::parse(text);

        assert_eq!(md.title, "Events");
        assert_eq!(md.intro, "Server events. They run handlers.");
        let s: Vec<_> = md.sections.iter().map(|s| (s.depth, s.title.as_str(), s.anchor.as_str(), s.parent.as_deref())).collect();
        assert_eq!(s, [(2, "Debounce", "debounce", None), (3, "With phem:bind", "with-phembind", Some("Debounce")), (2, "Debounce", "debounce-1", None)]);

        assert_eq!(md.text_of(&md.sections[0], true), "## Debounce\n\nWait.\n\n```php\n## not a heading\n```\n\n### With `phem:bind`\n\nBound.");
        assert_eq!(md.text_of(&md.sections[0], false), "## Debounce\n\nWait.\n\n```php\n## not a heading\n```");
        assert_eq!(md.body_of(&md.sections[1]), "Bound.");
    }

    #[test]
    fn makes_the_anchors_of_the_website() {
        let mut s = Slugger::default();

        assert_eq!(s.slug("`phemc` commands"), "phemc-commands");
        assert_eq!(s.slug("Static analysis (PHPStan)"), "static-analysis-phpstan");
        assert_eq!(s.slug("The path from a .phem file to the browser"), "the-path-from-a-phem-file-to-the-browser");
        assert_eq!(s.slug("Keep render() pure"), "keep-render-pure");
        assert_eq!(s.slug("phem_bind & co."), "phem_bind--co");
        assert_eq!(inline_text("Use [the docs](docs.md) and **`phemc`**"), "Use the docs and phemc");
    }

    #[test]
    fn gives_the_first_sentence() {
        assert_eq!(first_sentence("\n# T\n\nThe compiler turns files into PHP. It runs once.\n\nMore."), "The compiler turns files into PHP.");
        assert_eq!(first_sentence("```php\n$x;\n```\n\n- Read [the docs](a.md) first, e.g. here. Then go."), "Read the docs first, e.g. here.");
        assert_eq!(sentence("No end"), "No end");
        assert_eq!(clip("a section that opens, an accordion", 22), "a section that opens…");
        assert_eq!(clip("short", 25), "short");
    }
}
