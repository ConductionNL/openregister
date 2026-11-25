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

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/**
 * The object's status as a reader names it: the name of an extended status
 * object, its `statusLabel`, `statusPublicLabel` or `statusName`, else a
 * `status` that is not a reference id, or ''.
 *
 * @param {object|null} obj The object, as a search result or a connection.
 * @return {string}
 */
export function objectStatus(obj) {
	// A status read with _extend=status is the status object itself: its name.
	const status = obj?.status
	if (status && typeof status === 'object') {
		const name = status.name ?? status.title
		if (typeof name === 'string' && name !== '') return name
	}
	for (const key of ['statusLabel', 'statusPublicLabel', 'statusName', 'status']) {
		const value = obj?.[key]
		if (typeof value === 'string' && value !== '' && !UUID_RE.test(value)) {
			return value
		}
	}
	return ''
}

/**
 * The user id of the object's handler: its `assignee` or `handler`, or ''.
 *
 * @param {object|null} obj The object, as a search result or a connection.
 * @return {string}
 */
export function objectHandler(obj) {
	for (const key of ['assignee', 'handler']) {
		const value = obj?.[key]
		if (typeof value === 'string' && value !== '') {
			return value
		}
	}
	return ''
}

/**
 * The text split around the first match of the typed words, for marking it.
 *
 * @param {string} text The text shown.
 * @param {string} term The typed words.
 * @return {{before: string, match: string, after: string}}
 */
export function markParts(text, term) {
	const source = String(text || '')
	const needle = String(term || '').trim()
	const at = needle === '' ? -1 : source.toLocaleLowerCase().indexOf(needle.toLocaleLowerCase())
	if (at < 0) {
		return { before: source, match: '', after: '' }
	}
	return { before: source.slice(0, at), match: source.slice(at, at + needle.length), after: source.slice(at + needle.length) }
}
