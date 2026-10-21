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
