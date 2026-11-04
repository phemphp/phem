//! The PHP variables that are always defined at a markup literal. The codegen passes such a
//! variable to its template as `$name`, and another variable as `$name ?? null`. PHPStan then
//! sees the same code as for a plain PHP file.
//!
//! The check is one forward walk over the tokens of a function. A name is defined only in these
//! cases:
//!
//! - A parameter of the function, of a closure, or of an arrow function, and a `use` variable.
//! - The loop variable of a `foreach`, and the variable of a `for` init, in the braces of the
//!   loop. The variable of a `catch` in its braces.
//! - An assignment (`$x = ...`, `$x ??= ...`, `[$a, $b] = ...`) that starts a statement in a
//!   block, after the `;` of the statement. Or in each branch of an `if` chain with an `else`,
//!   or of a `try` and its `catch` blocks. A branch that ends with `return`, `throw`, `break`
//!   or `continue` does not count.
//!
//! The check is conservative: for a construct that it does not know, a name is not defined. An
//! alternative syntax block (`if (...): ... endif;`) stops the assignments of its frame, a label
//! (a `goto` target) forgets the assignments before it, and a brace with an unknown header
//! hides the names of the outer frames. `unset($x)` makes the name not defined.

use phemc_analyze::Toks;
use phemc_syntax::php::{TokKind, Token};
use phemc_syntax::Span;
use std::collections::{HashMap, HashSet};

const ASSIGN_OPS: &[&str] = &["=", "??=", ".=", "+=", "-=", "*=", "/=", "%=", "**=", "|=", "&=", "^=", "<<=", ">>="];

/// The statements after which the rest of a block does not run.
const TERMINATORS: &[&str] = &["return", "throw", "break", "continue", "exit", "die"];

/// The control structures with an alternative syntax: `if (...):`.
const ALT_SYNTAX: &[&str] = &["if", "elseif", "foreach", "for", "while", "switch", "declare"];

/// The headers of a brace that are not a function: their block sees the names of the frame.
const BLOCK_HEADERS: &[&str] = &["while", "match", "declare", "class"];

/// The names that are always defined at each markup literal of `scope` (a function or a method,
/// from its `function` keyword to its closing brace), by the start offset of the literal.
pub(crate) fn defined_at_markups(tokens: &[Token], src: &str, scope: Span) -> HashMap<usize, HashSet<String>> {
    let from = tokens.partition_point(|t| t.span.start < scope.start);
    let to = tokens.partition_point(|t| t.span.end <= scope.end).max(from);
    let toks = Toks::new(src, tokens[from..to].iter().copied());
    let mut walk = Walk { toks: &toks, frames: vec![Frame::new(Vec::new(), true, Kind::Block)], out: HashMap::new() };

    walk.run();

    walk.out
}

#[derive(Clone, Copy, PartialEq)]
enum Kind {
    Block,
    /// A `switch`: its statements run only for some cases.
    Switch,
    /// A branch of an `if` chain, or a `try` or `catch` block.
    Branch,
    /// An arrow function body: it ends at a `,`, `;` or closing bracket at its depth.
    Arrow,
}

/// The place of a brace in a chain.
#[derive(Clone, Copy, PartialEq)]
enum Branch {
    /// `if` or `try`: a new chain. `exhaustive` for a `try`. `starts` when the keyword starts a
    /// statement.
    First { exhaustive: bool, starts: bool },
    /// `elseif` or `catch`.
    Next,
    Else,
}

/// An `if` or `try` chain: the names that each branch defines.
struct Chain {
    /// `None` until a branch that goes on after the chain closes.
    common: Option<HashSet<String>>,
    /// One branch always runs: an `if` chain with an `else`, or a `try`.
    exhaustive: bool,
    /// The keyword starts a statement in a frame that counts its assignments.
    valid: bool,
    /// A branch just closed: the next token continues the chain, or ends it.
    waiting: bool,
}

