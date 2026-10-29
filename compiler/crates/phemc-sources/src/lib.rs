//! The source roots of a project: the source dir of the app, and the `.phpm` roots of the
//! Composer packages. `phemc` compiles all of them in one build.
//!
//! A package gives its root in its `composer.json`: `"extra": {"phem": {"source": "components"}}`.
//! The installed packages come from `<vendor dir>/composer/installed.json` (see [`vendor_dir`]).
//! The root package (the project itself) can give a root too: the tests of a package compile it
//! as a package.

use phemc_codegen::SourceInput;
use serde_json::Value;
use std::collections::{HashMap, HashSet};
use std::fmt;
use std::path::{Component, Path, PathBuf};

/// A directory of `.phpm` files.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct SourceRoot {
    /// The directory on disk.
    pub dir: PathBuf,
    /// The directory from the project root, with `/` separators (`src`, `vendor/acme/ui/components`).
    pub path: String,
    /// The start of the key of each file in the build: `""` for the app, `vendor/<package>/` for
    /// a package.
    pub prefix: String,
    /// The Composer package, or `None` for the app.
    pub package: Option<String>,
    /// Whether the package comes from `installed.json` (not the root package).
    pub installed: bool,
    /// The real directory of a package root that is a link (a Composer path repository), as the
    /// editor sees it: in the project, with the project dir that `roots()` got. `None` when the
    /// root is not a link.
    pub real_dir: Option<PathBuf>,
}

#[derive(Debug)]
pub struct Error(String);

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl Error {
    pub fn new(message: impl Into<String>) -> Self {
        Error(message.into())
    }
}

impl std::error::Error for Error {}

/// The roots of a project: the source dir of the app (`src`, relative to `project` or absolute),
/// then the root of the root package, then the roots of the installed packages, by name.
///
/// A missing source dir is an error, except in a package (its root `composer.json` gives
/// `extra.phem.source`): a package with no test app compiles only its own root.
pub fn roots(project: &Path, src: &Path) -> Result<Vec<SourceRoot>, Error> {
    let own = own_root(project)?;
    let mut roots = match app_root(project, src) {
        Ok(app) => vec![app],
        Err(_) if own.is_some() => vec![],
        Err(e) => return Err(e),
    };

    // The root of the package is the source dir of the app: it is the app.
    if let Some(own) = own.filter(|own| roots.first().is_none_or(|app| app.path != own.path)) {
        roots.push(own);
    }

    roots.extend(installed_roots(project)?);

    Ok(roots)
}

/// The source dir of the app only (the language server checks it when a package root is wrong).
pub fn app_root(project: &Path, src: &Path) -> Result<SourceRoot, Error> {
    let dir = project.join(src);
    if !dir.is_dir() {
        return Err(Error(format!("source directory `{}` does not exist", src.display())));
    }

    Ok(SourceRoot { path: from_root(project, &dir), dir, prefix: String::new(), package: None, installed: false, real_dir: None })
}

/// The root of the root package (the project itself), as a package.
fn own_root(project: &Path) -> Result<Option<SourceRoot>, Error> {
    let Some(composer) = read_json(&project.join("composer.json"))? else {
        return Ok(None);
    };

    let Some(source) = phem_source(&composer) else {
        return Ok(None);
    };

    let name = composer.get("name").and_then(Value::as_str).ok_or_else(|| Error("the root `composer.json` gives `extra.phem.source`, but no `name`".into()))?;

    package_root(project, project, name, source, false).map(Some)
}

/// The Composer vendor dir of a project, as Composer finds it: the `COMPOSER_VENDOR_DIR`
/// environment variable, the `config.vendor-dir` of the root `composer.json`, or `vendor`.
pub fn vendor_dir(project: &Path) -> PathBuf {
    vendor_dir_with(project, std::env::var("COMPOSER_VENDOR_DIR").ok())
}

/// The vendor dir, with the value of `COMPOSER_VENDOR_DIR` (the tests give it).
fn vendor_dir_with(project: &Path, env: Option<String>) -> PathBuf {
    let configured = env.filter(|d| !d.is_empty()).or_else(|| {
        let composer = read_json(&project.join("composer.json")).ok().flatten()?;
        composer.pointer("/config/vendor-dir").and_then(Value::as_str).map(String::from)
    });

    project.join(configured.as_deref().unwrap_or("vendor"))
}

