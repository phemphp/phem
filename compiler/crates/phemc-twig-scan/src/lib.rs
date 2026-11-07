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

/// The words of the operators. They are never names.
const OPERATORS: &[&str] = &["and", "or", "not", "in", "is", "matches", "b-and", "b-or", "b-xor", "xor"];

/// The bitwise operators. The lexer reads each one as one word, not as `b - and`.
const BITWISE: &[&str] = &["b-and", "b-or", "b-xor"];

/// The constants. They are values, not names.
const CONSTANTS: &[&str] = &["true", "false", "null", "none", "TRUE", "FALSE", "NULL", "NONE"];

/// The operators of two words. Each word alone is a name: `{% if with %}` reads `with`.
const PAIRS: &[(&str, &str)] = &[("starts", "with"), ("ends", "with"), ("has", "some"), ("has", "every")];

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
        let bitwise = BITWISE.iter().find(|op| src[i..].starts_with(**op) && !b.get(i + op.len()).is_some_and(|d| d.is_ascii_alphanumeric() || *d == b'_'));
        if let Some(op) = bitwise {
            i += op.len();
            out.push((Tok::Name(op.to_string()), start));
            continue;
        }

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
    collect_toks(&tokenize(src), base, extra_keywords, out);
}

/// The free variables of a list of tokens. An offset is `base` plus the offset of its token.
fn collect_toks(toks: &[(Tok, usize)], base: usize, extra_keywords: &[&str], out: &mut Vec<Name>) {
    let arrow_params = arrow_params(toks);

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
                    || is_operator(toks, i)
                    || CONSTANTS.contains(&name.as_str())
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
    /// True for a tag that is not a core Twig tag. Its head can contain keywords of the tag
    /// (`{% trans into 'fr' %}`), so the names in `used` are only guesses.
    pub guessed: bool,
}

/// Scans the head of a Twig tag (`for p in products`, `set x = 1`, `if a > b`).
/// The offsets in `used` are relative to `head`.
pub fn tag_vars(tag: &str, head: &str) -> TagVars {
    match tag {
        "for" => {
            let Some(in_pos) = find_word(head, "in") else { return TagVars::default() };

            let bound = head[..in_pos].split(',').map(|s| s.trim().to_string()).filter(|s| !s.is_empty()).collect();
            let mut used = Vec::new();
            // `for p in products if p.ok`: the `if` of Twig 2.
            collect(&head[in_pos + 2..], in_pos + 2, &["if"], &mut used);
            TagVars { bound, used: dedup(used), ..Default::default() }
        }
        "set" => match head.find('=') {
            Some(eq) => {
                let bound = head[..eq].split(',').map(|s| s.trim().to_string()).filter(|s| !s.is_empty()).collect();
                let mut used = Vec::new();
                collect(&head[eq + 1..], eq + 1, &[], &mut used);
                TagVars { bound, used: dedup(used), ..Default::default() }
            }
            None => TagVars { bound: vec![head.trim().to_string()], ..Default::default() },
        },
        "if" | "elseif" | "do" | "cache" => TagVars { used: expr_free_vars(head), ..Default::default() },
        "with" => TagVars { used: dedup(with_vars(head)), ..Default::default() },
        "include" | "embed" => TagVars { used: dedup(include_vars(head)), ..Default::default() },
        // Core tags whose heads do not read variables: filter, block, macro, and template names.
        "else" | "apply" | "filter" | "block" | "macro" | "autoescape" | "sandbox" | "guard" | "spaceless" | "verbatim"
        | "flush" | "deprecated" | "extends" | "use" | "import" | "from" | "types" => TagVars::default(),
        _ if tag.starts_with("end") => TagVars::default(),
        // Other tags (new Twig tags, or tags of extensions): read the head like an expression.
        _ => {
            let mut used = Vec::new();
            collect(head, 0, &[], &mut used);
            TagVars { bound: vec![], used: dedup(used), guessed: true }
        }
    }
}

/// The variables of `{% with vars only %}`. `only` is a keyword only after the expression.
fn with_vars(head: &str) -> Vec<Name> {
    let toks = tokenize(head);
    let end = keyword_after_operand(&toks, 0, &["only"]).unwrap_or(toks.len());

    let mut used = Vec::new();
    collect_toks(&toks[..end], 0, &[], &mut used);

    used
}

/// The variables of `{% include 'x.twig' ignore missing with vars only %}` (and of `embed`).
/// `ignore missing`, `with` and `only` are keywords only after the template expression.
fn include_vars(head: &str) -> Vec<Name> {
    let toks = tokenize(head);

    let mut used = Vec::new();
    let Some(mut i) = keyword_after_operand(&toks, 0, &["ignore", "with", "only"]) else {
        collect_toks(&toks, 0, &[], &mut used);
        return used;
    };

    collect_toks(&toks[..i], 0, &[], &mut used);

    if is_word(&toks, i, "ignore") {
        i += 2;
    }

    if is_word(&toks, i, "with") {
        let end = keyword_after_operand(&toks, i + 1, &["only"]).unwrap_or(toks.len());
        collect_toks(&toks[i + 1..end], 0, &[], &mut used);
    }

    used
}

/// The index of the first of `words` after `from` that comes after a whole operand, outside of
/// brackets. Twig reads an expression as long as it can: a name after an operand ends it.
fn keyword_after_operand(toks: &[(Tok, usize)], from: usize, words: &[&str]) -> Option<usize> {
    let mut depth = 0usize;
    for (i, (tok, _)) in toks.iter().enumerate().skip(from) {
        match tok {
            Tok::Punct("(" | "[" | "{") => depth += 1,
            Tok::Punct(")" | "]" | "}") => depth = depth.saturating_sub(1),
            Tok::Name(n) if depth == 0 && i > from && words.contains(&n.as_str()) && after_operand(toks, i) => return Some(i),
            _ => {}
        }
    }

    None
}

