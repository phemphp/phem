'use strict';

// A small lexer for .phpm files. It follows the rules of the TextMate grammar:
// a markup literal starts where a PHP (or Twig) expression starts, and it ends
// when its root element closes.
//
// scan(text) reads all the text and returns:
//   - context: where the end of the text is (the cursor, when the text is the
//     part of the document before the cursor);
//   - masks: the [start, end) ranges that are not PHP code (strings, comments,
//     and markup literals);
//   - delimiters: the [start, end) ranges of the Twig delimiters in markup
//     (`{{`, `}}`, `{%`, and `%}`).

// A markup literal can start after one of these characters or words.
const START_CHARS = new Set(['(', '[', ',', '=', '>', '?', ':', '!', '.', '|', '&']);
const START_WORDS = new Set(['return', 'yield', 'echo', 'print', 'and', 'or', 'xor', 'not', 'in']);

const RAW_TAGS = new Set(['script', 'style']);

class Eof extends Error {}

class Scanner {
    constructor(text) {
        this.text = text;
        this.i = 0;
        this.masks = [];
        this.delimiters = [];
        this.context = null;
        this.literal = -1;      // the start of the current markup literal
    }

    // Stops the scan: the end of the text is in the given context.
    eof(context) {
        this.context = context;
        throw new Eof();
    }

    startsWith(s) {
        return this.text.startsWith(s, this.i);
    }

    // Moves to the end of `close`. If `close` is not found, the scan stops.
    skipTo(close, context) {
        const end = this.text.indexOf(close, this.i);
        if (end < 0) {
            this.i = this.text.length;
            this.eof(context);
        }
        this.i = end + close.length;
    }

    // Moves past a quoted string. The current character is the quote.
    skipQuoted(context) {
        const quote = this.text[this.i++];
        while (this.i < this.text.length) {
            const c = this.text[this.i++];
            if (c === '\\') {
                this.i++;
            } else if (c === quote) {
                return;
            }
        }
        this.i = this.text.length;
        this.eof(context);
    }

    mask(start, fn) {
        try {
            fn();
        } finally {
            this.masks.push([start, this.i]);
        }
    }

    // True if `<` at the current position opens a tag or a fragment.
    opensTag() {
        const next = this.text[this.i + 1];
        return next === undefined || next === '>' || next === '\\' || /[A-Za-z]/.test(next);
    }

    run() {
        try {
            this.php();
        } catch (e) {
            if (!(e instanceof Eof)) {
                throw e;
            }
        }
        return { context: this.context, masks: this.masks, delimiters: this.delimiters };
    }

