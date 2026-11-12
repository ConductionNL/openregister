/**
 * Golden test for the initial state of the settings store.
 *
 * The full state, key order and nesting included, is compared with a
 * snapshot. Two stores must not share any nested object or array.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import { createPinia, setActivePinia } from 'pinia'
import { useSettingsStore } from './settings.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))

/**
 * Every object and array reachable from a value.
 *
 * @param {*} value The value to walk.
 * @param {Array} found The containers found so far.
 * @return {Array}
 */
function containers(value, found = []) {
	if (value !== null && typeof value === 'object') {
		found.push(value)
		Object.values(value).forEach((child) => containers(child, found))
	}
	return found
}

/**
 * The raw initial state of a fresh store.
 *
 * @return {object}
 */
function freshState() {
	setActivePinia(createPinia())
	return JSON.parse(JSON.stringify(useSettingsStore().$state))
}

describe('settings store initial state', () => {
	it('matches the golden state', () => {
		expect(JSON.stringify(freshState(), null, 1)).toMatchSnapshot()
	})

	it('gives every store its own nested objects and arrays', () => {
		setActivePinia(createPinia())
		const first = containers(useSettingsStore().$state)
		setActivePinia(createPinia())
		const second = new Set(containers(useSettingsStore().$state))
		expect(first.filter((container) => second.has(container))).toEqual([])
	})
})
