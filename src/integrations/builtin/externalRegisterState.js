// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
/**
 * What the external-register card shows for an answer of the leaf endpoint
 * (external-register-view-leaf, task 2.2): the URL it reads, the sentence for
 * each state (ExternalRegisterDegrade) and the record's fields.
 *
 * Only `not-found` says the register holds nothing. `source-absent` and
 * `not-configured` are for an administrator; `unreachable` and `refused` say
 * why we could not look; `no-key` says there is nothing to look up.
 *
 * @spec openspec/changes/external-register-view-leaf/tasks.md#2-leaf
 */

/**
 * The leaf endpoint path for a key, or null when there is no key to look up.
 *
 * @param {string} register The register of the sourced schema.
 * @param {string} schema The sourced schema.
 * @param {string|number|null|undefined} key The host's key value.
 * @param {boolean} refresh Whether to bypass the cache.
 * @return {string|null} The path under the app, or null.
 */
export function externalRegisterUrl(register, schema, key, refresh) {
	const value = key === null || key === undefined ? '' : String(key).trim()
	if (value === '' || !register || !schema) {
		return null
	}
	const path = '/apps/openregister/api/external-register/'
		+ [register, schema, value].map(encodeURIComponent).join('/')
	return refresh ? path + '?refresh=1' : path
}

/**
 * The sentence for a state, or '' when the record shows.
 *
 * @param {string} state The state the endpoint answered.
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {string} The sentence.
 */
export function externalRegisterMessage(state, t) {
	switch (state) {
	case 'ok':
	case '':
		return ''
	case 'no-key':
		return t('openregister', 'There is nothing to look up yet.')
	case 'not-found':
		return t('openregister', 'The register holds no record for this key.')
	case 'source-absent':
	case 'not-configured':
		return t('openregister', 'This register is not available here yet. An administrator can set it up.')
	case 'refused':
		return t('openregister', 'The register refused this request.')
	default:
		return t('openregister', 'The register did not answer. Try again later.')
	}
}

/**
 * The record's fields to show: the named ones in their order, else every
 * scalar field. A value that is not text is shown as JSON.
 *
 * @param {object|null} record The record.
 * @param {Array<string>} display The fields to show; all when empty.
 * @return {Array<{name: string, value: string}>} The fields.
 */
export function externalRegisterFields(record, display) {
	if (!record || typeof record !== 'object' || Array.isArray(record)) {
		return []
	}
	const names = Array.isArray(display) && display.length
		? display.filter((name) => typeof name === 'string' && name !== '')
		: Object.keys(record).filter((name) => !name.startsWith('@') && !name.startsWith('_') && isShown(record[name]))
	return names
		.map((name) => ({ name, value: valueAt(record, name) }))
		.filter((field) => field.value !== undefined)
		.map(({ name, value }) => ({ name, value: textOf(value) }))
}

/**
 * Whether a value is shown when no fields are named: scalars only.
 *
 * @param {unknown} value The value.
 * @return {boolean}
 */
function isShown(value) {
	return ['string', 'number', 'boolean'].includes(typeof value)
}

/**
 * The value at a dot path, or undefined.
 *
 * @param {object} record The record.
 * @param {string} path The dot path.
 * @return {unknown}
 */
function valueAt(record, path) {
	return path.split('.').reduce((at, part) => (at && typeof at === 'object' ? at[part] : undefined), record)
}

/**
 * A value as text.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
function textOf(value) {
	if (value === null) {
		return ''
	}
	return typeof value === 'object' ? JSON.stringify(value) : String(value)
}
