//! What a free name of a markup reads: a PHP variable of the function, a member of the class, a
//! class, or a Twig global. The code generator and the hints use the same rules.

use crate::classes::ClassNames;
use phemc_analyze::{has_variable, Class, Prop};
use std::collections::HashSet;

/// The global variables of Twig in a Symfony application. Markup can read them without a PHP
/// variable.
const TWIG_GLOBALS: &[&str] = &["app"];

pub(crate) enum Source<'a> {
    /// A class or a function that the file imports.
    Class,
    /// A PHP variable that is always defined at the markup.
    Variable,
    /// A PHP variable that can be undefined at the markup.
    MaybeVariable,
    This,
    Property(&'a Prop),
    Method,
    TwigGlobal,
    /// A property or a method of a parent class that the compiler does not see.
    ParentMember,
    Undefined,
}

/// The source of `name` in a markup of `scope_text` (a function or a method). `defined` has the
/// variables that are always defined at the markup.
pub(crate) fn source<'a>(name: &str, strict: bool, defined: &HashSet<String>, scope_text: &str, class: Option<&'a Class>, class_names: &ClassNames) -> Source<'a> {
    if class_names.is_class(name) {
        return Source::Class;
    }

    if defined.contains(name) {
        return Source::Variable;
    }

    if has_variable(scope_text, name) {
        return Source::MaybeVariable;
    }

    let Some(c) = class else {
        return if TWIG_GLOBALS.contains(&name) { Source::TwigGlobal } else { Source::Undefined };
    };

    if name == "this" {
        Source::This
    } else if let Some(p) = c.all_props().find(|p| p.name == name && !p.is_static) {
        Source::Property(p)
    } else if c.has_method(name) {
        Source::Method
    } else if TWIG_GLOBALS.contains(&name) {
        Source::TwigGlobal
    } else if c.open && strict {
        Source::ParentMember
    } else {
        Source::Undefined
    }
}
