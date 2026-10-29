//! The symbols of one project (a directory with a `composer.json` file):
//! - the classes of the Composer autoload maps (PSR-4 directories and the class map). For speed,
//!   the index reads only the file names here;
//! - the functions and the constants of the Composer `files` autoload entries;
//! - the components, the functions, and the classes of the `.phpm` files.
//!
//! The members of the classes come from the class index ([`crate::classes`]), on demand.

use crate::classes::ClassCache;
use crate::decl::{builtin_components, has_attr, parse, Attribute, Component, Parsed};
use regex::Regex;
use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::rc::Rc;
use std::sync::LazyLock;

const SKIP_DIRS: &[&str] = &["vendor", "node_modules", "var", ".git", "target", "cache"];
const SKIP_VENDOR_DIRS: &[&str] = &["Tests", "tests", "Test", "test", "Fixtures", "fixtures", "stubs"];
const MAX_FILES: usize = 50_000;

#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
pub enum SymbolKind {
    Class,
    Function,
    Const,
}

#[derive(Clone, Debug)]
pub struct Symbol {
    pub kind: SymbolKind,
    pub fqn: String,
    pub file: PathBuf,
}

/// The route of a page (a `#[Page]` class or function, or a `#[Route]` class component).
#[derive(Clone, Debug)]
pub struct Route {
    pub name: String,
    pub path: String,
    pub fqn: String,
    pub file: PathBuf,
    pub offset: usize,
}

/// A component and its file (`None` for a built-in component).
#[derive(Clone, Debug)]
pub struct FileComponent {
    pub component: Component,
    pub file: Option<PathBuf>,
}

impl std::ops::Deref for FileComponent {
    type Target = Component;

    fn deref(&self) -> &Component {
        &self.component
    }
}

pub struct Project {
    pub root: PathBuf,
    symbols: BTreeMap<(SymbolKind, String), Symbol>,
    pub phpm: BTreeMap<PathBuf, Rc<Parsed>>,
    pub classes: ClassCache,
    /// The PSR-4 directories of the index, with no symbolic links: a directory of a package that
    /// `vendor/` links to (a Composer path repository) is the directory of the package.
    dirs: Vec<PathBuf>,
}

impl Project {
    pub fn load(root: &Path) -> Self {
        let mut p = Project { root: root.to_path_buf(), symbols: BTreeMap::new(), phpm: BTreeMap::new(), classes: ClassCache::default(), dirs: vec![] };
        let vendor = root.join("vendor");
        if vendor.join("composer/autoload_psr4.php").exists() {
            p.load_vendor(&vendor);
        } else {
            p.load_composer_json();
        }

        p.walk_phpm(root, true);

        p
    }

    /// Whether the class index reads a file of this directory. The path has no symbolic links.
    pub fn indexes(&self, real_dir: &Path) -> bool {
        self.dirs.iter().any(|d| real_dir.starts_with(d))
    }

    fn add(&mut self, kind: SymbolKind, fqn: &str, file: &Path) {
        self.symbols.entry((kind, fqn.to_string())).or_insert_with(|| Symbol { kind, fqn: fqn.to_string(), file: file.to_path_buf() });
    }

    fn load_vendor(&mut self, vendor: &Path) {
        let composer = vendor.join("composer");
        let read = |name: &str| std::fs::read_to_string(composer.join(name)).unwrap_or_default();
        let mut counter = 0;
        for (prefix, dirs) in parse_autoload_map(&read("autoload_psr4.php"), vendor) {
            for dir in dirs {
                let is_vendor = dir.starts_with(vendor);
                self.add_dir(&dir);
                self.walk_psr4(&dir, &prefix, is_vendor, &mut counter);
            }
        }

        for (fqn, files) in parse_autoload_map(&read("autoload_classmap.php"), vendor) {
            if let Some(file) = files.first() {
                if !fqn.starts_with("Composer\\") && !fqn.contains("\\Tests\\") && !fqn.contains("\\Test\\") {
                    self.add(SymbolKind::Class, &fqn, file);
                }
            }
        }

        for (_, files) in parse_autoload_map(&read("autoload_files.php"), vendor) {
            if let Some(file) = files.first() {
                if let Ok(code) = std::fs::read_to_string(file) {
                    self.add_declarations(&parse(&code), file);
                }
            }
        }
    }