struct Frame {
    /// The parameters and the loop variables of the frame.
    header: HashSet<String>,
    /// The names that the statements of the frame assigned.
    assigned: HashSet<String>,
    /// The names that the current statement assigns, after its `;`.
    pending: Vec<String>,
    /// A function or closure body: the names of the outer frames are not visible in it.
    boundary: bool,
    kind: Kind,
    /// The `(`, `[` and `#[` depth in the frame.
    depth: usize,
    /// The next token starts a statement.
    stmt_start: bool,
    /// An alternative syntax block started in the frame: its statements run only in some cases.
    alt_syntax: bool,
    /// The current statement is a `return`, `throw`, `break` or `continue`.
    terminating: bool,
    /// A statement of the frame ended the block: the code after it does not run.
    terminated: bool,
    chain: Option<Chain>,
}

impl Frame {
    fn new(names: Vec<String>, boundary: bool, kind: Kind) -> Self {
        Frame {
            header: names.into_iter().collect(),
            assigned: HashSet::new(),
            pending: Vec::new(),
            boundary,
            kind,
            depth: 0,
            stmt_start: true,
            alt_syntax: false,
            terminating: false,
            terminated: false,
            chain: None,
        }
    }

    /// The statements of the frame always run in order.
    fn counts(&self) -> bool {
        !self.alt_syntax && matches!(self.kind, Kind::Block | Kind::Branch)
    }

    fn defined(&self) -> impl Iterator<Item = &String> {
        self.header.iter().chain(&self.assigned)
    }
}

struct Walk<'a> {
    toks: &'a Toks<'a>,
    frames: Vec<Frame>,
    out: HashMap<usize, HashSet<String>>,
}

