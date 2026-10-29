//! The Language Server Protocol loop, over stdin and stdout. It runs on one thread, so it also
//! runs in WebAssembly (WASI). The server checks the projects after the changes, when no other
//! message waits in the input.

use crate::check;
use crate::classes::{Index, Source};
use crate::complete::{complete, ItemKind};
use crate::project::{find_root, installer_root, Project};
use crate::references::{counted, declaration_at, target_at, Reference, Usages};
use crate::resolve::symbol_at;
use crate::scanner::scan;
use crate::sources::Sources;
use crate::symbols::{document_symbols, Outline, OutlineKind};
use lsp_types as lsp;
use serde_json::{json, Value};
use std::collections::{BTreeSet, HashMap};
use std::io::{BufRead, BufReader, Write};
use std::path::{Path, PathBuf};

/// The custom request that returns the ranges of the Twig delimiters of a document.
pub const TWIG_DELIMITERS: &str = "phem/twigDelimiters";

/// The command of a usage count (a code lens). The client shows the references. The arguments
/// are the URI of the document, the position of the declaration, and the locations of the
/// references.
pub const SHOW_REFERENCES: &str = "phem.showReferences";

const TRIGGER_CHARACTERS: &[&str] = &["<", "\\", ":", "/", ".", ">", "|", "$", "%", "'", "\""];

/// Runs the server on stdin and stdout until the client sends `exit`.
pub fn run() -> std::io::Result<()> {
    let stdin = std::io::stdin();
    let mut reader = BufReader::new(stdin.lock());
    let mut server = Server::new(std::io::stdout());

    while let Some(message) = read_message(&mut reader)? {
        if !server.handle(message) {
            break;
        }

        if reader.buffer().is_empty() {
            server.check_dirty();
        }
    }
    Ok(())
}

fn read_message(reader: &mut impl BufRead) -> std::io::Result<Option<Value>> {
    let mut length = None;
    loop {
        let mut line = String::new();
        if reader.read_line(&mut line)? == 0 {
            return Ok(None);
        }

        let line = line.trim_end();
        if line.is_empty() {
            break;
        }

        if let Some((name, value)) = line.split_once(':') {
            if name.eq_ignore_ascii_case("content-length") {
                length = value.trim().parse::<usize>().ok();
            }
        }
    }

    let Some(length) = length else { return Ok(Some(Value::Null)) };

    let mut body = vec![0; length];
    reader.read_exact(&mut body)?;
    Ok(Some(serde_json::from_slice(&body).unwrap_or(Value::Null)))
}

struct Document {
    uri: lsp::Url,
    text: String,
}

struct Server<W: Write> {
    out: W,
    /// The source roots of the projects.
    sources: Sources,
    /// The root of the first workspace folder: the fallback for a file outside a project.
    workspace: Option<PathBuf>,
    docs: HashMap<PathBuf, Document>,
    projects: HashMap<PathBuf, Project>,
    roots: HashMap<PathBuf, PathBuf>,
    /// The projects to check again.
    dirty: BTreeSet<PathBuf>,
    /// The files with diagnostics, for each project.
    published: HashMap<PathBuf, BTreeSet<PathBuf>>,
    /// The last error of the source roots that the user saw, for each project.
    root_errors: HashMap<PathBuf, String>,
    link_support: bool,
    watch_registration: bool,
    next_id: i64,
    shutdown: bool,
}

impl<W: Write> Server<W> {
    fn new(out: W) -> Self {
        Server {
            out,
            sources: Sources::new("src"),
            workspace: None,
            docs: HashMap::new(),
            projects: HashMap::new(),
            roots: HashMap::new(),
            dirty: BTreeSet::new(),
            published: HashMap::new(),
            root_errors: HashMap::new(),
            link_support: false,
            watch_registration: false,
            next_id: 0,
            shutdown: false,
        }
    }

    fn send(&mut self, message: Value) {
        let body = message.to_string();
        let _ = write!(self.out, "Content-Length: {}\r\n\r\n{body}", body.len());
        let _ = self.out.flush();
    }

    fn notify(&mut self, method: &str, params: impl serde::Serialize) {
        self.send(json!({ "jsonrpc": "2.0", "method": method, "params": params }));
    }

    /// Handles one message. Returns false after `exit`.
    fn handle(&mut self, message: Value) -> bool {
        let Some(method) = message.get("method").and_then(Value::as_str).map(str::to_string) else {
            // A response to a request of the server, or an invalid message.
            return true;
        };

        let params = message.get("params").cloned().unwrap_or(Value::Null);
        match message.get("id").cloned() {
            Some(id) => {
                let result = if self.shutdown && method != "shutdown" {
                    Err((-32600, "the server shuts down".to_string()))
                } else {
                    self.request(&method, params)
                };

                let response = match result {
                    Ok(result) => json!({ "jsonrpc": "2.0", "id": id, "result": result }),
                    Err((code, message)) => json!({ "jsonrpc": "2.0", "id": id, "error": { "code": code, "message": message } }),
                };

                self.send(response);
                true
            }
            None => self.notification(&method, params),
        }
    }

    fn request(&mut self, method: &str, params: Value) -> Result<Value, (i64, String)> {
        fn parse<T: serde::de::DeserializeOwned>(params: Value) -> Result<T, (i64, String)> {
            serde_json::from_value(params).map_err(|e| (-32602, e.to_string()))
        }

        let result = match method {
            "initialize" => self.initialize(parse(params)?),
            "shutdown" => {
                self.shutdown = true;
                Value::Null
            }
            "textDocument/completion" => self.completion(parse(params)?),
            "textDocument/hover" => self.hover(parse(params)?),
            "textDocument/definition" => self.definition(parse(params)?),
            "textDocument/references" => self.references(parse(params)?),
            "textDocument/codeLens" => self.code_lens(parse(params)?),
            "textDocument/documentSymbol" => self.document_symbol(parse(params)?),
            TWIG_DELIMITERS => self.twig_delimiters(parse(params)?),
            _ => return Err((-32601, format!("unknown method `{method}`"))),
        };

        Ok(result)
    }

    fn notification(&mut self, method: &str, params: Value) -> bool {
        match method {
            "exit" => return false,
            "initialized" => self.register_watchers(),
            "textDocument/didOpen" => {
                if let Ok(p) = serde_json::from_value::<lsp::DidOpenTextDocumentParams>(params) {
                    self.set_text(p.text_document.uri, p.text_document.text);
                }
            }
            "textDocument/didChange" => {
                if let Ok(p) = serde_json::from_value::<lsp::DidChangeTextDocumentParams>(params) {
                    if let Some(change) = p.content_changes.into_iter().last() {
                        self.set_text(p.text_document.uri, change.text);
                    }
                }
            }
            "textDocument/didClose" => {
                if let Ok(p) = serde_json::from_value::<lsp::DidCloseTextDocumentParams>(params) {
                    if let Some(file) = file_of(&p.text_document.uri) {
                        self.docs.remove(&file);
                        self.phpm_changed(&file, true);
                    }
                }
            }
            "workspace/didChangeWatchedFiles" => {
                if let Ok(p) = serde_json::from_value::<lsp::DidChangeWatchedFilesParams>(params) {
                    for change in p.changes {
                        if let Some(file) = file_of(&change.uri) {
                            self.file_changed(&file, change.typ);
                        }
                    }
                }
            }
            _ => {}
        }

        true
    }

