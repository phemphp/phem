//! `phemc` — the `.phpm` compiler.
//!
//! - `phemc build <src>`: compiles to PHP + Twig, writes the manifest, and warms up the Twig cache
//!   (`bin/console phem:warmup` of the Symfony application). It compiles the `.phpm` roots of the
//!   Composer packages with the app (see `phemc_sources`). It runs from the project root.
//! - `phemc check <src>`: reports diagnostics only. `--format json` prints them as JSON (for editors).
//! - `phemc watch <src> --exec <cmd>`: rebuilds on change and restarts `<cmd>`.
//! - `phemc lsp`: runs the language server on stdin and stdout (for editors).

use anyhow::{bail, Context, Result};
use clap::{Args, Parser, Subcommand, ValueEnum};
use phemc_codegen::{FileDiagnostics, ProjectOutput, SourceInput};
use phemc_sources::SourceRoot;
use phemc_syntax::Severity;
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitCode};
use std::sync::mpsc;
use std::time::{Duration, Instant};

#[derive(Parser)]
#[command(name = "phemc", version, about = "Compiles .phpm files (PHP + Twig markup) to PHP and Twig")]
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
    /// Run the language server on stdin and stdout.
    Lsp,
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
    #[arg(long, default_value = "var/phem")]
    out: PathBuf,
    /// The PHP binary for the Twig warmup.
    #[arg(long, default_value = "php")]
    php: String,
    /// Do not run `bin/console phem:warmup` after the build.
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
        Cmd::Lsp => phemc_lsp::run().map(|_| true).map_err(Into::into),
    };

    match result {
        Ok(true) => ExitCode::SUCCESS,
        Ok(false) => ExitCode::FAILURE,
        Err(e) => {
            eprintln!("phemc: {e:#}");
            ExitCode::FAILURE
        }
    }
}

/// The source roots of the project in the current directory: the app and the packages.
fn source_roots(src: &Path) -> Result<Vec<SourceRoot>> {
    Ok(phemc_sources::roots(Path::new("."), src)?)
}

fn read_sources(roots: &[SourceRoot]) -> Result<Vec<SourceInput>> {
    Ok(phemc_sources::read(roots, &HashMap::new())?)
}

/// Prints the diagnostics that the tools show (see `FileDiagnostics::shown()`). Returns (errors,
/// warnings).
fn report(files: &[FileDiagnostics]) -> (usize, usize) {
    let (mut errors, mut warnings) = (0, 0);
    for f in files {
        for d in f.shown() {
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

            let report = miette::Report::new(md).with_source_code(miette::NamedSource::new(f.path.clone(), f.text.clone()));
            eprintln!("{report:?}");
        }
    }

    (errors, warnings)
}

struct Compiled {
    out: Option<ProjectOutput>,
    /// The `.phpm` files, and the files in "(2 package(s))" when there are packages.
    files: String,
    errors: usize,
    warnings: usize,
}

fn compile(src: &Path) -> Result<Compiled> {
    let roots = source_roots(src)?;
    let inputs = read_sources(&roots)?;
    let files = files_summary(inputs.len(), roots.iter().filter(|r| r.package.is_some()).count());

    let (out, diags) = phemc_codegen::compile(inputs);
    let (errors, warnings) = report(&diags);

    Ok(Compiled { out, files, errors, warnings })
}

/// "42 file(s)", or "42 file(s) (2 package(s))" when there are packages.
fn files_summary(files: usize, packages: usize) -> String {
    match packages {
        0 => format!("{files} file(s)"),
        n => format!("{files} file(s) ({n} package(s))"),
    }
}

fn check(src: &Path) -> Result<bool> {
    let Compiled { files, errors, warnings, .. } = compile(src)?;
    eprintln!("phemc: checked {files}: {errors} error(s), {warnings} warning(s)");
    Ok(errors == 0)
}

/// `phemc check --format json`: prints the diagnostics to stdout. The exit code is the same as
/// with `--format text`.
fn check_json(src: &Path) -> Result<bool> {
    let (_, diags) = phemc_codegen::compile(read_sources(&source_roots(src)?)?);
    println!("{}", diagnostics_json(&diags));
    Ok(!diags.iter().flat_map(|f| &f.diags).any(|d| d.is_error()))
}

