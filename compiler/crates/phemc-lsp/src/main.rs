//! `phem-lsp`: the language server of `.phpm` files, on stdin and stdout.

fn main() -> std::io::Result<()> {
    phemc_lsp::run()
}