/// The roots of the installed packages, by name.
fn installed_roots(project: &Path) -> Result<Vec<SourceRoot>, Error> {
    let composer_dir = vendor_dir(project).join("composer");
    let Some(installed) = read_json(&composer_dir.join("installed.json"))? else {
        return Ok(vec![]);
    };

    // Composer 2 has `{"packages": [...]}`, Composer 1 has the list.
    let list = installed.get("packages").unwrap_or(&installed).as_array().cloned().unwrap_or_default();
    let mut roots = Vec::new();
    for package in &list {
        let (Some(name), Some(source)) = (package.get("name").and_then(Value::as_str), phem_source(package)) else {
            continue;
        };

        // Composer 1 has no `install-path`: the package is in `vendor/<name>`.
        let install = package.get("install-path").and_then(Value::as_str).map_or_else(|| format!("../{name}"), String::from);
        roots.push(package_root(project, &composer_dir.join(install), name, source, true)?);
    }

    roots.sort_by(|a, b| a.package.cmp(&b.package));

    Ok(roots)
}

/// The `.phpm` files of the roots, in the order of the roots. An open document (the language
/// server) replaces its file on disk. The editor can open a file of a linked package at its real
/// path (see `SourceRoot::real_dir`): this document replaces the file too.
pub fn read(roots: &[SourceRoot], open: &HashMap<PathBuf, String>) -> Result<Vec<SourceInput>, Error> {
    read_with(roots, |root, file, inner| {
        if let Some(text) = open.get(file).or_else(|| open.get(&root.real_dir.as_ref()?.join(inner))) {
            return Ok(text.clone());
        }

        std::fs::read_to_string(file).map_err(|e| Error(format!("cannot read {}: {e}", file.display())))
    })
}

/// The `.phpm` files of the roots, with the text that `text_of(root, file, path in the root)`
/// gives: the language server keeps the files of the packages in memory.
///
/// A root does not read the directory of another root (a test app in the root `.` of a package),
/// nor a Composer vendor dir (with `composer/installed.json`), `node_modules` or `.git` in it. Two files with the same key in the
/// build (an app file in `src/vendor/acme/ui/`, and a file of the package `acme/ui`) are an error.
pub fn read_with(roots: &[SourceRoot], mut text_of: impl FnMut(&SourceRoot, &Path, &Path) -> Result<String, Error>) -> Result<Vec<SourceInput>, Error> {
    let dirs: Vec<PathBuf> = roots.iter().map(|r| absolute(&r.dir)).collect();
    let mut keys: HashMap<String, String> = HashMap::new();
    let mut inputs = Vec::new();

    for (i, root) in roots.iter().enumerate() {
        let others: HashSet<&PathBuf> = dirs.iter().enumerate().filter(|(j, d)| *j != i && **d != dirs[i]).map(|(_, d)| d).collect();
        let walk = walkdir::WalkDir::new(&root.dir).sort_by_file_name().into_iter().filter_entry(|e| {
            let skipped = || matches!(e.file_name().to_str(), Some("node_modules" | ".git")) || e.path().join("composer/installed.json").is_file() || others.contains(&absolute(e.path()));

            e.depth() == 0 || !e.file_type().is_dir() || !skipped()
        });

        for entry in walk {
            let entry = entry.map_err(|e| Error(e.to_string()))?;
            let file = entry.path();
            if !entry.file_type().is_file() || file.extension().is_none_or(|e| e != "phpm") {
                continue;
            }

            let inner_path = file.strip_prefix(&root.dir).map_err(|e| Error(e.to_string()))?;
            let inner = inner_path.to_string_lossy().replace('\\', "/");
            // A root at the project root has an empty path.
            let path = if root.path.is_empty() { inner.clone() } else { format!("{}/{inner}", root.path) };
            let input = SourceInput { rel: format!("{}{inner}", root.prefix), path, package: root.package.clone(), text: text_of(root, file, inner_path)? };

            if let Some(other) = keys.insert(input.rel.clone(), input.path.clone()) {
                return Err(Error(format!("the files `{other}` and `{}` have the same key `{}` in the build: rename or move one of them", input.path, input.rel)));
            }

            inputs.push(input);
        }
    }

    Ok(inputs)
}

fn phem_source(package: &Value) -> Option<&str> {
    package.pointer("/extra/phem/source").and_then(Value::as_str)
}

fn package_root(project: &Path, base: &Path, name: &str, source: &str, installed: bool) -> Result<SourceRoot, Error> {
    if Path::new(source).is_absolute() {
        return Err(Error(format!("the package `{name}` gives an absolute `extra.phem.source` (`{source}`): give a path in the package")));
    }

    let dir = normalize(&base.join(source));
    if !dir.is_dir() {
        return Err(Error(format!("the package `{name}` gives the source dir `{source}`, but `{}` does not exist", dir.display())));
    }

    let path = from_root(project, &dir);
    let real_dir = real_dir(project, &path);

    Ok(SourceRoot { path, dir, prefix: format!("vendor/{name}/"), package: Some(name.to_string()), installed, real_dir })
}

