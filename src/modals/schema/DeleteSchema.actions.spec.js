/**
 * The delete schema dialog's actions: the stats-based object count and its
 * register-by-register fallback, deleting the schema and showing the
 * outcome, and resetting on close. Methods run against a plain `this` with
 * stub stores.
 *
 * @spec exclude Client-side schema delete dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteSchema from './DeleteSchema.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	objectStore: { currentType: 'object', getCollection: jest.fn(() => []), setFilters: jest.fn(), refreshObjectList: jest.fn() },
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { getSchemaStats: jest.fn(), deleteSchema: jest.fn() },
}))

const { navigationStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * A plain dialog instance with its own and its mixins' data, and bound methods.
 *
 * @return {object} The instance
 */
function dialog() {
	const vm = {}
	for (const mixin of DeleteSchema.mixins) Object.assign(vm, mixin.data ? mixin.data.call(vm) : {})
	Object.assign(vm, DeleteSchema.data.call(vm))
	for (const mixin of DeleteSchema.mixins) {
		for (const [name, fn] of Object.entries(mixin.methods || {})) vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(DeleteSchema.methods)) vm[name] = fn.bind(vm)
	return vm
}

const flush = () => new Promise((resolve) => jest.requireActual('timers').setImmediate(resolve))

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
	schemaStore.schemaItem = { id: 7, title: 'Person' }
	registerStore.registerList = [
		{ id: 1, title: 'Other', schemas: [3] },
		{ id: 2, title: 'People', schemas: [7] },
		{ id: 3, title: 'Archive', schemas: [7] },
	]
})

afterEach(() => jest.useRealTimers())

describe('DeleteSchema.countFromStats', () => {
	it('fills one placeholder per counted object and names the first register holding the schema', async () => {
		schemaStore.getSchemaStats.mockResolvedValueOnce({ objects: { total: 3 } })
		const vm = dialog()
		await vm.countFromStats()
		expect(schemaStore.getSchemaStats).toHaveBeenCalledWith(7)
		expect(vm.registerName).toBe('People')
		expect(vm.objects).toEqual([
			{ id: '...', name: 'Object' },
			{ id: '...', name: 'Object' },
			{ id: '...', name: 'Object' },
		])
	})

	it('leaves the dialog empty when the stats count no objects', async () => {
		schemaStore.getSchemaStats.mockResolvedValueOnce({ objects: { total: 0 } })
		const vm = dialog()
		await vm.countFromStats()
		expect(vm.objects).toEqual([])
		expect(vm.registerName).toBe('')
	})

	it('treats stats without an object block as zero', async () => {
		schemaStore.getSchemaStats.mockResolvedValueOnce({})
		const vm = dialog()
		await vm.countFromStats()
		expect(vm.objects).toEqual([])
	})

	it('leaves the dialog empty when no register holds the schema', async () => {
		registerStore.registerList = [{ id: 1, title: 'Other', schemas: [3] }]
		schemaStore.getSchemaStats.mockResolvedValueOnce({ objects: { total: 5 } })
		const vm = dialog()
		await vm.countFromStats()
		expect(vm.objects).toEqual([])
		expect(vm.registerName).toBe('')
	})
})

describe('DeleteSchema.initDialog', () => {
	it('stops after refreshing when there are no registers', async () => {
		registerStore.refreshRegisterList.mockImplementationOnce(async () => {
			registerStore.registerList = []
		})
		const vm = dialog()
		await vm.initDialog()
		expect(schemaStore.getSchemaStats).not.toHaveBeenCalled()
	})

	it('falls back to the registers one by one when the stats fail', async () => {
		jest.spyOn(console, 'warn').mockImplementation(() => {})
		const failure = new Error('stats down')
		schemaStore.getSchemaStats.mockRejectedValueOnce(failure)
		const vm = dialog()
		const visited = []
		vm.collectFromRegister = jest.fn(async (reg) => visited.push(reg.id))
		await vm.initDialog()
		expect(console.warn).toHaveBeenCalledWith(
			'Could not load schema stats, falling back to individual register checks:',
			failure,
		)
		expect(visited).toEqual([2, 3])
		console.warn.mockRestore()
	})
})

describe('DeleteSchema.deleteSchema', () => {
	it('deletes a copy of the active schema and closes two seconds after success', async () => {
		schemaStore.deleteSchema.mockResolvedValueOnce({ response: { ok: true } })
		const vm = dialog()
		vm.deleteSchema()
		expect(vm.loading).toBe(true)
		const sent = schemaStore.deleteSchema.mock.calls[0][0]
		expect(sent).toEqual({ id: 7, title: 'Person' })
		expect(sent).not.toBe(schemaStore.schemaItem)
		await flush()
		expect(vm.success).toBe(true)
		expect(vm.loading).toBe(false)
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('stays open after a refused delete', async () => {
		schemaStore.deleteSchema.mockResolvedValueOnce({ response: { ok: false } })
		const vm = dialog()
		vm.deleteSchema()
		await flush()
		expect(vm.success).toBe(false)
		jest.advanceTimersByTime(5000)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('shows the error message, or a fallback without one', async () => {
		schemaStore.deleteSchema.mockRejectedValueOnce(new Error('In use'))
		const vm = dialog()
		vm.deleteSchema()
		await flush()
		expect(vm.error).toBe('In use')

		schemaStore.deleteSchema.mockRejectedValueOnce({})
		vm.deleteSchema()
		await flush()
		expect(vm.error).toBe('An error occurred while deleting the schema')
		expect(vm.loading).toBe(false)
	})
})

describe('DeleteSchema.closeDialog', () => {
	it('closes the dialog and resets the counted objects', () => {
		const vm = dialog()
		Object.assign(vm, { success: true, loading: true, error: 'x', objects: [{}], registerName: 'People', isUpdated: true })
		vm.closeDialog()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(vm).toMatchObject({ success: false, loading: false, error: false, objects: [], registerName: '', isUpdated: false })
	})
})
