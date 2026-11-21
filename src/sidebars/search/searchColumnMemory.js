/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The columns a user picked on the records page, kept per schema in the
 * browser, so the choice is still there when they come back.
 *
 * A convenience for one viewer, not shared state: storage that is blocked,
 * full or cleared answers "nothing remembered", and the table then shows its
 * default columns, as it did before.
 *
 * @spec openspec/changes/records-api-and-table-stated/specs/zoeken-filteren/spec.md#REQ-RAT-002
 */

const PREFIX = 'openregister:search-columns:'

/**
 * The storage key for one schema, or null when there is no schema.
 *
 * @param {string|number|null|undefined} schemaId The schema.
 * @return {string|null} The key.
 */
function keyFor(schemaId) {
	if (schemaId === null || schemaId === undefined || String(schemaId) === '') {
		return null
	}
	return PREFIX + String(schemaId)
}

/**
 * Remember the columns picked for a schema. An empty list forgets them.
 *
 * @spec openspec/changes/records-api-and-table-stated/specs/zoeken-filteren/spec.md#REQ-RAT-002
 * @param {string|number|null|undefined} schemaId The schema.
 * @param {Array<string>} columns The visible column keys.
 * @return {void}
 */
export function rememberSearchColumns(schemaId, columns) {
	const key = keyFor(schemaId)
	if (key === null) {
		return
	}
	try {
		if (!Array.isArray(columns) || columns.length === 0) {
			window.localStorage.removeItem(key)
			return
		}
		window.localStorage.setItem(key, JSON.stringify(columns))
	} catch {
		// Storage unavailable: the choice lasts until the page is left.
	}
}

/**
 * The columns remembered for a schema; an empty list when none are.
 *
 * @spec openspec/changes/records-api-and-table-stated/specs/zoeken-filteren/spec.md#REQ-RAT-002
 * @param {string|number|null|undefined} schemaId The schema.
 * @return {Array<string>} The column keys.
 */
export function rememberedSearchColumns(schemaId) {
	const key = keyFor(schemaId)
	if (key === null) {
		return []
	}
	try {
		const stored = JSON.parse(window.localStorage.getItem(key) ?? '[]')
		return Array.isArray(stored) ? stored.filter((column) => typeof column === 'string') : []
	} catch {
		return []
	}
}
