//! `mamuc` — the `.phpm` compiler.
//!
//! - `mamuc build <src>`: compiles to PHP + Twig, writes the manifest, and warms up the Twig cache
//!   (`bin/console mamu:warmup` of the Symfony application).
//! - `mamuc check <src>`: reports diagnostics only. `--format json` prints them as JSON (for editors).
//! - `mamuc watch <src> --exec <cmd>`: rebuilds on change and restarts `<cmd>`.

use anyhow::{bail, Context, Result};
use clap::{Args, Parser, Subcommand, ValueEnum};
use mamuc_codegen::{FileDiagnostics, ProjectOutput, SourceInput};
use mamuc_syntax::Severity;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitCode};
use std::sync::mpsc;
use std::time::{Duration, Instant};

#[derive(Parser)]
#[command(name = "mamuc", version, about = "Compiles .phpm files (PHP + Twig markup) to PHP and Twig")]
struct Cli {
    #[command(subcommand)]
    cmd: Cmd,
}

#[derive(Subcommand)]
enum Cmd {
    /// Compile a source directory.
    Build(BuildArgs),
    /// Report diagnostics, write nothing.
    Check {
        src: PathBuf,
        /// The output format: `text` (on stderr) or `json` (on stdout, for editors).
        #[arg(long, value_enum, default_value_t = Format::Text)]
        format: Format,
    },
    /// Build, run a command, and rebuild + restart it when a source file changes.
    Watch {
        #[command(flatten)]
        build: BuildArgs,
        /// The command to run (and restart) after each successful build.
        #[arg(long)]
        exec: Option<String>,
    },
}

#[derive(Clone, Copy, ValueEnum)]
enum Format {
    Text,
    Json,
}

#[derive(Args, Clone)]
struct BuildArgs {
    /// The source directory.
    src: PathBuf,
    /// The output directory.
    #[arg(long, default_value = "var/cache/mamu")]
    out: PathBuf,
    /// The PHP binary for the Twig warmup.
    #[arg(long, default_value = "php")]
    php: String,
    /// Do not run `bin/console mamu:warmup` after the build.
    #[arg(long)]
    no_warmup: bool,
}

fn main() -> ExitCode {
    let cli = Cli::parse();
    let result = match cli.cmd {
        Cmd::Build(args) => build(&args),
        Cmd::Check { src, format: Format::Text } => check(&src),
        Cmd::Check { src, format: Format::Json } => check_json(&src),
        Cmd::Watch { build, exec } => watch(&build, exec.as_deref()).map(|_| true),
    };
    match result {
        Ok(true) => ExitCode::SUCCESS,
        Ok(false) => ExitCode::FAILURE,
        Err(e) => {
            eprintln!("mamuc: {e:#}");
            ExitCode::FAILURE
        }
    }
}

fn read_sources(src: &Path) -> Result<Vec<SourceInput>> {
    if !src.is_dir() {
        bail!("source directory `{}` does not exist", src.display());
    }
    let mut inputs = Vec::new();
    for entry in walkdir::WalkDir::new(src).sort_by_file_name() {
        let entry = entry?;
        if entry.file_type().is_file() && entry.path().extension().is_some_and(|e| e == "phpm") {
            let rel = entry.path().strip_prefix(src)?.to_string_lossy().replace('\\', "/");
            let text = std::fs::read_to_string(entry.path()).with_context(|| format!("cannot read {}", entry.path().display()))?;
            inputs.push(SourceInput { rel, text });
        }
    }
    Ok(inputs)
}

