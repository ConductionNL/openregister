/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a row in the mail panel shows of an object, as the picker boards draw
 * it (WpPickerStates): its record number and one line under its name.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

/**
 * The object's record number: its `identifier`, `number` or `reference`, or ''.
 *
 * @param {object|null} obj The object, as a search result or a connection.
 * @return {string}
 */
export function objectIdentifier(obj) {
	for (const key of ['identifier', 'number', 'reference']) {
		const value = obj?.[key]
		if ((typeof value === 'string' || typeof value === 'number') && String(value) !== '') {
			return String(value)
		}
	}
	return ''
}

/**
 * The line under the object's name: its description, or ''.
 *
 * @param {object|null} obj The object, as a search result or a connection.
 * @return {string}
 */
export function objectSubline(obj) {
	const description = obj?.['@self']?.description ?? obj?.description
	return typeof description === 'string' ? description : ''
}
