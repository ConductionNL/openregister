/**
 * The delete schema property dialog's actions: removing the property and its
 * required entry before saving, showing the outcome, closing two seconds
 * after an answer, and resetting on close. Methods run against a plain
 * `this` with stub stores.
 *
 * @spec exclude Client-side schema property delete dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteSchemaProperty from './DeleteSchemaProperty.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: {},
	registerStore: {},
	schemaStore: { saveSchema: jest.fn(), setSchemaPropertyKey: jest.fn() },
}))

const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * A plain dialog instance with its data and bound methods.
 *
 * @return {object} The instance
 */
function dialog() {
	const vm = { ...DeleteSchemaProperty.data.call({}) }
	for (const [name, fn] of Object.entries(DeleteSchemaProperty.methods)) vm[name] = fn.bind(vm)
	return vm
}

const flush = () => new Promise((resolve) => jest.requireActual('timers').setImmediate(resolve))

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
	schemaStore.schemaPropertyKey = 'email'
	schemaStore.schemaItem = {
		id: 7,
		title: 'Person',
		properties: { name: { type: 'string' }, email: { type: 'string' } },
		required: ['name', 'email'],
	}
})

afterEach(() => jest.useRealTimers())

describe('DeleteSchemaProperty.deleteProperty', () => {
	it('saves the schema without the property and without its required entry', async () => {
		schemaStore.saveSchema.mockResolvedValueOnce({ response: { ok: true } })
		const vm = dialog()
		vm.deleteProperty()
		expect(vm.loading).toBe(true)
		expect(schemaStore.saveSchema).toHaveBeenCalledWith({
			id: 7,
			title: 'Person',
			properties: { name: { type: 'string' } },
			required: ['name'],
		})
		await flush()
		expect(vm.loading).toBe(false)
		expect(vm.success).toBe(true)
	})

	it('closes the dialog two seconds after a successful save', async () => {
		schemaStore.saveSchema.mockResolvedValueOnce({ response: { ok: true } })
		const vm = dialog()
		vm.deleteProperty()
		await flush()
		jest.advanceTimersByTime(1999)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(schemaStore.setSchemaPropertyKey).toHaveBeenCalledWith(null)
		expect(vm.success).toBeNull()
	})

	it('records a refused save as unsuccessful', async () => {
		schemaStore.saveSchema.mockResolvedValueOnce({ response: { ok: false } })
		const vm = dialog()
		vm.deleteProperty()
		await flush()
		expect(vm.success).toBe(false)
		expect(vm.loading).toBe(false)
	})

	it('shows the error of a failed save and stays open', async () => {
		const err = new Error('Network down')
		schemaStore.saveSchema.mockRejectedValueOnce(err)
		const vm = dialog()
		vm.deleteProperty()
		await flush()
		expect(vm.error).toBe(err)
		expect(vm.loading).toBe(false)
		expect(vm.success).toBeNull()
		jest.advanceTimersByTime(5000)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})
})

describe('DeleteSchemaProperty.closeModal', () => {
	it('closes the modal, forgets the property and resets the state', () => {
		const vm = dialog()
		Object.assign(vm, { success: true, error: 'x', objects: [{ id: 1 }], isUpdated: true })
		vm.closeModal()
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(schemaStore.setSchemaPropertyKey).toHaveBeenCalledWith(null)
		expect(vm).toMatchObject({ success: null, error: false, objects: [], isUpdated: false })
	})

	it('cancels a pending automatic close', () => {
		const vm = dialog()
		const later = jest.fn()
		vm.closeModalTimeout = setTimeout(later, 2000)
		vm.closeModal()
		jest.advanceTimersByTime(2000)
		expect(later).not.toHaveBeenCalled()
	})
})