    /// Without `vendor/`, reads the autoload section of `composer.json`.
    fn load_composer_json(&mut self) {
        let Ok(text) = std::fs::read_to_string(self.root.join("composer.json")) else { return };
        let Ok(json) = serde_json::from_str::<serde_json::Value>(&text) else { return };
        let mut counter = 0;
        for section in ["autoload", "autoload-dev"] {
            let Some(psr4) = json.get(section).and_then(|s| s.get("psr-4")).and_then(|p| p.as_object()) else { continue };
            for (prefix, dirs) in psr4 {
                let dirs: Vec<&str> = match dirs {
                    serde_json::Value::String(s) => vec![s.as_str()],
                    serde_json::Value::Array(a) => a.iter().filter_map(|d| d.as_str()).collect(),
                    _ => vec![],
                };

                for dir in dirs {
                    let dir = self.root.join(dir);
                    self.add_dir(&dir);
                    self.walk_psr4(&dir, prefix, false, &mut counter);
                }
            }
        }
    }

    fn add_declarations(&mut self, parsed: &Parsed, file: &Path) {
        for f in &parsed.functions {
            self.add(SymbolKind::Function, &f.fqn, file);
        }

        for c in &parsed.classes {
            self.add(SymbolKind::Class, &c.fqn, file);
        }

        for c in &parsed.constants {
            self.add(SymbolKind::Const, &c.fqn, file);
        }
    }

    /// Keeps a PSR-4 directory of the index, with no symbolic links (see `indexes()`).
    fn add_dir(&mut self, dir: &Path) {
        if let Ok(real) = std::fs::canonicalize(dir) {
            self.dirs.push(real);
        }
    }

    /// Each `Name.php` file in a PSR-4 directory is the class `Prefix\...\Name`.
    fn walk_psr4(&mut self, dir: &Path, prefix: &str, is_vendor: bool, counter: &mut usize) {
        let Ok(entries) = std::fs::read_dir(dir) else { return };
        let mut entries: Vec<_> = entries.filter_map(Result::ok).collect();
        entries.sort_by_key(|e| e.file_name());

        let mut subdirs = Vec::new();
        for entry in entries {
            *counter += 1;
            if *counter > MAX_FILES {
                return;
            }

            let name = entry.file_name().to_string_lossy().into_owned();
            let path = entry.path();
            let is_dir = std::fs::metadata(&path).is_ok_and(|m| m.is_dir());
            if is_dir {
                if is_identifier(&name) && !(is_vendor && SKIP_VENDOR_DIRS.contains(&name.as_str())) && !SKIP_DIRS.contains(&name.as_str()) {
                    subdirs.push((path, format!("{prefix}{name}\\")));
                }
            } else if let Some(stem) = name.strip_suffix(".php") {
                if is_identifier(stem) && stem.starts_with(|c: char| c.is_ascii_uppercase() || c == '_') {
                    self.add(SymbolKind::Class, &format!("{prefix}{stem}"), &path);
                }
            }
        }

        for (dir, prefix) in subdirs {
            self.walk_psr4(&dir, &prefix, is_vendor, counter);
        }
    }

