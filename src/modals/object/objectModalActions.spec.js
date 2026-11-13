/**
 * The object modals' own actions: loading the upload form's options, closing
 * and resetting each modal, prettifying JSON, and the bulk delete's outcomes.
 *
 * @spec exclude Unit coverage of client-side object modal handlers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DownloadObject from './DownloadObject.vue'
import LockObject from './LockObject.vue'
import MassDeleteObject from './MassDeleteObject.vue'
import UploadObject from './UploadObject.vue'
import { navigationStore, objectStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/AutoFix.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))

/**
 * A bare component context with the component's (and its mixins') methods
 * bound to it.
 *
 * @param {object} component The component options
 * @param {object} state Extra state
 * @return {object} The context
 */
function context(component, state = {}) {
	const ctx = {}
	for (const mixin of component.mixins || []) {
		Object.assign(ctx, mixin.data ? mixin.data.call(ctx) : {})
		for (const [name, method] of Object.entries(mixin.methods || {})) {
			ctx[name] = method.bind(ctx)
		}
	}
	Object.assign(ctx, component.data.call(ctx), state)
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = typeof method === 'function' ? method.bind(ctx) : method
	}
	return ctx
}

/**
 * Let every pending promise callback run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 6; i++) {
		await Promise.resolve()
	}
}

beforeEach(() => {
	jest.useFakeTimers()
	jest.clearAllMocks()
	for (const store of [objectStore, registerStore, schemaStore]) {
		for (const key of Object.keys(store)) delete store[key]
	}
})
afterEach(() => jest.useRealTimers())

describe('UploadObject option loaders', () => {
	it('turns the mappings into single-select options', async () => {
		objectStore.getMappings = jest.fn(() => Promise.resolve({ data: [{ id: 4, name: 'Map A' }, { id: 5, name: 'Map B' }] }))
		const ctx = context(UploadObject)
		ctx.initializeMappings()
		expect(ctx.mappingsLoading).toBe(true)
		await flush()
		expect(ctx.mappings).toEqual({
			multiple: false,
			closeOnSelect: true,
			options: [{ id: 4, label: 'Map A' }, { id: 5, label: 'Map B' }],
			value: null,
		})
		expect(ctx.mappingsLoading).toBe(false)
	})

	it('labels the schemas by title once the list is refreshed', async () => {
		schemaStore.refreshSchemaList = jest.fn(() => {
			schemaStore.schemaList = [{ id: 7, title: 'Person' }]
			return Promise.resolve()
		})
		const ctx = context(UploadObject)
		ctx.initializeSchemas()
		expect(ctx.schemasLoading).toBe(true)
		await flush()
		expect(ctx.schemas.options).toEqual([{ id: 7, label: 'Person' }])
		expect(ctx.schemas.value).toBeNull()
		expect(ctx.schemasLoading).toBe(false)
	})

	it('labels the registers by title once the list is refreshed', async () => {
		registerStore.refreshRegisterList = jest.fn(() => {
			registerStore.registerList = [{ id: 3, title: 'Main' }, { id: 8, title: 'Archive' }]
			return Promise.resolve()
		})
		const ctx = context(UploadObject)
		ctx.initializeRegisters()
		expect(ctx.registersLoading).toBe(true)
		await flush()
		expect(ctx.registers.options).toEqual([{ id: 3, label: 'Main' }, { id: 8, label: 'Archive' }])
		expect(ctx.registers.multiple).toBe(false)
		expect(ctx.registersLoading).toBe(false)
	})

	it('starts all three loaders when mounted', () => {
		const ctx = {
			initializeMappings: jest.fn(),
			initializeSchemas: jest.fn(),
			initializeRegisters: jest.fn(),
		}
		UploadObject.mounted.call(ctx)
		expect(ctx.initializeMappings).toHaveBeenCalledTimes(1)
		expect(ctx.initializeSchemas).toHaveBeenCalledTimes(1)
		expect(ctx.initializeRegisters).toHaveBeenCalledTimes(1)
	})
})

describe('UploadObject form helpers', () => {
	it('closes the modal and resets the form', () => {
		const ctx = context(UploadObject, { success: true, loading: true, error: 'x', hasUpdated: true, object: '{"a":1}' })
		ctx.closeModal()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx.success).toBeNull()
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBe(false)
		expect(ctx.hasUpdated).toBe(false)
		expect(ctx.object).toEqual({ json: '{}', url: '' })
	})

	it('indents the JSON by two spaces', () => {
		const ctx = context(UploadObject, { object: '{"a":1,"b":[2]}' })
		ctx.prettifyJson()
		expect(ctx.object).toBe('{\n  "a": 1,\n  "b": [\n    2\n  ]\n}')
	})

	it('throws on JSON it cannot prettify, leaving the text', () => {
		const ctx = context(UploadObject, { object: '{bad' })
		expect(() => ctx.prettifyJson()).toThrow(SyntaxError)
		expect(ctx.object).toBe('{bad')
	})
})

describe('LockObject.closeModal', () => {
	it('closes, cancels the pending close and resets the form', () => {
		const ctx = context(LockObject, { process: 'review', duration: 60, success: true, loading: true, error: 'x' })
		ctx.closeModalTimeout = setTimeout(() => { throw new Error('should be cancelled') }, 2000)
		ctx.closeModal()
		jest.advanceTimersByTime(5000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx).toMatchObject({ process: '', duration: 3600, success: null, loading: false, error: null })
	})
})

describe('DownloadObject lifecycle', () => {
	it('starts the download when mounted with an object', () => {
		objectStore.objectItem = { id: 'obj-1' }
		const ctx = { downloadObject: jest.fn() }
		DownloadObject.mounted.call(ctx)
		expect(ctx.downloadObject).toHaveBeenCalledTimes(1)
	})

	it('does nothing when mounted without an object id', () => {
		objectStore.objectItem = {}
		const ctx = { downloadObject: jest.fn() }
		DownloadObject.mounted.call(ctx)
		expect(ctx.downloadObject).not.toHaveBeenCalled()
	})

	it('closes, cancels the pending close and resets its state', () => {
		const ctx = context(DownloadObject, { success: true, loading: true, error: 'x' })
		const closer = jest.fn()
		ctx.closeModalTimeout = setTimeout(closer, 2000)
		ctx.closeDialog()
		jest.advanceTimersByTime(5000)
		expect(closer).not.toHaveBeenCalled()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx).toMatchObject({ success: null, loading: false, error: false })
	})
})

describe('MassDeleteObject', () => {
	/**
	 * A delete dialog with two selected objects.
	 *
	 * @return {object} The context
	 */
	function dialog() {
		objectStore.refetchSearchCollection = jest.fn()
		objectStore.refreshObjectList = jest.fn(() => Promise.resolve())
		objectStore.selectedObjects = ['a', 'b']
		const ctx = context(MassDeleteObject, { selectedObjects: [{ id: 'a' }, { id: 'b' }] })
		ctx.$router = { push: jest.fn() }
		return ctx
	}

	it('deletes the selected ids, refreshes the lists and closes', async () => {
		const result = { successfulIds: ['a', 'b'], failedIds: [] }
		objectStore.massDeleteObject = jest.fn(() => Promise.resolve(result))
		const ctx = dialog()
		ctx.deleteObject()
		expect(ctx.loading).toBe(true)
		await flush()
		expect(objectStore.massDeleteObject).toHaveBeenCalledWith(['a', 'b'])
		expect(ctx).toMatchObject({ result, success: true, error: false, loading: false, startClosing: true })
		expect(objectStore.selectedObjects).toEqual([])
		expect(objectStore.refetchSearchCollection).toHaveBeenCalledTimes(1)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('stays open and reports when nothing was deleted', async () => {
		objectStore.massDeleteObject = jest.fn(() => Promise.resolve({ successfulIds: [], failedIds: ['a', 'b'] }))
		const ctx = dialog()
		ctx.deleteObject()
		await flush()
		expect(ctx).toMatchObject({ success: false, error: true, loading: false })
		expect(objectStore.refetchSearchCollection).not.toHaveBeenCalled()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('reports both outcomes on a partial delete and still closes', async () => {
		objectStore.massDeleteObject = jest.fn(() => Promise.resolve({ successfulIds: ['a'], failedIds: ['b'] }))
		const ctx = dialog()
		ctx.deleteObject()
		await flush()
		expect(ctx).toMatchObject({ success: true, error: true })
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('shows the reason a delete was refused', async () => {
		objectStore.massDeleteObject = jest.fn(() => Promise.reject(new Error('Forbidden')))
		const ctx = dialog()
		ctx.deleteObject()
		await flush()
		expect(ctx).toMatchObject({ success: false, error: 'Forbidden', loading: false })
	})

	it('falls back to a generic message for a failure without one', async () => {
		objectStore.massDeleteObject = jest.fn(() => Promise.reject({}))
		const ctx = dialog()
		ctx.deleteObject()
		await flush()
		expect(ctx.error).toBe('An error occurred while deleting the object')
	})

	it('closes, then goes to the deleted objects', () => {
		const ctx = dialog()
		const closer = jest.fn()
		ctx.closeModalTimeout = setTimeout(closer, 2000)
		ctx.navigateToDeleted()
		jest.advanceTimersByTime(5000)
		expect(closer).not.toHaveBeenCalled()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(ctx.$router.push).toHaveBeenCalledWith('/deleted')
	})
})
