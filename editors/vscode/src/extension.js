'use strict';

const path = require('path');
const vscode = require('vscode');
const { Project, findRoot } = require('./project');
const { complete } = require('./complete');
const { scan } = require('./scanner');
const { parse } = require('./php');
const { symbolAt } = require('./resolve');
const { documentSymbols } = require('./symbols');
const { check } = require('./diagnostics');

const KINDS = {
    class: vscode.CompletionItemKind.Class,
    function: vscode.CompletionItemKind.Function,
    const: vscode.CompletionItemKind.Constant,
    constant: vscode.CompletionItemKind.Constant,
    module: vscode.CompletionItemKind.Module,
    property: vscode.CompletionItemKind.Property,
    element: vscode.CompletionItemKind.Property,
    method: vscode.CompletionItemKind.Method,
    field: vscode.CompletionItemKind.Field,
    variable: vscode.CompletionItemKind.Variable,
    keyword: vscode.CompletionItemKind.Keyword,
    value: vscode.CompletionItemKind.Value,
    enumMember: vscode.CompletionItemKind.EnumMember,
};

const SYMBOL_KINDS = {
    namespace: vscode.SymbolKind.Namespace,
    class: vscode.SymbolKind.Class,
    interface: vscode.SymbolKind.Interface,
    trait: vscode.SymbolKind.Class,
    enum: vscode.SymbolKind.Enum,
    function: vscode.SymbolKind.Function,
    method: vscode.SymbolKind.Method,
    constructor: vscode.SymbolKind.Constructor,
    property: vscode.SymbolKind.Property,
    constant: vscode.SymbolKind.Constant,
    enumMember: vscode.SymbolKind.EnumMember,
};

const projects = new Map();     // root -> Project
const roots = new Map();        // directory -> root

async function rootFor(file) {
    const dir = path.dirname(file);
    let root = roots.get(dir);
    if (!root) {
        const folder = vscode.workspace.getWorkspaceFolder(vscode.Uri.file(file));
        root = await findRoot(file, folder ? folder.uri.fsPath : undefined);
        roots.set(dir, root);
    }
    return root;
}

async function projectFor(file) {
    const root = await rootFor(file);
    let project = projects.get(root);
    if (!project) {
        project = new Project(root);
        projects.set(root, project);
    }
    return project.load();
}

// The loaded project that contains `file`, if any.
function loadedProjectFor(file) {
    const root = roots.get(path.dirname(file));
    return root ? projects.get(root) : undefined;
}

function toItem(document, item) {
    const ci = new vscode.CompletionItem(item.label, KINDS[item.kind]);
    ci.detail = item.detail;
    if (item.documentation) {
        ci.documentation = new vscode.MarkdownString(item.documentation);
    }
    ci.insertText = item.snippet !== undefined ? new vscode.SnippetString(item.snippet) : item.insert;
    ci.range = new vscode.Range(document.positionAt(item.range[0]), document.positionAt(item.range[1]));
    ci.sortText = item.sortText;
    if (item.filterText) {
        ci.filterText = item.filterText;
    }
    if (item.edits.length) {
        ci.additionalTextEdits = item.edits.map((e) => vscode.TextEdit.insert(document.positionAt(e.offset), e.text));
    }
    if (item.retrigger) {
        ci.command = { command: 'editor.action.triggerSuggest', title: 'Suggest' };
    }
    return ci;
}

// The options of complete() and symbolAt() for a position of a document.
async function optionsFor(document, position) {
    const file = document.uri.fsPath;
    return {
        text: document.getText(),
        offset: document.offsetAt(position),
        file,
        project: document.uri.scheme === 'file' ? await projectFor(file) : null,
    };
}

function toRange(document, [start, end]) {
    return new vscode.Range(document.positionAt(start), document.positionAt(end));
}

const provider = {
    async provideCompletionItems(document, position, token, context) {
        const items = await complete({ ...(await optionsFor(document, position)), trigger: context.triggerCharacter });
        return items.map((item) => toItem(document, item));
    },
};

