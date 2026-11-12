/**
 * `t()` and `n()` for the object dialogs, without HTML escaping.
 *
 * @nextcloud/l10n escapes every placeholder value and sanitises the result by
 * default, for strings that end up in `v-html`. These dialogs render every
 * translated string as text (`{{ }}` or a bound attribute), which Vue escapes
 * itself, so the library's escaping would show `&#39;` and `&amp;` literally
 * in object names and values. Never pass a string from here to `v-html`.
 *
 * Named `t` and `n` on purpose: the l10n scanner (scripts/l10n/lib.js) finds
 * keys by the shape of its calls: `t` or `n`, the app id, then the source string.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import { translate, translatePlural } from '@nextcloud/l10n'

/** Plain-text output: the caller renders it as text, never as HTML. */
const AS_TEXT = { escape: false, sanitize: false }

/**
 * Translate a string for text output, placeholders filled in unescaped.
 *
 * @param {string} app The app id.
 * @param {string} text The source string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The translated text.
 */
export function t(app, text, vars) {
	return translate(app, text, vars, undefined, AS_TEXT)
}

/**
 * Translate a plural string for text output, placeholders filled in unescaped.
 *
 * @param {string} app The app id.
 * @param {string} singular The singular source string.
 * @param {string} plural The plural source string.
 * @param {number} count The number that picks the form.
 * @param {object} [vars] Placeholder values.
 * @return {string} The translated text.
 */
export function n(app, singular, plural, count, vars) {
	return translatePlural(app, singular, plural, count, vars, AS_TEXT)
}
