//! The source roots of the projects (see `phemc_sources`). The check runs on each change, so the
//! server keeps the roots of a project, and the text of the files of its installed packages: it
//! reads `installed.json`, the real directories of the linked packages, and the package files one
//! time. A Composer change, or a change of a root on disk, reads them again.

use phemc_sources::SourceRoot;
use std::collections::HashMap;
use std::path::{Path, PathBuf};

/// The roots of one project.
#[derive(Default)]
pub struct Roots {
    pub roots: Vec<SourceRoot>,
    /// Why the server could not read the packages (a wrong `extra.phem.source`, a broken
    /// `installed.json`). The roots then have only the source dir of the app.
    pub error: Option<String>,
    /// The text of the files of the installed packages, by file (the path in the root dir).
    pub texts: HashMap<PathBuf, String>,
}

impl Roots {
    /// A project with no source dir and no package root has no roots, and no error: it is not a
    /// project of Phem.
    fn load(project: &Path, source_dir: &str) -> Self {
        match phemc_sources::roots_or_app(project, Path::new(source_dir)) {
            Ok((roots, error)) => Roots { roots, error: error.map(|e| e.to_string()), ..Default::default() },
            Err(_) => Roots::default(),
        }
    }

    /// The root of the root package (the project is a package), not installed.
    fn own(&self) -> Option<&SourceRoot> {
        self.roots.iter().find(|r| r.package.is_some() && !r.installed)
    }
}

/// Whether a root has a file: in its dir, or in the real dir of a linked root.
fn has(root: &SourceRoot, file: &Path) -> bool {
    file.starts_with(&root.dir) || root.real_dir.as_ref().is_some_and(|d| file.starts_with(d))
}

pub struct Sources {
    /// The source directory of the `.phpm` files, relative to the project root.
    source_dir: String,
    projects: HashMap<PathBuf, Roots>,
}

impl Sources {
    pub fn new(source_dir: impl Into<String>) -> Self {
        Sources { source_dir: source_dir.into(), projects: HashMap::new() }
    }

    /// The roots of a project, read on the first use.
    pub fn of(&mut self, project: &Path) -> &mut Roots {
        let source_dir = &self.source_dir;
        self.projects.entry(project.to_path_buf()).or_insert_with(|| Roots::load(project, source_dir))
    }

    /// The known projects that compile a file. A project that installs the package of the file
    /// (in `vendor/`, or through a link) comes first: then the package does not check itself, and
    /// only the project publishes the diagnostics of the file. Else, the projects with the file
    /// in the source dir of their app, or in their own package root.
    pub fn compiling(&self, file: &Path) -> Vec<PathBuf> {
        let installing: Vec<PathBuf> = self.projects.iter().filter(|(_, p)| p.roots.iter().any(|r| r.installed && has(r, file))).map(|(project, _)| project.clone()).collect();
        if !installing.is_empty() {
            return installing;
        }

        self.projects.iter().filter(|(_, p)| p.roots.iter().any(|r| has(r, file))).map(|(project, _)| project.clone()).collect()
    }

    /// Whether another known project installs the own root of a package project: then that project
    /// checks the files of the package, and the package does not check itself.
    pub fn installed_elsewhere(&self, project: &Path) -> bool {
        let Some(own) = self.projects.get(project).and_then(Roots::own) else {
            return false;
        };

        self.projects.iter().any(|(other, p)| other != project && p.roots.iter().any(|r| r.installed && (has(r, &own.dir) || own.real_dir.as_ref().is_some_and(|d| has(r, d)))))
    }

    /// A `.phpm` file changed on disk. The kept text of the file goes away. The roots of a project
    /// are read again when one of its root dirs no longer exists, when they had an error (the fix
    /// can be a new dir), or when no known root has the file and the project can have it (a new
    /// source dir or package root): `candidates`.
    pub fn changed(&mut self, file: &Path, candidates: &[PathBuf]) {
        for p in self.projects.values_mut() {
            for root in p.roots.iter().filter(|r| r.installed && has(r, file)) {
                let inner = root.real_dir.as_ref().and_then(|d| file.strip_prefix(d).ok());
                p.texts.remove(&inner.map_or_else(|| file.to_path_buf(), |inner| root.dir.join(inner)));
            }
        }

        self.projects.retain(|_, p| p.error.is_none() && p.roots.iter().all(|r| r.dir.is_dir()));

        if self.compiling(file).is_empty() {
            for c in candidates {
                self.projects.remove(c);
            }
        }
    }

    /// Forgets the roots: after a Composer change.
    pub fn clear(&mut self) {
        self.projects.clear();
    }
}
