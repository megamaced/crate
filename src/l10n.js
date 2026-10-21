// Crate's translation functions: @nextcloud/l10n's, with its HTML handling off.
// Every translation Crate shows is rendered as text, by Vue's {{ }} or a toast
// (see utils/toast.js), never as HTML. Escaping its placeholders would show
// "&amp;" for "&", and sanitising it as HTML would show "&lt;" for "<" and
// drop anything that looks like a tag.
//
// Call them with the literal app id and literal strings, one call per line in
// templates: bin/l10n finds the strings to translate by reading the source.
import { n as translatePlural, t as translate } from '@nextcloud/l10n'

export const t = (app, text, vars, count, options) =>
  translate(app, text, vars, count, { escape: false, sanitize: false, ...options })

export const n = (app, singular, plural, count, vars, options) =>
  translatePlural(app, singular, plural, count, vars, { escape: false, sanitize: false, ...options })

/**
 * Cuts a translated sentence at a {placeholder}, so a template can put markup
 * there (a name in bold, say) while translators see one whole sentence.
 * A translation that lost the placeholder shows as plain text.
 *
 * @param {string} text translated sentence
 * @param {string} placeholder name between the braces
 * @return {{before: string, after: string}}
 */
export function splitAt(text, placeholder) {
  const marker = `{${placeholder}}`
  const at = text.indexOf(marker)
  return at === -1
    ? { before: text, after: '' }
    : { before: text.slice(0, at), after: text.slice(at + marker.length) }
}

/**
 * Cuts a translated sentence at a pair of markers such as {linkStart} and
 * {linkEnd}, so the words between them can be wrapped in a link or <strong>.
 * A translation that lost its markers shows as plain text.
 *
 * @param {string} text translated sentence
 * @param {string} start name of the opening marker
 * @param {string} end name of the closing marker
 * @return {{before: string, inner: string, after: string}}
 */
export function splitAround(text, start, end) {
  const { before, after: rest } = splitAt(text, start)
  const { before: inner, after } = splitAt(rest, end)
  return { before, inner, after }
}