    /// Finds the `.phpm` files of the project. A subdirectory with its own `composer.json` file is
    /// another project.
    fn walk_phpm(&mut self, dir: &Path, top: bool) {
        if !top && dir.join("composer.json").exists() {
            return;
        }

        let Ok(entries) = std::fs::read_dir(dir) else { return };
        for entry in entries.filter_map(Result::ok) {
            let name = entry.file_name().to_string_lossy().into_owned();
            let path = entry.path();
            if entry.file_type().is_ok_and(|t| t.is_dir()) {
                if !SKIP_DIRS.contains(&name.as_str()) && !name.starts_with('.') {
                    self.walk_phpm(&path, false);
                }
            } else if name.ends_with(".phpm") {
                self.update_phpm(&path, None);
            }
        }
    }

    /// Reads a `.phpm` file again. `text` is the text of an open document.
    pub fn update_phpm(&mut self, file: &Path, text: Option<&str>) {
        let text = match text {
            Some(t) => Some(t.to_string()),
            None => std::fs::read_to_string(file).ok(),
        };

        match text {
            Some(t) => {
                self.phpm.insert(file.to_path_buf(), Rc::new(parse(&t)));
            }
            None => {
                self.phpm.remove(file);
            }
        }
    }

    pub fn delete_phpm(&mut self, file: &Path) {
        self.phpm.remove(file);
    }

    /// The parsed `.phpm` files, where `current` replaces the parsed version of its file.
    fn phpm_with<'a>(&'a self, file: &'a Path, current: &'a Parsed) -> impl Iterator<Item = (&'a Path, &'a Parsed)> {
        self.phpm.iter().filter(move |(f, _)| f.as_path() != file).map(|(f, p)| (f.as_path(), p.as_ref())).chain(std::iter::once((file, current)))
    }

    /// All the components of the project, and the built-in components.
    pub fn components(&self, file: &Path, current: &Parsed) -> Vec<FileComponent> {
        let mut list: Vec<FileComponent> = self
            .phpm_with(file, current)
            .flat_map(|(f, p)| p.components.iter().map(move |c| FileComponent { component: c.clone(), file: Some(f.to_path_buf()) }))
            .collect();
        list.extend(builtin_components().into_iter().map(|c| FileComponent { component: c, file: None }));
        list
    }

    /// All the symbols of a kind, with the declarations of the `.phpm` files.
    pub fn all_symbols(&self, kind: SymbolKind, file: &Path, current: &Parsed) -> Vec<Symbol> {
        let mut map: BTreeMap<String, Symbol> = self.symbols.values().filter(|s| s.kind == kind).map(|s| (s.fqn.clone(), s.clone())).collect();
        for (f, parsed) in self.phpm_with(file, current) {
            let fqns: Vec<&String> = match kind {
                SymbolKind::Class => parsed.classes.iter().map(|d| &d.fqn).collect(),
                SymbolKind::Function => parsed.functions.iter().map(|d| &d.fqn).collect(),
                SymbolKind::Const => parsed.constants.iter().map(|d| &d.fqn).collect(),
            };

            for fqn in fqns {
                map.insert(fqn.clone(), Symbol { kind, fqn: fqn.clone(), file: f.to_path_buf() });
            }
        }

        map.into_values().collect()
    }

    /// The file that declares a class or a function.
    pub fn file_of(&self, kind: SymbolKind, fqn: &str) -> Option<PathBuf> {
        if let Some(s) = self.symbols.get(&(kind, fqn.to_string())) {
            return Some(s.file.clone());
        }

        for (file, parsed) in &self.phpm {
            let found = match kind {
                SymbolKind::Class => parsed.classes.iter().any(|d| d.fqn.eq_ignore_ascii_case(fqn)),
                _ => parsed.functions.iter().any(|d| d.fqn.eq_ignore_ascii_case(fqn)),
            };

            if found {
                return Some(file.clone());
            }
        }

        self.symbols.values().find(|s| s.kind == kind && s.fqn.eq_ignore_ascii_case(fqn)).map(|s| s.file.clone())
    }

    /// The routes of the pages of the `.phpm` files.
    pub fn routes(&self, file: &Path, current: &Parsed) -> Vec<Route> {
        self.phpm_with(file, current).flat_map(|(f, p)| routes_of(p, f)).collect()
    }
}

