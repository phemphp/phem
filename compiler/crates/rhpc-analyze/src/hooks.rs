//! Rules of Hooks: call hooks only at the top level of a component or a custom hook.

use crate::scan::{is_hook_name, FileInfo, Func};
use rhpc_syntax::diag::codes;
use rhpc_syntax::php::{lex, TokKind, Token};
use rhpc_syntax::{Diagnostic, Span};

const CONTROL: &[&str] = &["if", "elseif", "while", "for", "foreach", "switch", "match", "catch"];
const ALT_END: &[&str] = &["endif", "endwhile", "endfor", "endforeach", "endswitch"];

#[derive(Clone, Copy, PartialEq)]
enum Pending {
    None,
    Cond,
    Closure,
}

struct Frame {
    cond: bool,
    closure: bool,
}

pub fn check(file: &FileInfo, src: &str) -> Vec<Diagnostic> {
    let Ok(lexed) = lex(src) else { return vec![] };
    let toks: Vec<Token> = lexed.tokens.into_iter().filter(|t| !t.is_trivia()).collect();
    let mut diags = Vec::new();
    for f in &file.functions {
        let allowed = f.component_kind.is_some() || f.is_hook();
        check_body(f, &toks, src, allowed, "function", &mut diags);
    }
    for c in &file.classes {
        for m in &c.methods {
            let allowed = c.is_component && m.name.eq_ignore_ascii_case("render");
            check_body(m, &toks, src, allowed, "method", &mut diags);
        }
    }
    diags
}

fn check_body(f: &Func, all: &[Token], src: &str, allowed: bool, what: &str, diags: &mut Vec<Diagnostic>) {
    let toks: Vec<Token> = all.iter().copied().filter(|t| f.body.contains(t.span)).collect();
    let text = |i: usize| toks.get(i).map_or("", |t| t.text(src));
    let is_op = |i: usize, op: &str| toks.get(i).is_some_and(|t| t.kind == TokKind::Op) && text(i) == op;
    let word = |i: usize| match toks.get(i) {
        Some(t) if t.kind == TokKind::Ident => text(i).to_ascii_lowercase(),
        _ => String::new(),
    };
    let matching = |open: usize| {
        let mut depth = 0;
        for j in open..toks.len() {
            match text(j) {
                "(" | "[" => depth += 1,
                ")" | "]" => {
                    depth -= 1;
                    if depth == 0 {
                        return j;
                    }
                }
                _ => {}
            }
        }
        toks.len().saturating_sub(1)
    };

    let mut frames: Vec<Frame> = Vec::new();
    let mut paren = 0i32;
    // Conditional statements (`if (x) stmt;`, `a ? b : c`, `a && b`) end at `;` at this frame depth.
    let mut stmt_cond: Vec<usize> = Vec::new();
    // Arrow functions: (frame depth, paren depth at `fn`).
    let mut arrows: Vec<(usize, i32)> = Vec::new();
    let mut pending = Pending::None;
    let mut alt_depth = 0;
    let mut early_return: Option<Span> = None; // the first conditional `return`

    let mut i = 0;
    while i < toks.len() {
        let t = toks[i];
        let w = word(i);
        let in_closure = frames.iter().any(|f| f.closure) || !arrows.is_empty();
        let in_cond = frames.iter().any(|f| f.cond) || !stmt_cond.is_empty() || alt_depth > 0;

        if t.kind == TokKind::Ident && CONTROL.contains(&w.as_str()) && is_op(i + 1, "(") {
            let close = matching(i + 1);
            if is_op(close + 1, "{") {
                pending = Pending::Cond;
            } else if is_op(close + 1, ":") && w != "match" {
                alt_depth += 1;
                i = close + 2;
                continue;
            } else {
                stmt_cond.push(frames.len());
            }
            i = close + 1;
            continue;
        }
        if t.kind == TokKind::Ident && matches!(w.as_str(), "else" | "do" | "try" | "finally") {
            if is_op(i + 1, "{") {
                pending = Pending::Cond;
            } else if is_op(i + 1, ":") {
                i += 2;
                continue;
            } else if word(i + 1) != "if" {
                stmt_cond.push(frames.len());
            }
            i += 1;
            continue;
        }
        if t.kind == TokKind::Ident && ALT_END.contains(&w.as_str()) {
            alt_depth = (alt_depth - 1).max(0);
        }
        if t.kind == TokKind::Ident && w == "function" {
            pending = Pending::Closure;
        }
        if t.kind == TokKind::Ident && w == "fn" {
            arrows.push((frames.len(), paren));
        }
        if t.kind == TokKind::Ident && w == "return" && in_cond && !in_closure && early_return.is_none() {
            early_return = Some(t.span);
        }

        if t.kind == TokKind::Op {
            match text(i) {
                "{" => {
                    frames.push(Frame { cond: pending == Pending::Cond, closure: pending == Pending::Closure });
                    pending = Pending::None;
                }
                "}" => {
                    frames.pop();
                    let d = frames.len();
                    stmt_cond.retain(|&s| s <= d);
                    arrows.retain(|&(fd, _)| fd <= d);
                }
                "(" | "[" => paren += 1,
                ")" | "]" => {
                    paren -= 1;
                    arrows.retain(|&(_, p)| p <= paren);
                }
                "," => {
                    let d = frames.len();
                    arrows.retain(|&(fd, p)| !(fd == d && p == paren));
                }
                ";" => {
                    let d = frames.len();
                    stmt_cond.retain(|&s| s != d);
                    arrows.retain(|&(fd, _)| fd != d);
                }
                "?" | "??" | "&&" | "||" => stmt_cond.push(frames.len()),
                _ => {}
            }
        }
        if t.kind == TokKind::Ident && matches!(w.as_str(), "and" | "or" | "xor") {
            stmt_cond.push(frames.len());
        }

        let is_call = t.kind == TokKind::Ident
            && is_hook_name(text(i))
            && is_op(i + 1, "(")
            && !(i > 0 && (is_op(i - 1, "->") || is_op(i - 1, "?->") || is_op(i - 1, "::") || matches!(word(i - 1).as_str(), "function" | "new")));
        if is_call {
            let name = text(i).rsplit('\\').next().unwrap_or("");
            let span = t.span;
            if !allowed {
                diags.push(
                    Diagnostic::error(codes::HOOK_OUTSIDE_COMPONENT, format!("hook `{name}` is called in {what} `{}`, which is not a component or a hook", f.name), span)
                        .with_label("hook call")
                        .with_help("call hooks only in components (capitalized functions, or `render()` of a #[Component] class) or in custom hooks (`useSomething`)"),
                );
            } else if in_closure {
                diags.push(
                    Diagnostic::error(codes::HOOK_IN_CLOSURE, format!("hook `{name}` is called inside a closure"), span)
                        .with_label("hook call")
                        .with_help("call hooks at the top level of the component, not inside callbacks"),
                );
            } else if in_cond {
                diags.push(
                    Diagnostic::error(codes::HOOK_CONDITIONAL, format!("hook `{name}` is called conditionally"), span)
                        .with_label("inside a condition, a loop, or a try block")
                        .with_help("hooks must run in the same order on each render; move the call to the top level, or extract a component"),
                );
            } else if early_return.is_some() {
                diags.push(
                    Diagnostic::error(codes::HOOK_AFTER_RETURN, format!("hook `{name}` is called after an early return"), span)
                        .with_label("hook call")
                        .with_help("call all hooks before any conditional `return`"),
                );
            }
        }
        i += 1;
    }
}

