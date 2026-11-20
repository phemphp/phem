//! Measures the retrieval of `phemc search` against simpler searches on the same chunks:
//!
//! - `keyword`: the chunks that have the most words of the query, as `grep` finds them;
//! - `bm25-plain`: BM25 on the text only, with a plain tokenizer (no context, no key, no
//!   camelCase split, no stop words, no plural);
//! - `phemc-no-context`: `phemc search` without the context header of the docs and the examples;
//! - `phemc`: `phemc search`.
//!
//! Run it from a project root: `cargo run -p phemc-knowledge --example retrieval_report -- <src>
//! <eval.yaml>...`. It prints one Markdown table for each eval file.

use phemc_knowledge::search::{parse_eval, Case, Chunk, Index};
use phemc_knowledge::Knowledge;
use std::collections::HashMap;
use std::path::Path;

const STOP: &[&str] = &["a", "an", "and", "the", "of", "to", "in", "with", "for", "is", "it", "on", "or", "how", "do", "i", "what", "does", "my", "own", "when", "this"];

fn plain_tokens(text: &str) -> Vec<String> {
    text.to_lowercase().split(|c: char| !c.is_alphanumeric()).filter(|w| !w.is_empty()).map(String::from).collect()
}

/// The keys by `grep`: the number of words of the query in the chunk, then the number of
/// matches, then the key.
fn keyword(chunks: &[Chunk], query: &str) -> Vec<String> {
    let words: Vec<String> = plain_tokens(query).into_iter().filter(|w| !STOP.contains(&w.as_str())).collect();
    let mut scored: Vec<(usize, usize, &str)> = chunks
        .iter()
        .map(|c| {
            let hay = format!("{} {} {}", c.key, c.context, c.text).to_lowercase();
            let found = words.iter().filter(|w| hay.contains(w.as_str())).count();
            let count = words.iter().map(|w| hay.matches(w.as_str()).count()).sum();
            (found, count, c.key.as_str())
        })
        .filter(|(found, _, _)| *found > 0)
        .collect();

    scored.sort_by(|a, b| b.0.cmp(&a.0).then(b.1.cmp(&a.1)).then(a.2.cmp(b.2)));

    scored.into_iter().map(|(_, _, k)| k.to_string()).collect()
}

/// The keys by BM25 on the text only, with a plain tokenizer.
fn bm25_plain(chunks: &[Chunk], query: &str) -> Vec<String> {
    let docs: Vec<HashMap<String, f64>> = chunks
        .iter()
        .map(|c| {
            let mut tf = HashMap::new();
            for t in plain_tokens(&c.text) {
                *tf.entry(t).or_insert(0.0) += 1.0;
            }
            tf
        })
        .collect();

    let lengths: Vec<f64> = docs.iter().map(|d| d.values().sum()).collect();
    let average = lengths.iter().sum::<f64>() / lengths.len().max(1) as f64;
    let n = docs.len() as f64;
    let mut terms = plain_tokens(query);
    terms.dedup();

    let mut scored: Vec<(f64, &str)> = Vec::new();
    for (i, c) in chunks.iter().enumerate() {
        let mut score = 0.0;
        for t in &terms {
            let Some(tf) = docs[i].get(t) else { continue };

            let df = docs.iter().filter(|d| d.contains_key(t)).count() as f64;
            let idf = (1.0 + (n - df + 0.5) / (df + 0.5)).ln();
            score += idf * tf * 2.2 / (tf + 1.2 * (0.25 + 0.75 * lengths[i] / average));
        }

        if score > 0.0 {
            scored.push((score, c.key.as_str()));
        }
    }

    scored.sort_by(|a, b| b.0.total_cmp(&a.0).then(a.1.cmp(b.1)));

    scored.into_iter().map(|(_, k)| k.to_string()).collect()
}

fn index_keys(index: &Index, query: &str) -> Vec<String> {
    index.ranked(query).into_iter().map(|(c, _)| c.key.clone()).collect()
}

struct Score {
    hit1: f64,
    recall5: f64,
    recall20: f64,
    mrr: f64,
}

fn score(cases: &[Case], rank: impl Fn(&str) -> Vec<String>) -> Score {
    let (mut keys, mut hit1, mut r5, mut r20, mut rr) = (0.0, 0.0, 0.0, 0.0, 0.0);
    for case in cases {
        let ranked = rank(&case.query);
        if ranked.first().is_some_and(|k| case.expect.contains(k)) {
            hit1 += 1.0;
        }

        for key in &case.expect {
            keys += 1.0;
            if let Some(pos) = ranked.iter().position(|k| k == key) {
                r5 += f64::from(pos < 5);
                r20 += f64::from(pos < 20);
                rr += if pos < 20 { 1.0 / (pos + 1) as f64 } else { 0.0 };
            }
        }
    }

    Score { hit1: hit1 * 100.0 / cases.len() as f64, recall5: r5 * 100.0 / keys, recall20: r20 * 100.0 / keys, mrr: rr / keys }
}

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let (src, evals) = args.split_first().expect("usage: retrieval_report <src> <eval.yaml>...");
    let roots = phemc_sources::roots(Path::new("."), Path::new(src)).expect("the source roots");
    let knowledge = Knowledge::load(Path::new("."), &roots).expect("the knowledge");

    let chunks = knowledge.chunks();
    let full = Index::new(chunks.clone());
    let no_context = Index::new(chunks.iter().cloned().map(|c| Chunk { scored_context: false, ..c }).collect());
    println!("{} chunks ({} components, {} examples)\n", chunks.len(), knowledge.catalog.components.len(), knowledge.examples.len());

    for file in evals {
        let cases = parse_eval(&std::fs::read_to_string(file).expect("the eval file")).expect("the eval");
        println!("### {file} ({} queries)\n", cases.len());
        println!("| Search | Top 1 | Recall@5 | Recall@20 | MRR@20 |\n|---|---|---|---|---|");

        let rows: [(&str, Score); 4] = [
            ("keyword (grep)", score(&cases, |q| keyword(&chunks, q))),
            ("BM25, text only", score(&cases, |q| bm25_plain(&chunks, q))),
            ("phemc search, no context", score(&cases, |q| index_keys(&no_context, q))),
            ("phemc search", score(&cases, |q| index_keys(&full, q))),
        ];

        for (name, s) in rows {
            println!("| {name} | {:.0} % | {:.0} % | {:.0} % | {:.2} |", s.hit1, s.recall5, s.recall20, s.mrr);
        }

        println!();
    }
}