/// Prints the diagnostics. Returns (errors, warnings).
fn report(src: &Path, files: &[FileDiagnostics]) -> (usize, usize) {
    let (mut errors, mut warnings) = (0, 0);
    for f in files {
        for d in &f.diags {
            let severity = match d.severity {
                Severity::Error => {
                    errors += 1;
                    miette::Severity::Error
                }
                Severity::Warning => {
                    warnings += 1;
                    miette::Severity::Warning
                }
            };
            let mut md = miette::MietteDiagnostic::new(d.message.clone())
                .with_code(d.code)
                .with_severity(severity)
                .with_label(miette::LabeledSpan::new(d.label.clone(), d.span.start, d.span.len().max(1)));
            if let Some(h) = &d.help {
                md = md.with_help(h.clone());
            }
            let name = src.join(&f.rel).display().to_string();
            let report = miette::Report::new(md).with_source_code(miette::NamedSource::new(name, f.text.clone()));
            eprintln!("{report:?}");
        }
    }
    (errors, warnings)
}

fn compile(src: &Path) -> Result<(Option<ProjectOutput>, usize, usize, usize)> {
    let inputs = read_sources(src)?;
    let count = inputs.len();
    let (out, diags) = mamuc_codegen::compile(inputs);
    let (errors, warnings) = report(src, &diags);
    Ok((out, count, errors, warnings))
}

fn check(src: &Path) -> Result<bool> {
    let (_, count, errors, warnings) = compile(src)?;
    eprintln!("mamuc: checked {count} file(s): {errors} error(s), {warnings} warning(s)");
    Ok(errors == 0)
}

/// `mamuc check --format json`: prints the diagnostics to stdout. The exit code is the same as
/// with `--format text`.
fn check_json(src: &Path) -> Result<bool> {
    let (_, diags) = mamuc_codegen::compile(read_sources(src)?);
    println!("{}", diagnostics_json(&diags));
    Ok(!diags.iter().flat_map(|f| &f.diags).any(|d| d.is_error()))
}

/// The diagnostics as JSON:
/// `{"files": [{"file": "Pages/index.phpm", "diagnostics": [{"severity", "code", "message", "label",
/// "help", "start": {"line", "character"}, "end": {...}}]}]}`.
/// The file is relative to the source directory. The lines start at 0, and the characters count
/// UTF-16 code units, as the Language Server Protocol and VS Code do.
fn diagnostics_json(files: &[FileDiagnostics]) -> serde_json::Value {
    let files: Vec<_> = files
        .iter()
        .filter(|f| !f.diags.is_empty())
        .map(|f| {
            let diags: Vec<_> = f
                .diags
                .iter()
                .map(|d| {
                    serde_json::json!({
                        "severity": if d.is_error() { "error" } else { "warning" },
                        "code": d.code,
                        "message": d.message,
                        "label": d.label,
                        "help": d.help,
                        "start": position(&f.text, d.span.start),
                        "end": position(&f.text, end_of(&f.text, d.span.start, d.span.end)),
                    })
                })
                .collect();
            serde_json::json!({ "file": f.rel, "diagnostics": diags })
        })
        .collect();
    serde_json::json!({ "files": files })
}

/// The end of a span. An empty span gets the next character, as in the text format.
fn end_of(text: &str, start: usize, end: usize) -> usize {
    if end > start {
        return end;
    }
    text.get(start..).and_then(|rest| rest.chars().next()).map_or(start, |c| start + c.len_utf8())
}

/// The 0-based line and the UTF-16 column of a byte offset. Editors do not keep the byte order
/// mark in the text, so it does not count.
fn position(text: &str, offset: usize) -> serde_json::Value {
    let mut offset = offset.min(text.len());
    while !text.is_char_boundary(offset) {
        offset -= 1;
    }
    let before = &text[..offset];
    let line_start = before.rfind('\n').map_or(0, |i| i + 1);
    let line = &before[line_start..];
    serde_json::json!({
        "line": before.matches('\n').count(),
        "character": line.strip_prefix('\u{feff}').unwrap_or(line).encode_utf16().count(),
    })
}

