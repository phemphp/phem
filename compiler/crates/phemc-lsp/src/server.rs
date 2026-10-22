//! The Language Server Protocol loop, over stdin and stdout. It runs on one thread, so it also
//! runs in WebAssembly (WASI). The server checks the projects after the changes, when no other
//! message waits in the input.

use crate::check;
use crate::classes::{Index, Source};
use crate::complete::{complete, ItemKind};
use crate::project::{find_root, Project};
use crate::resolve::symbol_at;
use crate::scanner::scan;
use crate::symbols::{document_symbols, Outline, OutlineKind};
use lsp_types as lsp;
use serde_json::{json, Value};
use std::collections::{BTreeSet, HashMap};
use std::io::{BufRead, BufReader, Write};
use std::path::{Path, PathBuf};

/// The custom request that returns the ranges of the Twig delimiters of a document.
pub const TWIG_DELIMITERS: &str = "phem/twigDelimiters";

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
    /// The source directory of the `.phpm` files, relative to the project root.
    source_dir: String,
    /// The root of the first workspace folder: the fallback for a file outside a project.
    workspace: Option<PathBuf>,
    docs: HashMap<PathBuf, Document>,
    projects: HashMap<PathBuf, Project>,
    roots: HashMap<PathBuf, PathBuf>,
    /// The projects to check again.
    dirty: BTreeSet<PathBuf>,
    /// The files with diagnostics, for each project.
    published: HashMap<PathBuf, BTreeSet<PathBuf>>,
    link_support: bool,
    watch_registration: bool,
    next_id: i64,
    shutdown: bool,
}

impl<W: Write> Server<W> {
    fn new(out: W) -> Self {
        Server {
            out,
            source_dir: "src".into(),
            workspace: None,
            docs: HashMap::new(),
            projects: HashMap::new(),
            roots: HashMap::new(),
            dirty: BTreeSet::new(),
            published: HashMap::new(),
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
                        self.phpm_changed(&file);
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
            self.source_dir = dir.to_string();
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
        let watchers: Vec<_> = ["**/*.phpm", "**/*.php", "**/composer.json", "**/vendor/composer/installed.json"]
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
            // The open documents replace the files on disk.
            for (path, doc) in &self.docs {
                if path.starts_with(&root) && path.extension().is_some_and(|e| e == "phpm") {
                    project.update_phpm(path, Some(&doc.text));
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
        self.phpm_changed(&file);
    }

    /// A `.phpm` file changed: in an open document, on disk, or both.
    fn phpm_changed(&mut self, file: &Path) {
        if file.extension().is_none_or(|e| e != "phpm") {
            return;
        }
        let root = self.root_of(file);
        let text = self.docs.get(file).map(|d| d.text.clone());
        if let Some(project) = self.projects.get_mut(&root) {
            project.update_phpm(file, text.as_deref());
        }
        self.dirty.insert(root);
    }

    fn file_changed(&mut self, file: &Path, typ: lsp::FileChangeType) {
        let name = file.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default();
        if name == "composer.json" || name == "installed.json" {
            // A Composer update: index the projects again.
            self.projects.clear();
            self.roots.clear();
            return;
        }
        if name.ends_with(".phpm") {
            if typ == lsp::FileChangeType::DELETED {
                let root = self.root_of(file);
                if let Some(project) = self.projects.get_mut(&root) {
                    project.delete_phpm(file);
                }
                self.dirty.insert(root);
            } else if !self.docs.contains_key(file) {
                self.phpm_changed(file);
            }
            return;
        }
        // A new or a deleted class file: index the project again. The class index reads a changed
        // class file again (it checks the modification time). The files in `vendor/`, `var/`
        // (caches), and `node_modules/` do not count.
        if name.ends_with(".php") && typ != lsp::FileChangeType::CHANGED {
            let root = self.root_of(file);
            let inside = file.strip_prefix(&root).ok().and_then(|r| r.components().next()).map(|c| c.as_os_str().to_string_lossy().into_owned());
            if !matches!(inside.as_deref(), Some("vendor" | "var" | "node_modules")) {
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
        for root in std::mem::take(&mut self.dirty) {
            let src = root.join(&self.source_dir);
            if !src.is_dir() {
                continue;
            }
            let files = check::check(&src, &open);
            let mut now = BTreeSet::new();
            for f in &files {
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
            let symbol = symbol_at(index, offset_of(text, pos.position))?;
            let location = symbol.location?;
            let target_text = match docs.get(&location.file) {
                Some(t) => t.clone(),
                None => index.source(&location.file)?.text.clone(),
            };
            let at = range(&target_text, location.offset, location.offset);
            let uri = lsp::Url::from_file_path(&location.file).ok()?;
            Some(if link_support {
                lsp::GotoDefinitionResponse::Link(vec![lsp::LocationLink {
                    origin_selection_range: Some(range(text, symbol.range.0, symbol.range.1)),
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

    #[cfg(unix)]
    #[test]
    fn a_new_class_of_a_linked_package_goes_into_the_index_of_the_app() {
        // As the quickstart: `vendor/phem/phem` is a link to the package, outside the app.
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
        assert!(class(&mut server, "Phem\\LiveCollection").is_none());

        // The editor sends the real path of the new file: it is in the package, not in the app.
        let file = package.join("src/LiveCollection.php");
        std::fs::write(&file, "<?php\n").unwrap();
        server.file_changed(&file, lsp::FileChangeType::CREATED);
        assert!(class(&mut server, "Phem\\LiveCollection").is_some());

        std::fs::remove_file(&file).unwrap();
        server.file_changed(&file, lsp::FileChangeType::DELETED);
        assert!(class(&mut server, "Phem\\LiveCollection").is_none());

        // A new class of the app goes into the index too.
        std::fs::write(app.join("src/Kernel.php"), "<?php\n").unwrap();
        server.file_changed(&app.join("src/Kernel.php"), lsp::FileChangeType::CREATED);
        assert!(class(&mut server, "App\\Kernel").is_some());
        std::fs::remove_dir_all(dir).unwrap();
    }
}
