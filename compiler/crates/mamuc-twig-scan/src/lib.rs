//! A light Twig expression scanner.
//!
//! It does not parse Twig fully (Twig itself does that at warmup). It finds the free variable
//! names of an expression (the names that must come from PHP), the names that `for` and `set`
//! bind, and whether an expression is a call (`handle()`), for the "pass the function" lint.

/// A variable name and its byte offset in the scanned text.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Name {
    pub name: String,
    pub offset: usize,
}

#[derive(Clone, Debug, PartialEq)]
enum Tok {
    Name(String),
    Num,
    /// A string, with the expressions of its `#{...}` interpolations (and their offsets).
    Str(Vec<(String, usize)>),
    Punct(&'static str),
}

const PUNCT: &[&str] = &[
    "...", "=>", "==", "!=", "<=", ">=", "??", "?:", "?.", "**", "//", "..", "(", ")", "[", "]", "{", "}", "?", ":",
    ".", ",", "|", "+", "-", "~", "*", "/", "%", "<", ">", "=", "!",
];

const KEYWORDS: &[&str] = &[
    "and", "or", "not", "in", "is", "matches", "starts", "ends", "with", "b-and", "b-or", "b-xor", "true", "false",
    "null", "none", "TRUE", "FALSE", "NULL", "NONE", "xor", "has", "some", "every", "if",
];

/// Twig special variables; they never come from PHP.
const SPECIAL: &[&str] = &["loop", "_context", "_self", "_charset", "_key", "_parent"];

fn tokenize(src: &str) -> Vec<(Tok, usize)> {
    let b = src.as_bytes();
    let mut out = Vec::new();
    let mut i = 0;
    'outer: while i < b.len() {
        let c = b[i];
        if c.is_ascii_whitespace() {
            i += 1;
            continue;
        }
        let start = i;
        if c.is_ascii_alphabetic() || c == b'_' {
            while i < b.len() && (b[i].is_ascii_alphanumeric() || b[i] == b'_') {
                i += 1;
            }
            out.push((Tok::Name(src[start..i].to_string()), start));
            continue;
        }
        if c.is_ascii_digit() {
            while i < b.len() && (b[i].is_ascii_alphanumeric() || b[i] == b'_' || (b[i] == b'.' && b.get(i + 1).is_some_and(|d| d.is_ascii_digit()))) {
                i += 1;
            }
            out.push((Tok::Num, start));
            continue;
        }
        if c == b'\'' || c == b'"' {
            i += 1;
            let mut interps = Vec::new();
            while i < b.len() {
                if b[i] == b'\\' {
                    i += 2;
                    continue;
                }
                if c == b'"' && b[i] == b'#' && b.get(i + 1) == Some(&b'{') {
                    let inner = i + 2;
                    let mut depth = 1;
                    i += 2;
                    while i < b.len() && depth > 0 {
                        match b[i] {
                            b'{' => depth += 1,
                            b'}' => depth -= 1,
                            _ => {}
                        }
                        i += 1;
                    }
                    interps.push((src[inner..i.saturating_sub(1)].to_string(), inner));
                    continue;
                }
                if b[i] == c {
                    i += 1;
                    break;
                }
                i += 1;
            }
            out.push((Tok::Str(interps), start));
            continue;
        }
        for p in PUNCT {
            if src[i..].starts_with(p) {
                i += p.len();
                out.push((Tok::Punct(p), start));
                continue 'outer;
            }
        }
        i += src[i..].chars().next().map_or(1, char::len_utf8);
    }
    out
}

/// Free variables of a Twig expression, in order, without duplicates.
pub fn expr_free_vars(expr: &str) -> Vec<Name> {
    let mut out = Vec::new();
    collect(expr, 0, &[], &mut out);
    dedup(out)
}

fn dedup(names: Vec<Name>) -> Vec<Name> {
    let mut seen = std::collections::HashSet::new();
    names.into_iter().filter(|n| seen.insert(n.name.clone())).collect()
}