fn build(args: &BuildArgs) -> Result<bool> {
    let started = Instant::now();
    let (out, count, errors, warnings) = compile(&args.src)?;
    let Some(project) = out else {
        eprintln!("mamuc: build failed: {errors} error(s), {warnings} warning(s)");
        return Ok(false);
    };
    let written = write_output(args, &project)?;
    eprintln!(
        "mamuc: compiled {count} file(s), {} template(s), {} page(s); {written} file(s) changed, {warnings} warning(s) in {} ms",
        project.manifest.templates.len(),
        project.manifest.pages.len(),
        started.elapsed().as_millis()
    );
    if !args.no_warmup {
        warmup(args)?;
    }
    Ok(true)
}

/// Writes a file only when its content changed (keeps mtimes for the Twig cache and OPcache).
fn write_if_changed(path: &Path, content: &str) -> Result<bool> {
    if std::fs::read(path).is_ok_and(|old| old == content.as_bytes()) {
        return Ok(false);
    }
    if let Some(dir) = path.parent() {
        std::fs::create_dir_all(dir)?;
    }
    std::fs::write(path, content).with_context(|| format!("cannot write {}", path.display()))?;
    Ok(true)
}

fn relative(from_dir: &Path, to: &Path) -> String {
    let from: Vec<_> = from_dir.components().collect();
    let to_c: Vec<_> = to.components().collect();
    let common = from.iter().zip(&to_c).take_while(|(a, b)| a == b).count();
    let mut parts: Vec<String> = std::iter::repeat_n("..".to_string(), from.len() - common).collect();
    parts.extend(to_c[common..].iter().map(|c| c.as_os_str().to_string_lossy().to_string()));
    parts.join("/")
}

fn write_output(args: &BuildArgs, project: &ProjectOutput) -> Result<usize> {
    let out = &args.out;
    std::fs::create_dir_all(out)?;
    let mut written = 0;
    let mut keep = std::collections::HashSet::new();

    for f in &project.files {
        let p = out.join(&f.php_path);
        written += write_if_changed(&p, &f.php)? as usize;
        keep.insert(p);
        for t in &f.templates {
            let p = out.join("templates").join(&t.name);
            written += write_if_changed(&p, &t.content)? as usize;
            keep.insert(p);
        }
    }
    // Remove stale outputs of deleted sources.
    for dir in ["php", "templates"] {
        for e in walkdir::WalkDir::new(out.join(dir)).into_iter().filter_map(|e| e.ok()) {
            if e.file_type().is_file() && !keep.contains(e.path()) {
                std::fs::remove_file(e.path())?;
                written += 1;
            }
        }
    }

    let manifest = &project.manifest;
    written += write_if_changed(&out.join("manifest.php"), &manifest.to_php())? as usize;
    written += write_if_changed(&out.join("manifest.json"), &manifest.to_json())? as usize;

    let mut autoload = String::from("<?php\n\n// Generated by mamuc. Loads the compiled components.\n\n");
    for f in &manifest.files {
        autoload.push_str(&format!("require_once __DIR__.'/{f}';\n"));
    }
    written += write_if_changed(&out.join("autoload.php"), &autoload)? as usize;

    let abs_out = std::path::absolute(out)?;
    let vendor = std::path::absolute("vendor/autoload.php")?;
    let preload = format!(
        "<?php\n\n// Generated by mamuc. OPcache preload: the Composer autoloader and the compiled components.\n\n\
         require_once __DIR__.'/{}';\nrequire_once __DIR__.'/autoload.php';\n",
        relative(&abs_out, &vendor)
    );
    written += write_if_changed(&out.join("preload.php"), &preload)? as usize;
    Ok(written)
}

fn warmup(args: &BuildArgs) -> Result<()> {
    let bin = Path::new("bin/console");
    if !bin.exists() || !Path::new("vendor/autoload.php").exists() {
        eprintln!("mamuc: skipped the Twig warmup (no bin/console or vendor/; run `composer install`)");
        return Ok(());
    }
    let status = Command::new(&args.php)
        .arg(bin)
        .arg("mamu:warmup")
        .status()
        .with_context(|| format!("cannot run `{}`", args.php))?;
    if !status.success() {
        bail!("the Twig warmup failed");
    }
    Ok(())
}