/// True when the token before `i` ends an operand: a value, a name or a closing bracket.
fn after_operand(toks: &[(Tok, usize)], i: usize) -> bool {
    match &toks[i - 1].0 {
        Tok::Num | Tok::Str(_) | Tok::Punct(")" | "]" | "}") => true,
        Tok::Name(_) => !is_operator(toks, i - 1),
        Tok::Punct(_) => false,
    }
}

/// True when token `i` is a word of an operator: `and`, or a word of `starts with`.
fn is_operator(toks: &[(Tok, usize)], i: usize) -> bool {
    let pair = |a: usize, b: usize| match (toks.get(a), toks.get(b)) {
        (Some((Tok::Name(x), _)), Some((Tok::Name(y), _))) => PAIRS.contains(&(x.as_str(), y.as_str())),
        _ => false,
    };

    matches!(&toks[i].0, Tok::Name(n) if OPERATORS.contains(&n.as_str())) || pair(i, i + 1) || (i > 0 && pair(i - 1, i))
}

fn is_word(toks: &[(Tok, usize)], i: usize, word: &str) -> bool {
    matches!(toks.get(i), Some((Tok::Name(n), _)) if n == word)
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
        assert!(!tag_vars("if", "a").guessed);
        assert_eq!(tag_vars("block", "content"), TagVars::default());
        assert_eq!(tag_vars("endtrans", ""), TagVars::default());

        let t = tag_vars("trans", "with {'%name%': user.name} into locale");
        assert!(t.guessed);
        // The keywords of an unknown tag are guesses too: they give no diagnostic.
        assert_eq!(t.used.iter().map(|n| n.name.as_str()).collect::<Vec<_>>(), ["with", "user", "into", "locale"]);
    }

    #[test]
    fn single_words_of_operators_are_names() {
        assert_eq!(names("with or has or some or every or starts or ends"), ["with", "has", "some", "every", "starts", "ends"]);
        assert_eq!(names("a starts with b and c ends with d"), ["a", "b", "c", "d"]);
        assert_eq!(names("items has some i => i > n"), ["items", "n"]);
        assert_eq!(names("items has every i => i > n"), ["items", "n"]);
    }

    #[test]
    fn bitwise_operators() {
        assert_eq!(names("flags b-and 4"), ["flags"]);
        assert_eq!(names("a b-or b b-xor c"), ["a", "b", "c"]);
        assert_eq!(names("b - and_more"), ["b", "and_more"]);
    }

    fn used(tag: &str, head: &str) -> Vec<String> {
        tag_vars(tag, head).used.into_iter().map(|n| n.name).collect()
    }

    #[test]
    fn tag_keywords_are_names_in_other_tags() {
        for name in ["missing", "ignore", "only", "with", "as", "key", "sandboxed"] {
            assert_eq!(used("if", &format!("not {name}")), [name], "if {name}");
            assert_eq!(used("elseif", name), [name], "elseif {name}");
            assert_eq!(used("do", name), [name], "do {name}");
        }
    }

    #[test]
    fn include_and_embed_keywords() {
        for tag in ["include", "embed"] {
            assert_eq!(used(tag, "'x.twig' ignore missing"), Vec::<String>::new());
            assert_eq!(used(tag, "'x.twig' ignore missing with {a: b} only"), ["b"]);
            assert_eq!(used(tag, "tpl with vars"), ["tpl", "vars"]);
            assert_eq!(used(tag, "['a.twig', b] only"), ["b"]);
            assert_eq!(used(tag, "'x/' ~ missing ~ '.twig' with {only: only}"), ["missing", "only"]);
            assert_eq!(used(tag, "'x.twig' with only"), ["only"]);
            // A keyword word that is an operand is a name.
            assert_eq!(used(tag, "'x.twig' with base ~ only"), ["base", "only"]);
            assert_eq!(used(tag, "'x.twig' with flag ?: only only"), ["flag", "only"]);
            // A constant ends an operand too.
            assert_eq!(used(tag, "cond ? 'a.twig' : null ignore missing"), ["cond"]);
            assert_eq!(used(tag, "tpl is not null with vars"), ["tpl", "vars"]);
            assert_eq!(used(tag, "flag ? 'a.twig' : false only"), ["flag"]);
            // A name like the first word of an operator is a name when the second word does not follow.
            assert_eq!(used(tag, "has with vars"), ["has", "vars"]);
            assert_eq!(used(tag, "ends only"), ["ends"]);
            assert_eq!(used(tag, "name starts with 'a' ? 'a.twig' : 'b.twig' with vars"), ["name", "vars"]);
        }

        let t = tag_vars("include", "'x.twig' ignore missing with {a: missing} only");
        assert_eq!(t.used, [Name { name: "missing".into(), offset: 33 }]);
    }

    #[test]
    fn with_keywords() {
        assert_eq!(used("with", "vars only"), ["vars"]);
        assert_eq!(used("with", "{a: only}"), ["only"]);
        assert_eq!(used("with", "only"), ["only"]);
        assert_eq!(used("with", "a ~ only"), ["a", "only"]);
        assert_eq!(used("with", "not only"), ["only"]);
        assert_eq!(used("with", "a ?: only only"), ["a", "only"]);
        assert_eq!(used("with", "{a: 1}|merge(extra) only"), ["extra"]);
        assert_eq!(used("with", ""), Vec::<String>::new());
    }

    #[test]
    fn calls() {
        assert!(is_call("handleClick()"));
        assert!(is_call("a.b(1, c(2))"));
        assert!(!is_call("handleClick"));
        assert!(!is_call("f() ~ g()"));
    }
}
