/**
 * ImportRegister's logic: file-type checks on upload, the import run and its
 * failure, which file types need which scope, register/schema changes, the
 * mount-time loads, closing, and the per-sheet error helpers.
 *
 * @spec exclude Unit spec of client-side dialog logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ImportRegister from './ImportRegister.vue'
import {
	dashboardStore,
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	dashboardStore: { preload: jest.fn() },
	navigationStore: { setModal: jest.fn() },
	objectStore: { refreshObjectList: jest.fn(), initializeProperties: jest.fn() },
	registerStore: {
		registerItem: null,
		registerList: [],
		importRegister: jest.fn(),
		refreshRegisterList: jest.fn(),
		setRegisterItem: jest.fn(),
	},
	schemaStore: {
		schemaItem: null,
		schemaList: [],
		refreshSchemaList: jest.fn(),
		setSchemaItem: jest.fn(),
	},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/Import.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))

/**
 * A component context: data, computed getters and bound methods.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { ...ImportRegister.data() }
	for (const [name, getter] of Object.entries(ImportRegister.computed)) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), enumerable: false })
	}
	for (const [name, method] of Object.entries(ImportRegister.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const file = (name, size = 10) => ({ name, size })

beforeEach(() => {
	jest.clearAllMocks()
	registerStore.registerItem = null
	registerStore.registerList = []
	schemaStore.schemaItem = null
	schemaStore.schemaList = []
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	console.error.mockRestore()
})

describe('ImportRegister file choice', () => {
	it('keeps an allowed file and clears an earlier error', () => {
		const ctx = context()
		ctx.error = 'old'
		ctx.handleFileUpload({ target: { files: [file('Data.XLSX')] } })
		expect(ctx.selectedFile).toEqual(file('Data.XLSX'))
		expect(ctx.error).toBeNull()
		expect(ctx.isValidFileType).toBe(true)
	})

	it('refuses a disallowed file with the allowed types listed', () => {
		const ctx = context()
		ctx.selectedFile = file('a.json')
		ctx.handleFileUpload({ target: { files: [file('notes.txt')] } })
		expect(ctx.selectedFile).toBeNull()
		expect(ctx.error).toBe('Invalid file type: notes.txt. Please select a .json, .xlsx, .xls, .csv file.')
	})

	it('clears the selection when the picker is cancelled', () => {
		const ctx = context()
		ctx.selectedFile = file('a.json')
		ctx.error = 'old'
		ctx.handleFileUpload({ target: { files: [] } })
		expect(ctx.selectedFile).toBeNull()
		expect(ctx.error).toBeNull()
		expect(ctx.isValidFileType).toBe(false)
	})
})

describe('ImportRegister import', () => {
	it('refuses to start without a valid file', async () => {
		const ctx = context()
		await ctx.importRegister()
		expect(ctx.error).toBe('Please select a valid file to import')
		expect(registerStore.importRegister).not.toHaveBeenCalled()

		ctx.selectedFile = file('notes.txt')
		await ctx.importRegister()
		expect(registerStore.importRegister).not.toHaveBeenCalled()
	})

	it.each([
		[{ responseData: { summary: { Sheet1: { created: [1] } } } }, { Sheet1: { created: [1] } }],
		[{ summary: { Sheet2: { updated: [1] } } }, { Sheet2: { updated: [1] } }],
		[{}, null],
		[undefined, null],
	])('records the summary of %p', async (result, summary) => {
		registerStore.importRegister.mockResolvedValue(result)
		const ctx = context()
		ctx.selectedFile = file('r.json')
		await ctx.importRegister()
		expect(registerStore.importRegister).toHaveBeenCalledWith(ctx.selectedFile, expect.any(Function))
		expect(ctx.importSummary).toEqual(summary)
		expect(ctx.importResults).toEqual(summary)
		expect(ctx.success).toBe(true)
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBeNull()
	})

	it('shows the heartbeat for a large file while the import runs and passes its status on', async () => {
		const ctx = context()
		let during = null
		registerStore.importRegister.mockImplementation(async (picked, onStatus) => {
			during = ctx.isHeartbeatActive
			onStatus({ healthy: true })
			return {}
		})
		ctx.selectedFile = file('big.csv', 500 * 1024 + 1)
		await ctx.importRegister()
		expect(during).toBe(true)
		expect(ctx.heartbeatStatus).toEqual({ healthy: true })
		expect(ctx.isHeartbeatActive).toBe(false)
	})

	it('does not show the heartbeat for a file of exactly 500 KB', async () => {
		const ctx = context()
		let during = null
		registerStore.importRegister.mockImplementation(async () => {
			during = ctx.isHeartbeatActive
			return {}
		})
		ctx.selectedFile = file('edge.csv', 500 * 1024)
		await ctx.importRegister()
		expect(during).toBe(false)
	})

	it.each([
		[new Error('Too large'), 'Too large'],
		[{}, 'Failed to import register'],
	])('reports a failed import %p', async (failure, message) => {
		registerStore.importRegister.mockRejectedValue(failure)
		const ctx = context()
		ctx.selectedFile = file('big.json', 600 * 1024)
		await ctx.importRegister()
		expect(ctx.error).toBe(message)
		expect(ctx.loading).toBe(false)
		expect(ctx.success).toBe(false)
		expect(ctx.isHeartbeatActive).toBe(false)
	})
})

describe('ImportRegister scope', () => {
	it.each([
		['r.json', null, null, true],
		['r.xlsx', null, null, false],
		['r.xlsx', { id: 1 }, null, true],
		['r.xls', { id: 1 }, null, true],
		['r.csv', { id: 1 }, null, false],
		['r.csv', { id: 1 }, { id: 2 }, true],
		['r.txt', { id: 1 }, { id: 2 }, false],
	])('%s with register %p and schema %p is complete: %p', (name, register, schema, complete) => {
		registerStore.registerItem = register
		schemaStore.schemaItem = schema
		const ctx = context()
		ctx.selectedFile = file(name)
		expect(ctx.checkDataCompleted()).toBe(complete)
	})

	it('is never complete without a file', () => {
		expect(context().checkDataCompleted()).toBe(false)
	})

	it('offers only the schemas of the chosen register, by id or object', () => {
		const ctx = context()
		expect(ctx.schemaOptions).toEqual({ options: [] })
		registerStore.registerItem = { id: 1, schemas: [2, { id: 3 }] }
		schemaStore.schemaList = [{ id: 1, title: 'A' }, { id: 2, title: 'B' }, { id: 3, title: 'C' }]
		expect(ctx.schemaOptions.options.map((option) => option.value ?? option.id)).toEqual([2, 3])
	})

	it('a chosen register clears the schema and reloads schemas only when it has some', async () => {
		schemaStore.refreshSchemaList.mockResolvedValue()
		const ctx = context()
		await ctx.handleRegisterChange({ id: 1, schemas: [] })
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 1, schemas: [] })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(null)
		expect(schemaStore.refreshSchemaList).not.toHaveBeenCalled()

		await ctx.handleRegisterChange({ id: 2, schemas: [5] })
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(ctx.schemaLoading).toBe(false)

		await ctx.handleRegisterChange(undefined)
		expect(registerStore.setRegisterItem).toHaveBeenLastCalledWith(null)
	})

	it('a failed schema reload is logged and stops loading', async () => {
		schemaStore.refreshSchemaList.mockRejectedValue(new Error('down'))
		const ctx = context()
		await ctx.refreshSchemas()
		expect(console.error).toHaveBeenCalledWith('Error loading schemas:', new Error('down'))
		expect(ctx.schemaLoading).toBe(false)
	})

	it('a chosen schema loads its objects; clearing it does not', async () => {
		const ctx = context()
		await ctx.handleSchemaChange({ id: 4 })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 4 })
		expect(objectStore.initializeProperties).toHaveBeenCalledWith({ id: 4 })
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)

		await ctx.handleSchemaChange(null)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
	})
})

describe('ImportRegister lifecycle', () => {
	it('mounting preloads and refreshes both lists, and objects when both are chosen', async () => {
		registerStore.refreshRegisterList.mockResolvedValue()
		schemaStore.refreshSchemaList.mockResolvedValue()
		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		const ctx = context()
		ImportRegister.mounted.call(ctx)
		expect(dashboardStore.preload).toHaveBeenCalled()
		expect(ctx.registerLoading).toBe(true)
		expect(ctx.schemaLoading).toBe(true)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
		await Promise.resolve()
		await Promise.resolve()
		expect(ctx.registerLoading).toBe(false)
		expect(ctx.schemaLoading).toBe(false)
	})

	it('mounting without a schema does not load objects', () => {
		registerStore.refreshRegisterList.mockResolvedValue()
		schemaStore.refreshSchemaList.mockResolvedValue()
		registerStore.registerItem = { id: 1 }
		ImportRegister.mounted.call(context())
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('closing resets the dialog to its closed state; staying open does nothing', () => {
		const ctx = context()
		ctx.selectedFile = file('a.json')
		ctx.success = true
		ctx.expandedSheets = { S: true }
		ctx.includeObjects = false

		ctx.handleDialogClose(true)
		expect(navigationStore.setModal).not.toHaveBeenCalled()

		ctx.handleDialogClose(false)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx.selectedFile).toBeNull()
		expect(ctx.success).toBe(false)
		expect(ctx.expandedSheets).toEqual({})
		expect(ctx.includeObjects).toBe(true)
	})
})

describe('ImportRegister sheet summaries', () => {
	it('knows whether any sheet has errors', () => {
		const ctx = context()
		expect(ctx.hasImportErrors).toBe(false)
		ctx.importResults = { A: { errors: [] }, B: {} }
		expect(ctx.hasImportErrors).toBe(false)
		ctx.importResults = { A: { errors: [] }, B: { errors: [{ type: 'x' }] } }
		expect(ctx.hasImportErrors).toBe(true)
	})

	it('counts, types and toggles per sheet', () => {
		const ctx = context()
		const sheet = {
			created: [1, 2],
			errors: [{ type: 'ValidationException' }, { type: 'ValidationException' }, { type: 'Other' }],
		}
		expect(ctx.countOf(sheet, 'created')).toBe(2)
		expect(ctx.countOf(sheet, 'updated')).toBe(0)
		expect(ctx.hasErrorType(sheet, 'Other')).toBe(true)
		expect(ctx.hasErrorType(sheet, 'Missing')).toBe(false)
		expect(ctx.hasErrorType({}, 'Other')).toBe(false)
		expect(ctx.getInvalidCount(sheet)).toBe(2)
		expect(ctx.getInvalidCount({ errors: 'x' })).toBe(0)
		expect(ctx.getInvalidCount({})).toBe(0)

		ctx.toggleDetails('S')
		ctx.toggleErrorDetails('S')
		expect(ctx.expandedSheets).toEqual({ S: true })
		expect(ctx.expandedErrors).toEqual({ S: true })
		ctx.toggleDetails('S')
		expect(ctx.expandedSheets).toEqual({ S: false })
	})

	it.each([
		[{ errors: [{ error: 'data is not a valid attribute of X' }] }, true],
		[{ errors: [{ error: 'ProcessingException thrown' }] }, true],
		[{ errors: [{ error: 'Invalid property foo' }] }, true],
		[{ errors: [{ error: 'Timeout' }, { type: 'x' }] }, false],
		[{ errors: 'x' }, false],
		[{}, false],
	])('flags cache-related errors in %p: %p', (sheet, expected) => {
		expect(context().isCacheRelatedError(sheet)).toBe(expected)
	})

	it.each([
		[0, '0 Bytes'],
		[512, '512 Bytes'],
		[1536, '1.5 KB'],
		[5 * 1024 * 1024, '5 MB'],
	])('formats %p bytes as %p', (bytes, text) => {
		expect(context().formatFileSize(bytes)).toBe(text)
	})
})
