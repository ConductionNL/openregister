/**
 * The upload schema modal: prettifying the JSON, uploading a cleaned copy and
 * closing after a success, showing the error of a failed upload, and resetting
 * on close. Methods run against a plain `this` with a stub schema store.
 *
 * @spec exclude Client-side schema upload modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import UploadSchema from './UploadSchema.vue'

jest.mock('@codemirror/lang-json', () => ({ json: jest.fn(), jsonParseLinter: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-codemirror6', () => ({}))
jest.mock('vue-material-design-icons/AutoFix.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	schemaStore: { uploadSchema: jest.fn() },
}))

const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * A plain modal instance with its data and bound methods.
 *
 * @return {object} The instance
 */
function modal() {
	const vm = UploadSchema.data()
	for (const [name, fn] of Object.entries(UploadSchema.methods)) vm[name] = fn.bind(vm)
	return vm
}

const flush = () => new Promise((resolve) => jest.requireActual('timers').setImmediate(resolve))

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
})

afterEach(() => jest.useRealTimers())

describe('UploadSchema', () => {
	it('prettifies the JSON', () => {
		const vm = modal()
		vm.schema.json = '{"a":1,"b":[1,2]}'
		vm.prettifyJson()
		expect(vm.schema.json).toBe('{\n  "a": 1,\n  "b": [\n    1,\n    2\n  ]\n}')
	})

	it('uploads a compact copy and closes after a success', async () => {
		schemaStore.uploadSchema.mockResolvedValueOnce({ response: { ok: true } })
		const vm = modal()
		vm.schema = { json: '{\n  "title": "Person"\n}', url: '' }
		vm.uploadSchema()
		expect(vm.loading).toBe(true)
		expect(schemaStore.uploadSchema).toHaveBeenCalledWith({ json: '{"title":"Person"}', url: '' })
		await flush()
		expect(vm.success).toBe(true)
		expect(vm.loading).toBe(false)
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm.schema).toEqual({ json: '{}', url: '' })
	})

	it('stays open after a refused upload', async () => {
		schemaStore.uploadSchema.mockResolvedValueOnce({ response: { ok: false } })
		const vm = modal()
		vm.uploadSchema()
		await flush()
		expect(vm.success).toBe(false)
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('shows the error of a failed upload', async () => {
		schemaStore.uploadSchema.mockRejectedValueOnce(new Error('Invalid schema'))
		const vm = modal()
		vm.uploadSchema()
		await flush()
		expect(vm.error).toBe('Invalid schema')
		expect(vm.success).toBe(false)

		schemaStore.uploadSchema.mockRejectedValueOnce({})
		vm.uploadSchema()
		await flush()
		expect(vm.error).toBe('An error occurred while uploading the schema')
	})

	it('resets everything on close', () => {
		const vm = modal()
		Object.assign(vm, { success: true, loading: true, error: 'x', hasUpdated: true, schema: { json: '[]', url: 'u' } })
		vm.closeModal()
		expect(vm).toMatchObject({ success: null, loading: false, error: false, hasUpdated: false, schema: { json: '{}', url: '' } })
	})
})
