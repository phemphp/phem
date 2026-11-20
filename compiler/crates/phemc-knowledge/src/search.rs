//! A lexical search (BM25) over the chunks: the sections of the docs, the components and the
//! examples. Each chunk has a context header that the index reads with its text. The tokenizer
//! is fixed and the ties go to the smaller key, so a query always gives the same result.

use serde::Serialize;
use std::collections::HashMap;

/// A unit of the search.
#[derive(Clone, Debug, Serialize)]
pub struct Chunk {
    /// The key that gets the full text: `events#debounce`, a component or an example FQCN.
    pub key: String,
    /// `doc`, `guide`, `component` or `example`.
    pub kind: &'static str,
    /// Where the chunk is: the doc and its headings, or the family of a component.
    pub context: String,
    /// The text that the index reads, with the context.
    #[serde(skip)]
    pub text: String,
    /// Whether the index reads the context. The context of a component repeats the name of its
    /// family, which its key has already: it would rank the parts with the root. The context of
    /// an example lists all the components that it uses.
    #[serde(skip)]
    pub scored_context: bool,
}

/// A result of a search.
#[derive(Clone, Debug, Serialize)]
pub struct Hit {
    pub key: String,
    pub kind: &'static str,
    pub context: String,
    /// The first sentence of the text.
    pub summary: String,
}

// The weights of the fields: a word of the key or of the context counts more than a word of
// the text.
const KEY_WEIGHT: f64 = 3.0;
const CONTEXT_WEIGHT: f64 = 2.0;
const K1: f64 = 1.2;
const B: f64 = 0.75;

pub struct Index {
    chunks: Vec<Chunk>,
    /// The weighted frequency of each term in each chunk.
    terms: Vec<HashMap<String, f64>>,
    lengths: Vec<f64>,
    average: f64,
    /// The number of chunks with each term.
    df: HashMap<String, usize>,
}

impl Index {
    pub fn new(chunks: Vec<Chunk>) -> Self {
        let mut terms = Vec::with_capacity(chunks.len());
        let mut df: HashMap<String, usize> = HashMap::new();

        for c in &chunks {
            let mut tf: HashMap<String, f64> = HashMap::new();
            let context = if c.scored_context { c.context.as_str() } else { "" };
            for (text, weight) in [(c.key.as_str(), KEY_WEIGHT), (context, CONTEXT_WEIGHT), (c.text.as_str(), 1.0)] {
                for t in tokens(text) {
                    *tf.entry(t).or_default() += weight;
                }
            }

            for t in tf.keys() {
                *df.entry(t.clone()).or_default() += 1;
            }

            terms.push(tf);
        }

        let lengths: Vec<f64> = terms.iter().map(|tf| tf.values().sum()).collect();
        let average = if lengths.is_empty() { 1.0 } else { lengths.iter().sum::<f64>() / lengths.len() as f64 };

        Index { chunks, terms, lengths, average, df }
    }

    pub fn chunks(&self) -> &[Chunk] {
        &self.chunks
    }

    /// All the chunks that have a term of the query, by score, then by key.
    pub fn ranked(&self, query: &str) -> Vec<(&Chunk, f64)> {
        let mut query_terms: Vec<String> = Vec::new();
        for t in tokens(query) {
            if !query_terms.contains(&t) {
                query_terms.push(t);
            }
        }

        let n = self.chunks.len() as f64;
        let mut out: Vec<(&Chunk, f64)> = Vec::new();
        for (i, chunk) in self.chunks.iter().enumerate() {
            let mut score = 0.0;
            for t in &query_terms {
                let Some(tf) = self.terms[i].get(t) else { continue };

                let df = self.df[t] as f64;
                let idf = (1.0 + (n - df + 0.5) / (df + 0.5)).ln();
                score += idf * tf * (K1 + 1.0) / (tf + K1 * (1.0 - B + B * self.lengths[i] / self.average));
            }

            if score > 0.0 {
                out.push((chunk, score));
            }
        }

        out.sort_by(|a, b| b.1.total_cmp(&a.1).then_with(|| a.0.key.cmp(&b.0.key)));

        out
    }

    /// The best chunks for a query.
    pub fn search(&self, query: &str, limit: usize) -> Vec<Hit> {
        self.ranked(query)
            .into_iter()
            .take(limit)
            .map(|(c, _)| Hit { key: c.key.clone(), kind: c.kind, context: c.context.clone(), summary: crate::markdown::first_sentence(&c.text) })
            .collect()
    }
}

impl Hit {
    pub fn md(hits: &[Hit], query: &str) -> String {
        let mut out = format!("# Search: {query}\n\nRead a result with `phemc docs <key>`, `phemc components <key>` or `phemc examples <key>`.\n\n");
        if hits.is_empty() {
            out.push_str("No result.\n");
        }

        for (i, h) in hits.iter().enumerate() {
            out.push_str(&format!("{}. `{}` ({}) {}\n", i + 1, h.key, h.kind, h.context));
            if !h.summary.is_empty() {
                out.push_str(&format!("   {}\n", h.summary));
            }
        }

        out
    }
}

