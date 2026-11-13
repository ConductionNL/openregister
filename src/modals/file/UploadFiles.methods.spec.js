/**
 * The upload modal's small helpers: closing, the queue edits, the size and
 * name helpers, the object context it reads and the watchers it reacts with.
 *
 * @spec exclude Unit tests of client-side upload modal helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import UploadFiles from './UploadFiles.vue'
import { navigationStore, objectStore } from '../../store/store.js'
import { useFileSelection } from './../../composables/UseFileSelection.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: { objectItem: null },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('./../../composables/UseFileSelection.js', () => {
	const selection = {
		openFileUpload: jest.fn(),
		files: { value: null },
		reset: jest.fn(),
		setTags: jest.fn(),
	}
	return { useFileSelection: Object.assign(() => selection, { selection }) }
})
jest.mock('vue-material-design-icons/AlphaXCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Exclamation.vue', () => ({}))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Minus.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TagEdit.vue', () => ({}))
jest.mock('vue-material-design-icons/TrayArrowDown.vue', () => ({}))

/**
 * A modal context with the modal's methods and computeds bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function modal(state = {}) {
	const ctx = { ...UploadFiles.data.call({}), ...state }
	for (const [name, method] of Object.entries(UploadFiles.methods)) {
		ctx[name] = typeof method === 'function' ? method.bind(ctx) : method
	}
	for (const [name, getter] of Object.entries(UploadFiles.computed)) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), configurable: true })
	}
	return ctx
}

const { reset: mockReset, setTags: mockSetTags, files: mockFiles } = useFileSelection.selection

beforeEach(() => {
	mockReset.mockClear()
	mockSetTags.mockClear()
	navigationStore.setModal.mockClear()
	objectStore.objectItem = null
	mockFiles.value = null
})

describe('upload files helpers', () => {
	it('closes the modal and clears the outcome and the queue', () => {
		const ctx = modal({ success: true, error: 'boom' })

		ctx.closeModal()

		expect(ctx.success).toBeNull()
		expect(ctx.error).toBeNull()
		expect(mockReset).toHaveBeenCalledWith()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('splits a file name at its last dot', () => {
		const ctx = modal()

		expect(ctx.getFileNameAndExtension('report.final.pdf')).toEqual({ name: 'report.final', extension: 'pdf' })
		expect(ctx.getFileNameAndExtension('.env')).toEqual({ name: '', extension: 'env' })
	})

	it('treats only files above 512MB as too big', () => {
		const ctx = modal()

		expect(ctx.getTooBigFiles(536870480)).toBe(false)
		expect(ctx.getTooBigFiles(536870481)).toBe(true)
	})

	it('marks too-big queued files and reports whether there were any', () => {
		const ctx = modal()
		const small = { size: 10, status: 'pending' }
		const big = { size: 600000000, status: 'pending' }

		expect(ctx.checkForTooBigFiles(null)).toBe(false)
		expect(ctx.checkForTooBigFiles([small])).toBe(false)
		expect(small.status).toBe('pending')
		expect(ctx.checkForTooBigFiles([small, big])).toBe(true)
		expect(big.status).toBe('too_large')
		expect(small.status).toBe('pending')
	})

	it('saves edited tags on the file, re-uploads and leaves edit mode', () => {
		const ctx = modal({ editingTags: 'a.txt', editedTags: ['x'] })
		ctx.addAttachments = jest.fn()
		const file = { name: 'a.txt', tags: [], status: 'failed' }

		ctx.saveTags(file, ['x', 'y'])

		expect(file).toEqual({ name: 'a.txt', tags: ['x', 'y'], status: 'pending' })
		expect(ctx.addAttachments).toHaveBeenCalledWith()
		expect(ctx.editingTags).toBeNull()
		expect(ctx.editedTags).toEqual([])
	})

	it('removes a file from the queue and stops editing it', () => {
		const ctx = modal({ editingTags: 'a.txt' })

		ctx.removeFile('a.txt')

		expect(mockReset).toHaveBeenCalledWith('a.txt')
		expect(ctx.editingTags).toBeNull()
	})

	it('keeps editing another file when removing one', () => {
		const ctx = modal({ editingTags: 'b.txt' })

		ctx.removeFile('a.txt')

		expect(mockReset).toHaveBeenCalledWith('a.txt')
		expect(ctx.editingTags).toBe('b.txt')
	})

	it('is disabled when the object item has a download url or a title', () => {
		const item = (objectItem) => modal({ objectStore: { objectItem } })

		expect(item({ downloadUrl: 'https://x' }).checkIfDisabled()).toBe(true)
		expect(item({ title: 'Doc' }).checkIfDisabled()).toBe(true)
		expect(item({}).checkIfDisabled()).toBe(false)
	})
})

describe('upload files object context', () => {
	it('reads ids from the object @self, plain or as objects', () => {
		objectStore.objectItem = { id: 'fallback', '@self': { register: { id: 4 }, schema: 7, id: 'o-1' } }
		const ctx = modal()

		expect(ctx.objectItem).toBe(objectStore.objectItem)
		expect(ctx.registerId).toBe(4)
		expect(ctx.schemaId).toBe(7)
		expect(ctx.objectId).toBe('o-1')
		expect(ctx.hasObjectContext()).toBe(true)
	})

	it('falls back to the object id and reads plain ids', () => {
		objectStore.objectItem = { id: 'fallback', '@self': { register: 2, schema: { id: 9 } } }
		const ctx = modal()

		expect(ctx.registerId).toBe(2)
		expect(ctx.schemaId).toBe(9)
		expect(ctx.objectId).toBe('fallback')
	})

	it('has no context without an object', () => {
		const ctx = modal()

		expect(ctx.registerId).toBeUndefined()
		expect(ctx.schemaId).toBeUndefined()
		expect(ctx.objectId).toBeUndefined()
		expect(ctx.hasObjectContext()).toBe(false)
	})

	it('exposes the queued files', () => {
		mockFiles.value = [{ name: 'a' }]

		expect(modal().filesComputed).toBe(mockFiles.value)
	})
})

describe('upload files watchers', () => {
	it('uploads when files are queued, not when the queue empties', () => {
		const ctx = modal()
		ctx.addAttachments = jest.fn()
		const handler = UploadFiles.watch.filesComputed.handler.bind(ctx)

		handler([], null)
		handler(null, [])
		expect(ctx.addAttachments).not.toHaveBeenCalled()

		handler([{ name: 'a' }], [])
		expect(ctx.addAttachments).toHaveBeenCalledTimes(1)
	})

	it('passes the chosen labels to the file selection', () => {
		const handler = UploadFiles.watch.labelOptions.handler

		const tagged = modal()
		tagged.labelOptions.value = ['t1']
		handler.call(tagged)
		expect(mockSetTags).toHaveBeenLastCalledWith(['t1'])

		const unlabelled = modal()
		unlabelled.labelOptions.value = ['No label']
		handler.call(unlabelled)
		expect(mockSetTags).toHaveBeenLastCalledWith(null)
	})

	it('refuses to upload without an object context', async () => {
		const ctx = modal()
		ctx.uploadAll = jest.fn()

		await ctx.addAttachments()

		expect(ctx.error).toBe('Missing object context')
		expect(ctx.uploadAll).not.toHaveBeenCalled()
		expect(ctx.loading).toBe(false)
	})
})
