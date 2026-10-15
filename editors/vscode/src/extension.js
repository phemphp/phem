'use strict';

const path = require('path');
const vscode = require('vscode');
const { Project, findRoot } = require('./project');
const { complete } = require('./complete');

const KINDS = {
    class: vscode.CompletionItemKind.Class,
    function: vscode.CompletionItemKind.Function,
    const: vscode.CompletionItemKind.Constant,
    module: vscode.CompletionItemKind.Module,
    property: vscode.CompletionItemKind.Property,
};

const projects = new Map();     // root -> Project
const roots = new Map();        // directory -> root

async function projectFor(file) {
    const dir = path.dirname(file);
    let root = roots.get(dir);
    if (!root) {
        const folder = vscode.workspace.getWorkspaceFolder(vscode.Uri.file(file));
        root = await findRoot(file, folder ? folder.uri.fsPath : undefined);
        roots.set(dir, root);
    }
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

const provider = {
    async provideCompletionItems(document, position) {
        const file = document.uri.fsPath;
        const project = document.uri.scheme === 'file' ? await projectFor(file) : null;
        const items = complete({
            text: document.getText(),
            offset: document.offsetAt(position),
            file,
            project,
        });
        return items.map((item) => toItem(document, item));
    },
};

function activate(context) {
    context.subscriptions.push(
        vscode.languages.registerCompletionItemProvider({ language: 'rhp' }, provider, '<', '\\', ':', '/'),
    );

    // Keep the index up to date.
    const rhp = vscode.workspace.createFileSystemWatcher('**/*.rhp');
    const onRhp = (uri) => {
        const project = loadedProjectFor(uri.fsPath);
        if (project) {
            project.updateRhp(uri.fsPath);
        } else {
            for (const p of projects.values()) {
                if (uri.fsPath.startsWith(p.root + path.sep)) {
                    p.reset();
                }
            }
        }
    };
    rhp.onDidChange(onRhp);
    rhp.onDidCreate(onRhp);
    rhp.onDidDelete((uri) => {
        for (const p of projects.values()) {
            p.deleteRhp(uri.fsPath);
        }
    });

    // A new or deleted class file, or a Composer update: index the project again.
    const reset = (uri) => {
        for (const p of projects.values()) {
            if (uri.fsPath.startsWith(p.root + path.sep)) {
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
    composer.onDidChange(resetAll);
    composer.onDidCreate(resetAll);
    composer.onDidDelete(resetAll);

    context.subscriptions.push(rhp, php, composer);
}

function deactivate() {}

module.exports = { activate, deactivate };
