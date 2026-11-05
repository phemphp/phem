//! `render()` is pure, as in React: its output depends only on the props and the state. An event
//! renders only the components that can change, so a component that reads other data shows old
//! data. This check reports the PHP code of a `render()` method or of a function component that
//! reads other data: a service of the constructor, a static property, a superglobal, or the clock
//! or a random source. The markup part (a service in a markup expression) is in the code generator.

use crate::scan::{FileInfo, Func};
use phemc_syntax::diag::codes;
use phemc_syntax::php::{Lexed, TokKind, Token};
use phemc_syntax::{Diagnostic, Span};

const SUPERGLOBALS: &[&str] = &["$_GET", "$_POST", "$_COOKIE", "$_SESSION", "$_SERVER", "$_REQUEST", "$_ENV", "$_FILES", "$GLOBALS"];

/// Functions whose result changes from call to call (lower case).
const CLOCK_AND_RANDOM: &[&str] = &[
    "time", "date", "gmdate", "mktime", "microtime", "hrtime", "date_create", "date_create_immutable", "rand", "mt_rand", "random_int",
    "random_bytes", "uniqid", "lcg_value", "array_rand", "shuffle", "str_shuffle",
];

const DATE_CLASSES: &[&str] = &["datetime", "datetimeimmutable"];

pub const HELP: &str = "`render()` must be pure: read the data in `mount()` or in a handler, and keep it in a `#[State]` property; \
                        or call `$e->refresh()` in the handler that changes the data";

pub fn check(file: &FileInfo, src: &str, lexed: &Lexed) -> Vec<Diagnostic> {
    let mut diags = Vec::new();
    for f in file.functions.iter().filter(|f| f.component_kind.is_some()) {
        check_body(f, &[], src, lexed, &mut diags);
    }

    for c in file.classes.iter().filter(|c| c.is_component) {
        let services: Vec<&str> = c.all_props().filter(|p| p.is_promoted).map(|p| p.name.as_str()).collect();
        for m in c.methods.iter().filter(|m| m.name.eq_ignore_ascii_case("render")) {
            check_body(m, &services, src, lexed, &mut diags);
        }
    }

    diags
}

fn check_body(f: &Func, services: &[&str], src: &str, lexed: &Lexed, diags: &mut Vec<Diagnostic>) {
    let toks: Vec<Token> = lexed.tokens.iter().copied().filter(|t| !t.is_trivia() && t.span.start >= f.body.start && t.span.end <= f.body.end).collect();
    let is_op = |i: usize, op: &str| toks.get(i).is_some_and(|t| t.kind == TokKind::Op && t.text(src) == op);
    let is_word = |i: usize, w: &str| toks.get(i).is_some_and(|t| t.kind == TokKind::Ident && t.text(src).eq_ignore_ascii_case(w));
    let what = if f.name.eq_ignore_ascii_case("render") { "`render()`".to_string() } else { format!("the component `{}`", f.name) };
    let mut report = |span: Span, reads: String| {
        diags.push(Diagnostic::warning(codes::IMPURE_RENDER, format!("{what} reads {reads}"), span).with_label("not a prop or state").with_help(HELP));
    };

    for (i, t) in toks.iter().enumerate() {
        let text = t.text(src);
        match t.kind {
            // `$this->service`
            TokKind::Var if text == "$this" && (is_op(i + 1, "->") || is_op(i + 1, "?->")) => {
                if let Some(name) = toks.get(i + 2).filter(|n| n.kind == TokKind::Ident).map(|n| n.text(src)) {
                    if services.contains(&name) && !is_op(i + 3, "(") {
                        report(Span::new(t.span.start, toks[i + 2].span.end), format!("the service `${name}`"));
                    }
                }
            }
            TokKind::Var if SUPERGLOBALS.contains(&text) => report(t.span, format!("the superglobal `{text}`")),
            // `Foo::$bar`, `self::$bar`, `static::$bar`
            TokKind::Var if i > 1 && is_op(i - 1, "::") && toks[i - 2].kind == TokKind::Ident => {
                report(Span::new(toks[i - 2].span.start, t.span.end), format!("the static property `{}::{text}`", toks[i - 2].text(src)));
            }
            TokKind::Ident if is_op(i + 1, "(") && !(i > 0 && (is_op(i - 1, "->") || is_op(i - 1, "?->") || is_op(i - 1, "::") || is_word(i - 1, "function"))) => {
                let short = text.trim_start_matches('\\').to_ascii_lowercase();
                if i > 0 && is_word(i - 1, "new") {
                    // `new DateTime()` and `new DateTimeImmutable()` with no argument: now.
                    if DATE_CLASSES.contains(&short.as_str()) && is_op(i + 2, ")") {
                        report(Span::new(toks[i - 1].span.start, toks[i + 2].span.end), "the clock".to_string());
                    }
                } else if CLOCK_AND_RANDOM.contains(&short.as_str()) {
                    let source = if ["rand", "mt_rand", "random_int", "random_bytes", "uniqid", "lcg_value", "array_rand", "shuffle", "str_shuffle"].contains(&short.as_str()) { "a random value" } else { "the clock" };
                    report(t.span, format!("{source} (`{text}()`)"));
                }
            }
            _ => {}
        }
    }
}

#[cfg(test)]
mod tests {
    use crate::scan::scan;
    use phemc_syntax::php::lex;

    fn messages(src: &str) -> Vec<String> {
        let lexed = lex(src).unwrap();
        let info = scan("a.phem", src, &lexed);
        super::check(&info, src, &lexed).into_iter().map(|d| format!("{} {}", d.code, d.message)).collect()
    }

    #[test]
    fn a_service_in_render_is_reported() {
        let src = "<?phem\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class A {\n    public function __construct(private readonly Repo $repo) {}\n    public function mount(): void { $this->n = $this->repo->count(); }\n    public function render() { $n = $this->repo->count(); return <p>{{ n }}</p>; }\n}\n";
        assert_eq!(messages(src), ["W008 `render()` reads the service `$repo`"]);
    }

    #[test]
    fn globals_the_clock_and_random_values_are_reported() {
        let src = "<?phem\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction A() { $a = Rows::$all; $b = $_SESSION['x']; $c = time(); $d = new \\DateTimeImmutable(); $e = random_int(1, 6); return <p>{{ a }}</p>; }\n";
        assert_eq!(
            messages(src),
            [
                "W008 the component `A` reads the static property `Rows::$all`",
                "W008 the component `A` reads the superglobal `$_SESSION`",
                "W008 the component `A` reads the clock (`time()`)",
                "W008 the component `A` reads the clock",
                "W008 the component `A` reads a random value (`random_int()`)",
            ]
        );
    }

    #[test]
    fn pure_code_is_fine() {
        let src = "<?phem\nuse Phem\\Attribute\\Component;\n#[Component]\nfinal class A {\n    public function __construct(private readonly Repo $repo) {}\n    private array $rows = [];\n    public function mount(): void { $this->rows = $this->repo->all(); }\n    public function save(): void { $this->repo->save(time()); }\n    public function render() { $rows = $this->rows; $d = new \\DateTimeImmutable('2020-01-01'); $x = Rows::count(); $y = $this->format(); return <p>{{ rows|length }}</p>; }\n}\n";
        assert!(messages(src).is_empty(), "{:?}", messages(src));

        let src = "<?phem\nfunction helper() { return time(); }\n";
        assert!(messages(src).is_empty(), "a plain function is not a component");
    }
}
