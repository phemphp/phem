'use strict';

// Starts the Phem language server for the .phpm files, and colors the Twig delimiters.
//
// The server is the phemc-lsp crate. By default, the extension runs its WebAssembly build
// (server/phem-lsp.wasm) with the Node of VS Code. With the `phem.phemc.path` setting, it runs
// `phemc lsp` instead.

const vscode = require('vscode');
const { LanguageClient, TransportKind } = require('vscode-languageclient/node');

let client = null;

function serverOptions(context) {
    const phemc = vscode.workspace.getConfiguration('phem').get('phemc.path', '');
    if (phemc) {
        return { command: phemc, args: ['lsp'], transport: TransportKind.stdio };
    }
    return {
        module: context.asAbsolutePath('server/phem-lsp.cjs'),
        transport: TransportKind.stdio,
        options: { execArgv: ['--no-warnings'] },
    };
}

async function start(context) {
    const folder = vscode.workspace.workspaceFolders ? vscode.workspace.workspaceFolders[0].uri : undefined;
    const sourceDir = vscode.workspace.getConfiguration('phem', folder).get('phemc.sourceDir', 'src');
    client = new LanguageClient('phem', 'Phem', serverOptions(context), {
        documentSelector: [{ scheme: 'file', language: 'phpm' }],
        initializationOptions: { sourceDir },
    });
    await client.start();
    vscode.window.visibleTextEditors.forEach(decorate);
}

async function stop() {
    if (client) {
        const c = client;
        client = null;
        await c.stop();
    }
}

// A new server build (`make vscode-install`) replaces server/phem-lsp.wasm, but the running
// server keeps the old build until it starts again.
async function restart(context) {
    await stop();
    await start(context);
}

// Bracket pair colorization gives a bracket the color of its depth, so the Twig delimiters were
// yellow in a template and blue in a function body. The delimiters are not colorized brackets
// (see colorizedBracketPairs); they always get the color of the first bracket level. The server
// finds them (the `phem/twigDelimiters` request).
const delimiterType = vscode.window.createTextEditorDecorationType({
    color: new vscode.ThemeColor('editorBracketHighlight.foreground1'),
});

async function decorate(editor) {
    const document = editor.document;
    if (document.languageId !== 'phpm' || document.uri.scheme !== 'file') {
        return;
    }
    const enabled = vscode.workspace.getConfiguration('editor.bracketPairColorization', document).get('enabled', true);
    if (!enabled || !client) {
        editor.setDecorations(delimiterType, []);
        return;
    }
    try {
        const ranges = await client.sendRequest('phem/twigDelimiters', { uri: document.uri.toString() });
        editor.setDecorations(delimiterType, ranges.map((r) => client.protocol2CodeConverter.asRange(r)));
    } catch {
        // The server stopped or restarts: keep the old decorations.
    }
}

// The command of a usage count (a code lens of the server). The arguments are the URI of the
// document, the position of the declaration, and the locations of the references, as JSON.
// VS Code then shows the references in a peek view. Click one to go to it.
function showReferences(uri, position, locations) {
    if (!client) {
        return;
    }
    const c = client.protocol2CodeConverter;
    return vscode.commands.executeCommand(
        'editor.action.showReferences',
        c.asUri(uri),
        c.asPosition(position),
        locations.map((l) => c.asLocation(l)),
    );
}

function activate(context) {
    start(context);

    const timers = new Map();   // document -> timer
    const decorateAll = (document) => {
        for (const editor of vscode.window.visibleTextEditors) {
            if (!document || editor.document === document) {
                decorate(editor);
            }
        }
    };
    context.subscriptions.push(
        delimiterType,
        { dispose: stop },
        vscode.commands.registerCommand('phem.restartServer', () => restart(context)),
        vscode.commands.registerCommand('phem.showReferences', showReferences),
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
        vscode.workspace.onDidChangeConfiguration(async (e) => {
            if (e.affectsConfiguration('phem.phemc')) {
                await restart(context);
            } else if (e.affectsConfiguration('editor.bracketPairColorization')) {
                decorateAll();
            }
        }),
    );
}

function deactivate() {
    return stop();
}

module.exports = { activate, deactivate };
