/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The list loading, schema property sync and metadata columns the dashboard
 * and search sidebars share.
 *
 * @spec exclude Unit test of a client-side sidebar mixin.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { objectStore, registerStore, schemaStore } from '../store/store.js'
import { scopeListLoader } from './scopeListLoader.js'

jest.mock('../store/store.js', () => ({
	objectStore: {
		metadata: {},
		properties: { stale: true },
		initializeProperties: jest.fn(),
		initializeColumnFilters: jest.fn(),
	},
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { schemaList: [], refreshSchemaList: jest.fn() },
}))

/**
 * Mount a bare host with the mixin and both loading flags raised.
 *
 * @return {object} The wrapper
 */
function host() {
	return mount(
		{
			mixins: [scopeListLoader],
			data: () => ({ registerLoading: true, schemaLoading: true }),
			render: () => null,
		},
	)
}

/**
 * A promise and the function that settles it.
 *
 * @return {{promise: Promise, resolve: Function}} The pair
 */
function deferred() {
	let resolve
	const promise = new Promise((done) => {
		resolve = done
	})
	return { promise, resolve }
}

beforeEach(() => {
	jest.clearAllMocks()
	registerStore.registerList = []
	schemaStore.schemaList = []
	objectStore.properties = { stale: true }
	objectStore.metadata = {}
})

describe('scopeListLoader', () => {
	it('loads empty lists and clears each flag when its list arrives', async () => {
		const registers = deferred()
		const schemas = deferred()
		registerStore.refreshRegisterList.mockReturnValue(registers.promise)
		schemaStore.refreshSchemaList.mockReturnValue(schemas.promise)
		const wrapper = host()

		wrapper.vm.loadScopeLists()
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.registerLoading).toBe(true)
		expect(wrapper.vm.schemaLoading).toBe(true)

		registers.resolve()
		await flushPromises()
		expect(wrapper.vm.registerLoading).toBe(false)
		expect(wrapper.vm.schemaLoading).toBe(true)

		schemas.resolve()
		await flushPromises()
		expect(wrapper.vm.schemaLoading).toBe(false)
	})

	it('keeps loaded lists and clears both flags at once', () => {
		registerStore.registerList = [{ id: 1 }]
		schemaStore.schemaList = [{ id: 2 }]
		const wrapper = host()

		wrapper.vm.loadScopeLists()
		expect(registerStore.refreshRegisterList).not.toHaveBeenCalled()
		expect(schemaStore.refreshSchemaList).not.toHaveBeenCalled()
		expect(wrapper.vm.registerLoading).toBe(false)
		expect(wrapper.vm.schemaLoading).toBe(false)
	})

	it('initialises the properties of a picked schema', () => {
		const schema = { id: 3 }
		host().vm.syncSchemaProperties(schema)
		expect(objectStore.initializeProperties).toHaveBeenCalledWith(schema)
		expect(objectStore.initializeColumnFilters).not.toHaveBeenCalled()
		expect(objectStore.properties).toEqual({ stale: true })
	})

	it('clears the properties when the schema is cleared', () => {
		host().vm.syncSchemaProperties(null)
		expect(objectStore.initializeProperties).not.toHaveBeenCalled()
		expect(objectStore.properties).toEqual({})
		expect(objectStore.initializeColumnFilters).toHaveBeenCalledTimes(1)
	})

	it('flattens the object metadata into columns', () => {
		objectStore.metadata = {
			created: { label: 'Created', enabled: true },
			owner: { label: 'Owner' },
		}
		expect(host().vm.metadataColumns).toEqual([
			{ id: 'created', label: 'Created', enabled: true },
			{ id: 'owner', label: 'Owner' },
		])
	})
})
