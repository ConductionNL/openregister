/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The objects requests of the mail sidebar's actions tab: the initial list,
 * a search and creating an object all go to the schema's objects endpoint.
 *
 * @spec exclude Unit test of client-side mail sidebar requests.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ActionsTab from './ActionsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
}))
jest.mock('../../dialogs/mail/CreateConnectedObjectDialog.vue', () => ({
	name: 'CreateConnectedObjectDialog',
	render: () => null,
}))

const SCHEMA = {
	id: 5,
	title: 'Leads',
	configuration: {
		linkedTypes: ['mail'],
		mailObjectTemplate: { title: '{subject}' },
	},
}
const REGISTER = { id: 2, schemas: [5] }
const OBJECTS = '/index.php/apps/openregister/api/objects/2/5'

/**
 * Answer the list requests the tab makes on creation and the object lists.
 *
 * @param {object} objectsResponse The data the objects endpoint returns
 * @return {void}
 */
function serve(objectsResponse) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/api/schemas')) {
			return Promise.resolve({ data: { results: [SCHEMA] } })
		}
		if (url.endsWith('/api/registers')) {
			return Promise.resolve({ data: { results: [REGISTER] } })
		}
		return Promise.resolve({ data: objectsResponse })
	})
}

/**
 * The objects-endpoint calls axios.get received.
 *
 * @return {Array<Array>} The calls
 */
function objectCalls() {
	return axios.get.mock.calls.filter(([url]) => url === OBJECTS)
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('ActionsTab objects requests', () => {
	it('loads the first 20 objects of each schema on creation', async () => {
		serve({ results: [{ id: 'a' }] })
		const wrapper = mount(ActionsTab)
		await flushPromises()

		expect(objectCalls()).toEqual([
			[OBJECTS, { params: { _limit: 20 }, timeout: 10000 }],
		])
		expect(wrapper.vm.searchResults[5]).toEqual([{ id: 'a' }])
	})

	it('searches with the term and keeps a bare array result', async () => {
		serve([{ id: 'b' }])
		const wrapper = mount(ActionsTab)
		await flushPromises()
		axios.get.mockClear()

		wrapper.vm.searchTerms[5] = 'acme'
		await wrapper.vm.searchObjects(SCHEMA)

		expect(objectCalls()).toEqual([
			[OBJECTS, { params: { _search: 'acme', _limit: 20 }, timeout: 10000 }],
		])
		expect(Object.keys(objectCalls()[0][1].params)).toEqual([
			'_search',
			'_limit',
		])
		expect(wrapper.vm.searchResults[5]).toEqual([{ id: 'b' }])
		expect(wrapper.vm.searching[5]).toBe(false)
	})

	it('reloads the initial list for an empty search', async () => {
		serve({ results: [] })
		const wrapper = mount(ActionsTab)
		await flushPromises()
		axios.get.mockClear()

		await wrapper.vm.searchObjects(SCHEMA)
		await flushPromises()

		expect(objectCalls()).toEqual([
			[OBJECTS, { params: { _limit: 20 }, timeout: 10000 }],
		])
	})

	it('posts a new object to the objects endpoint', async () => {
		serve({ results: [] })
		const wrapper = mount(ActionsTab)
		await flushPromises()
		axios.post.mockRejectedValue(new Error('refused'))
		jest.spyOn(console, 'error').mockImplementation(() => {})

		wrapper.vm.createDialog = { show: true, schema: SCHEMA, data: {} }
		await wrapper.vm.submitCreate({ title: 'Hello' })

		expect(axios.post).toHaveBeenCalledWith(
			OBJECTS,
			{ title: 'Hello' },
			{ timeout: 15000 },
		)
		expect(wrapper.vm.createSaving).toBe(false)
		console.error.mockRestore()
	})
})