    fn initialize(&mut self, params: lsp::InitializeParams) -> Value {
        if let Some(dir) = params.initialization_options.as_ref().and_then(|o| o.get("sourceDir")).and_then(Value::as_str) {
            self.sources = Sources::new(dir);
        }

        #[allow(deprecated)]
        let folder = params.workspace_folders.as_ref().and_then(|f| f.first()).map(|f| f.uri.clone()).or(params.root_uri.clone());
        self.workspace = folder.as_ref().and_then(file_of);
        let caps = &params.capabilities;
        self.link_support = caps.text_document.as_ref().and_then(|t| t.definition.as_ref()).and_then(|d| d.link_support).unwrap_or(false);
        self.watch_registration = caps.workspace.as_ref().and_then(|w| w.did_change_watched_files.as_ref()).and_then(|d| d.dynamic_registration).unwrap_or(false);

        let capabilities = lsp::ServerCapabilities {
            text_document_sync: Some(lsp::TextDocumentSyncCapability::Kind(lsp::TextDocumentSyncKind::FULL)),
            completion_provider: Some(lsp::CompletionOptions {
                trigger_characters: Some(TRIGGER_CHARACTERS.iter().map(|c| c.to_string()).collect()),
                ..Default::default()
            }),
            hover_provider: Some(lsp::HoverProviderCapability::Simple(true)),
            definition_provider: Some(lsp::OneOf::Left(true)),
            references_provider: Some(lsp::OneOf::Left(true)),
            code_lens_provider: Some(lsp::CodeLensOptions { resolve_provider: Some(false) }),
            document_symbol_provider: Some(lsp::OneOf::Left(true)),
            ..Default::default()
        };

        json!({
            "capabilities": capabilities,
            "serverInfo": { "name": "phemc", "version": env!("CARGO_PKG_VERSION") },
        })
    }

    /// Asks the client to send the changes of the files that the index reads.
    fn register_watchers(&mut self) {
        if !self.watch_registration {
            return;
        }

        let watchers: Vec<_> = ["**/*.phpm", "**/*.php", "**/composer.json", "**/composer/installed.json"]
            .iter()
            .map(|g| lsp::FileSystemWatcher { glob_pattern: lsp::GlobPattern::String(g.to_string()), kind: None })
            .collect();
        let registration = lsp::Registration {
            id: "phem-watch".into(),
            method: "workspace/didChangeWatchedFiles".into(),
            register_options: Some(json!({ "watchers": watchers })),
        };

        self.next_id += 1;
        let id = format!("phem-{}", self.next_id);
        self.send(json!({
            "jsonrpc": "2.0",
            "id": id,
            "method": "client/registerCapability",
            "params": lsp::RegistrationParams { registrations: vec![registration] },
        }));
    }

    fn root_of(&mut self, file: &Path) -> PathBuf {
        let dir = file.parent().unwrap_or(file).to_path_buf();
        if let Some(root) = self.roots.get(&dir) {
            return root.clone();
        }

        let root = find_root(file, self.workspace.as_deref());
        self.roots.insert(dir, root.clone());
        root
    }

    /// The project of a file, loaded on demand.
    fn project(&mut self, file: &Path) -> (PathBuf, &mut Project) {
        let root = self.root_of(file);
        if !self.projects.contains_key(&root) {
            let mut project = Project::load(&root);

            // The open documents replace the files on disk, also the files of the packages.
            for (path, doc) in self.docs.iter().filter(|(p, _)| p.extension().is_some_and(|e| e == "phpm")) {
                if path.starts_with(&root) {
                    project.update_phpm(path, Some(&doc.text));
                } else {
                    project.package_changed(path, Some(&doc.text));
                }
            }

            self.projects.insert(root.clone(), project);
        }

        let project = self.projects.get_mut(&root).unwrap();
        (root, project)
    }

    fn set_text(&mut self, uri: lsp::Url, text: String) {
        let Some(file) = file_of(&uri) else { return };

        self.docs.insert(file.clone(), Document { uri, text });
        self.phpm_changed(&file, false);
    }

    /// A `.phpm` file changed: in an open document, or on disk (`on_disk`: also a closed
    /// document, whose text is the one on disk again). The projects that install the package of
    /// the file index it again too.
    fn phpm_changed(&mut self, file: &Path, on_disk: bool) {
        if file.extension().is_none_or(|e| e != "phpm") {
            return;
        }

        let root = self.root_of(file);
        let text = self.docs.get(file).map(|d| d.text.clone());
        if let Some(project) = self.projects.get_mut(&root) {
            project.update_phpm(file, text.as_deref());
        }

        for project in self.projects.values_mut() {
            project.package_changed(file, text.as_deref());
        }

        self.check_again(file, root, on_disk);
    }

    /// Marks the projects that compile a `.phpm` file as dirty (see `Sources::compiling()`): the
    /// projects that install its package (in `vendor/`, or through a link), else the project of
    /// the file. The workspace and the project that installs the file in `vendor/` are known
    /// projects, also before the first check. A change on disk can change the roots (a new source
    /// dir, a new package root): see `Sources::changed()`.
    fn check_again(&mut self, file: &Path, root: PathBuf, on_disk: bool) {
        let known: Vec<PathBuf> = self.workspace.clone().into_iter().chain(installer_root(file, self.workspace.as_deref())).collect();
        if on_disk {
            let candidates: Vec<PathBuf> = known.iter().cloned().chain([root.clone()]).collect();
            self.sources.changed(file, &candidates);
        }

        for project in &known {
            self.sources.of(project);
        }

        let compiling = self.sources.compiling(file);
        if compiling.is_empty() {
            self.dirty.insert(root);
        } else {
            self.dirty.extend(compiling);
        }
    }

    fn file_changed(&mut self, file: &Path, typ: lsp::FileChangeType) {
        let name = file.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default();
        if name == "composer.json" || name == "installed.json" {
            // A Composer update: index the projects again, read their source roots again, and check
            // them again (a package can add or remove components). An error of the roots shows
            // again if it is still there.
            self.dirty.extend(self.projects.keys().chain(self.published.keys()).cloned());
            self.projects.clear();
            self.roots.clear();
            self.sources.clear();
            self.root_errors.clear();
            return;
        }

        if name.ends_with(".phpm") {
            if typ == lsp::FileChangeType::DELETED {
                let root = self.root_of(file);
                if let Some(project) = self.projects.get_mut(&root) {
                    project.delete_phpm(file);
                }

                for project in self.projects.values_mut() {
                    project.package_changed(file, None);
                }

                self.check_again(file, root, true);
            } else if !self.docs.contains_key(file) {
                self.phpm_changed(file, true);
            }

            return;
        }

        // A new or a deleted class file: index the project again. The class index reads a changed
        // class file again (it checks the modification time). The files in the Composer vendor
        // dir, `var/` (caches), and `node_modules/` do not count.
        if name.ends_with(".php") && typ != lsp::FileChangeType::CHANGED {
            let root = self.root_of(file);
            let inside = file.strip_prefix(&root).ok().and_then(|r| r.components().next()).map(|c| c.as_os_str().to_string_lossy().into_owned());
            if !matches!(inside.as_deref(), Some("var" | "node_modules")) && !file.starts_with(phemc_sources::vendor_dir(&root)) {
                self.projects.remove(&root);
            }

            // A project that indexes the file through a link in `vendor/` (a Composer path
            // repository) also indexes it again. A deleted file has no real path: its directory
            // has one.
            if let Some(real) = file.parent().and_then(|d| std::fs::canonicalize(d).ok()) {
                self.projects.retain(|_, p| !p.indexes(&real));
            }
        }
    }