/// The diagnostics as JSON:
/// `{"files": [{"file": "src/Ui/HomePage.phpm", "diagnostics": [{"severity", "code", "message", "label",
/// "help", "start": {"line", "character"}, "end": {...}}]}]}`.
/// The file is relative to the project root. The diagnostics are the ones that the tools show (see
/// `FileDiagnostics::shown()`). The lines start at 0, and the characters count UTF-16 code units,
/// as the Language Server Protocol and VS Code do.
fn diagnostics_json(files: &[FileDiagnostics]) -> serde_json::Value {
    let files: Vec<_> = files
        .iter()
        .filter(|f| f.shown().next().is_some())
        .map(|f| {
            let diags: Vec<_> = f
                .shown()
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
            serde_json::json!({ "file": f.path, "diagnostics": diags })
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
    let Compiled { out, files, errors, warnings } = compile(&args.src)?;
    let Some(project) = out else {
        eprintln!("phemc: build failed: {errors} error(s), {warnings} warning(s)");
        return Ok(false);
    };

    let written = write_output(args, &project)?;
    eprintln!(
        "phemc: compiled {files}, {} template(s), {} page(s); {written} file(s) changed, {warnings} warning(s) in {} ms",
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

    let mut autoload = String::from("<?php\n\n// Generated by phemc. Loads the compiled components.\n\n");
    for f in &manifest.files {
        autoload.push_str(&format!("require_once __DIR__.'/{f}';\n"));
    }

    written += write_if_changed(&out.join("autoload.php"), &autoload)? as usize;
    Ok(written)
}

fn warmup(args: &BuildArgs) -> Result<()> {
    let bin = Path::new("bin/console");
    if !bin.exists() || !Path::new("vendor/autoload.php").exists() {
        eprintln!("phemc: skipped the Twig warmup (no bin/console or vendor/; run `composer install`)");
        return Ok(());
    }

    let status = Command::new(&args.php)
        .arg(bin)
        .arg("phem:warmup")
        .status()
        .with_context(|| format!("cannot run `{}`", args.php))?;
    if !status.success() {
        bail!("the Twig warmup failed");
    }

    Ok(())
}

fn spawn(cmd: &str) -> Result<Child> {
    eprintln!("phemc: $ {cmd}");
    Command::new("sh").arg("-c").arg(format!("exec {cmd}")).spawn().context("cannot start the command")
}

/// The dirs that `phemc watch` watches: the source roots (recursive), and the Composer dir of the
/// vendor dir (not recursive) for a change of `installed.json` (a `composer require` or a
/// `composer update`). Composer writes a new file, so the watch is on the dir.
struct Watched {
    roots: Vec<PathBuf>,
    composer: Option<PathBuf>,
}

impl Watched {
    /// Watches the roots of the project again: it watches the new dirs and unwatches the old ones.
    /// When the roots cannot be read (a wrong package root), it keeps the dirs that it watches and
    /// adds the source dir of the app, so the app still rebuilds, and a later fix in
    /// `composer.json` or `installed.json` reads the roots again.
    fn update(&mut self, watcher: &mut impl notify::Watcher, project: &Path, src: &Path) -> Result<()> {
        use notify::RecursiveMode;

        let composer = phemc_sources::vendor_dir(project).join("composer");
        if self.composer.as_ref() != Some(&composer) && composer.is_dir() {
            watcher.watch(&composer, RecursiveMode::NonRecursive)?;
            self.composer = Some(composer);
        }

        let (dirs, mut failed): (Vec<PathBuf>, _) = match phemc_sources::roots_or_app(project, src) {
            Ok((roots, None)) => (roots.into_iter().map(|r| r.dir).collect(), None),
            Ok((app, Some(e))) => (self.roots.iter().cloned().chain(app.into_iter().map(|r| r.dir)).collect(), Some(anyhow::Error::from(e))),
            Err(e) => (self.roots.clone(), Some(anyhow::Error::from(e))),
        };

        for dir in self.roots.iter().filter(|d| !dirs.contains(d)) {
            let _ = watcher.unwatch(dir);
        }

        let mut watched: Vec<PathBuf> = self.roots.iter().filter(|d| dirs.contains(d)).cloned().collect();
        for dir in dirs.into_iter().filter(|d| !self.roots.contains(d)) {
            match watcher.watch(&dir, RecursiveMode::Recursive) {
                Ok(()) => watched.push(dir),
                Err(e) => failed = Some(anyhow::Error::from(e).context(format!("cannot watch {}", dir.display()))),
            }
        }

        self.roots = watched;

        failed.map_or(Ok(()), Err)
    }
}

/// Whether a change of the watched files needs a build, and whether `installed.json` changed.
fn change_of(event: &notify::Result<notify::Event>) -> (bool, bool) {
    let is_installed = |p: &PathBuf| p.file_name().is_some_and(|n| n == "installed.json");

    match event {
        Ok(e) if !e.kind.is_access() => {
            let installed = e.paths.iter().any(is_installed);
            (installed || e.paths.iter().any(|p| p.extension().is_some_and(|x| x == "phpm")), installed)
        }
        _ => (false, false),
    }
}

fn watch(args: &BuildArgs, exec: Option<&str>) -> Result<()> {
    let project = Path::new(".");

    // A missing source dir is an error (a typo). A wrong package root is reported, and the watch
    // waits for its fix.
    phemc_sources::roots_or_app(project, &args.src)?;

    let mut child = None;
    let rebuild = |child: &mut Option<Child>, first: bool| -> Result<()> {
        match build(args) {
            Ok(true) => {
                if let Some(cmd) = exec {
                    if let Some(mut c) = child.take() {
                        let _ = c.kill();
                        let _ = c.wait();
                    }

                    *child = Some(spawn(cmd)?);
                }
            }
            // The first build has no previous build.
            Ok(false) if !first => eprintln!("phemc: the previous build keeps running"),
            Ok(false) => {}
            Err(e) => eprintln!("phemc: {e:#}"),
        }

        Ok(())
    };

    rebuild(&mut child, true)?;

    let (tx, rx) = mpsc::channel();
    let mut watcher = notify::recommended_watcher(tx)?;
    let mut watched = Watched { roots: Vec::new(), composer: None };
    if let Err(e) = watched.update(&mut watcher, project, &args.src) {
        eprintln!("phemc: {e:#}");
    }

    eprintln!("phemc: watching {} source dir(s) for changes", watched.roots.len());

    loop {
        let (relevant, mut installed) = change_of(&rx.recv()?);
        if !relevant {
            continue;
        }

        // Debounce: editors write files in several steps, and Composer writes several files.
        while let Ok(e) = rx.recv_timeout(Duration::from_millis(150)) {
            installed |= change_of(&e).1;
        }

        if installed {
            eprintln!("phemc: the Composer packages changed");
            if let Err(e) = watched.update(&mut watcher, project, &args.src) {
                eprintln!("phemc: {e:#}");
            }
        }

        eprintln!("phemc: change detected, rebuilding");
        rebuild(&mut child, false)?;
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use phemc_syntax::{Diagnostic, Span};
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
                rel: "Ui/index.phpm".into(),
                path: "src/Ui/index.phpm".into(),
                package: None,
                text: text.clone(),
                diags: vec![Diagnostic::error("E001", "Unknown component", Span::new(start, start + 3))
                    .with_label("here")
                    .with_help("Import it")],
            },
            FileDiagnostics { rel: "Ui/ok.phpm".into(), path: "src/Ui/ok.phpm".into(), package: None, text: String::new(), diags: vec![] },
            // The app cannot fix a warning of a package.
            FileDiagnostics {
                rel: "vendor/acme/ui/Badge.phpm".into(),
                path: "vendor/acme/ui/components/Badge.phpm".into(),
                package: Some("acme/ui".into()),
                text: text.clone(),
                diags: vec![Diagnostic::warning("W002", "No key", Span::new(start, start + 3))],
            },
        ];

        assert_eq!(
            diagnostics_json(&files),
            json!({"files": [{"file": "src/Ui/index.phpm", "diagnostics": [{
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

    /// A watcher that keeps the watched paths.
    #[derive(Default)]
    struct FakeWatcher {
        paths: Vec<PathBuf>,
    }

    impl notify::Watcher for FakeWatcher {
        fn new<F: notify::EventHandler>(_: F, _: notify::Config) -> notify::Result<Self> {
            Ok(Self::default())
        }

        fn watch(&mut self, path: &Path, _: notify::RecursiveMode) -> notify::Result<()> {
            self.paths.push(path.to_path_buf());
            Ok(())
        }

        fn unwatch(&mut self, path: &Path) -> notify::Result<()> {
            self.paths.retain(|p| p != path);
            Ok(())
        }

        fn kind() -> notify::WatcherKind {
            notify::WatcherKind::NullWatcher
        }
    }

    #[test]
    fn watch_keeps_the_app_and_composer_when_a_package_root_is_wrong() {
        let dir = std::env::temp_dir().join(format!("phemc-cli-watch-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        let write = |file: &str, text: &str| {
            std::fs::create_dir_all(dir.join(file).parent().unwrap()).unwrap();
            std::fs::write(dir.join(file), text).unwrap();
        };

        write("src/Home.phpm", "");
        write("vendor/acme/ui/components/Badge.phpm", "");
        write("vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#);

        let mut watcher = FakeWatcher::default();
        let mut watched = Watched { roots: Vec::new(), composer: None };
        watched.update(&mut watcher, &dir, Path::new("src")).unwrap();
        assert_eq!(watcher.paths, [dir.join("vendor/composer"), dir.join("src"), dir.join("vendor/acme/ui/components")]);

        // `composer update` gives a wrong root: the app and Composer stay watched.
        write("vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "missing"}}}]}"#);
        assert!(watched.update(&mut watcher, &dir, Path::new("src")).unwrap_err().to_string().contains("missing"));
        assert!(watcher.paths.contains(&dir.join("vendor/composer")) && watcher.paths.contains(&dir.join("src")));

        // The fix removes the package: its dir is not watched any more, and the other dirs are not
        // watched two times.
        write("vendor/composer/installed.json", r#"{"packages": []}"#);
        watched.update(&mut watcher, &dir, Path::new("src")).unwrap();
        assert_eq!(watcher.paths, [dir.join("vendor/composer"), dir.join("src")]);

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[test]
    fn the_summary_gives_the_packages() {
        assert_eq!(files_summary(3, 0), "3 file(s)");
        assert_eq!(files_summary(42, 2), "42 file(s) (2 package(s))");
    }

    #[test]
    fn the_build_removes_the_output_of_a_removed_package() {
        let out = std::env::temp_dir().join(format!("phemc-cli-out-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&out);
        let args = BuildArgs { src: "src".into(), out: out.clone(), php: "php".into(), no_warmup: true };
        let home = SourceInput::app("Home.phpm", "<?phpm\nnamespace App;\nfunction Home() { return <p />; }\n");
        let badge = SourceInput { rel: "vendor/acme/ui/Badge.phpm".into(), path: "vendor/acme/ui/components/Badge.phpm".into(), package: Some("acme/ui".into()), text: "<?phpm\nnamespace Acme\\Ui;\nuse Phem\\Attribute\\Component;\n#[Component]\nfunction Badge() { return <b />; }\n".into() };

        let (project, _) = phemc_codegen::compile(vec![home.clone(), badge]);
        write_output(&args, &project.unwrap()).unwrap();
        assert!(out.join("php/vendor/acme/ui/Badge.php").is_file());
        assert!(out.join("templates/vendor/acme/ui/Badge.0.twig").is_file());

        // `composer remove acme/ui`: the next build has no file of the package.
        let (project, _) = phemc_codegen::compile(vec![home]);
        write_output(&args, &project.unwrap()).unwrap();
        assert!(!out.join("php/vendor/acme/ui/Badge.php").exists());
        assert!(!out.join("templates/vendor/acme/ui/Badge.0.twig").exists());
        assert!(!std::fs::read_to_string(out.join("autoload.php")).unwrap().contains("vendor/acme"));

        std::fs::remove_dir_all(out).unwrap();
    }

    #[test]
    fn a_change_of_a_source_or_of_installed_json_needs_a_build() {
        use notify::event::{AccessKind, EventKind, ModifyKind};

        let event = |kind: EventKind, path: &str| Ok(notify::Event::new(kind).add_path(PathBuf::from(path)));
        let modify = EventKind::Modify(ModifyKind::Any);

        assert_eq!(change_of(&event(modify, "/app/src/Home.phpm")), (true, false));
        assert_eq!(change_of(&event(modify, "/app/vendor/composer/installed.json")), (true, true));
        assert_eq!(change_of(&event(modify, "/app/src/Kernel.php")), (false, false));
        assert_eq!(change_of(&event(EventKind::Access(AccessKind::Any), "/app/src/Home.phpm")), (false, false));
        assert_eq!(change_of(&Err(notify::Error::generic("lost"))), (false, false));
    }
}
