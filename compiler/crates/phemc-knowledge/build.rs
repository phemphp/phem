//! Embeds the docs of phem (`docs/*.md` of the repository) in the binary, so `phemc docs` shows
//! the docs of the installed version. The docs for the maintainers are not embedded.

use std::path::Path;

/// The docs for the maintainers of phem, not for the users.
const SKIPPED: &[&str] = &["contributing", "releasing"];

fn main() {
    let docs = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../../docs");
    println!("cargo:rerun-if-changed={}", docs.display());

    let mut files: Vec<_> = std::fs::read_dir(&docs)
        .unwrap_or_else(|e| panic!("cannot read {}: {e}", docs.display()))
        .filter_map(|e| e.ok().map(|e| e.path()))
        .filter(|p| p.extension().is_some_and(|x| x == "md"))
        .filter(|p| p.file_stem().and_then(|s| s.to_str()).is_some_and(|s| !SKIPPED.contains(&s)))
        .collect();
    files.sort();

    let mut out = String::from("/// The docs of phem: (slug, Markdown), by slug.\npub static DOCS: &[(&str, &str)] = &[\n");
    for file in &files {
        println!("cargo:rerun-if-changed={}", file.display());
        let slug = file.file_stem().unwrap().to_string_lossy();
        let path = std::fs::canonicalize(file).unwrap();
        out.push_str(&format!("    ({slug:?}, include_str!({:?})),\n", path.display().to_string()));
    }

    out.push_str("];\n");

    let dest = Path::new(&std::env::var("OUT_DIR").unwrap()).join("docs.rs");
    std::fs::write(dest, out).unwrap();
}