    /// Checks the dirty projects and publishes their diagnostics.
    fn check_dirty(&mut self) {
        let open: HashMap<PathBuf, String> = self.docs.iter().map(|(p, d)| (p.clone(), d.text.clone())).collect();

        // All the known projects first: a package that another project installs does not check
        // itself.
        let dirty = std::mem::take(&mut self.dirty);
        for project in dirty.iter().chain(&self.workspace.clone()) {
            self.sources.of(project);
        }

        for root in dirty {
            if self.sources.installed_elsewhere(&root) {
                // Its diagnostics go away, except on the files that another project publishes.
                let before = self.published.remove(&root).unwrap_or_default();
                let others: BTreeSet<PathBuf> = self.published.values().flatten().cloned().collect();
                for file in before.difference(&others) {
                    self.publish(file, vec![]);
                }

                continue;
            }

            let checked = check::check(&root, self.sources.of(&root), &open);
            self.root_error(&root, checked.error);

            let mut now = BTreeSet::new();
            for f in &checked.files {
                if f.diags.is_empty() {
                    continue;
                }

                now.insert(f.file.clone());
                let diagnostics: Vec<lsp::Diagnostic> = f
                    .diags
                    .iter()
                    .map(|d| lsp::Diagnostic {
                        range: range(&f.text, d.span.start, check::end_of(&f.text, d.span.start, d.span.end)),
                        severity: Some(if d.is_error() { lsp::DiagnosticSeverity::ERROR } else { lsp::DiagnosticSeverity::WARNING }),
                        code: Some(lsp::NumberOrString::String(d.code.to_string())),
                        source: Some("phemc".into()),
                        message: check::message(d),
                        ..Default::default()
                    })
                    .collect();
                self.publish(&f.file, diagnostics);
            }

            let before = self.published.insert(root.clone(), now.clone()).unwrap_or_default();
            for file in before.difference(&now) {
                self.publish(file, vec![]);
            }
        }
    }

    /// Shows an error of the source roots of a project (a wrong package root) one time: the check
    /// runs on each change.
    fn root_error(&mut self, root: &Path, error: Option<String>) {
        let Some(error) = error else {
            self.root_errors.remove(root);
            return;
        };

        if self.root_errors.get(root) == Some(&error) {
            return;
        }

        self.notify("window/showMessage", lsp::ShowMessageParams { typ: lsp::MessageType::ERROR, message: format!("phemc: {error}") });
        self.root_errors.insert(root.to_path_buf(), error);
    }

    fn publish(&mut self, file: &Path, diagnostics: Vec<lsp::Diagnostic>) {
        let uri = match self.docs.get(file) {
            Some(d) => d.uri.clone(),
            None => match lsp::Url::from_file_path(file) {
                Ok(u) => u,
                Err(()) => return,
            },
        };

        self.notify("textDocument/publishDiagnostics", lsp::PublishDiagnosticsParams { uri, diagnostics, version: None });
    }

    /// The index of a request on a document, and the document text.
    fn with_index<T>(&mut self, uri: &lsp::Url, f: impl FnOnce(&Index, &str) -> T) -> Option<T> {
        let file = file_of(uri)?;
        let text = match self.docs.get(&file) {
            Some(d) => d.text.clone(),
            None => std::fs::read_to_string(&file).ok()?,
        };

        let (_, project) = self.project(&file);
        let index = Index::new(project, Source::new(file, text.clone()));
        Some(f(&index, &text))
    }

    /// The `.phpm` sources of the project of a document, for a request on the document.
    fn with_usages<T>(&mut self, uri: &lsp::Url, f: impl FnOnce(&Usages, &Index) -> T) -> Option<T> {
        let file = file_of(uri)?;
        let open: HashMap<PathBuf, String> = self.docs.iter().map(|(p, d)| (p.clone(), d.text.clone())).collect();

        let (_, project) = self.project(&file);
        let usages = Usages::load(project, &open);
        let index = usages.index(&file)?;

        Some(f(&usages, &index))
    }

    fn completion(&mut self, params: lsp::CompletionParams) -> Value {
        let pos = params.text_document_position;
        let trigger = params.context.and_then(|c| c.trigger_character).and_then(|c| c.chars().next());
        let items = self.with_index(&pos.text_document.uri, |index, text| {
            let offset = offset_of(text, pos.position);
            complete(index, offset, trigger).into_iter().map(|item| completion_item(text, item)).collect::<Vec<_>>()
        });
        json!(items.unwrap_or_default())
    }

    fn hover(&mut self, params: lsp::HoverParams) -> Value {
        let pos = params.text_document_position_params;
        let hover = self.with_index(&pos.text_document.uri, |index, text| {
            let symbol = symbol_at(index, offset_of(text, pos.position))?;
            Some(lsp::Hover {
                contents: lsp::HoverContents::Markup(lsp::MarkupContent { kind: lsp::MarkupKind::Markdown, value: symbol.hover? }),
                range: Some(range(text, symbol.range.0, symbol.range.1)),
            })
        });
        json!(hover.flatten())
    }

    fn definition(&mut self, params: lsp::GotoDefinitionParams) -> Value {
        let pos = params.text_document_position_params;
        let link_support = self.link_support;
        let docs: HashMap<PathBuf, String> = self.docs.iter().map(|(p, d)| (p.clone(), d.text.clone())).collect();

        let result = self.with_index(&pos.text_document.uri, |index, text| {
            let offset = offset_of(text, pos.position);
            let (origin, location) = match symbol_at(index, offset).and_then(|s| Some((s.range, s.location?))) {
                Some(found) => found,
                // A declaration goes to itself: the editor then shows its references.
                None => {
                    let d = declaration_at(&index.current.parsed, &index.current.file, offset)?;
                    (d.range(), d.location)
                }
            };

            let target_text = match docs.get(&location.file) {
                Some(t) => t.clone(),
                None => index.source(&location.file)?.text.clone(),
            };

            let at = range(&target_text, location.offset, location.offset);
            let uri = lsp::Url::from_file_path(&location.file).ok()?;

            Some(if link_support {
                lsp::GotoDefinitionResponse::Link(vec![lsp::LocationLink {
                    origin_selection_range: Some(range(text, origin.0, origin.1)),
                    target_uri: uri,
                    target_range: at,
                    target_selection_range: at,
                }])
            } else {
                lsp::GotoDefinitionResponse::Scalar(lsp::Location { uri, range: at })
            })
        });
        json!(result.flatten())
    }