/// Words that do not help a search.
const STOP_WORDS: &[&str] = &[
    "a", "an", "and", "are", "as", "at", "be", "by", "can", "do", "does", "for", "from", "has", "have", "how", "i", "if", "in", "into", "is", "it", "its", "of", "on", "or", "that", "the", "their", "then", "this", "to", "use", "uses", "used", "was", "what", "when", "where", "which", "who", "why", "with", "you", "your",
];

/// The terms of a text: the words, lowercase, with the camelCase and snake_case words split (the
/// whole word is a term too), no stop words, and the plural `s` removed. A code like `E042`
/// stays one term.
pub fn tokens(text: &str) -> Vec<String> {
    let mut out = Vec::new();
    for word in text.split(|c: char| !c.is_alphanumeric() && c != '_').filter(|w| !w.is_empty()) {
        let parts = split_word(word);
        if parts.len() > 1 {
            push_term(&word.replace('_', "").to_lowercase(), &mut out);
        }

        for p in parts {
            push_term(&p.to_lowercase(), &mut out);
        }
    }

    out
}

fn push_term(word: &str, out: &mut Vec<String>) {
    if word.chars().count() < 2 || STOP_WORDS.contains(&word) {
        return;
    }

    out.push(stem(word));
}

/// `DialogPopup` → `Dialog`, `Popup`; `HTMLParser` → `HTML`, `Parser`; `phem_bind` → `phem`,
/// `bind`.
fn split_word(word: &str) -> Vec<&str> {
    let mut parts = Vec::new();
    for piece in word.split('_').filter(|p| !p.is_empty()) {
        let chars: Vec<(usize, char)> = piece.char_indices().collect();
        let mut start = 0;
        for k in 1..chars.len() {
            let (i, c) = chars[k];
            let prev = chars[k - 1].1;
            let next_lower = chars.get(k + 1).is_some_and(|(_, n)| n.is_lowercase());
            if c.is_uppercase() && (prev.is_lowercase() || (prev.is_uppercase() && next_lower)) {
                parts.push(&piece[start..i]);
                start = i;
            }
        }

        parts.push(&piece[start..]);
    }

    parts
}

/// Removes a plural `s`: `dialogs` → `dialog`, `entries` → `entry`, not `class` or `status`.
fn stem(word: &str) -> String {
    if word.len() > 4 && word.ends_with("ies") {
        return format!("{}y", &word[..word.len() - 3]);
    }

    if word.len() > 3 && word.ends_with('s') && !word.ends_with("ss") && !word.ends_with("us") && !word.ends_with("is") {
        return word[..word.len() - 1].to_string();
    }

    word.to_string()
}

/// A query of a retrieval eval, and the keys that must be in its top results.
#[derive(Clone, Debug, PartialEq)]
pub struct Case {
    pub query: String,
    pub expect: Vec<String>,
}

/// Reads a retrieval eval: a YAML list of queries with the keys that they must find.
///
/// ```yaml
/// - query: confirm before a delete
///   expect: [events#confirm, Acme\Ui\Examples\ConfirmDemo]
/// - query: a modal with a form
///   expect:
///     - Acme\Ui\Examples\DialogFormDemo
/// ```
///
/// It reads only this form: plain or quoted values, `#` comments.
pub fn parse_eval(text: &str) -> Result<Vec<Case>, String> {
    let mut cases: Vec<Case> = Vec::new();
    let mut in_list = false;

    for (n, raw) in text.lines().enumerate() {
        let line = raw.trim_end();
        let t = line.trim_start();
        if t.is_empty() || t.starts_with('#') {
            continue;
        }

        let error = || format!("line {}: cannot read `{t}`", n + 1);
        if let Some(q) = t.strip_prefix("- query:") {
            cases.push(Case { query: scalar(q), expect: vec![] });
            in_list = false;
        } else if let Some(e) = t.strip_prefix("expect:") {
            let case = cases.last_mut().ok_or_else(error)?;
            let e = e.trim();
            in_list = e.is_empty();
            if let Some(items) = e.strip_prefix('[').and_then(|e| e.strip_suffix(']')) {
                case.expect.extend(items.split(',').map(scalar).filter(|k| !k.is_empty()));
            } else if !in_list {
                case.expect.push(scalar(e));
            }
        } else if let (true, Some(item)) = (in_list, t.strip_prefix("- ")) {
            cases.last_mut().ok_or_else(error)?.expect.push(scalar(item));
        } else {
            return Err(error());
        }
    }

    if let Some(c) = cases.iter().find(|c| c.query.is_empty() || c.expect.is_empty()) {
        return Err(format!("the query `{}` has no query text or no expected key", c.query));
    }

    Ok(cases)
}

fn scalar(s: &str) -> String {
    let s = s.trim();
    let quoted = s.len() >= 2 && ((s.starts_with('\'') && s.ends_with('\'')) || (s.starts_with('"') && s.ends_with('"')));

    if quoted { s[1..s.len() - 1].to_string() } else { s.to_string() }
}

/// The result of a retrieval eval.
#[derive(Debug, Serialize)]
pub struct EvalReport {
    pub queries: usize,
    pub keys: usize,
    pub found: usize,
    pub limit: usize,
    pub misses: Vec<Miss>,
}