const definitionProvider = {
    async provideDefinition(document, position) {
        const result = await symbolAt(await optionsFor(document, position));
        if (!result || !result.location) {
            return null;
        }
        const target = new vscode.Position(result.location.line, result.location.character);
        return [{
            originSelectionRange: toRange(document, result.range),
            targetUri: vscode.Uri.file(result.location.file),
            targetRange: new vscode.Range(target, target),
            targetSelectionRange: new vscode.Range(target, target),
        }];
    },
};

const hoverProvider = {
    async provideHover(document, position) {
        const result = await symbolAt(await optionsFor(document, position));
        if (!result || !result.hover) {
            return null;
        }
        return new vscode.Hover(new vscode.MarkdownString(result.hover), toRange(document, result.range));
    },
};

function toSymbol(document, s) {
    const symbol = new vscode.DocumentSymbol(s.name, s.detail || '', SYMBOL_KINDS[s.kind], toRange(document, s.range), toRange(document, s.selection));
    symbol.children = s.children.map((c) => toSymbol(document, c));
    return symbol;
}

const symbolProvider = {
    provideDocumentSymbols(document) {
        const text = document.getText();
        return documentSymbols(parse(text), text).map((s) => toSymbol(document, s));
    },
};

// Diagnostics: `phemc check --format json`, on save. It is off when the
// `phem.phemc.path` setting is empty.
let diagnostics = null;          // created in activate()
const checks = new Map();       // root -> { timer, busy, again, files }
let generation = 0;             // changes with the settings; a late result of an old run is dropped
let warned = false;

function toDiagnostic(d) {
    const range = new vscode.Range(d.start.line, d.start.character, d.end.line, d.end.character);
    const diagnostic = new vscode.Diagnostic(range, d.message, d.severity === 'warning' ? vscode.DiagnosticSeverity.Warning : vscode.DiagnosticSeverity.Error);
    diagnostic.code = d.code;
    diagnostic.source = 'phemc';
    return diagnostic;
}

// Replaces the diagnostics of one project. `files` (the files of its last
// run) keeps the diagnostics of a nested project.
function setDiagnostics(state, map) {
    for (const file of state.files) {
        diagnostics.delete(vscode.Uri.file(file));
    }
    state.files = new Set(map.keys());
    for (const [file, list] of map) {
        diagnostics.set(vscode.Uri.file(file), list.map(toDiagnostic));
    }
}

async function runCheck(root, uri) {
    const config = vscode.workspace.getConfiguration('phem', uri);
    const command = config.get('phemc.path', '');
    if (!command) {
        return;
    }
    const state = checks.get(root);
    if (state.busy) {
        state.again = true;
        return;
    }
    state.busy = true;
    const started = generation;
    try {
        const map = await check(command, root, config.get('phemc.sourceDir', 'src'));
        if (started === generation) {
            setDiagnostics(state, map);
        }
    } catch (e) {
        if (started === generation) {
            // Old diagnostics are not true any more.
            setDiagnostics(state, new Map());
            if (!warned) {
                warned = true;
                vscode.window.showWarningMessage(`phemc check failed (phem.phemc.path = ${command}): ${e.message}`);
            }
        }
    } finally {
        state.busy = false;
        if (state.again) {
            state.again = false;
            scheduleCheck(root, uri);
        }
    }
}

function scheduleCheck(root, uri) {
    const state = checks.get(root) || { timer: null, busy: false, again: false, files: new Set() };
    checks.set(root, state);
    clearTimeout(state.timer);
    state.timer = setTimeout(() => runCheck(root, uri), 300);
}

async function checkDocument(document) {
    if (document.languageId === 'phpm' && document.uri.scheme === 'file') {
        scheduleCheck(await rootFor(document.uri.fsPath), document.uri);
    }
}

// Bracket pair colorization gives a bracket the color of its depth, so the
// Twig delimiters were yellow in a template and blue in a function body. The
// delimiters are not colorized brackets (see colorizedBracketPairs); they
// always get the color of the first bracket level.
const delimiterType = vscode.window.createTextEditorDecorationType({
    color: new vscode.ThemeColor('editorBracketHighlight.foreground1'),
});