    fn references(&mut self, params: lsp::ReferenceParams) -> Value {
        let pos = params.text_document_position;
        let include_declaration = params.context.include_declaration;
        let result = self.with_usages(&pos.text_document.uri, |usages, index| {
            let target = target_at(index, offset_of(&index.current.text, pos.position))?;
            let mut list = usages.find(std::slice::from_ref(&target)).pop().unwrap_or_default();
            if include_declaration {
                list.insert(0, Reference { file: target.location.file.clone(), range: target.range() });
            }

            Some(list.iter().filter_map(|r| location_of(index, r)).collect::<Vec<_>>())
        });
        json!(result.flatten())
    }

    /// A usage count above each function, class, and method of a document.
    fn code_lens(&mut self, params: lsp::CodeLensParams) -> Value {
        let uri = params.text_document.uri;
        let lenses = self.with_usages(&uri, |usages, index| {
            let source = &index.current;
            let targets = counted(&source.parsed, &source.file);
            let found = usages.find(&targets);

            targets
                .iter()
                .zip(found)
                .map(|(target, list)| {
                    let at = range(&source.text, target.range().0, target.range().1);
                    let locations: Vec<_> = list.iter().filter_map(|r| location_of(index, r)).collect();
                    let title = if list.len() == 1 { "1 usage".to_string() } else { format!("{} usages", list.len()) };
                    lsp::CodeLens {
                        range: at,
                        command: Some(lsp::Command { title, command: SHOW_REFERENCES.into(), arguments: Some(vec![json!(uri), json!(at.start), json!(locations)]) }),
                        data: None,
                    }
                })
                .collect::<Vec<_>>()
        });
        json!(lenses.unwrap_or_default())
    }

    fn document_symbol(&mut self, params: lsp::DocumentSymbolParams) -> Value {
        let Some(file) = file_of(&params.text_document.uri) else { return Value::Null };

        let text = match self.docs.get(&file) {
            Some(d) => d.text.clone(),
            None => return Value::Null,
        };

        let source = Source::new(file, text);
        let list: Vec<_> = document_symbols(&source.parsed, &source.text).into_iter().map(|s| outline(&source.text, s)).collect();
        json!(list)
    }

    fn twig_delimiters(&mut self, params: lsp::TextDocumentIdentifier) -> Value {
        let Some(doc) = file_of(&params.uri).and_then(|f| self.docs.get(&f)) else { return json!([]) };
        let ranges: Vec<_> = scan(&doc.text).delimiters.into_iter().map(|(s, e)| range(&doc.text, s, e)).collect();
        json!(ranges)
    }
}

/// The LSP location of a reference.
fn location_of(index: &Index, reference: &Reference) -> Option<lsp::Location> {
    let source = index.source(&reference.file)?;
    let uri = lsp::Url::from_file_path(&reference.file).ok()?;

    Some(lsp::Location { uri, range: range(&source.text, reference.range.0, reference.range.1) })
}

fn file_of(uri: &lsp::Url) -> Option<PathBuf> {
    if uri.scheme() != "file" {
        return None;
    }

    uri.to_file_path().ok()
}

/// The byte offset of an LSP position. The character counts UTF-16 code units.
pub fn offset_of(text: &str, pos: lsp::Position) -> usize {
    let mut line_start = 0;
    for _ in 0..pos.line {
        match text[line_start..].find('\n') {
            Some(i) => line_start += i + 1,
            None => return text.len(),
        }
    }

    let line_end = text[line_start..].find('\n').map_or(text.len(), |i| line_start + i);
    let mut units = 0;
    for (i, c) in text[line_start..line_end].char_indices() {
        if units >= pos.character as usize {
            return line_start + i;
        }

        units += c.len_utf16();
    }

    line_end
}

/// The LSP position of a byte offset.
pub fn position(text: &str, offset: usize) -> lsp::Position {
    let mut offset = offset.min(text.len());
    while !text.is_char_boundary(offset) {
        offset -= 1;
    }

    let before = &text[..offset];
    let line_start = before.rfind('\n').map_or(0, |i| i + 1);
    lsp::Position { line: before.matches('\n').count() as u32, character: before[line_start..].encode_utf16().count() as u32 }
}

pub fn range(text: &str, start: usize, end: usize) -> lsp::Range {
    lsp::Range { start: position(text, start), end: position(text, end) }
}

fn completion_kind(kind: ItemKind) -> lsp::CompletionItemKind {
    match kind {
        ItemKind::Class => lsp::CompletionItemKind::CLASS,
        ItemKind::Function => lsp::CompletionItemKind::FUNCTION,
        ItemKind::Constant => lsp::CompletionItemKind::CONSTANT,
        ItemKind::Module => lsp::CompletionItemKind::MODULE,
        ItemKind::Property | ItemKind::Element => lsp::CompletionItemKind::PROPERTY,
        ItemKind::Method => lsp::CompletionItemKind::METHOD,
        ItemKind::Field => lsp::CompletionItemKind::FIELD,
        ItemKind::Variable => lsp::CompletionItemKind::VARIABLE,
        ItemKind::Keyword => lsp::CompletionItemKind::KEYWORD,
        ItemKind::Value => lsp::CompletionItemKind::VALUE,
        ItemKind::EnumMember => lsp::CompletionItemKind::ENUM_MEMBER,
    }
}

fn completion_item(text: &str, item: crate::complete::Item) -> lsp::CompletionItem {
    let (new_text, format) = match (item.snippet, item.insert) {
        (Some(s), _) => (s, lsp::InsertTextFormat::SNIPPET),
        (None, Some(i)) => (i, lsp::InsertTextFormat::PLAIN_TEXT),
        (None, None) => (item.label.clone(), lsp::InsertTextFormat::PLAIN_TEXT),
    };

    let edits: Vec<lsp::TextEdit> = item.edits.iter().map(|e| lsp::TextEdit { range: range(text, e.offset, e.offset), new_text: e.text.clone() }).collect();

    lsp::CompletionItem {
        label: item.label,
        kind: Some(completion_kind(item.kind)),
        detail: (!item.detail.is_empty()).then_some(item.detail),
        documentation: item.documentation.map(|d| lsp::Documentation::MarkupContent(lsp::MarkupContent { kind: lsp::MarkupKind::Markdown, value: d })),
        sort_text: Some(item.sort_text),
        filter_text: item.filter_text,
        insert_text_format: Some(format),
        text_edit: Some(lsp::CompletionTextEdit::Edit(lsp::TextEdit { range: range(text, item.range.0, item.range.1), new_text })),
        additional_text_edits: (!edits.is_empty()).then_some(edits),
        command: item.retrigger.then(|| lsp::Command { title: "Suggest".into(), command: "editor.action.triggerSuggest".into(), arguments: None }),
        ..Default::default()
    }
}

fn outline_kind(kind: OutlineKind) -> lsp::SymbolKind {
    match kind {
        OutlineKind::Namespace => lsp::SymbolKind::NAMESPACE,
        OutlineKind::Class | OutlineKind::Trait => lsp::SymbolKind::CLASS,
        OutlineKind::Interface => lsp::SymbolKind::INTERFACE,
        OutlineKind::Enum => lsp::SymbolKind::ENUM,
        OutlineKind::Function => lsp::SymbolKind::FUNCTION,
        OutlineKind::Method => lsp::SymbolKind::METHOD,
        OutlineKind::Constructor => lsp::SymbolKind::CONSTRUCTOR,
        OutlineKind::Property => lsp::SymbolKind::PROPERTY,
        OutlineKind::Constant => lsp::SymbolKind::CONSTANT,
        OutlineKind::EnumMember => lsp::SymbolKind::ENUM_MEMBER,
    }
}