fn collect(src: &str, base: usize, extra_keywords: &[&str], out: &mut Vec<Name>) {
    let toks = tokenize(src);
    let arrow_params = arrow_params(&toks);
    // A stack of open brackets, to know if a `name:` is a hash key or a named argument.
    let mut brackets: Vec<&'static str> = Vec::new();
    // After `is`: 1 = expect `not` or a test name, 2 = expect the second word of `divisible by` / `same as`.
    let mut after_is = 0u8;
    for (i, (tok, off)) in toks.iter().enumerate() {
        let prev = i.checked_sub(1).map(|j| &toks[j].0);
        let next = toks.get(i + 1).map(|t| &t.0);
        match tok {
            Tok::Punct(p @ ("(" | "[" | "{")) => {
                brackets.push(p);
                after_is = 0;
            }
            Tok::Punct(")" | "]" | "}") => {
                brackets.pop();
                after_is = 0;
            }
            Tok::Str(interps) => {
                for (inner, inner_off) in interps {
                    collect(inner, base + inner_off, extra_keywords, out);
                }
                after_is = 0;
            }
            Tok::Name(name) => {
                if name == "is" {
                    after_is = 1;
                    continue;
                }
                // A test name: `is defined`, `is not empty`, `is divisible by(3)`, `is same as(x)`.
                if after_is == 1 {
                    if name != "not" {
                        after_is = if name == "divisible" || name == "same" { 2 } else { 0 };
                    }
                    continue;
                }
                if after_is == 2 {
                    after_is = 0;
                    continue;
                }
                let attr_or_filter = matches!(prev, Some(Tok::Punct("." | "|" | "?.")));
                let call = matches!(next, Some(Tok::Punct("(")));
                let keyed = matches!(next, Some(Tok::Punct(":")) | Some(Tok::Punct("=")))
                    && matches!(prev, Some(Tok::Punct("{" | "," | "(")))
                    && matches!(brackets.last(), Some(&"{") | Some(&"("));
                let ternary_branch = matches!(prev, Some(Tok::Punct("?")));
                if attr_or_filter
                    || (call && !matches!(prev, Some(Tok::Punct("..."))))
                    || (keyed && !ternary_branch)
                    || KEYWORDS.contains(&name.as_str())
                    || extra_keywords.contains(&name.as_str())
                    || SPECIAL.contains(&name.as_str())
                    || arrow_params.contains(&i)
                {
                    continue;
                }
                out.push(Name { name: name.clone(), offset: base + off });
            }
            _ => after_is = 0,
        }
    }
}

/// Token indexes of arrow function parameters: `p => ...` and `(a, b) => ...`, plus their uses.
fn arrow_params(toks: &[(Tok, usize)]) -> std::collections::HashSet<usize> {
    let mut names = std::collections::HashSet::new();
    for (i, (t, _)) in toks.iter().enumerate() {
        if *t != Tok::Punct("=>") || i == 0 {
            continue;
        }
        match &toks[i - 1].0 {
            Tok::Name(n) => {
                names.insert(n.clone());
            }
            Tok::Punct(")") => {
                let mut j = i - 1;
                while j > 0 {
                    j -= 1;
                    match &toks[j].0 {
                        Tok::Name(n) => {
                            names.insert(n.clone());
                        }
                        Tok::Punct("(") => break,
                        _ => {}
                    }
                }
            }
            _ => {}
        }
    }
    // Arrow parameters are rare and short; treat every use of the name as bound.
    toks.iter()
        .enumerate()
        .filter(|(_, (t, _))| matches!(t, Tok::Name(n) if names.contains(n)))
        .map(|(i, _)| i)
        .collect()
}

/// The result of scanning a Twig tag head.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct TagVars {
    /// Names that the tag binds (`for k, v in ...`, `set x = ...`).
    pub bound: Vec<String>,
    /// Free variables that the tag reads.
    pub used: Vec<Name>,
}

/// Scans the head of a Twig tag (`for p in products`, `set x = 1`, `if a > b`).
/// The offsets in `used` are relative to `head`.
pub fn tag_vars(tag: &str, head: &str) -> TagVars {
    match tag {
        "for" => {
            let Some(in_pos) = find_word(head, "in") else { return TagVars::default() };
            let bound = head[..in_pos].split(',').map(|s| s.trim().to_string()).filter(|s| !s.is_empty()).collect();
            let mut used = Vec::new();
            collect(&head[in_pos + 2..], in_pos + 2, &[], &mut used);
            TagVars { bound, used: dedup(used) }
        }
        "set" => match head.find('=') {
            Some(eq) => {
                let bound = head[..eq].split(',').map(|s| s.trim().to_string()).filter(|s| !s.is_empty()).collect();
                let mut used = Vec::new();
                collect(&head[eq + 1..], eq + 1, &[], &mut used);
                TagVars { bound, used: dedup(used) }
            }
            None => TagVars { bound: vec![head.trim().to_string()], used: vec![] },
        },
        "if" | "elseif" | "do" | "with" | "include" | "embed" | "cache" => {
            let mut used = Vec::new();
            collect(head, 0, &["only", "ignore", "missing", "sandboxed", "key"], &mut used);
            TagVars { bound: vec![], used: dedup(used) }
        }
        // Tags whose heads do not read variables: else, apply (filter names), block, macro, ...
        _ => TagVars::default(),
    }
}

