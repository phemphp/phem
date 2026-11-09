//! Class names in markup. A free name that starts with an uppercase letter is a class, not a
//! template variable, when the file imports it with `use`, or when a component or an enum of the
//! sources has this name in the namespace of the file:
//!
//! - `OrderStatus.Cancelled` → `phem_constant('App\\OrderStatus::Cancelled')` (a case or a constant)
//! - `OrderStatus.cases()` → `enum_cases('App\\OrderStatus')`
//! - `path(ProductPage, {...})` → `phem_path('App\\Ui\\ProductPage', {...})`, and `url()` → `phem_url()`
//!
//! E062: an enum of the sources has no such case or constant, or `cases()` of a class of the
//! sources that is not an enum. The runtime checks the other classes when Twig compiles the
//! template. E063: the class of `path()` / `url()` is not a page with a route (an error page has
//! none). E064: a class name in another
//! place.

use phemc_analyze::{ComponentDef, Enums, FileInfo, Kind, Registry};
use phemc_syntax::diag::codes;
use phemc_syntax::{Diagnostic, Span};
use phemc_twig_scan::{class_names, ClassUse};

pub(crate) struct ClassNames<'a> {
    info: &'a FileInfo,
    reg: &'a Registry,
    enums: &'a Enums,
}

impl<'a> ClassNames<'a> {
    pub(crate) fn new(info: &'a FileInfo, reg: &'a Registry, enums: &'a Enums) -> Self {
        Self { info, reg, enums }
    }

    /// Whether a name of the markup is a class.
    pub(crate) fn is_class(&self, name: &str) -> bool {
        self.class(name).is_some() || self.function(name).is_some()
    }

    /// The FQCN of a class name: a `use` import, or a component or an enum of the sources in
    /// the namespace of the file.
    fn class(&self, name: &str) -> Option<String> {
        if !name.starts_with(|c: char| c.is_ascii_uppercase()) {
            return None;
        }

        if let Some(fqcn) = self.info.class_uses.get(&name.to_ascii_lowercase()) {
            return Some(fqcn.clone());
        }

        let fqcn = self.info.resolve_class(name);
        let known = self.enums.contains_key(&fqcn.to_ascii_lowercase()) || self.component(&fqcn).is_some_and(|d| d.kind == Kind::Class);

        known.then_some(fqcn)
    }

    /// The FQN of a function name: a `use function` import, or a function component of the
    /// sources in the namespace of the file.
    fn function(&self, name: &str) -> Option<String> {
        if !name.starts_with(|c: char| c.is_ascii_uppercase()) {
            return None;
        }

        if let Some(fqn) = self.info.function_uses.get(&name.to_ascii_lowercase()) {
            return Some(fqn.clone());
        }

        let fqn = self.info.resolve_class(name);

        self.component(&fqn).is_some_and(|d| d.kind != Kind::Class).then_some(fqn)
    }

    /// The help of E030 for a capitalized name that the file does not import: the `use` of a
    /// component or an enum of the sources with this short name.
    pub(crate) fn import_help(&self, name: &str) -> Option<String> {
        if !name.starts_with(|c: char| c.is_ascii_uppercase()) {
            return None;
        }

        let short = |fqcn: &str| fqcn.rsplit('\\').next().is_some_and(|s| s == name);
        let fqcn = self.reg.keys().find(|k| short(k)).or_else(|| self.enums.values().map(|e| &e.fqn).find(|k| short(k)))?;

        Some(format!("import the class to use it in markup: `use {fqcn};`"))
    }