fn is_identifier(s: &str) -> bool {
    s.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_') && s.chars().all(|c| c.is_ascii_alphanumeric() || c == '_')
}

static MAP_ENTRY: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"'((?:[^'\\]|\\.)*)'\s*=>\s*(array\(([^)]*)\)|[^,\n]+)").unwrap());
static MAP_PATH: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"\$(vendorDir|baseDir)\s*\.\s*'([^']*)'").unwrap());

/// Reads a Composer autoload map (`autoload_psr4.php`, `autoload_classmap.php`,
/// `autoload_files.php`): the keys and their paths.
pub fn parse_autoload_map(code: &str, vendor: &Path) -> Vec<(String, Vec<PathBuf>)> {
    let base = vendor.parent().unwrap_or(vendor);
    let resolve = |expr: &str| -> Option<PathBuf> {
        if let Some(m) = MAP_PATH.captures(expr) {
            let dir = if &m[1] == "vendorDir" { vendor } else { base };
            return Some(dir.join(m[2].trim_start_matches('/')));
        }

        let e = expr.trim();
        (e.len() >= 2 && e.starts_with('\'') && e.ends_with('\'')).then(|| PathBuf::from(&e[1..e.len() - 1]))
    };

    MAP_ENTRY
        .captures_iter(code)
        .map(|m| {
            let key = m[1].replace("\\\\", "\\");
            let exprs: Vec<&str> = match m.get(3) {
                Some(list) => list.as_str().split(',').collect(),
                None => vec![m.get(2).unwrap().as_str()],
            };

            (key, exprs.into_iter().filter_map(resolve).collect())
        })
        .collect()
}