fn find_word(s: &str, word: &str) -> Option<usize> {
    let b = s.as_bytes();
    let mut from = 0;
    while let Some(rel) = s[from..].find(word) {
        let i = from + rel;
        let before = i == 0 || !(b[i - 1].is_ascii_alphanumeric() || b[i - 1] == b'_');
        let after = b.get(i + word.len()).is_none_or(|c| !(c.is_ascii_alphanumeric() || *c == b'_'));
        if before && after {
            return Some(i);
        }
        from = i + word.len();
    }
    None
}

/// True when the whole expression is a call, like `handleClick()` or `a.b(1)`.
pub fn is_call(expr: &str) -> bool {
    let toks = tokenize(expr);
    if toks.len() < 3 || toks.last().map(|t| &t.0) != Some(&Tok::Punct(")")) {
        return false;
    }
    // name (. name)* ( ... ) with balanced parentheses that close at the end.
    let mut i = 0;
    loop {
        if !matches!(toks.get(i).map(|t| &t.0), Some(Tok::Name(_))) {
            return false;
        }
        i += 1;
        match toks.get(i).map(|t| &t.0) {
            Some(Tok::Punct(".")) => i += 1,
            Some(Tok::Punct("(")) => break,
            _ => return false,
        }
    }
    let mut depth = 0;
    for (j, (t, _)) in toks.iter().enumerate().skip(i) {
        match t {
            Tok::Punct("(") => depth += 1,
            Tok::Punct(")") => {
                depth -= 1;
                if depth == 0 {
                    return j == toks.len() - 1;
                }
            }
            _ => {}
        }
    }
    false
}

#[cfg(test)]
mod tests {
    use super::*;

    fn names(expr: &str) -> Vec<String> {
        expr_free_vars(expr).into_iter().map(|n| n.name).collect()
    }

    #[test]
    fn simple() {
        assert_eq!(names("user.name"), ["user"]);
        assert_eq!(names("'Photo of ' ~ user.name"), ["user"]);
        assert_eq!(names("count + 1"), ["count"]);
    }

    #[test]
    fn filters_functions_and_tests() {
        assert_eq!(names("items|length > 0 and range(1, n)|join(sep)"), ["items", "n", "sep"]);
        assert_eq!(names("x is defined and y is not empty"), ["x", "y"]);
        assert_eq!(names("a is divisible by(3)"), ["a"]);
    }

    #[test]
    fn hashes_ternaries_and_named_args() {
        assert_eq!(names("{width: size, height: size}"), ["size"]);
        assert_eq!(names("{color: p.isFruit ? 'magenta' : other}"), ["p", "other"]);
        assert_eq!(names("ok ? yes : no"), ["ok", "yes", "no"]);
        assert_eq!(names("d|date(format: fmt)"), ["d", "fmt"]);
        assert_eq!(names("{...rest, a: 1}"), ["rest"]);
    }

    #[test]
    fn arrows_and_strings() {
        assert_eq!(names("items|map(p => p.price * rate)"), ["items", "rate"]);
        assert_eq!(names("\"Hello #{user.name}!\""), ["user"]);
        assert_eq!(names("loop.index ~ _context"), Vec::<String>::new());
    }

    #[test]
    fn tags() {
        let t = tag_vars("for", "key, product in products if product.ok");
        assert_eq!(t.bound, ["key", "product"]);
        assert_eq!(t.used.iter().map(|n| n.name.as_str()).collect::<Vec<_>>(), ["products", "product"]);
        assert_eq!(tag_vars("set", "total = a + b").bound, ["total"]);
        assert_eq!(tag_vars("set", "body").bound, ["body"]);
    }

    #[test]
    fn calls() {
        assert!(is_call("handleClick()"));
        assert!(is_call("a.b(1, c(2))"));
        assert!(!is_call("handleClick"));
        assert!(!is_call("f() ~ g()"));
    }
}
