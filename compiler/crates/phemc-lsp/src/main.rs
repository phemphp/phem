//! `phem-lsp`: the language server of `.phem` files, on stdin and stdout.

fn main() -> std::io::Result<()> {
    phemc_lsp::run()
}
