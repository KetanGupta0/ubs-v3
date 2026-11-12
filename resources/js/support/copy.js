/**
 * Helpers for a fixed page's wording (App\Support\PageCopy).
 *
 * Wording arrives as plain text. These only split and fill it; nothing here
 * turns it into HTML.
 */

/** Paragraphs from a "paragraphs" field: separated by a blank line. */
export function paragraphs(text) {
    return String(text ?? '').split(/\n{2,}/).map((part) => part.trim()).filter(Boolean);
}

/** Replace {tokens} with live figures, leaving any it does not know as written. */
export function fill(text, figures = {}) {
    return String(text ?? '').replace(/\{([a-z_]+)\}/g, (match, token) => (token in figures ? figures[token] : match));
}

/** Numbered items (pillars.1.title, pillars.1.body, ...) zipped with the design's own icons. */
export function numbered(copy, prefix, icons, fields = ['title', 'body']) {
    return icons.map((icon, index) => ({
        icon,
        ...Object.fromEntries(fields.map((field) => [field, copy[`${prefix}.${index + 1}.${field}`]])),
    }));
}
