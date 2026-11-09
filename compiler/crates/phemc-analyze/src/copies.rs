//! The PHP part of hint H001: a statement `$var = $this->prop;` in a method of a component that
//! only copies a property for the markup. The markup reads the properties of the class (also the
//! private ones), so the copy is not necessary. The markup part (the uses of `var` and the quick
//! fix) is in the code generator.

use crate::has_variable;
use crate::scan::{Class, Func};
use phemc_syntax::php::{Lexed, TokKind, Token};
use phemc_syntax::Span;

/// Functions that read a variable by its name (`compact('rows')`). A variable variable (`$$name`)
/// does it too.
const BY_NAME: &[&str] = &["compact", "extract", "get_defined_vars"];

/// The ends of the alternative syntax (`if (...): ... endif;`). Its blocks have no braces, so
/// the top level of the body is not known.
const ALTERNATIVE_ENDS: &[&str] = &["endif", "endforeach", "endfor", "endwhile", "endswitch", "enddeclare"];

/// A statement that copies a property into a variable.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Copy {
    /// The variable, without `$`.
    pub var: String,
    pub prop: String,
    /// `$var = $this->prop;`
    pub stmt: Span,
    /// The span to delete: the full line (indent and newline) when the statement is alone on
    /// its line, else the statement. See `line_of()`.
    pub line: Span,
}

/// The copies of a method of a component class. A copy is `$var = $this->prop;` at the top
/// level of the body, of a property that is not static and not a service, into a variable that
/// the PHP code does not use in another place. The code after the copy must not read or change
/// the property, and must not call a method of the class: then the variable can hold an older
/// value than the property.
pub fn copies(class: &Class, method: &Func, src: &str, lexed: &Lexed) -> Vec<Copy> {
    if !class.is_component {
        return vec![];
    }

    let toks: Vec<Token> = lexed.tokens.iter().copied().filter(|t| !t.is_trivia() && t.span.start >= method.body.start && t.span.end <= method.body.end).collect();
    let is_op = |i: usize, op: &str| toks.get(i).is_some_and(|t| t.kind == TokKind::Op && t.text(src) == op);
    let is_word = |i: usize, words: &[&str]| toks.get(i).is_some_and(|t| t.kind == TokKind::Ident && words.iter().any(|w| t.text(src).eq_ignore_ascii_case(w)));

    // Code that reads a variable by its name can read a copy with no `$var` token. A static
    // closure has no `$this`, so its markup cannot read the property.
    let unsafe_body = toks.iter().enumerate().any(|(i, t)| {
        (is_word(i, BY_NAME) && is_op(i + 1, "("))
            || (t.kind == TokKind::Op && t.text(src) == "$")
            || is_word(i, ALTERNATIVE_ENDS)
            || (is_word(i, &["static"]) && is_word(i + 1, &["fn", "function"]))
    });
    if unsafe_body {
        return vec![];
    }

    let mut out = Vec::new();
    let mut depth = 0usize;
    for (i, t) in toks.iter().enumerate() {
        if is_op(i, "{") {
            depth += 1;
        } else if is_op(i, "}") {
            depth = depth.saturating_sub(1);
        }

        let starts_statement = i == 0 || is_op(i - 1, ";") || is_op(i - 1, "{") || is_op(i - 1, "}");
        if depth != 0 || t.kind != TokKind::Var || !starts_statement {
            continue;
        }

        let is_copy = is_op(i + 1, "=")
            && toks.get(i + 2).is_some_and(|n| n.kind == TokKind::Var && n.text(src) == "$this")
            && is_op(i + 3, "->")
            && toks.get(i + 4).is_some_and(|n| n.kind == TokKind::Ident)
            && is_op(i + 5, ";");
        if !is_copy {
            continue;
        }

        let var = &t.text(src)[1..];
        let prop = toks[i + 4].text(src);
        let stmt = Span::new(t.span.start, toks[i + 5].span.end);
        let used_again = has_variable(&src[method.body.start..stmt.start], var) || has_variable(&src[stmt.end..method.body.end], var);
        if var == "this" || used_again || !class.all_props().any(|p| p.name == prop && !p.is_static && !p.is_promoted) || can_change(&toks[i + 6..], prop, src) {
            continue;
        }

        out.push(Copy { var: var.to_string(), prop: prop.to_string(), stmt, line: line_of(src, stmt) });
    }

    out
}

/// Whether the code after a copy can change the property: it reads or writes `$this->prop`
/// (`$this->lines[] = $line`), or calls a method of the class (`$this->reset()`). A call on a
/// service (`$this->streams->toPage(...)`) does not change a property of the class.
fn can_change(toks: &[Token], prop: &str, src: &str) -> bool {
    toks.windows(3).enumerate().any(|(i, w)| {
        let member = w[0].kind == TokKind::Var && w[0].text(src) == "$this" && matches!(w[1].text(src), "->" | "?->") && w[2].kind == TokKind::Ident;
        let call = toks.get(i + 3).is_some_and(|t| t.text(src) == "(");

        member && (w[2].text(src) == prop || call)
    })
}