#[derive(Debug, Serialize)]
pub struct Miss {
    pub query: String,
    pub key: String,
    /// The rank of the key, or `None` when the query does not find it, or the key does not exist.
    pub rank: Option<usize>,
    /// The key is not the key of a chunk.
    pub unknown: bool,
}

impl EvalReport {
    pub fn run(index: &Index, cases: &[Case], limit: usize) -> Self {
        let mut misses = Vec::new();
        let mut keys = 0;

        for case in cases {
            let ranked = index.ranked(&case.query);
            for key in &case.expect {
                keys += 1;
                let rank = ranked.iter().position(|(c, _)| &c.key == key).map(|r| r + 1);
                if rank.is_none_or(|r| r > limit) {
                    let unknown = !index.chunks().iter().any(|c| &c.key == key);
                    misses.push(Miss { query: case.query.clone(), key: key.clone(), rank, unknown });
                }
            }
        }

        EvalReport { queries: cases.len(), keys, found: keys - misses.len(), limit, misses }
    }

    pub fn passed(&self) -> bool {
        self.misses.is_empty()
    }

    pub fn md(&self) -> String {
        let recall = if self.keys == 0 { 100.0 } else { self.found as f64 * 100.0 / self.keys as f64 };
        let mut out = format!("phemc: retrieval eval: {} quer(ies), {}/{} key(s) in the top {} (recall@{} {recall:.1} %)\n", self.queries, self.found, self.keys, self.limit, self.limit);
        for m in &self.misses {
            let why = match (m.unknown, m.rank) {
                (true, _) => "no chunk has this key".to_string(),
                (false, Some(r)) => format!("rank {r}"),
                (false, None) => "not found".to_string(),
            };

            out.push_str(&format!("- \"{}\": `{}` ({why})\n", m.query, m.key));
        }

        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn chunk(key: &str, context: &str, text: &str) -> Chunk {
        Chunk { key: key.into(), kind: "doc", context: context.into(), text: text.into(), scored_context: true }
    }

    #[test]
    fn splits_the_words() {
        assert_eq!(tokens("DialogPopup"), ["dialogpopup", "dialog", "popup"]);
        assert_eq!(tokens("the HTMLParser of phem_bind"), ["htmlparser", "html", "parser", "phembind", "phem", "bind"]);
        assert_eq!(tokens("E042: dialogs, entries and a class"), ["e042", "dialog", "entry", "class"]);
        assert_eq!(tokens("Phem\\Ui\\Dialog phem:bind"), ["phem", "ui", "dialog", "phem", "bind"]);
    }

    #[test]
    fn ranks_by_score_then_by_key() {
        let index = Index::new(vec![
            chunk("b", "Forms", "A form with fields."),
            chunk("a", "Forms", "A form with fields."),
            chunk("dialog", "Dialog — also: modal", "A popup."),
            chunk("events#debounce", "Events › Debounce", "Wait before the event."),
        ]);

        let keys: Vec<_> = index.search("form", 20).into_iter().map(|h| h.key).collect();
        assert_eq!(keys, ["a", "b"]);
        assert_eq!(index.search("a modal", 20)[0].key, "dialog");
        assert_eq!(index.search("debounce", 1)[0].summary, "Wait before the event.");
        assert!(index.search("nothing here", 20).is_empty());

        // A context that the index doesn't read.
        let index = Index::new(vec![Chunk { scored_context: false, ..chunk("arrow", "part of Popover", "An arrow.") }, chunk("tooltip", "Tooltip", "A popup.")]);
        assert!(index.search("popover", 20).is_empty());
    }

    #[test]
    fn reads_an_eval() {
        let text = "# The eval.\n- query: confirm a delete\n  expect: [events#confirm, 'Acme\\Ui\\ConfirmDemo']\n\n- query: \"a modal\"\n  expect:\n    - Acme\\Ui\\Dialog\n";
        assert_eq!(
            parse_eval(text).unwrap(),
            [
                Case { query: "confirm a delete".into(), expect: vec!["events#confirm".into(), "Acme\\Ui\\ConfirmDemo".into()] },
                Case { query: "a modal".into(), expect: vec!["Acme\\Ui\\Dialog".into()] },
            ]
        );

        assert!(parse_eval("- query: x\n").unwrap_err().contains("no expected key"));
        assert!(parse_eval("query: x\n").unwrap_err().contains("line 1"));
    }

    #[test]
    fn an_eval_reports_the_misses() {
        let index = Index::new(vec![chunk("forms", "Forms", "A form."), chunk("events", "Events", "An event.")]);
        let cases = parse_eval("- query: form\n  expect: [forms, events, missing]\n").unwrap();
        let report = EvalReport::run(&index, &cases, 20);

        assert!(!report.passed());
        assert_eq!(report.md(), "phemc: retrieval eval: 1 quer(ies), 1/3 key(s) in the top 20 (recall@20 33.3 %)\n- \"form\": `events` (not found)\n- \"form\": `missing` (no chunk has this key)\n");
    }
}
