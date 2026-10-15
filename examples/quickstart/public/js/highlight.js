// A small regex tokenizer for .rhp source: PHP code, markup tags and Twig.
// Every piece of text is HTML-escaped before it goes into a span.

const PHP_KEYWORDS = [
    "function", "fn", "return", "use", "namespace", "export", "default", "final", "class", "public",
    "private", "protected", "static", "const", "new", "if", "else", "elseif", "foreach", "for", "while",
    "as", "match", "echo", "true", "false", "null", "int", "string", "bool", "float", "array", "void", "mixed",
].join("|");

const TWIG_KEYWORDS = "in|and|or|not|is|if|else|true|false|null";

const MAIN = new RegExp([
    String.raw`(?<comment>\/\*[\s\S]*?\*\/|\/\/[^\n]*|\{#[\s\S]*?#\})`,
    String.raw`(?<twig>\{\{[\s\S]*?\}\}|\{%[\s\S]*?%\})`,
    String.raw`(?<open><\?php)`,
    String.raw`(?<tag><\/?>|<\/?[A-Za-z][\w:.-]*(?:[^<>{}"]|"[^"]*"|\{\{[\s\S]*?\}\})*\/?>)`,
    String.raw`(?<string>'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*")`,
    String.raw`(?<variable>\$[A-Za-z_]\w*)`,
    String.raw`(?<attribute>#\[[^\]\n]*\])`,
    String.raw`(?<keyword>\b(?:${PHP_KEYWORDS})\b)`,
    String.raw`(?<call>\b[A-Za-z_]\w*(?=\())`,
    String.raw`(?<number>\b\d+(?:\.\d+)?\b)`,
    String.raw`(?<word>\w+)`,
    String.raw`(?<other>[\s\S])`,
].join("|"), "g");

const TAG = /^(<\/?)([A-Za-z][\w:.-]*)?([\s\S]*?)(\/?>)$/;
const TAG_INNER = /(?<twig>\{\{[\s\S]*?\}\})|(?<string>"[^"]*")|(?<attr>[A-Za-z_][\w:.-]*)|(?<other>[\s\S])/g;
const TWIG_INNER = new RegExp(String.raw`(?<string>'[^']*'|"[^"]*")|(?<keyword>\b(?:${TWIG_KEYWORDS})\b)|(?<number>\b\d+\b)|(?<other>\w+|[\s\S])`, "g");

export function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function span(cls, text) {
    if (text === "") return "";
    const escaped = escapeHtml(text);
    return cls ? `<span class="tok-${cls}">${escaped}</span>` : escaped;
}

// Runs a global regex with named groups. Each group maps to a class name
// ("" for plain text) or to a function that returns HTML.
function tokenize(regex, text, handlers) {
    let html = "";
    for (const match of text.matchAll(regex)) {
        const groups = match.groups || {};
        const name = Object.keys(handlers).find((key) => groups[key] !== undefined);
        if (name === undefined) {
            html += span("", match[0]);
            continue;
        }
        const handler = handlers[name];
        html += typeof handler === "function" ? handler(groups[name]) : span(handler, groups[name]);
    }
    return html;
}

function highlightTwig(text) {
    const open = text.slice(0, 2);
    const close = text.slice(-2);
    let inner = text.slice(2, -2);
    let head = "";

    const tagName = open === "{%" ? /^(\s*)(\w+)/.exec(inner) : null;
    if (tagName) {
        head = span("", tagName[1]) + span("keyword", tagName[2]);
        inner = inner.slice(tagName[0].length);
    }

    const body = tokenize(TWIG_INNER, inner, { string: "string", keyword: "keyword", number: "number", other: "" });

    return span("twig", open) + head + body + span("twig", close);
}

function highlightTag(tag) {
    const m = TAG.exec(tag);
    if (!m) return span("", tag);

    const name = m[2] || "";
    const nameClass = /^[A-Z]/.test(name) ? "component" : "tag";
    const attributes = tokenize(TAG_INNER, m[3], { twig: highlightTwig, string: "string", attr: "attr", other: "" });

    return span("punct", m[1]) + span(nameClass, name) + attributes + span("punct", m[4]);
}

export function highlight(source) {
    return tokenize(MAIN, source, {
        comment: "comment",
        twig: highlightTwig,
        open: "keyword",
        tag: highlightTag,
        string: "string",
        variable: "variable",
        attribute: "attribute",
        keyword: "keyword",
        call: (name) => span(/^[A-Z]/.test(name) ? "component" : "function", name),
        number: "number",
        word: "",
        other: "",
    });
}