impl Walk<'_> {
    fn frame(&mut self) -> &mut Frame {
        self.frames.last_mut().expect("the walk has a frame")
    }

    fn top(&self) -> &Frame {
        self.frames.last().expect("the walk has a frame")
    }

    fn kind(&self, i: usize) -> Option<TokKind> {
        self.toks.t.get(i).map(|t| t.kind)
    }

    /// The names of the frames up to the first function boundary.
    fn visible(&self) -> HashSet<String> {
        let mut names = HashSet::new();
        for f in self.frames.iter().rev() {
            names.extend(f.defined().cloned());
            if f.boundary {
                break;
            }
        }

        names
    }

    fn run(&mut self) {
        let mut i = 0;
        while i < self.toks.t.len() {
            i = self.token(i);
        }
    }

    /// Processes the token `i`, and returns the index of the next token.
    fn token(&mut self, i: usize) -> usize {
        self.end_arrows(i);
        self.end_chain(i);

        let text = self.toks.text(i);
        let kind = self.toks.t[i].kind;
        let stmt_start = self.top().stmt_start && self.top().depth == 0;

        if let TokKind::Markup(_) = kind {
            let names = self.visible();
            self.out.insert(self.toks.t[i].span.start, names);
        }

        if kind == TokKind::Ident {
            if text.eq_ignore_ascii_case("fn") && self.toks.is_op(i + 1, "(") && !self.is_member(i) {
                return self.arrow(i);
            }

            if text.eq_ignore_ascii_case("unset") && self.toks.is_op(i + 1, "(") {
                self.unset(i + 1);
            }

            if stmt_start && self.toks.is_op(i + 1, ":") && !text.eq_ignore_ascii_case("default") && !text.eq_ignore_ascii_case("else") {
                self.label();
                return i + 2;
            }

            if stmt_start && TERMINATORS.iter().any(|w| text.eq_ignore_ascii_case(w)) {
                self.frame().terminating = true;
            }

            if stmt_start && text.eq_ignore_ascii_case("list") && self.toks.is_op(i + 1, "(") {
                self.destructuring(i + 1);
            }
        }

        if kind == TokKind::Var && stmt_start && self.kind(i + 1) == Some(TokKind::Op) && ASSIGN_OPS.contains(&self.toks.text(i + 1)) {
            let name = text[1..].to_string();
            self.frame().pending.push(name);
        }

        if kind == TokKind::AttrOpen {
            self.frame().depth += 1;
        }

        if kind == TokKind::Op {
            match text {
                "[" if stmt_start => {
                    self.destructuring(i);
                    self.frame().depth += 1;
                }
                "(" | "[" => self.frame().depth += 1,
                ")" | "]" => {
                    let f = self.frame();
                    f.depth = f.depth.saturating_sub(1);
                }
                ":" if self.starts_alt_syntax(i) => self.frame().alt_syntax = true,
                "{" => {
                    self.open(i);
                    return i + 1;
                }
                "}" => {
                    self.close();
                    return i + 1;
                }
                _ => {}
            }
        }

        // The `;` of a statement: its assignments count from the next statement.
        let f = self.frame();
        let ends = kind == TokKind::Op && text == ";" && f.depth == 0;
        if ends {
            let pending = std::mem::take(&mut f.pending);
            if f.counts() {
                f.assigned.extend(pending);
            }

            f.terminated |= f.terminating;
            f.terminating = false;
        }

        f.stmt_start = ends;

        i + 1
    }

    /// The identifier at `i` is a member name: `$a->fn(...)`, `A::fn(...)`.
    fn is_member(&self, i: usize) -> bool {
        i.checked_sub(1).is_some_and(|p| self.toks.is_op(p, "->") || self.toks.is_op(p, "?->") || self.toks.is_op(p, "::"))
    }

    /// `[$a, $b] = ...` or `list($a, $b) = ...` from the bracket at `open`: the names of the
    /// statement.
    fn destructuring(&mut self, open: usize) {
        let close = self.toks.matching(open);
        if !self.toks.is_op(close + 1, "=") {
            return;
        }

        // A name that is a whole item: not `$a[$i]` or `$a->b`, where `$i` and `$a` are read.
        let names: Vec<String> = (open + 1..close)
            .filter(|&j| self.kind(j) == Some(TokKind::Var))
            .filter(|&j| matches!(self.toks.text(j + 1), "," | "]" | ")") && matches!(self.toks.text(j - 1), "[" | "(" | "," | "=>" | "&"))
            .map(|j| self.toks.text(j)[1..].to_string())
            .collect();
        self.frame().pending.extend(names);
    }

    /// `unset(...)` from the bracket at `open`: its names are not defined.
    fn unset(&mut self, open: usize) {
        let close = self.toks.matching(open);
        let names = self.vars(open, close);
        for f in &mut self.frames {
            f.header.retain(|n| !names.contains(n));
            f.assigned.retain(|n| !names.contains(n));
        }
    }

    /// A `goto` target: the code can come from another point of the function, so the
    /// assignments before the label do not count.
    fn label(&mut self) {
        for f in self.frames.iter_mut().rev() {
            f.assigned.clear();
            f.pending.clear();
            f.chain = None;
            if f.boundary {
                break;
            }
        }

        self.frame().stmt_start = true;
    }

    /// The `:` at `i` opens an alternative syntax block: `if (...):`, `else:`.
    fn starts_alt_syntax(&self, i: usize) -> bool {
        let Some(prev) = i.checked_sub(1) else { return false };

        if self.toks.is_word(prev, "else") {
            return true;
        }

        self.toks.is_op(prev, ")")
            && self.toks.matching_back(prev).and_then(|open| open.checked_sub(1)).is_some_and(|kw| ALT_SYNTAX.iter().any(|w| self.toks.is_word(kw, w)))
    }

    /// `fn (params) => body`: a frame with the parameters, until the end of the body.
    fn arrow(&mut self, i: usize) -> usize {
        let close = self.toks.matching(i + 1);
        let names = self.vars(i + 1, close);

        let mut j = close + 1;
        while j < self.toks.t.len() && !self.toks.is_op(j, "=>") {
            j += 1;
        }

        self.frame().stmt_start = false;
        self.frames.push(Frame::new(names, false, Kind::Arrow));

        j + 1
    }

    /// Ends the arrow functions that the token `i` closes.
    fn end_arrows(&mut self, i: usize) {
        while let Some(f) = self.frames.last() {
            if f.kind != Kind::Arrow || f.depth != 0 {
                break;
            }

            if !matches!(self.toks.text(i), "," | ";" | ")" | "]" | "}") {
                break;
            }

            self.frames.pop();
        }
    }

    /// After the closing brace of a branch: the chain goes on with `elseif`, `catch` or
    /// `else {`, or it ends. When one of its branches always runs, the names of all its branches
    /// are defined after it.
    fn end_chain(&mut self, i: usize) {
        let is_else = self.toks.is_word(i, "else");
        let continues = self.toks.is_word(i, "elseif") || self.toks.is_word(i, "catch") || (is_else && self.toks.is_op(i + 1, "{"));
        let f = self.frames.last_mut().expect("the walk has a frame");
        let Some(chain) = f.chain.as_mut().filter(|c| c.waiting) else { return };

        if continues {
            chain.waiting = false;
            return;
        }

        // `else if`, or an `else` with no braces: the chain ends at the next token.
        if is_else {
            chain.valid = false;
            return;
        }

        let chain = f.chain.take().expect("the chain exists");
        if chain.valid && chain.exhaustive && f.counts() {
            f.assigned.extend(chain.common.unwrap_or_default());
        }
    }

    fn open(&mut self, i: usize) {
        let (names, boundary, kind, branch) = self.header(i);
        let f = self.frame();

        match branch {
            Some(Branch::First { exhaustive, starts }) => {
                let valid = starts && f.depth == 0 && f.counts();
                f.chain = Some(Chain { common: None, exhaustive, valid, waiting: false });
            }
            Some(Branch::Else) => {
                if let Some(c) = &mut f.chain {
                    c.exhaustive = true;
                }
            }
            Some(Branch::Next) | None => {}
        }

        self.frames.push(Frame::new(names, boundary, kind));
    }

    fn close(&mut self) {
        // The outermost frame is the walk: the closing brace of the function pops the function.
        if self.frames.len() == 1 {
            return;
        }

        let done = self.frames.pop().expect("the walk has a frame");
        let f = self.frame();
        f.stmt_start = f.depth == 0;

        if done.kind != Kind::Branch {
            return;
        }

        let Some(c) = &mut f.chain else { return };

        c.waiting = true;

        // A branch that ends with `return` does not go on after the chain.
        if !done.terminated {
            let names: HashSet<String> = done.defined().cloned().collect();
            c.common = Some(match c.common.take() {
                Some(common) => common.intersection(&names).cloned().collect(),
                None => names,
            });
        }
    }

    /// The `:` of the return type that ends at `j`: `function (): ?Node {`,
    /// `function (): (A&B)|null {`.
    fn return_type(&self, j: usize) -> Option<usize> {
        let mut k = j;
        loop {
            if self.kind(k) == Some(TokKind::Ident) || self.toks.is_op(k, "?") || self.toks.is_op(k, "|") || self.toks.is_op(k, "&") {
                k = k.checked_sub(1)?;
            } else if self.toks.is_op(k, ")") {
                // A group of a DNF type: `(A&B)` after `:` or `|`.
                let before = self.toks.matching_back(k)?.checked_sub(1)?;
                if !(self.toks.is_op(before, ":") || self.toks.is_op(before, "|")) {
                    return None;
                }

                k = before;
            } else {
                break;
            }
        }

        (k < j && self.toks.is_op(k, ":")).then_some(k)
    }

    /// The `(` of the header before the `{` at `i`, after an optional return type.
    fn header_paren(&self, i: usize) -> Option<usize> {
        let mut j = i.checked_sub(1)?;
        if let Some(colon) = self.return_type(j) {
            j = colon.checked_sub(1)?;
        }

        if !self.toks.is_op(j, ")") {
            return None;
        }

        self.toks.matching_back(j)
    }

    /// The keyword at `kw` starts a statement: the token before it ends one, or there is none.
    fn starts_statement(&self, kw: usize) -> bool {
        kw.checked_sub(1).is_none_or(|before| matches!(self.toks.text(before), ";" | "{" | "}"))
    }

    /// The token `kw` is the name of a function: `function name(`, `function &name(`.
    fn is_function_name(&self, kw: usize) -> bool {
        let before = |n: usize| kw.checked_sub(n).is_some_and(|p| self.toks.is_word(p, "function"));

        before(1) || (kw.checked_sub(1).is_some_and(|p| self.toks.is_op(p, "&")) && before(2))
    }

    /// The names, the boundary, the kind and the branch of the frame that the `{` at `i` opens.
    fn header(&self, i: usize) -> (Vec<String>, bool, Kind, Option<Branch>) {
        let prev = i.wrapping_sub(1);
        if self.toks.is_word(prev, "else") {
            return (Vec::new(), false, Kind::Branch, Some(Branch::Else));
        }

        if self.toks.is_word(prev, "try") {
            return (Vec::new(), false, Kind::Branch, Some(Branch::First { exhaustive: true, starts: self.starts_statement(prev) }));
        }

        let Some(open) = self.header_paren(i) else { return (Vec::new(), false, Kind::Block, None) };
        let Some(kw) = open.checked_sub(1) else { return (Vec::new(), true, Kind::Block, None) };

        let close = self.toks.matching(open);

        if self.toks.is_word(kw, "use") && kw.checked_sub(1).is_some_and(|p| self.toks.is_op(p, ")")) {
            let params = self.toks.matching_back(kw - 1).map(|o| self.vars(o, kw - 1)).unwrap_or_default();
            return ([params, self.vars(open, close)].concat(), true, Kind::Block, None);
        }

        if self.toks.is_word(kw, "function") || self.is_function_name(kw) {
            return (self.vars(open, close), true, Kind::Block, None);
        }

        if self.toks.is_word(kw, "if") {
            return (Vec::new(), false, Kind::Branch, Some(Branch::First { exhaustive: false, starts: self.starts_statement(kw) }));
        }

        if self.toks.is_word(kw, "elseif") {
            return (Vec::new(), false, Kind::Branch, Some(Branch::Next));
        }

        if self.toks.is_word(kw, "catch") {
            return (self.vars(open, close), false, Kind::Branch, Some(Branch::Next));
        }

        if self.toks.is_word(kw, "switch") {
            return (Vec::new(), false, Kind::Switch, None);
        }

        if self.toks.is_word(kw, "foreach") {
            let from = (open..close).find(|&j| self.toks.is_word(j, "as")).unwrap_or(close);
            return (self.vars(from, close), false, Kind::Block, None);
        }

        if self.toks.is_word(kw, "for") {
            let init_end = (open..close).find(|&j| self.toks.is_op(j, ";")).unwrap_or(close);
            let names = (open + 1..init_end).filter(|&j| self.kind(j) == Some(TokKind::Var) && self.toks.is_op(j + 1, "=")).map(|j| self.toks.text(j)[1..].to_string()).collect();
            return (names, false, Kind::Block, None);
        }

        // An unknown header hides the names of the outer frames.
        let known = BLOCK_HEADERS.iter().any(|w| self.toks.is_word(kw, w));

        (Vec::new(), !known, Kind::Block, None)
    }

    /// The names of the variables between the tokens `from` and `to`.
    fn vars(&self, from: usize, to: usize) -> Vec<String> {
        (from..to.min(self.toks.t.len())).filter(|&j| self.kind(j) == Some(TokKind::Var)).map(|j| self.toks.text(j)[1..].to_string()).collect()
    }
}
