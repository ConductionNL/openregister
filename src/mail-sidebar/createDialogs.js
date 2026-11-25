/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Create dialogs that other apps offer to the Mail panel. An app that owns a
 * schema can replace the panel's generic "New … from this mail" form with its
 * own create dialog by pushing an entry onto
 * `window.OCA.OpenRegister.mailCreateDialogs` from a script it loads on Mail's
 * page; the order in which the two scripts load does not matter.
 *
 * An entry is `{ register, schema, open }`: `register` and `schema` are the
 * slugs (or ids) the dialog creates in, and `open(context)` shows the app's
 * dialog and resolves with the created object, or with null when the person
 * cancels. The context carries `initialData` (the schema's mail template filled
 * from the open mail), `register` and `schema` (the objects the panel knows)
 * and `mail` (`{ accountId, messageId }`). The panel then connects the mail to
 * the created object, as it does after its own form.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

/**
 * The entries apps have offered so far.
 *
 * @return {Array<object>}
 */
export function offeredCreateDialogs() {
	const list = window.OCA?.OpenRegister?.mailCreateDialogs
	return Array.isArray(list) ? list : []
}

/**
 * Whether a slug-or-id from an entry names this register or schema.
 *
 * @param {string|number} wanted The entry's slug or id.
 * @param {object} known The register or schema the panel has.
 * @return {boolean}
 */
function names(wanted, known) {
	if (wanted === undefined || wanted === null || !known) return false
	const value = String(wanted)
	return [known.slug, known.id, known.uuid].some((candidate) => candidate !== undefined && candidate !== null && String(candidate) === value)
}

/**
 * The create dialog an app offers for this register and schema, or null.
 *
 * @param {object} register The register the panel creates in.
 * @param {object} schema The schema the panel creates.
 * @return {object|null} The entry, with an `open` function.
 */
export function createDialogFor(register, schema) {
	return offeredCreateDialogs().find((entry) => entry
		&& typeof entry.open === 'function'
		&& names(entry.register, register)
		&& names(entry.schema, schema)) || null
}
