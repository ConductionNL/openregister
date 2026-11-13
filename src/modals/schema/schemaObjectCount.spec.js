/**
 * The object count mixin of the schema modals: loading the count from the
 * stats endpoint and what it shows when that fails.
 *
 * @spec exclude Test of client-side modal state around a stats request.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { schemaObjectCount } from './schemaObjectCount.js'

jest.mock('../../store/store.js', () => ({
	schemaStore: { schemaItem: null, getSchemaStats: null },
}))

const { schemaStore } = jest.requireMock('../../store/store.js')

/**
 * The mixin's state and methods bound to a plain host.
 *
 * @return {object} The host
 */
function host() {
	const vm = {
		...schemaObjectCount.data(),
		logCountFailure: jest.fn(),
	}
	for (const [name, method] of Object.entries(schemaObjectCount.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

describe('schemaObjectCount', () => {
	beforeEach(() => {
		schemaStore.schemaItem = { id: 147 }
		schemaStore.getSchemaStats = jest.fn()
	})

	it('starts as loading with no count', () => {
		expect(schemaObjectCount.data()).toEqual({
			countLoading: true,
			countFailed: false,
			objectCount: 0,
			objectStats: null,
		})
	})

	it('exposes the schema store for its watcher', () => {
		expect(schemaObjectCount.computed.schemaStore()).toBe(schemaStore)
	})

	it('loads the total from the stats of the current schema', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({ objects: { total: 12, invalid: 2 } })
		const vm = host()

		await vm.loadObjectCount()

		expect(schemaStore.getSchemaStats).toHaveBeenCalledWith(147)
		expect(vm.objectStats).toEqual({ total: 12, invalid: 2 })
		expect(vm.objectCount).toBe(12)
		expect(vm.countLoading).toBe(false)
		expect(vm.countFailed).toBe(false)
	})

	it('counts zero when the stats carry no objects', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({})
		const vm = host()

		await vm.loadObjectCount()

		expect(vm.objectStats).toBeUndefined()
		expect(vm.objectCount).toBe(0)
		expect(vm.countFailed).toBe(false)
	})

	it('does not ask without a schema', async () => {
		schemaStore.schemaItem = null
		const vm = host()

		await vm.loadObjectCount()

		expect(schemaStore.getSchemaStats).not.toHaveBeenCalled()
		expect(vm.countLoading).toBe(false)
		expect(vm.objectCount).toBe(0)
	})

	it('marks the count failed and logs when the stats request fails', async () => {
		const error = new Error('offline')
		schemaStore.getSchemaStats.mockRejectedValue(error)
		const vm = host()
		vm.objectCount = 5
		vm.objectStats = { total: 5 }

		await vm.loadObjectCount()

		expect(vm.logCountFailure).toHaveBeenCalledWith(error)
		expect(vm.countFailed).toBe(true)
		expect(vm.objectCount).toBe(0)
		expect(vm.objectStats).toBeNull()
		expect(vm.countLoading).toBe(false)
	})

	it('resets a previous failure when loading again', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({ objects: { total: 1 } })
		const vm = host()
		vm.countFailed = true

		await vm.loadObjectCount()

		expect(vm.countFailed).toBe(false)
		expect(vm.objectCount).toBe(1)
	})

	describe('schema watcher', () => {
		const handler = schemaObjectCount.watch['schemaStore.schemaItem'].handler

		it('loads the count for a new schema while none is counted', () => {
			const vm = { objectCount: 0, loadObjectCount: jest.fn() }

			handler.call(vm, { id: 3 })

			expect(vm.loadObjectCount).toHaveBeenCalledTimes(1)
		})

		it('does not reload once counted or without a schema id', () => {
			const vm = { objectCount: 4, loadObjectCount: jest.fn() }

			handler.call(vm, { id: 3 })
			vm.objectCount = 0
			handler.call(vm, null)
			handler.call(vm, {})

			expect(vm.loadObjectCount).not.toHaveBeenCalled()
		})
	})
})