function decorate(editor) {
    if (editor.document.languageId !== 'phpm') {
        return;
    }
    const enabled = vscode.workspace
        .getConfiguration('editor.bracketPairColorization', editor.document)
        .get('enabled', true);
    const ranges = enabled
        ? scan(editor.document.getText()).delimiters.map(([start, end]) => new vscode.Range(
            editor.document.positionAt(start),
            editor.document.positionAt(end),
        ))
        : [];
    editor.setDecorations(delimiterType, ranges);
}

function activate(context) {
    const selector = { language: 'phpm' };
    context.subscriptions.push(
        vscode.languages.registerCompletionItemProvider(selector, provider, '<', '\\', ':', '/', '.', '>', '|', '$', '%', '\'', '"'),
        vscode.languages.registerDefinitionProvider(selector, definitionProvider),
        vscode.languages.registerHoverProvider(selector, hoverProvider),
        vscode.languages.registerDocumentSymbolProvider(selector, symbolProvider),
    );

    // Diagnostics.
    diagnostics = vscode.languages.createDiagnosticCollection('phemc');
    vscode.workspace.textDocuments.forEach(checkDocument);
    context.subscriptions.push(
        diagnostics,
        vscode.workspace.onDidSaveTextDocument(checkDocument),
        vscode.workspace.onDidChangeConfiguration((e) => {
            if (e.affectsConfiguration('phem.phemc')) {
                warned = false;
                generation++;
                diagnostics.clear();
                checks.forEach((state) => state.files.clear());
                vscode.workspace.textDocuments.forEach(checkDocument);
            }
        }),
    );

    // Color the Twig delimiters.
    const timers = new Map();   // document -> timer
    const decorateAll = (document) => {
        for (const editor of vscode.window.visibleTextEditors) {
            if (!document || editor.document === document) {
                decorate(editor);
            }
        }
    };
    vscode.window.visibleTextEditors.forEach(decorate);
    context.subscriptions.push(
        delimiterType,
        vscode.window.onDidChangeVisibleTextEditors((editors) => editors.forEach(decorate)),
        vscode.workspace.onDidChangeTextDocument(({ document }) => {
            if (document.languageId !== 'phpm') {
                return;
            }
            clearTimeout(timers.get(document));
            timers.set(document, setTimeout(() => {
                timers.delete(document);
                decorateAll(document);
            }, 100));
        }),
        vscode.workspace.onDidChangeConfiguration((e) => {
            if (e.affectsConfiguration('editor.bracketPairColorization')) {
                decorateAll();
            }
        }),
    );

    // Keep the index up to date.
    const phpm = vscode.workspace.createFileSystemWatcher('**/*.phpm');
    const onPhpm = (uri) => {
        const project = loadedProjectFor(uri.fsPath);
        if (project) {
            project.updatePhpm(uri.fsPath);
        } else {
            for (const p of projects.values()) {
                if (uri.fsPath.startsWith(p.root + path.sep)) {
                    p.reset();
                }
            }
        }
    };
    phpm.onDidChange(onPhpm);
    phpm.onDidCreate(onPhpm);
    phpm.onDidDelete((uri) => {
        for (const p of projects.values()) {
            p.deletePhpm(uri.fsPath);
        }
    });

    // A new or deleted class file, or a Composer update: index the project again.
    // The files in vendor/ (Composer, see below), var/ (caches), and
    // node_modules/ do not count.
    const reset = (uri) => {
        for (const p of projects.values()) {
            const rel = path.relative(p.root, uri.fsPath);
            if (!rel.startsWith('..') && !path.isAbsolute(rel) && !/^(vendor|var|node_modules)[\\/]/.test(rel)) {
                p.reset();
            }
        }
    };
    const php = vscode.workspace.createFileSystemWatcher('**/*.php', false, true, false);
    php.onDidCreate(reset);
    php.onDidDelete(reset);
    const composer = vscode.workspace.createFileSystemWatcher('**/{composer.json,vendor/composer/installed.json}');
    const resetAll = () => {
        roots.clear();
        projects.clear();
    };
    // A changed class file: the class index reads it again (it checks the mtime).
    composer.onDidChange(resetAll);
    composer.onDidCreate(resetAll);
    composer.onDidDelete(resetAll);

    context.subscriptions.push(phpm, php, composer);
}

function deactivate() {}

module.exports = { activate, deactivate };
