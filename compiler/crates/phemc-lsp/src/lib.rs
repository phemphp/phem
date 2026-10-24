//! The language server of `.phpm` files. It gives the diagnostics of the compiler as the user
//! types, and the completion, the definition, the references, the hover, and the outline of a
//! tolerant parser that reads incomplete files.
//!
//! `phemc lsp` runs it. The `phem-lsp` binary runs it too: the editors bundle that binary,
//! compiled to WebAssembly (`wasm32-wasip1`), and run it with Node.

mod check;
mod classes;
mod complete;
mod data;
mod decl;
mod imports;
mod project;
mod references;
mod resolve;
mod scanner;
mod scope;
mod server;
mod symbols;
mod twig;
mod types;

#[cfg(test)]
mod tests;

pub use server::run;