    /// The Twig code with the class names replaced. `base` is the offset of `code` in the source.
    /// `bound` has the names that Twig binds at this point (`{% for Item in %}`): they are not
    /// classes.
    pub(crate) fn rewrite(&self, code: &str, base: usize, bound: &[String], diags: &mut Vec<Diagnostic>) -> String {
        if !code.bytes().any(|b| b.is_ascii_uppercase()) {
            return code.to_string();
        }

        let mut out = String::new();
        let mut at = 0;
        for c in class_names(code) {
            if !self.is_class(&c.name) || bound.contains(&c.name) || c.start < at {
                continue;
            }

            let span = Span::new(base + c.offset, base + c.offset + c.name.len());
            out.push_str(&code[at..c.start]);
            out.push_str(&self.lower(&c.name, &c.kind, span, base, diags).unwrap_or_else(|| "null".into()));
            at = c.end;
        }

        out.push_str(&code[at..]);

        out
    }

    fn lower(&self, name: &str, kind: &ClassUse, span: Span, base: usize, diags: &mut Vec<Diagnostic>) -> Option<String> {
        let class = self.class(name);
        let class = class.as_ref();

        match kind {
            ClassUse::Member { member, offset, call: false } => {
                let Some(fqcn) = class else { return self.misplaced(name, span, diags) };

                // A case is in the enum itself. A constant can come from an interface or a trait.
                if let Some(e) = self.enums.get(&fqcn.to_ascii_lowercase()) {
                    if !e.cases.contains(member) && !e.constants.contains(member) && !e.open {
                        let span = Span::new(base + offset, base + offset + member.len());
                        diags.push(
                            Diagnostic::error(codes::UNKNOWN_CLASS_MEMBER, format!("`{name}` has no case `{member}`"), span)
                                .with_label("not a case or a constant")
                                .with_help(format!("its cases are: {}", e.cases.join(", "))),
                        );
                        return None;
                    }
                }

                Some(format!("phem_constant({})", twig_str(&format!("{fqcn}::{member}"))))
            }
            ClassUse::Member { member, call: true, .. } if member == "cases" => {
                let Some(fqcn) = class else { return self.misplaced(name, span, diags) };

                if self.enums.get(&fqcn.to_ascii_lowercase()).is_none() && self.component(fqcn).is_some() {
                    diags.push(Diagnostic::error(codes::UNKNOWN_CLASS_MEMBER, format!("`{name}` is not an enum, so it has no `cases()`"), span).with_label("a component"));
                    return None;
                }

                Some(format!("enum_cases({})", twig_str(fqcn)))
            }
            ClassUse::PageArg { callee } => {
                let function = self.function(name);
                let fqn = function.as_ref().or(class)?;
                let def = self.component(fqn);
                if !def.is_some_and(|d| d.routed) {
                    let help = match def {
                        Some(d) if d.page => "an error page has no route: link to a page, or to a route name: `path('name')`",
                        Some(_) => "add `#[Page]` to the component, or link to a route name: `path('name')`",
                        None => "import a page of the sources, or link to a route name: `path('name')`",
                    };
                    diags.push(Diagnostic::error(codes::NOT_A_PAGE, format!("`{name}` is not a page"), span).with_label("not a page").with_help(help));
                    return None;
                }

                Some(format!("phem_{callee}({}", twig_str(&def.map_or(fqn.as_str(), |d| d.fqcn.as_str()))))
            }
            _ => self.misplaced(name, span, diags),
        }
    }

    /// E064: a class name that is not a case, a constant, `cases()` or a page link.
    fn misplaced(&self, name: &str, span: Span, diags: &mut Vec<Diagnostic>) -> Option<String> {
        diags.push(
            Diagnostic::error(codes::CLASS_IN_MARKUP, format!("`{name}` is a class, not a variable"), span)
                .with_label("an imported class")
                .with_help(format!("markup reads a case or a constant (`{name}.NAME`), the cases of an enum (`{name}.cases()`), or links to a page (`path({name})`)")),
        );

        None
    }

    /// The component of a FQCN. PHP class names ignore the case.
    fn component(&self, fqcn: &str) -> Option<&'a ComponentDef> {
        self.reg.get(fqcn).or_else(|| self.reg.values().find(|d| d.fqcn.eq_ignore_ascii_case(fqcn)))
    }
}

/// A Twig string: `'App\\Ui\\Page'`.
fn twig_str(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}
