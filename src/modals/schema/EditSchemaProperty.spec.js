/**
 * Golden test for the initial form state of EditSchemaProperty.
 *
 * The component's data() is called on its own and the full state, key order
 * and nesting included, is compared with a snapshot. Two calls must not share
 * any nested object or array, so editing one form never leaks into the next.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import EditSchemaProperty from './EditSchemaProperty.vue'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (p) => p }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}), { virtual: true })
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}), {
	virtual: true,
})
jest.mock('vue-material-design-icons/Plus.vue', () => ({}), { virtual: true })

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

describe('EditSchemaProperty initial state', () => {
	it('matches the golden state', () => {
		const state = EditSchemaProperty.data.call({})
		expect(JSON.stringify(state, null, 1)).toMatchSnapshot()
	})

	it('gives every form its own nested objects and arrays', () => {
		const first = containers(EditSchemaProperty.data.call({}))
		const second = new Set(containers(EditSchemaProperty.data.call({})))
		expect(first.filter((container) => second.has(container))).toEqual([])
	})
})