    php() {
        const text = this.text;
        let last = '';          // the last character or word of code
        let newline = true;     // a new line started after `last`

        while (this.i < text.length) {
            const c = text[this.i];
            const start = this.i;

            if (c === '\n') {
                newline = true;
                this.i++;
                continue;
            }
            if (c === ' ' || c === '\t' || c === '\r') {
                this.i++;
                continue;
            }

            // `<?phpm` in a .phpm file, `<?php` in the PHP classes that the project reads.
            if (this.startsWith('<?php') || this.startsWith('<?=')) {
                this.i += this.startsWith('<?=') ? 3 : this.startsWith('<?phpm') ? 6 : 5;
                last = '';
                newline = true;
                continue;
            }
            if ((c === '#' && text[this.i + 1] !== '[') || this.startsWith('//')) {
                this.mask(start, () => {
                    const end = text.indexOf('\n', this.i);
                    this.i = end < 0 ? text.length : end;
                    if (end < 0) {
                        this.eof({ kind: 'php', comment: true });
                    }
                });
                continue;
            }
            if (this.startsWith('/*')) {
                this.mask(start, () => this.skipTo('*/', { kind: 'php', comment: true }));
                continue;
            }
            if (c === '\'' || c === '"' || c === '`') {
                this.mask(start, () => this.skipQuoted({ kind: 'php', string: true }));
                last = 'value';
                newline = false;
                continue;
            }
            if (this.startsWith('<<<')) {
                const m = /^<<<[ \t]*(['"]?)([A-Za-z_]\w*)\1\r?\n/.exec(text.slice(this.i, this.i + 200));
                if (m) {
                    this.mask(start, () => {
                        const close = new RegExp('^[ \\t]*' + m[2] + '\\b', 'm');
                        const rest = text.slice(this.i + m[0].length);
                        const found = close.exec(rest);
                        if (!found) {
                            this.i = text.length;
                            this.eof({ kind: 'php', string: true });
                        }
                        this.i += m[0].length + found.index + found[0].length;
                    });
                    last = 'value';
                    newline = false;
                    continue;
                }
            }
            if (c === '<' && (newline || START_CHARS.has(last) || START_WORDS.has(last)) && this.opensTag()) {
                this.literal = start;
                this.mask(start, () => this.markup());
                last = 'value';
                newline = false;
                continue;
            }

            newline = false;
            const word = /^[A-Za-z_\x80-￿][\w\x80-￿]*/.exec(text.slice(this.i, this.i + 256));
            if (word) {
                last = word[0].toLowerCase();
                this.i += word[0].length;
                continue;
            }
            last = c;
            this.i++;
        }
        this.eof({ kind: 'php' });
    }

    // Reads one markup literal. The current character is the `<` of its root.
    markup() {
        const text = this.text;
        const tags = [];

        while (true) {
            if (this.i >= text.length) {
                this.eof({ kind: 'text', tags: tags.slice(), literal: this.literal });
            }
            const c = text[this.i];

            if (c === '<') {
                if (this.startsWith('<!--')) {
                    this.skipTo('-->', { kind: 'comment' });
                    continue;
                }
                if (text[this.i + 1] === '/') {
                    this.closeTag(tags);
                    if (tags.length === 0) {
                        return;
                    }
                    continue;
                }
                if (this.opensTag()) {
                    const name = this.openTag(tags);
                    if (name !== null) {
                        tags.push(name);
                        if (RAW_TAGS.has(name)) {
                            this.raw(name);
                        }
                    } else if (tags.length === 0) {
                        return;
                    }
                    continue;
                }
            } else if (c === '{') {
                const next = text[this.i + 1];
                if (next === '{') {
                    this.delimiter();
                    this.twig('}}');
                    continue;
                }
                if (next === '%') {
                    this.delimiter();
                    this.twig('%}');
                    continue;
                }
                if (next === '#') {
                    this.i += 2;
                    this.skipTo('#}', { kind: 'comment' });
                    continue;
                }
            }
            this.i++;
        }
    }

    // Moves past a two-character Twig delimiter and records it.
    delimiter() {
        this.delimiters.push([this.i, this.i + 2]);
        this.i += 2;
    }

    // Reads `<name attrs>` or `<name attrs />`. Returns the name of an open
    // element, or null for a self-closing tag.
    openTag(tags) {
        const text = this.text;
        this.i++;
        const nameStart = this.i;
        const name = /^[\w.:\\-]*/.exec(text.slice(this.i, this.i + 256))[0];
        this.i += name.length;
        if (this.i >= text.length) {
            this.eof({ kind: 'tagName', partial: name, start: nameStart, parent: tags[tags.length - 1] ?? null });
        }

        const attrs = [];
        while (true) {
            if (this.i >= text.length) {
                this.eof({ kind: 'attr', tag: name, attrs, partial: '' });
            }
            const c = text[this.i];
            if (c === '>') {
                this.i++;
                return name;
            }
            if (this.startsWith('/>')) {
                this.i += 2;
                return null;
            }
            if (this.startsWith('{{')) {
                this.delimiter();
                this.twig('}}');
                continue;
            }
            if (this.startsWith('{#')) {
                this.i += 2;
                this.skipTo('#}', { kind: 'comment' });
                continue;
            }
            if (c === '"' || c === '\'') {
                this.skipQuoted({ kind: 'attrValue', tag: name, attr: attrs[attrs.length - 1] ?? null, quote: this.i, attrs });
                continue;
            }
            const attr = /^[^\s=/>"'{]+/.exec(text.slice(this.i, this.i + 256));
            if (attr) {
                this.i += attr[0].length;
                if (this.i >= text.length) {
                    this.eof({ kind: 'attr', tag: name, attrs, partial: attr[0] });
                }
                attrs.push(attr[0]);
                continue;
            }
            this.i++;
        }
    }

    // Reads `</name>` and closes the open element.
    closeTag(tags) {
        const text = this.text;
        this.i += 2;
        const start = this.i;
        const name = /^[\w.:\\-]*/.exec(text.slice(this.i, this.i + 256))[0];
        this.i += name.length;
        if (this.i >= text.length) {
            this.eof({ kind: 'closeTag', partial: name, start, expected: tags[tags.length - 1] ?? null });
        }
        this.skipTo('>', { kind: 'closeTag', partial: null, start, expected: tags[tags.length - 1] ?? null });
        tags.pop();
    }

    // Skips the content of <script> and <style>, up to the closing tag.
    raw(name) {
        const re = new RegExp('</' + name + '\\s*>', 'i');
        const found = re.exec(this.text.slice(this.i));
        if (!found) {
            this.i = this.text.length;
            this.eof({ kind: 'raw', tag: name });
        }
        this.i += found.index;
    }

    // Reads Twig code up to `close` (`}}` or `%}`). Twig code can contain
    // hashes ({a: 1}) and markup literals (ok ? <A /> : <B />).
    //
    // The `twig` context has `open` (`{{` or `{%`), `start` (the offset after
    // the delimiter), and `literal` (the start of the markup literal). In a
    // string, it also has `quote` (the offset of the quote).
    twig(close) {
        const text = this.text;
        const base = { kind: 'twig', open: close === '}}' ? '{{' : '{%', start: this.i, literal: this.literal };
        let depth = 0;
        let last = '(';

        while (true) {
            if (this.i >= text.length) {
                this.eof(base);
            }
            const c = text[this.i];
            if (depth === 0 && this.startsWith(close)) {
                this.delimiter();
                return;
            }
            if (c === ' ' || c === '\t' || c === '\r' || c === '\n') {
                this.i++;
                continue;
            }
            if (c === '\'' || c === '"') {
                this.skipQuoted({ ...base, string: true, quote: this.i });
                last = 'value';
                continue;
            }
            if (c === '<' && (START_CHARS.has(last) || START_WORDS.has(last) || last === '{' || last === '~') && this.opensTag()) {
                this.markup();
                last = 'value';
                continue;
            }
            if (c === '{') {
                depth++;
            } else if (c === '}') {
                depth--;
            }
            const word = /^[A-Za-z_]\w*/.exec(text.slice(this.i, this.i + 256));
            if (word) {
                last = word[0].toLowerCase();
                this.i += word[0].length;
                continue;
            }
            last = c;
            this.i++;
        }
    }
}

function scan(text) {
    return new Scanner(text).run();
}

// Returns the text with all non-code ranges replaced by spaces. The offsets
// (and the line breaks) stay the same.
function maskText(text, masks) {
    const chars = text.split('');
    for (const [start, end] of masks) {
        for (let i = start; i < end && i < chars.length; i++) {
            if (chars[i] !== '\n') {
                chars[i] = ' ';
            }
        }
    }
    return chars.join('');
}

module.exports = { scan, maskText };
