//! Phem has no hooks. A component keeps its state in a `#[Component]` class, so a call to a
//! former built-in hook (`Phem\useState`, ...) is an error with a hint.

use crate::scan::FileInfo;
use phemc_syntax::diag::codes;
use phemc_syntax::php::{Lexed, TokKind, Token};
use phemc_syntax::Diagnostic;

const REMOVED: &[&str] = &["usestate", "usereducer", "useref", "usememo", "usecallback", "useid", "usecontext"];

pub fn check(file: &FileInfo, src: &str, lexed: &Lexed) -> Vec<Diagnostic> {
    let toks: Vec<Token> = lexed.tokens.iter().copied().filter(|t| !t.is_trivia()).collect();
    let is_op = |i: usize, op: &str| toks.get(i).is_some_and(|t| t.kind == TokKind::Op && t.text(src) == op);
    let is_word = |i: usize, w: &str| toks.get(i).is_some_and(|t| t.kind == TokKind::Ident && t.text(src).eq_ignore_ascii_case(w));

    let mut diags = Vec::new();
    for (i, t) in toks.iter().enumerate() {
        if t.kind != TokKind::Ident || !is_op(i + 1, "(") {
            continue;
        }

        // A method call, a static call, a declaration or `new` is not a call to a hook function.
        if i > 0 && (is_op(i - 1, "->") || is_op(i - 1, "?->") || is_op(i - 1, "::") || is_word(i - 1, "function") || is_word(i - 1, "new")) {
            continue;
        }

        let name = t.text(src);
        if !is_removed_hook(file, name) {
            continue;
        }

        let short = name.rsplit('\\').next().unwrap_or(name);
        diags.push(
            Diagnostic::error(codes::HOOK_REMOVED, format!("`{short}` is not available: Phem has no hooks"), t.span)
                .with_label("hook call")
                .with_help(
                    "keep state in a `#[Component]` class: a `#[State]` property for the state, a method for the handler, \
                     `mount()` for the first values, and the constructor for services",
                ),
        );
    }

    diags
}

/// True when a function name resolves to one of the removed `Phem\use*` functions.
fn is_removed_hook(file: &FileInfo, name: &str) -> bool {
    let fqn = if let Some(abs) = name.strip_prefix('\\') {
        abs.to_string()
    } else if name.contains('\\') {
        if file.namespace.is_empty() { name.to_string() } else { format!("{}\\{name}", file.namespace) }
    } else if let Some(imported) = file.function_uses.get(&name.to_ascii_lowercase()) {
        imported.clone()
    } else if file.namespace.is_empty() {
        name.to_string()
    } else {
        format!("{}\\{name}", file.namespace)
    };

    let fqn = fqn.to_ascii_lowercase();
    fqn.strip_prefix("phem\\").is_some_and(|short| REMOVED.contains(&short))
}

#[cfg(test)]
mod tests {
    use crate::scan::scan;
    use phemc_syntax::php::lex;

    fn codes(src: &str) -> Vec<&'static str> {
        let lexed = lex(src).unwrap();
        let info = scan("a.phem", src, &lexed);
        super::check(&info, src, &lexed).into_iter().map(|d| d.code).collect()
    }

    #[test]
    fn imported_and_qualified_hooks_are_errors() {
        assert_eq!(codes("<?phem\nnamespace App;\nuse function Phem\\useState;\nfunction A() { [$a] = useState(0); }\n"), ["E020"]);
        assert_eq!(codes("<?phem\nnamespace App;\nuse function Phem\\{useId, useRef};\nfunction A() { useId(); useRef(1); }\n"), ["E020", "E020"]);
        assert_eq!(codes("<?phem\nnamespace App;\nfunction A() { \\Phem\\useState(0); }\n"), ["E020"]);
    }

    #[test]
    fn other_use_functions_are_fine() {
        assert!(codes("<?phem\nnamespace App;\nfunction useTheme() { return 'dark'; }\nfunction A() { useTheme(); }\n").is_empty());
        assert!(codes("<?phem\nnamespace App;\nfunction A($s) { $s->useState(1); X::useState(2); }\n").is_empty());
        assert!(codes("<?phem\nnamespace App;\nfunction A() { useState(0); }\n").is_empty(), "App\\useState is a user function");
    }
}