/// The span to delete for a statement. When the statement is alone on its line, it is the full
/// line, and also the blank line after it when the line before is blank or opens a block: the
/// fix leaves no blank line at the start of a block, and no two blank lines.
fn line_of(src: &str, stmt: Span) -> Span {
    let start = src[..stmt.start].rfind('\n').map_or(0, |n| n + 1);
    let mut end = src[stmt.end..].find('\n').map_or(src.len(), |n| stmt.end + n + 1);
    if !src[start..stmt.start].trim().is_empty() || !src[stmt.end..end].trim().is_empty() {
        return stmt;
    }

    let before = src[..start].strip_suffix('\n').unwrap_or_default();
    let line_before = &before[before.rfind('\n').map_or(0, |n| n + 1)..];
    let next_end = src[end..].find('\n').map(|n| end + n + 1);
    if let Some(next_end) = next_end.filter(|&e| src[end..e].trim().is_empty()) {
        if line_before.trim().is_empty() || line_before.trim_end().ends_with('{') {
            end = next_end;
        }
    }

    Span::new(start, end)
}

#[cfg(test)]
mod tests {
    use crate::scan::scan;
    use phemc_syntax::php::lex;

    fn copies(body: &str) -> Vec<(String, String, String)> {
        let src = format!(
            "<?phem\nuse Phem\\Attribute\\{{Component, State}};\n#[Component]\nfinal class A {{\n    public function __construct(private readonly Repo $repo) {{}}\n    #[State] private array $lines = [];\n    public static int $max = 3;\n    public function render() {{\n{body}\n        return <p>{{{{ lines|length }}}}</p>;\n    }}\n}}\n"
        );
        let lexed = lex(&src).unwrap();
        let info = scan("a.phem", &src, &lexed);
        let c = &info.classes[0];
        let m = c.methods.iter().find(|m| m.name == "render").unwrap();

        super::copies(c, m, &src, &lexed).into_iter().map(|x| (x.var, x.prop, x.line.text(&src).to_string())).collect()
    }

    #[test]
    fn a_copy_is_found_with_its_line() {
        assert_eq!(copies("        $lines = $this->lines;"), [("lines".into(), "lines".into(), "        $lines = $this->lines;\n".into())]);
        assert_eq!(copies("        $rows = $this->lines;"), [("rows".into(), "lines".into(), "        $rows = $this->lines;\n".into())]);
        assert_eq!(copies("        $a = 1; $rows = $this->lines;"), [("rows".into(), "lines".into(), "$rows = $this->lines;".into())]);
    }

    #[test]
    fn a_variable_with_another_use_is_not_a_copy() {
        assert!(copies("        $lines = $this->lines;\n        $lines[] = 1;").is_empty());
        assert!(copies("        $lines = $this->lines;\n        $n = count($lines);").is_empty());
        assert!(copies("        $lines = $this->lines;\n        $f = function () use ($lines) {};").is_empty());
        assert!(copies("        $lines = $this->lines;\n        $s = \"{$lines[0]}\";").is_empty());
        assert!(copies("        $lines = $this->lines;\n        $all = compact('lines');").is_empty());
    }

    #[test]
    fn other_statements_are_not_copies() {
        assert!(copies("        if (true) { $lines = $this->lines; }").is_empty(), "not at the top level");
        assert!(copies("        if (true) $lines = $this->lines;").is_empty(), "not at the top level");
        assert!(copies("        $repo = $this->repo;").is_empty(), "a service is W008");
        assert!(copies("        $lines = $this->lines ?? [];").is_empty(), "not only a copy");
        assert!(copies("        $lines = $this->other;").is_empty(), "not a property of the class");
        assert!(copies("        $max = $this->max;").is_empty(), "a static property");
        assert!(copies("        if (true): $a = 1; $lines = $this->lines; endif;").is_empty(), "in a block of the alternative syntax");
        assert!(copies("        $lines = $this->lines;\n        $f = static fn () => 1;").is_empty(), "a static closure has no `$this`");
    }

    #[test]
    fn a_copy_of_a_property_that_can_change_after_it_is_not_reported() {
        // The variable keeps the old value: the markup reads the copy, not the property.
        assert!(copies("        $lines = $this->lines;\n        $this->lines = [];").is_empty());
        assert!(copies("        $rows = $this->lines;\n        $this->lines[] = 1;").is_empty());
        assert!(copies("        $rows = $this->lines;\n        $this->reset();").is_empty(), "a method of the class can change it");

        // A service cannot change a property of the class.
        assert_eq!(copies("        $rows = $this->lines;\n        $n = $this->repo->count();").len(), 1);
    }

    #[test]
    fn the_fix_leaves_no_blank_line_at_the_start_of_a_block() {
        let lines = |body: &str| copies(body).into_iter().map(|c| c.2).collect::<Vec<_>>();

        // The copy is the first statement: the blank line after it goes too.
        assert_eq!(lines("        $rows = $this->lines;\n\n        $a = 1;"), ["        $rows = $this->lines;\n\n"]);
        // Between two blank lines: one blank line stays.
        assert_eq!(lines("        $a = 1;\n\n        $rows = $this->lines;\n\n        $b = 2;"), ["        $rows = $this->lines;\n\n"]);
        // Between two statements: only its line.
        assert_eq!(lines("        $a = 1;\n        $rows = $this->lines;\n\n        $b = 2;"), ["        $rows = $this->lines;\n"]);
    }
}