#[allow(deprecated)]
fn outline(text: &str, s: Outline) -> lsp::DocumentSymbol {
    lsp::DocumentSymbol {
        name: s.name,
        detail: (!s.detail.is_empty()).then_some(s.detail),
        kind: outline_kind(s.kind),
        tags: None,
        deprecated: None,
        range: range(text, s.range.0, s.range.1),
        selection_range: range(text, s.selection.0, s.selection.1),
        children: Some(s.children.into_iter().map(|c| outline(text, c)).collect()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::project::SymbolKind;

    #[test]
    fn converts_utf16_positions() {
        // `♥` is 3 bytes and 1 UTF-16 unit; `😀` is 4 bytes and 2 UTF-16 units.
        let text = "a\n♥ 😀x\n";
        let x = text.find('x').unwrap();
        assert_eq!(position(text, x), lsp::Position { line: 1, character: 4 });
        assert_eq!(offset_of(text, lsp::Position { line: 1, character: 4 }), x);
        assert_eq!(offset_of(text, lsp::Position { line: 1, character: 99 }), text.len() - 1);
        assert_eq!(offset_of(text, lsp::Position { line: 9, character: 0 }), text.len());
    }

    fn frame(message: Value) -> String {
        let body = message.to_string();
        format!("Content-Length: {}\r\n\r\n{body}", body.len())
    }

    fn responses(out: &[u8]) -> Vec<Value> {
        let mut reader = BufReader::new(out);
        let mut list = Vec::new();
        while let Some(m) = read_message(&mut reader).unwrap() {
            list.push(m);
        }

        list
    }

    #[test]
    fn answers_requests_and_publishes_diagnostics() {
        let dir = crate::tests::temp_dir();
        std::fs::write(dir.join("composer.json"), "{}").unwrap();
        std::fs::create_dir_all(dir.join("src")).unwrap();
        let file = dir.join("src/index.phpm");
        let text = "<?phpm\nnamespace App;\n\nfunction Home()\n{\n    return <Missing />;\n}\n";
        std::fs::write(&file, text).unwrap();
        let uri = lsp::Url::from_file_path(&file).unwrap();

        let mut server = Server::new(Vec::new());
        let input = [
            json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}, "initializationOptions": {"sourceDir": "src"}}}),
            json!({"jsonrpc": "2.0", "method": "textDocument/didOpen", "params": {"textDocument": {"uri": uri, "languageId": "phpm", "version": 1, "text": text}}}),
            json!({"jsonrpc": "2.0", "id": 2, "method": "textDocument/completion", "params": {"textDocument": {"uri": uri}, "position": {"line": 5, "character": 12}}}),
            json!({"jsonrpc": "2.0", "id": 3, "method": TWIG_DELIMITERS, "params": {"uri": uri}}),
        ];
        let bytes: String = input.into_iter().map(frame).collect();
        let mut reader = BufReader::new(bytes.as_bytes());
        while let Some(m) = read_message(&mut reader).unwrap() {
            server.handle(m);
        }
        server.check_dirty();

        let out = responses(&server.out);
        assert!(out[0]["result"]["capabilities"]["completionProvider"].is_object());
        let completion = out.iter().find(|m| m["id"] == 2).unwrap();
        assert!(completion["result"].as_array().unwrap().iter().any(|i| i["label"] == "div"));
        assert_eq!(out.iter().find(|m| m["id"] == 3).unwrap()["result"], json!([]));
        let diagnostics = out.iter().find(|m| m["method"] == "textDocument/publishDiagnostics").unwrap();
        assert_eq!(diagnostics["params"]["diagnostics"][0]["code"], "E010");
        assert_eq!(diagnostics["params"]["diagnostics"][0]["range"]["start"], json!({"line": 5, "character": 12}));

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[test]
    fn checks_the_components_of_the_packages_with_the_app() {
        let dir = crate::tests::temp_dir();
        std::fs::write(dir.join("composer.json"), "{}").unwrap();
        std::fs::create_dir_all(dir.join("src")).unwrap();
        std::fs::create_dir_all(dir.join("vendor/composer")).unwrap();
        std::fs::create_dir_all(dir.join("vendor/acme/ui/components")).unwrap();
        std::fs::write(dir.join("vendor/acme/ui/composer.json"), "{}").unwrap();
        std::fs::write(dir.join("vendor/composer/installed.json"), r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#).unwrap();
        // W002 in the package: a list item with no key.
        let badge = dir.join("vendor/acme/ui/components/Badge.phpm");
        std::fs::write(&badge, "<?phpm\nnamespace Acme\\Ui;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Badge()\n{\n    return <ul>{% for i in [1] %}<li>{{ i }}</li>{% endfor %}</ul>;\n}\n").unwrap();
        let file = dir.join("src/index.phpm");
        let text = "<?phpm\nnamespace App;\n\nuse Acme\\Ui\\Badge;\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Home()\n{\n    return <div><Badge /><Missing /></div>;\n}\n";
        std::fs::write(&file, text).unwrap();
        let uri = lsp::Url::from_file_path(&file).unwrap();

        let mut server = Server::new(Vec::new());
        let input = [
            json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}}}),
            json!({"jsonrpc": "2.0", "method": "textDocument/didOpen", "params": {"textDocument": {"uri": uri, "languageId": "phpm", "version": 1, "text": text}}}),
        ];
        let bytes: String = input.into_iter().map(frame).collect();
        let mut reader = BufReader::new(bytes.as_bytes());
        while let Some(m) = read_message(&mut reader).unwrap() {
            server.handle(m);
        }
        server.check_dirty();

        // `<Badge>` is a component of the package. Only the app file has diagnostics.
        let out = responses(&server.out);
        let published: Vec<_> = out.iter().filter(|m| m["method"] == "textDocument/publishDiagnostics").collect();
        assert_eq!(published.len(), 1, "{published:?}");
        assert_eq!(published[0]["params"]["uri"], json!(uri));
        let codes: Vec<_> = published[0]["params"]["diagnostics"].as_array().unwrap().iter().map(|d| d["code"].clone()).collect();
        assert_eq!(codes, [json!("E010")]);

        // The app checks a file of the package, but the class index of the file is the one of
        // the package.
        assert_eq!(installer_root(&badge, None), Some(dir.clone()));
        assert_eq!(find_root(&badge, None), dir.join("vendor/acme/ui"));

        std::fs::remove_dir_all(dir).unwrap();
    }

    /// Opens the documents in the workspace, and checks the dirty projects two times, as after
    /// two changes. `between` comes before the second check.
    fn open_and_check(workspace: &Path, docs: &[(&Path, &str)], between: Option<Value>) -> Vec<Value> {
        let handle = |server: &mut Server<Vec<u8>>, input: Vec<Value>| {
            let bytes: String = input.into_iter().map(frame).collect();
            let mut reader = BufReader::new(bytes.as_bytes());
            while let Some(m) = read_message(&mut reader).unwrap() {
                server.handle(m);
            }
        };

        let mut server = Server::new(Vec::new());
        let root = lsp::Url::from_file_path(workspace).unwrap();
        let mut input = vec![json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}, "rootUri": root}})];
        for (file, text) in docs {
            let uri = lsp::Url::from_file_path(file).unwrap();
            input.push(json!({"jsonrpc": "2.0", "method": "textDocument/didOpen", "params": {"textDocument": {"uri": uri, "languageId": "phpm", "version": 1, "text": text}}}));
        }

        handle(&mut server, input);
        server.check_dirty();

        handle(&mut server, between.into_iter().collect());
        let checked: Vec<PathBuf> = server.published.keys().cloned().collect();
        server.dirty.extend(checked);
        server.check_dirty();

        responses(&server.out)
    }

    #[test]
    fn a_wrong_package_root_shows_an_error_and_checks_the_app() {
        let dir = crate::tests::temp_dir();
        std::fs::write(dir.join("composer.json"), "{}").unwrap();
        std::fs::create_dir_all(dir.join("src")).unwrap();
        std::fs::create_dir_all(dir.join("vendor/composer")).unwrap();
        std::fs::write(dir.join("vendor/composer/installed.json"), r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#).unwrap();
        let file = dir.join("src/index.phpm");
        let text = "<?phpm\nnamespace App;\n\nfunction Home()\n{\n    return <Missing />;\n}\n";
        std::fs::write(&file, text).unwrap();

        let out = open_and_check(&dir, &[(&file, text)], None);

        // One message for two checks with the same error.
        let messages: Vec<_> = out.iter().filter(|m| m["method"] == "window/showMessage").collect();
        assert_eq!(messages.len(), 1, "{messages:?}");
        assert!(messages[0]["params"]["message"].as_str().unwrap().contains("the package `acme/ui` gives the source dir `components`"));
        let diagnostics = out.iter().find(|m| m["method"] == "textDocument/publishDiagnostics").unwrap();
        assert_eq!(diagnostics["params"]["diagnostics"][0]["code"], "E010");

        // After a Composer change, the error shows again.
        let installed = lsp::Url::from_file_path(dir.join("vendor/composer/installed.json")).unwrap();
        let change = json!({"jsonrpc": "2.0", "method": "workspace/didChangeWatchedFiles", "params": {"changes": [{"uri": installed, "type": 2}]}});
        let out = open_and_check(&dir, &[(&file, text)], Some(change));
        assert_eq!(out.iter().filter(|m| m["method"] == "window/showMessage").count(), 2);

        std::fs::remove_dir_all(dir).unwrap();
    }

    /// An app in `dir` with two packages: `acme/base` in `vendor/`, and `acme/ui` in
    /// `packages/ui`, linked in `vendor/` (a Composer path repository). `acme/ui` gives its own root
    /// too, but it has no `vendor/`: only the app has `acme/base`.
    #[cfg(unix)]
    fn linked_app(dir: &Path) {
        let write = |file: &str, text: &str| {
            std::fs::create_dir_all(dir.join(file).parent().unwrap()).unwrap();
            std::fs::write(dir.join(file), text).unwrap();
        };

        write("composer.json", "{}");
        write("src/index.phpm", "<?phpm\nnamespace App;\n\nfunction Home()\n{\n    return <p />;\n}\n");
        write("vendor/acme/base/components/Base.phpm", "<?phpm\nnamespace Acme\\Base;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Base()\n{\n    return <b />;\n}\n");
        write("packages/ui/composer.json", r#"{"name": "acme/ui", "extra": {"phem": {"source": "components"}}}"#);
        write("packages/ui/components/Badge.phpm", "<?phpm\n");
        write("vendor/composer/installed.json", r#"{"packages": [
            {"name": "acme/base", "install-path": "../acme/base", "extra": {"phem": {"source": "components"}}},
            {"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}
        ]}"#);
        std::os::unix::fs::symlink(dir.join("packages/ui"), dir.join("vendor/acme/ui")).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn the_app_checks_a_linked_package_at_its_real_files() {
        let dir = crate::tests::temp_dir();
        linked_app(&dir);
        let badge = dir.join("packages/ui/components/Badge.phpm");
        let uri = json!(lsp::Url::from_file_path(&badge).unwrap());
        let publishes = |out: &[Value]| out.iter().filter(|m| m["method"] == "textDocument/publishDiagnostics" && m["params"]["uri"] == uri).map(|m| m["params"]["diagnostics"].clone()).collect::<Vec<_>>();

        // Only the package file is open, and the app has no check yet. The editor has the text of
        // the document, not the text on disk. `<Base>` is in the app only, so the package does
        // not check itself: it would report E010.
        let text = "<?phpm\nnamespace Acme\\Ui;\n\nuse Acme\\Base\\Base;\n\nfunction Badge()\n{\n    return <div><Base /><Missing /></div>;\n}\n";
        let out = open_and_check(&dir, &[(&badge, text)], None);
        let codes: Vec<_> = publishes(&out).iter().flat_map(|d| d.as_array().unwrap().iter().map(|d| d["code"].clone()).collect::<Vec<_>>()).collect();
        assert!(!codes.is_empty() && codes.iter().all(|c| c == "E010"), "{codes:?}");
        assert!(publishes(&out).iter().all(|d| d.as_array().unwrap().len() == 1), "only `<Missing>`: {:?}", publishes(&out));

        std::fs::remove_dir_all(dir).unwrap();
    }

    /// Gives the messages to a server.
    fn feed(server: &mut Server<Vec<u8>>, input: Vec<Value>) {
        let bytes: String = input.into_iter().map(frame).collect();
        let mut reader = BufReader::new(bytes.as_bytes());
        while let Some(m) = read_message(&mut reader).unwrap() {
            server.handle(m);
        }
    }

    fn file_event(file: &Path, typ: u32) -> Value {
        json!({"jsonrpc": "2.0", "method": "workspace/didChangeWatchedFiles", "params": {"changes": [{"uri": lsp::Url::from_file_path(file).unwrap(), "type": typ}]}})
    }

    /// The codes of the last diagnostics of a file.
    fn last_codes(server: &Server<Vec<u8>>, file: &Path) -> Vec<Value> {
        let uri = json!(lsp::Url::from_file_path(file).unwrap());
        let out = responses(&server.out);
        let last = out.iter().rev().find(|m| m["method"] == "textDocument/publishDiagnostics" && m["params"]["uri"] == uri);
        last.map(|m| m["params"]["diagnostics"].as_array().unwrap().iter().map(|d| d["code"].clone()).collect()).unwrap_or_default()
    }

    #[test]
    fn a_new_package_root_is_checked_after_its_first_file() {
        // A package whose root does not exist yet.
        let dir = crate::tests::temp_dir();
        std::fs::write(dir.join("composer.json"), r#"{"name": "acme/ui", "extra": {"phem": {"source": "components"}}}"#).unwrap();
        std::fs::create_dir_all(dir.join("src")).unwrap();
        let mut server = Server::new(Vec::new());
        feed(&mut server, vec![json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}, "rootUri": lsp::Url::from_file_path(&dir).unwrap()}})]);
        assert!(server.sources.of(&dir).error.is_some());

        let badge = dir.join("components/Badge.phpm");
        std::fs::create_dir_all(badge.parent().unwrap()).unwrap();
        std::fs::write(&badge, "<?phpm\nnamespace Acme\\Ui;\n\nfunction Badge()\n{\n    return <Missing />;\n}\n").unwrap();
        feed(&mut server, vec![file_event(&badge, 1)]);
        server.check_dirty();

        assert_eq!(last_codes(&server, &badge), [json!("E010")]);

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn a_package_that_the_app_installs_does_not_check_itself() {
        let dir = crate::tests::temp_dir();
        linked_app(&dir);
        let badge = dir.join("packages/ui/components/Badge.phpm");
        std::fs::write(&badge, "<?phpm\nnamespace Acme\\Ui;\n\nuse Acme\\Base\\Base;\n\nfunction Badge()\n{\n    return <div><Base /><Missing /></div>;\n}\n").unwrap();

        // The package checked itself before (E010 for `<Base>` and `<Missing>`), then a Composer
        // change makes the app and the package dirty.
        let mut server = Server::new(Vec::new());
        feed(&mut server, vec![json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}, "rootUri": lsp::Url::from_file_path(&dir).unwrap()}})]);
        let package = dir.join("packages/ui");
        server.dirty.insert(package.clone());
        server.workspace = None;
        server.check_dirty();
        assert_eq!(last_codes(&server, &badge), [json!("E010"), json!("E010")]);

        server.workspace = Some(dir.clone());
        server.dirty.extend([dir.clone(), package.clone()]);
        server.check_dirty();

        // Only the app checks the file: `<Base>` is a component of the app.
        assert_eq!(last_codes(&server, &badge), [json!("E010")]);
        assert!(!server.published.contains_key(&package));

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[test]
    fn the_files_of_the_packages_are_read_one_time() {
        let dir = crate::tests::temp_dir();
        std::fs::write(dir.join("composer.json"), "{}").unwrap();
        std::fs::create_dir_all(dir.join("vendor/acme/ui/components")).unwrap();
        std::fs::create_dir_all(dir.join("vendor/composer")).unwrap();
        std::fs::write(dir.join("vendor/composer/installed.json"), r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#).unwrap();
        let badge = dir.join("vendor/acme/ui/components/Badge.phpm");
        std::fs::write(&badge, "<?phpm\nnamespace Acme\\Ui;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Badge()\n{\n    return <b />;\n}\n").unwrap();
        let home = dir.join("src/index.phpm");
        let text = "<?phpm\nnamespace App;\n\nuse Acme\\Ui\\Badge;\n\nfunction Home()\n{\n    return <Badge />;\n}\n";
        std::fs::create_dir_all(home.parent().unwrap()).unwrap();
        std::fs::write(&home, text).unwrap();

        let mut server = Server::new(Vec::new());
        feed(&mut server, vec![
            json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}, "rootUri": lsp::Url::from_file_path(&dir).unwrap()}}),
            json!({"jsonrpc": "2.0", "method": "textDocument/didOpen", "params": {"textDocument": {"uri": lsp::Url::from_file_path(&home).unwrap(), "languageId": "phpm", "version": 1, "text": text}}}),
        ]);
        server.check_dirty();
        assert_eq!(last_codes(&server, &home), Vec::<Value>::new());

        // A change on disk with no event: the check keeps the text that it read.
        std::fs::write(&badge, "<?phpm\nnamespace Acme\\Ui;\n").unwrap();
        server.dirty.insert(dir.clone());
        server.check_dirty();
        assert_eq!(last_codes(&server, &home), Vec::<Value>::new());

        // The event of the change: the check reads the file again.
        feed(&mut server, vec![file_event(&badge, 2)]);
        server.check_dirty();
        assert_eq!(last_codes(&server, &home), [json!("E010")]);

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn a_linked_package_file_open_in_vendor_keeps_its_path() {
        let dir = crate::tests::temp_dir();
        linked_app(&dir);
        let badge = dir.join("vendor/acme/ui/components/Badge.phpm");
        let text = "<?phpm\nnamespace Acme\\Ui;\n\nfunction Badge()\n{\n    return <Missing />;\n}\n";

        let out = open_and_check(&dir, &[(&badge, text)], None);

        // The editor opened the file in `vendor/`: the diagnostics go there, not to the real path.
        let uri = json!(lsp::Url::from_file_path(&badge).unwrap());
        let published = out.iter().find(|m| m["method"] == "textDocument/publishDiagnostics" && m["params"]["uri"] == uri).unwrap();
        assert_eq!(published["params"]["diagnostics"][0]["code"], "E010");

        std::fs::remove_dir_all(dir).unwrap();
    }

    /// An app in `dir` with the package `acme/ui` in `vendor/`, and its page that renders `<Badge>`.
    /// Returns the file of the package and the file of the page.
    fn app_with_badge(dir: &Path) -> (PathBuf, PathBuf) {
        std::fs::write(dir.join("composer.json"), "{}").unwrap();
        std::fs::create_dir_all(dir.join("vendor/composer")).unwrap();
        std::fs::create_dir_all(dir.join("vendor/acme/ui/components")).unwrap();
        std::fs::create_dir_all(dir.join("src")).unwrap();
        std::fs::write(dir.join("vendor/composer/installed.json"), r#"{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "components"}}}]}"#).unwrap();
        let badge = dir.join("vendor/acme/ui/components/Badge.phpm");
        std::fs::write(&badge, "<?phpm\nnamespace Acme\\Ui;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Badge()\n{\n    return <b />;\n}\n").unwrap();
        let home = dir.join("src/index.phpm");
        std::fs::write(&home, "<?phpm\nnamespace App;\n\nuse Acme\\Ui\\Badge;\n\nfunction Home()\n{\n    return <Badge />;\n}\n").unwrap();

        (badge, home)
    }

    fn open(file: &Path) -> Value {
        let text = std::fs::read_to_string(file).unwrap();
        json!({"jsonrpc": "2.0", "method": "textDocument/didOpen", "params": {"textDocument": {"uri": lsp::Url::from_file_path(file).unwrap(), "languageId": "phpm", "version": 1, "text": text}}})
    }

    #[test]
    fn a_closed_package_document_is_read_again_from_disk() {
        let dir = crate::tests::temp_dir();
        let (badge, home) = app_with_badge(&dir);
        let mut server = Server::new(Vec::new());
        feed(&mut server, vec![json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}, "rootUri": lsp::Url::from_file_path(&dir).unwrap()}}), open(&home)]);
        server.check_dirty();
        assert_eq!(last_codes(&server, &home), Vec::<Value>::new());

        // The package file changes on disk with no event, and the editor opens and closes it.
        std::fs::write(&badge, "<?phpm\nnamespace Acme\\Ui;\n").unwrap();
        let uri = lsp::Url::from_file_path(&badge).unwrap();
        feed(&mut server, vec![open(&badge), json!({"jsonrpc": "2.0", "method": "textDocument/didClose", "params": {"textDocument": {"uri": uri}}})]);
        server.check_dirty();

        assert_eq!(last_codes(&server, &home), [json!("E010")]);

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[test]
    fn a_removed_package_root_is_read_again() {
        let dir = crate::tests::temp_dir();
        let (badge, home) = app_with_badge(&dir);
        let mut server = Server::new(Vec::new());
        feed(&mut server, vec![json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}, "rootUri": lsp::Url::from_file_path(&dir).unwrap()}}), open(&home)]);
        server.check_dirty();

        // The package goes away, and `installed.json` has it still (a `composer update` that runs).
        std::fs::remove_dir_all(dir.join("vendor/acme")).unwrap();
        feed(&mut server, vec![file_event(&badge, 3)]);
        server.check_dirty();

        let out = responses(&server.out);
        assert!(out.iter().any(|m| m["method"] == "window/showMessage" && m["params"]["message"].as_str().unwrap().contains("acme/ui")));
        assert_eq!(last_codes(&server, &home), [json!("E010")]);

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[test]
    fn answers_references_and_usage_counts() {
        let dir = crate::tests::temp_dir();
        std::fs::write(dir.join("composer.json"), "{}").unwrap();
        std::fs::create_dir_all(dir.join("src")).unwrap();
        let card = dir.join("src/Card.phpm");
        let card_text = "<?phpm\nnamespace App;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Card()\n{\n    return <p />;\n}\n";
        std::fs::write(&card, card_text).unwrap();
        let page = dir.join("src/index.phpm");
        std::fs::write(&page, "<?phpm\nnamespace App;\n\nuse Phem\\Attribute\\Component;\n\n#[Component]\nfunction Home()\n{\n    return <div><Card /><Card /></div>;\n}\n").unwrap();
        let card_uri = lsp::Url::from_file_path(&card).unwrap();
        let page_uri = lsp::Url::from_file_path(&page).unwrap();

        let mut server = Server::new(Vec::new());
        let input = [
            json!({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"capabilities": {}}}),
            json!({"jsonrpc": "2.0", "method": "textDocument/didOpen", "params": {"textDocument": {"uri": card_uri, "languageId": "phpm", "version": 1, "text": card_text}}}),
            json!({"jsonrpc": "2.0", "id": 2, "method": "textDocument/codeLens", "params": {"textDocument": {"uri": card_uri}}}),
            json!({"jsonrpc": "2.0", "id": 3, "method": "textDocument/references", "params": {"textDocument": {"uri": card_uri}, "position": {"line": 6, "character": 10}, "context": {"includeDeclaration": true}}}),
            json!({"jsonrpc": "2.0", "id": 4, "method": "textDocument/definition", "params": {"textDocument": {"uri": card_uri}, "position": {"line": 6, "character": 10}}}),
        ];
        let bytes: String = input.into_iter().map(frame).collect();
        let mut reader = BufReader::new(bytes.as_bytes());
        while let Some(m) = read_message(&mut reader).unwrap() {
            server.handle(m);
        }

        let out = responses(&server.out);
        assert_eq!(out[0]["result"]["capabilities"]["referencesProvider"], true);

        // The usage count of `Card`: the client shows the two tags of the page.
        let lens = &out.iter().find(|m| m["id"] == 2).unwrap()["result"][0];
        assert_eq!(lens["range"]["start"], json!({"line": 6, "character": 9}));
        assert_eq!(lens["command"]["title"], "2 usages");
        assert_eq!(lens["command"]["command"], SHOW_REFERENCES);
        assert_eq!(lens["command"]["arguments"][0], json!(card_uri));
        assert_eq!(lens["command"]["arguments"][1], json!({"line": 6, "character": 9}));
        let tag = |character: u32| json!({"uri": page_uri, "range": {"start": {"line": 8, "character": character}, "end": {"line": 8, "character": character + 4}}});
        assert_eq!(lens["command"]["arguments"][2], json!([tag(17), tag(25)]));

        let references = &out.iter().find(|m| m["id"] == 3).unwrap()["result"];
        assert_eq!(references.as_array().unwrap().len(), 3);
        assert_eq!(references[0]["uri"], json!(card_uri));
        assert_eq!(references[1], tag(17));

        // A declaration goes to itself.
        let definition = &out.iter().find(|m| m["id"] == 4).unwrap()["result"];
        assert_eq!(definition, &json!({"uri": card_uri, "range": {"start": {"line": 6, "character": 9}, "end": {"line": 6, "character": 9}}}));

        std::fs::remove_dir_all(dir).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn a_new_class_of_a_linked_package_goes_into_the_index_of_the_app() {
        // As a Composer path repository: `vendor/phem/phem` is a link to the package, outside the app.
        let dir = crate::tests::temp_dir();
        let package = dir.join("phem");
        std::fs::create_dir_all(package.join("src")).unwrap();
        std::fs::write(package.join("composer.json"), r#"{"autoload": {"psr-4": {"Phem\\": "src/"}}}"#).unwrap();
        std::fs::write(package.join("src/Html.php"), "<?php\n").unwrap();
        let app = dir.join("app");
        std::fs::create_dir_all(app.join("src")).unwrap();
        std::fs::create_dir_all(app.join("vendor/composer")).unwrap();
        std::fs::create_dir_all(app.join("vendor/phem")).unwrap();
        std::os::unix::fs::symlink("../../../phem", app.join("vendor/phem/phem")).unwrap();
        std::fs::write(app.join("composer.json"), r#"{"autoload": {"psr-4": {"App\\": "src/"}}}"#).unwrap();
        std::fs::write(
            app.join("vendor/composer/autoload_psr4.php"),
            "<?php\n$vendorDir = dirname(__DIR__);\n$baseDir = dirname($vendorDir);\nreturn array(\n    'Phem\\\\' => array($vendorDir . '/phem/phem/src'),\n    'App\\\\' => array($baseDir . '/src'),\n);\n",
        )
        .unwrap();
        let page = app.join("src/index.phpm");
        std::fs::write(&page, "<?phpm\n").unwrap();

        let mut server = Server::new(Vec::new());
        let class = |server: &mut Server<Vec<u8>>, fqn: &str| server.project(&page).1.file_of(SymbolKind::Class, fqn);
        assert!(class(&mut server, "Phem\\Html").is_some());
        assert!(class(&mut server, "Phem\\NewClass").is_none());

        // The editor sends the real path of the new file: it is in the package, not in the app.
        let file = package.join("src/NewClass.php");
        std::fs::write(&file, "<?php\n").unwrap();
        server.file_changed(&file, lsp::FileChangeType::CREATED);
        assert!(class(&mut server, "Phem\\NewClass").is_some());

        std::fs::remove_file(&file).unwrap();
        server.file_changed(&file, lsp::FileChangeType::DELETED);
        assert!(class(&mut server, "Phem\\NewClass").is_none());

        // A new class of the app goes into the index too.
        std::fs::write(app.join("src/Kernel.php"), "<?php\n").unwrap();
        server.file_changed(&app.join("src/Kernel.php"), lsp::FileChangeType::CREATED);
        assert!(class(&mut server, "App\\Kernel").is_some());

        std::fs::remove_dir_all(dir).unwrap();
    }
}
