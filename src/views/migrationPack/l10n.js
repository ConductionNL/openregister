/**
 * `t()` for the migration-pack screens, without HTML escaping.
 *
 * @nextcloud/l10n escapes every placeholder value and sanitises the result by
 * default, for strings that end up in `v-html`. These screens render every
 * translated string as text (`{{ }}` or a bound attribute), which Vue escapes
 * itself, so the library's escaping would show `&quot;` and `&amp;` literally
 * in column names, JSON values and parse errors. Never pass a string from here
 * to `v-html`.
 *
 * Named `t` on purpose: the l10n scanner (scripts/l10n/lib.js) finds keys by
 * the shape of its calls: `t`, the app id, then the source string.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import { translate } from '@nextcloud/l10n'

/** Plain-text output: the caller renders it as text, never as HTML. */
const AS_TEXT = { escape: false, sanitize: false }

/**
 * Translate a string for text output, placeholders filled in unescaped.
 *
 * @param {string} app The app id.
 * @param {string} text The source string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The translated text.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function t(app, text, vars) {
	return translate(app, text, vars, undefined, AS_TEXT)
}
