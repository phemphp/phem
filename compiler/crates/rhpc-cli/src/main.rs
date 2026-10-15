//! `rhpc` — the `.rhp` compiler.
//!
//! - `rhpc build <src>`: compiles to PHP + Twig, writes the manifest, and warms up the Twig cache.
//! - `rhpc check <src>`: reports diagnostics only.
//! - `rhpc watch <src> --exec <cmd>`: rebuilds on change and restarts `<cmd>`.

use anyhow::{bail, Context, Result};
use clap::{Args, Parser, Subcommand};
use rhpc_codegen::{FileDiagnostics, ProjectOutput, SourceInput};
use rhpc_syntax::Severity;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitCode};
use std::sync::mpsc;
use std::time::{Duration, Instant};

#[derive(Parser)]
#[command(name = "rhpc", version, about = "Compiles .rhp files (PHP + Twig markup) to PHP and Twig")]
struct Cli {
    #[command(subcommand)]
    cmd: Cmd,
}

#[derive(Subcommand)]
enum Cmd {
    /// Compile a source directory.
    Build(BuildArgs),
    /// Report diagnostics, write nothing.
    Check { src: PathBuf },
    /// Build, run a command, and rebuild + restart it when a source file changes.
    Watch {
        #[command(flatten)]
        build: BuildArgs,
        /// The command to run (and restart) after each successful build.
        #[arg(long)]
        exec: Option<String>,
    },
}

#[derive(Args, Clone)]
struct BuildArgs {
    /// The source directory (it contains `pages/`).
    src: PathBuf,
    /// The output directory.
    #[arg(long, default_value = "var/cache/rhp")]
    out: PathBuf,
    /// Production build (the runtime disables Twig auto-reload).
    #[arg(long)]
    prod: bool,
    /// The PHP binary for the Twig warmup.
    #[arg(long, default_value = "php")]
    php: String,
    /// Do not run `vendor/bin/rephp warmup` after the build.
    #[arg(long)]
    no_warmup: bool,
}

fn main() -> ExitCode {
    let cli = Cli::parse();
    let result = match cli.cmd {
        Cmd::Build(args) => build(&args),
        Cmd::Check { src } => check(&src),
        Cmd::Watch { build, exec } => watch(&build, exec.as_deref()).map(|_| true),
    };
    match result {
        Ok(true) => ExitCode::SUCCESS,
        Ok(false) => ExitCode::FAILURE,
        Err(e) => {
            eprintln!("rhpc: {e:#}");
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
        if entry.file_type().is_file() && entry.path().extension().is_some_and(|e| e == "rhp") {
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
    let (out, diags) = rhpc_codegen::compile(inputs);
    let (errors, warnings) = report(src, &diags);
    Ok((out, count, errors, warnings))
}

fn check(src: &Path) -> Result<bool> {
    let (_, count, errors, warnings) = compile(src)?;
    eprintln!("rhpc: checked {count} file(s): {errors} error(s), {warnings} warning(s)");
    Ok(errors == 0)
}

fn build(args: &BuildArgs) -> Result<bool> {
    let started = Instant::now();
    let (out, count, errors, warnings) = compile(&args.src)?;
    let Some(project) = out else {
        eprintln!("rhpc: build failed: {errors} error(s), {warnings} warning(s)");
        return Ok(false);
    };
    let written = write_output(args, &project)?;
    eprintln!(
        "rhpc: compiled {count} file(s), {} template(s), {} route(s); {written} file(s) changed, {warnings} warning(s) in {} ms",
        project.manifest.templates.len(),
        project.manifest.routes.len(),
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
    let mut m = manifest.to_php();
    if args.prod {
        m = m.replacen("return [", "return ['prod' => true,", 1);
    }
    written += write_if_changed(&out.join("manifest.php"), &m)? as usize;
    written += write_if_changed(&out.join("manifest.json"), &manifest.to_json())? as usize;

    let mut autoload = String::from("<?php\n\n// Generated by rhpc. Loads the compiled components.\n\n");
    for f in &manifest.files {
        autoload.push_str(&format!("require_once __DIR__.'/{f}';\n"));
    }
    written += write_if_changed(&out.join("autoload.php"), &autoload)? as usize;

    let abs_out = std::path::absolute(out)?;
    let vendor = std::path::absolute("vendor/autoload.php")?;
    let preload = format!(
        "<?php\n\n// Generated by rhpc. OPcache preload: the runtime, the compiled components, and the Twig cache.\n\n\
         require_once __DIR__.'/{}';\nrequire_once __DIR__.'/autoload.php';\n\\Rephp\\Runtime::preload(__DIR__);\n",
        relative(&abs_out, &vendor)
    );
    written += write_if_changed(&out.join("preload.php"), &preload)? as usize;
    Ok(written)
}

fn warmup(args: &BuildArgs) -> Result<()> {
    let bin = Path::new("vendor/bin/rephp");
    if !bin.exists() {
        eprintln!("rhpc: skipped the Twig warmup (no vendor/bin/rephp; run `composer install`)");
        return Ok(());
    }
    let status = Command::new(&args.php)
        .arg(bin)
        .arg("warmup")
        .arg(format!("--cache={}", args.out.display()))
        .status()
        .with_context(|| format!("cannot run `{}`", args.php))?;
    if !status.success() {
        bail!("the Twig warmup failed");
    }
    Ok(())
}

fn spawn(cmd: &str) -> Result<Child> {
    eprintln!("rhpc: $ {cmd}");
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
    eprintln!("rhpc: watching {} for changes", args.src.display());

    loop {
        let event = rx.recv()?;
        let relevant = |e: &notify::Result<notify::Event>| {
            e.as_ref().is_ok_and(|e| !e.kind.is_access() && e.paths.iter().any(|p| p.extension().is_some_and(|x| x == "rhp")))
        };
        if !relevant(&event) {
            continue;
        }
        // Debounce: editors write files in several steps.
        while rx.recv_timeout(Duration::from_millis(150)).is_ok() {}
        eprintln!("rhpc: change detected, rebuilding");
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
            Ok(false) => eprintln!("rhpc: the previous build keeps running"),
            Err(e) => eprintln!("rhpc: {e:#}"),
        }
    }
}