fn spawn(cmd: &str) -> Result<Child> {
    eprintln!("mamuc: $ {cmd}");
    Command::new("sh").arg("-c").arg(format!("exec {cmd}")).spawn().context("cannot start the command")
}

fn watch(args: &BuildArgs, exec: Option<&str>) -> Result<()> {
    use notify::{RecursiveMode, Watcher};

    let mut child = None;
    if build(args)? {
        if let Some(cmd) = exec {
            child = Some(spawn(cmd)?);
        }
    }
    let (tx, rx) = mpsc::channel();
    let mut watcher = notify::recommended_watcher(tx)?;
    watcher.watch(&args.src, RecursiveMode::Recursive)?;
    eprintln!("mamuc: watching {} for changes", args.src.display());

    loop {
        let event = rx.recv()?;
        let relevant = |e: &notify::Result<notify::Event>| {
            e.as_ref().is_ok_and(|e| !e.kind.is_access() && e.paths.iter().any(|p| p.extension().is_some_and(|x| x == "phpm")))
        };
        if !relevant(&event) {
            continue;
        }
        // Debounce: editors write files in several steps.
        while rx.recv_timeout(Duration::from_millis(150)).is_ok() {}
        eprintln!("mamuc: change detected, rebuilding");
        match build(args) {
            Ok(true) => {
                if let Some(cmd) = exec {
                    if let Some(mut c) = child.take() {
                        let _ = c.kill();
                        let _ = c.wait();
                    }
                    child = Some(spawn(cmd)?);
                }
            }
            Ok(false) => eprintln!("mamuc: the previous build keeps running"),
            Err(e) => eprintln!("mamuc: {e:#}"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use mamuc_syntax::{Diagnostic, Span};
    use serde_json::json;

    #[test]
    fn position_counts_utf16_units() {
        // `♥` is 3 bytes and 1 UTF-16 unit; `😀` is 4 bytes and 2 UTF-16 units.
        let text = "a\n♥ 😀x";
        let offset = text.find('x').unwrap();
        assert_eq!(position(text, offset), json!({"line": 1, "character": 4}));
        assert_eq!(position(text, 0), json!({"line": 0, "character": 0}));
        assert_eq!(position(text, text.len() + 10), json!({"line": 1, "character": 5}));
        assert_eq!(position("\u{feff}<?phpm", 4), json!({"line": 0, "character": 1}));
    }

    #[test]
    fn an_empty_span_gets_the_next_character() {
        assert_eq!(end_of("a♥b", 1, 1), 4);
        assert_eq!(end_of("ab", 0, 2), 2);
        assert_eq!(end_of("ab", 2, 2), 2);
    }

    #[test]
    fn json_lists_the_files_with_diagnostics() {
        let text = "<?phpm\nreturn <Foo />;\n".to_string();
        let start = text.find("Foo").unwrap();
        let files = vec![
            FileDiagnostics {
                rel: "Pages/index.phpm".into(),
                text: text.clone(),
                diags: vec![Diagnostic::error("E001", "Unknown component", Span::new(start, start + 3))
                    .with_label("here")
                    .with_help("Import it")],
            },
            FileDiagnostics { rel: "Pages/ok.phpm".into(), text: String::new(), diags: vec![] },
        ];
        assert_eq!(
            diagnostics_json(&files),
            json!({"files": [{"file": "Pages/index.phpm", "diagnostics": [{
                "severity": "error",
                "code": "E001",
                "message": "Unknown component",
                "label": "here",
                "help": "Import it",
                "start": {"line": 1, "character": 8},
                "end": {"line": 1, "character": 11},
            }]}]})
        );
    }
}