/// The real directory of a root that is a link, as the editor sees it (see `SourceRoot::real_dir`).
fn real_dir(project: &Path, path: &str) -> Option<PathBuf> {
    let real_project = std::fs::canonicalize(project).ok()?;
    let real = std::fs::canonicalize(project.join(path)).ok()?;
    if real == real_project.join(path) {
        return None;
    }

    // In the project, the path keeps the project dir of the editor (with its links, as `/var` on
    // macOS).
    Some(real.strip_prefix(&real_project).map_or_else(|_| real.clone(), |inner| project.join(inner)))
}

/// The path of a directory from the project root, with `/` separators. A directory outside the
/// project keeps its absolute path.
fn from_root(project: &Path, dir: &Path) -> String {
    let (project, dir) = (absolute(project), absolute(dir));
    let inner = dir.strip_prefix(&project).map(Path::to_path_buf).unwrap_or(dir);

    inner.to_string_lossy().replace('\\', "/")
}

/// The absolute path, with no `.` and `..` parts and no access to the disk (see [`normalize`]).
fn absolute(path: &Path) -> PathBuf {
    normalize(&std::path::absolute(path).unwrap_or_else(|_| path.to_path_buf()))
}

/// Removes the `.` and `..` parts of a path, with no access to the disk: a link of a Composer
/// path repository stays in `vendor/`.
fn normalize(path: &Path) -> PathBuf {
    let mut out = PathBuf::new();
    for part in path.components() {
        match part {
            Component::CurDir => {}
            Component::ParentDir if matches!(out.components().next_back(), Some(Component::Normal(_))) => {
                out.pop();
            }
            _ => out.push(part),
        }
    }

    out
}

