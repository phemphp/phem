//! Lexer and markup parser for `.rhp` files.
//!
//! A `.rhp` file is PHP source that can contain markup literals (like JSX in JavaScript).
//! [`php::lex`] tokenizes the PHP code and hands each markup literal to [`markup`],
//! which parses it into a [`markup::Node`] tree that keeps Twig regions intact.

pub mod diag;
pub mod markup;
pub mod php;
pub mod span;

pub use diag::{Diagnostic, Severity};
pub use span::Span;