static ATTR_NAME: LazyLock<Regex> = LazyLock::new(|| Regex::new(r#"\bname\s*:\s*(?:'([^']*)'|"([^"]*)")"#).unwrap());
static ATTR_PATH: LazyLock<Regex> = LazyLock::new(|| Regex::new(r#"^\s*(?:path\s*:\s*)?(?:'([^']*)'|"([^"]*)")"#).unwrap());
static ATTR_PATH_NAMED: LazyLock<Regex> = LazyLock::new(|| Regex::new(r#"\bpath\s*:\s*(?:'([^']*)'|"([^"]*)")"#).unwrap());

fn quoted(re: &Regex, s: &str) -> Option<String> {
    re.captures(s).map(|c| c.get(1).or(c.get(2)).map_or(String::new(), |m| m.as_str().to_string()))
}

/// The routes of the pages of one parsed file.
pub fn routes_of(parsed: &Parsed, file: &Path) -> Vec<Route> {
    let mut list = Vec::new();
    for decl in &parsed.classes {
        if has_attr(&decl.attrs, "Component") || has_attr(&decl.attrs, "Page") {
            push_routes(&mut list, &decl.attributes, &["Page", "Route"], &decl.fqn, file, decl.offset);
        }
    }

    // The Symfony `#[Route]` cannot mark a function: only `#[Page]` makes a function page.
    for decl in &parsed.functions {
        push_routes(&mut list, &decl.attributes, &["Page"], &decl.fqn, file, decl.offset);
    }

    list
}

/// The routes of the route attributes of one declaration.
fn push_routes(list: &mut Vec<Route>, attributes: &[Attribute], names: &[&str], fqn: &str, file: &Path, offset: usize) {
    let attrs = attributes.iter().filter(|a| a.name.rsplit('\\').next().is_some_and(|n| names.contains(&n)));
    for (i, attr) in attrs.enumerate() {
        let name = quoted(&ATTR_NAME, &attr.args).unwrap_or_else(|| route_name(fqn) + &if i > 0 { format!("_{i}") } else { String::new() });
        let path = quoted(&ATTR_PATH, &attr.args).or_else(|| quoted(&ATTR_PATH_NAMED, &attr.args)).unwrap_or_default();
        list.push(Route { name, path, fqn: fqn.to_string(), file: file.to_path_buf(), offset });
    }
}

/// `App\Page\Counter` → `app_page_counter`, as in `src/Routing/PageLoader.php` of phem/phem.
fn route_name(fqn: &str) -> String {
    fqn.replace('\\', "_").to_lowercase()
}

/// The project that installs the package of a file in `vendor/`: the directory with the
/// `vendor/` directory and a `composer.json`. It compiles the `.phpm` files of the package with its
/// own (see `phemc_sources`), so the diagnostics of the file come from its check. The class index
/// of the file stays the one of [`find_root`].
pub fn installer_root(file: &Path, fallback: Option<&Path>) -> Option<PathBuf> {
    for dir in file.ancestors().skip(1) {
        if dir.file_name().is_some_and(|n| n == "vendor") && dir.parent().is_some_and(|p| p.join("composer.json").exists()) {
            return dir.parent().map(Path::to_path_buf);
        }

        if fallback == Some(dir) {
            break;
        }
    }

    None
}

/// The directory of the nearest `composer.json` file, or `fallback`, or the directory of the file.
pub fn find_root(file: &Path, fallback: Option<&Path>) -> PathBuf {
    let mut dir = file.parent();
    while let Some(d) = dir {
        if d.join("composer.json").exists() {
            return d.to_path_buf();
        }

        if fallback == Some(d) {
            break;
        }

        dir = d.parent();
    }

    fallback.map(Path::to_path_buf).or_else(|| file.parent().map(Path::to_path_buf)).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    const SOURCE: &str = r"<?phpm
namespace App\Page;

use Phem\Attribute\{Component, Page};
use Symfony\Component\Routing\Attribute\Route;

#[Page('/home', name: 'home')]
#[Page(path: '/start')]
final class Home
{
    public function render() { return <p />; }
}

#[Route('/legacy')]
#[Component]
final class Legacy
{
    public function render() { return <p />; }
}

#[Route('/plain')]
final class Plain
{
}

#[Page('/about')]
function About() { return <p />; }

#[Route('/routed')]
#[Component]
function Routed() { return <p />; }
";

    #[test]
    fn a_page_is_a_class_or_a_function_with_page_or_a_class_with_route_and_component() {
        let routes: Vec<_> = routes_of(&parse(SOURCE), Path::new("src/page/home.phpm")).into_iter().map(|r| (r.name, r.path, r.fqn)).collect();
        let r = |n: &str, p: &str, f: &str| (n.to_string(), p.to_string(), f.to_string());
        assert_eq!(routes, [r("home", "/home", "App\\Page\\Home"), r("app_page_home_1", "/start", "App\\Page\\Home"), r("app_page_legacy", "/legacy", "App\\Page\\Legacy"), r("app_page_about", "/about", "App\\Page\\About")]);
    }

    #[test]
    fn reads_autoload_maps() {
        let code = "<?php\n$vendorDir = dirname(__DIR__);\n$baseDir = dirname($vendorDir);\nreturn array(\n    'Phem\\\\' => array($vendorDir . '/phem/phem/src'),\n    'App\\\\' => array($baseDir . '/src', $baseDir . '/lib'),\n    'abc' => $vendorDir . '/acme/f.php',\n);\n";
        let map = parse_autoload_map(code, Path::new("/p/vendor"));
        assert_eq!(map[0], ("Phem\\".to_string(), vec![PathBuf::from("/p/vendor/phem/phem/src")]));
        assert_eq!(map[1], ("App\\".to_string(), vec![PathBuf::from("/p/src"), PathBuf::from("/p/lib")]));
        assert_eq!(map[2], ("abc".to_string(), vec![PathBuf::from("/p/vendor/acme/f.php")]));
    }
}