fn read_json(file: &Path) -> Result<Option<Value>, Error> {
    let Ok(text) = std::fs::read_to_string(file) else {
        return Ok(None);
    };

    serde_json::from_str(&text).map(Some).map_err(|e| Error(format!("cannot read {}: {e}", file.display())))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicUsize, Ordering};

    /// A new empty project directory.
    fn project() -> PathBuf {
        static NEXT: AtomicUsize = AtomicUsize::new(0);
        let dir = std::env::temp_dir().join(format!("phemc-sources-{}-{}", std::process::id(), NEXT.fetch_add(1, Ordering::Relaxed)));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(dir.join("src")).unwrap();

        dir
    }

    fn write(dir: &Path, file: &str, text: &str) {
        let file = dir.join(file);
        std::fs::create_dir_all(file.parent().unwrap()).unwrap();
        std::fs::write(file, text).unwrap();
    }

    fn summary(roots: &[SourceRoot]) -> Vec<(String, String, Option<String>)> {
        // A root that is not a link has no real dir.
        assert!(roots.iter().all(|r| r.real_dir.is_none()));

        roots.iter().map(|r| (r.path.clone(), r.prefix.clone(), r.package.clone())).collect()
    }

    #[test]
    fn an_app_has_one_root() {
        let dir = project();
        write(&dir, "composer.json", r#"{"name": "acme/app"}"#);

        assert_eq!(summary(&roots(&dir, Path::new("src")).unwrap()), [("src".into(), String::new(), None)]);
        assert!(roots(&dir, Path::new("lib")).unwrap_err().to_string().contains("`lib` does not exist"));
    }

    #[test]
    fn the_installed_packages_with_a_source_are_roots() {
        let dir = project();
        write(&dir, "vendor/composer/installed.json", r#"{"packages": [
            {"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}},
            {"name": "acme/base", "install-path": "../../packages/base", "extra": {"phem": {"source": "src"}}},
            {"name": "symfony/yaml", "install-path": "../symfony/yaml"}
        ]}"#);
        std::fs::create_dir_all(dir.join("vendor/acme/ui/components")).unwrap();
        std::fs::create_dir_all(dir.join("packages/base/src")).unwrap();

        assert_eq!(
            summary(&roots(&dir, Path::new("src")).unwrap()),
            [
                ("src".into(), String::new(), None),
                // A path repository with no link: its directory is outside `vendor/`.
                ("packages/base/src".into(), "vendor/acme/base/".into(), Some("acme/base".into())),
                ("vendor/acme/ui/components".into(), "vendor/acme/ui/".into(), Some("acme/ui".into())),
            ]
        );
    }

    #[test]
    fn only_the_packages_of_installed_json_are_installed() {
        let dir = project();
        write(&dir, "composer.json", r#"{"name": "acme/base", "extra": {"phem": {"source": "components"}}}"#);
        write(&dir, "vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#);
        std::fs::create_dir_all(dir.join("components")).unwrap();
        std::fs::create_dir_all(dir.join("vendor/acme/ui/components")).unwrap();

        let installed: Vec<_> = roots(&dir, Path::new("src")).unwrap().iter().map(|r| (r.package.clone(), r.installed)).collect();
        assert_eq!(installed, [(None, false), (Some("acme/base".into()), false), (Some("acme/ui".into()), true)]);
    }

    #[test]
    fn composer_1_lists_the_packages() {
        let dir = project();
        write(&dir, "vendor/composer/installed.json", r#"[{"name": "acme/ui", "extra": {"phem": {"source": "components"}}}]"#);
        std::fs::create_dir_all(dir.join("vendor/acme/ui/components")).unwrap();

        assert_eq!(summary(&roots(&dir, Path::new("src")).unwrap())[1], ("vendor/acme/ui/components".into(), "vendor/acme/ui/".into(), Some("acme/ui".into())));
    }

    #[test]
    fn the_root_package_is_a_package() {
        let dir = project();
        write(&dir, "composer.json", r#"{"name": "acme/ui", "extra": {"phem": {"source": "components"}}}"#);
        std::fs::create_dir_all(dir.join("components")).unwrap();
        std::fs::create_dir_all(dir.join("tests/app/src")).unwrap();

        assert_eq!(
            summary(&roots(&dir, Path::new("tests/app/src")).unwrap()),
            [("tests/app/src".into(), String::new(), None), ("components".into(), "vendor/acme/ui/".into(), Some("acme/ui".into()))]
        );

        // The root of the package is the source dir of the app: it is the app.
        assert_eq!(summary(&roots(&dir, Path::new("components")).unwrap()), [("components".into(), String::new(), None)]);
        // A package with no test app: its root is enough. In an app, the error stays (see
        // an_app_has_one_root).
        std::fs::remove_dir(dir.join("src")).unwrap();
        assert_eq!(summary(&roots(&dir, Path::new("src")).unwrap()), [("components".into(), "vendor/acme/ui/".into(), Some("acme/ui".into()))]);
    }

    #[test]
    fn a_wrong_source_is_an_error() {
        let dir = project();
        write(&dir, "vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#);
        assert!(roots(&dir, Path::new("src")).unwrap_err().to_string().contains("the package `acme/ui` gives the source dir `components`"));

        write(&dir, "vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "extra": {"phem": {"source": "/abs"}}}]}"#);
        assert!(roots(&dir, Path::new("src")).unwrap_err().to_string().contains("absolute"));

        write(&dir, "composer.json", r#"{"extra": {"phem": {"source": "components"}}}"#);
        assert!(roots(&dir, Path::new("src")).unwrap_err().to_string().contains("no `name`"));
    }

    #[test]
    fn read_gives_the_key_and_the_path_of_each_file() {
        let dir = project();
        write(&dir, "src/Page/Home.phpm", "<?phpm home");
        write(&dir, "src/notes.txt", "not a source");
        write(&dir, "vendor/acme/ui/components/Badge.phpm", "<?phpm badge");
        write(&dir, "vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#);

        let roots = roots(&dir, Path::new("src")).unwrap();
        let open = HashMap::from([(dir.join("src/Page/Home.phpm"), "<?phpm open".to_string())]);
        let inputs: Vec<_> = read(&roots, &open).unwrap().into_iter().map(|i| (i.rel, i.path, i.package, i.text)).collect();

        assert_eq!(
            inputs,
            [
                ("Page/Home.phpm".into(), "src/Page/Home.phpm".into(), None, "<?phpm open".into()),
                ("vendor/acme/ui/Badge.phpm".into(), "vendor/acme/ui/components/Badge.phpm".into(), Some("acme/ui".into()), "<?phpm badge".into()),
            ]
        );
    }

    #[cfg(unix)]
    #[test]
    fn read_finds_an_open_document_at_the_real_path_of_a_linked_package() {
        // As a Composer path repository: `vendor/acme/ui` is a link to `packages/ui`.
        let dir = project();
        write(&dir, "packages/ui/components/Badge.phpm", "<?phpm disk");
        write(&dir, "vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#);
        std::fs::create_dir_all(dir.join("vendor/acme")).unwrap();
        std::os::unix::fs::symlink(dir.join("packages/ui"), dir.join("vendor/acme/ui")).unwrap();

        let roots = roots(&dir, Path::new("src")).unwrap();
        assert_eq!(roots[1].real_dir, Some(dir.join("packages/ui/components")));

        let open = HashMap::from([(dir.join("packages/ui/components/Badge.phpm"), "<?phpm open".to_string())]);
        let inputs = read(&roots, &open).unwrap();

        assert_eq!((inputs[0].path.as_str(), inputs[0].text.as_str()), ("vendor/acme/ui/components/Badge.phpm", "<?phpm open"));
    }

    #[test]
    fn a_root_does_not_read_another_root_or_a_vendor_dir() {
        // A package with its root `.`, and a test app in it.
        let dir = project();
        write(&dir, "composer.json", r#"{"name": "acme/ui", "extra": {"phem": {"source": "."}}}"#);
        write(&dir, "Badge.phpm", "<?phpm badge");
        write(&dir, "tests/app/src/Home.phpm", "<?phpm home");
        write(&dir, "vendor/other/x/Other.phpm", "<?phpm other");
        write(&dir, "vendor/composer/installed.json", r#"{"packages": []}"#);

        let inputs: Vec<_> = read(&roots(&dir, Path::new("tests/app/src")).unwrap(), &HashMap::new()).unwrap().into_iter().map(|i| (i.rel, i.path)).collect();
        assert_eq!(inputs, [("Home.phpm".into(), "tests/app/src/Home.phpm".into()), ("vendor/acme/ui/Badge.phpm".into(), "Badge.phpm".into())]);
    }

    #[test]
    fn two_files_with_the_same_key_are_an_error() {
        // An app file in `src/vendor/acme/ui/`, and a file of the package `acme/ui`.
        let dir = project();
        write(&dir, "src/vendor/acme/ui/Badge.phpm", "<?phpm app");
        write(&dir, "vendor/acme/ui/components/Badge.phpm", "<?phpm package");
        write(&dir, "vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#);

        let error = read(&roots(&dir, Path::new("src")).unwrap(), &HashMap::new()).unwrap_err().to_string();
        assert_eq!(error, "the files `src/vendor/acme/ui/Badge.phpm` and `vendor/acme/ui/components/Badge.phpm` have the same key `vendor/acme/ui/Badge.phpm` in the build: rename or move one of them");
    }

    #[test]
    fn the_vendor_dir_comes_from_the_composer_config() {
        let dir = project();
        write(&dir, "composer.json", r#"{"config": {"vendor-dir": "lib/vendor"}}"#);
        write(&dir, "lib/vendor/composer/installed.json", r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#);
        std::fs::create_dir_all(dir.join("lib/vendor/acme/ui/components")).unwrap();

        assert_eq!(vendor_dir(&dir), dir.join("lib/vendor"));
        assert_eq!(summary(&roots(&dir, Path::new("src")).unwrap())[1], ("lib/vendor/acme/ui/components".into(), "vendor/acme/ui/".into(), Some("acme/ui".into())));
    }

    #[test]
    fn an_absolute_source_dir_in_the_project_has_a_path_from_the_root() {
        let dir = project();

        assert_eq!(roots(&dir, &dir.join("src")).unwrap()[0].path, "src");
    }

    #[test]
    fn the_vendor_dir_of_the_environment_comes_first() {
        let dir = project();
        write(&dir, "composer.json", r#"{"config": {"vendor-dir": "lib/vendor"}}"#);

        assert_eq!(vendor_dir_with(&dir, Some("deps".into())), dir.join("deps"));
        assert_eq!(vendor_dir_with(&dir, Some(String::new())), dir.join("lib/vendor"));

        // No environment value and no config: `vendor`.
        let plain = project();
        assert_eq!(vendor_dir_with(&plain, None), plain.join("vendor"));
    }

    #[test]
    fn a_package_outside_the_project_keeps_its_absolute_path() {
        // A custom installer can put a package outside the project.
        let dir = project();
        let outside = project();
        std::fs::create_dir_all(outside.join("ui/components")).unwrap();
        write(&dir, "vendor/composer/installed.json", &format!(r#"{{"packages": [{{"name": "acme/ui", "install-path": "{}", "extra": {{"phem": {{"source": "components"}}}}}}]}}"#, outside.join("ui").display()));

        let roots = roots(&dir, Path::new("src")).unwrap();
        assert_eq!(roots[1].path, outside.join("ui/components").to_string_lossy());
        assert_eq!(roots[1].prefix, "vendor/acme/ui/");
    }
}