#[cfg(test)]
mod tests {
    use crate::scan::scan;
    use rhpc_syntax::php::lex;

    fn codes(body: &str) -> Vec<&'static str> {
        let src = format!("<?php\nfunction App() {{\n{body}\nreturn <div />;\n}}\nfunction helper() {{ useState(1); }}\n");
        let lexed = lex(&src).unwrap();
        let info = scan("a.rhp", &src, &lexed);
        super::check(&info, &src).into_iter().map(|d| d.code).collect()
    }

    #[test]
    fn top_level_is_fine() {
        assert_eq!(codes("[$a, $setA] = useState(0); $r = useRef(null); $f = fn() => $setA(1);"), ["E023"]);
    }

    #[test]
    fn conditional_loop_closure_return() {
        assert_eq!(codes("if ($x) { useState(0); }"), ["E020", "E023"]);
        assert_eq!(codes("if ($x) useState(0);"), ["E020", "E023"]);
        assert_eq!(codes("foreach ($xs as $x) { useState(0); }"), ["E020", "E023"]);
        assert_eq!(codes("$a = $x ? useState(0) : 1;"), ["E020", "E023"]);
        assert_eq!(codes("$f = fn() => useState(0);"), ["E021", "E023"]);
        assert_eq!(codes("$f = function () { useState(0); };"), ["E021", "E023"]);
        assert_eq!(codes("if ($x) { return <p />; }\n useState(0);"), ["E022", "E023"]);
        assert_eq!(codes("if ($x) { $y = 1; } useState(0);"), ["E023"]);
    }
}
