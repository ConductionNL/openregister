/**
 * The record modal's form and file helpers: the JSON editor sync, array and
 * object field edits, file selection and paging, batch and single file
 * deletion, the label editor with its optimistic save, and copying.
 * Methods run against a plain `this` and stub stores.
 *
 * @spec exclude Client-side record modal form and file handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ViewObject from './ViewObject.vue'
import { updateFileLabels } from '../../services/fileMetadata.js'

jest.mock('../../store/store.js', () => ({
	objectStore: {
		objectItem: null,
		objectTypes: [],
		files: null,
		registerObjectType: jest.fn(),
		fetchFiles: jest.fn(),
		setObjectItem: jest.fn(),
		deleteFile: jest.fn(),
		fetchTags: jest.fn(),
		getTags: [],
	},
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: { registerItem: null, setRegisterItem: jest.fn(), getRegister: jest.fn() },
	schemaStore: { schemaItem: null, schemaList: [], setSchemaItem: jest.fn(), getSchema: jest.fn() },
	dashboardStore: {},
	organisationStore: {},
}))
jest.mock('@codemirror/lang-json', () => ({ json: jest.fn(), jsonParseLinter: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({ showWarning: jest.fn() }))
jest.mock('../../services/fileMetadata.js', () => ({ updateFileLabels: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('vue-codemirror6', () => ({}))
jest.mock('vue-material-design-icons/Alert.vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/ExclamationThick.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Tag.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('../../components/i18n/TranslationFieldEditor.vue', () => ({}))
jest.mock('../../components/object-relations/ContactsTab.vue', () => ({}))
jest.mock('../../components/object-relations/DeckTab.vue', () => ({}))
jest.mock('../../components/object-relations/EmailsTab.vue', () => ({}))
jest.mock('../../components/object-relations/EventsTab.vue', () => ({}))
jest.mock('../../components/object-relations/RelationsTab.vue', () => ({}))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))

const { objectStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = ViewObject

/**
 * A plain component context: computed values as getters, methods bound.
 *
 * @param {object} state Data, and computed values or methods to replace
 * @return {object} The context
 */
function modal(state = {}) {
	const vm = {
		formData: {},
		selectedAttachments: [],
		filesCurrentPage: 1,
		filesPerPage: 2,
		fileIdsLoading: [],
		propertyChangeDebounceTimers: {},
	}
	for (const [name, fn] of Object.entries(computed)) {
		if (!(name in state)) {
			Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true, enumerable: true })
		}
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (typeof fn === 'function' && !(name in state)) vm[name] = fn.bind(vm)
	}
	for (const [key, value] of Object.entries(state)) {
		Object.defineProperty(vm, key, { value, writable: true, configurable: true, enumerable: true })
	}
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.objectItem = { id: 'o-1', '@self': { id: 'o-1', register: { id: 3 }, schema: 7 } }
	objectStore.objectTypes = ['3-7']
	delete objectStore.batchFiles
	objectStore.getTags = []
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => jest.restoreAllMocks())

describe('ViewObject JSON editor sync', () => {
	it('tells valid JSON from blank or broken text', () => {
		const vm = modal()

		expect(vm.isValidJson('{"a":1}')).toBe(true)
		expect(vm.isValidJson('[]')).toBe(true)
		expect(vm.isValidJson('')).toBe(false)
		expect(vm.isValidJson('   ')).toBe(false)
		expect(vm.isValidJson(null)).toBe(false)
		expect(vm.isValidJson('{a:1}')).toBe(false)
	})

	it('pretty-prints valid JSON and leaves broken JSON as typed', () => {
		const vm = modal({ jsonData: '{"a":1,"b":[1,2]}' })
		vm.formatJSON()
		expect(vm.jsonData).toBe('{\n  "a": 1,\n  "b": [\n    1,\n    2\n  ]\n}')

		vm.jsonData = '{broken'
		vm.formatJSON()
		expect(vm.jsonData).toBe('{broken')
	})

	it('fills the form from the JSON and releases the guard on the next tick', () => {
		const ticks = []
		const vm = modal({ jsonData: '{"name":"Ada"}', isInternalUpdate: false, error: null, $nextTick: (fn) => ticks.push(fn) })

		vm.updateFormFromJson()

		expect(vm.formData).toEqual({ name: 'Ada' })
		expect(vm.isInternalUpdate).toBe(true)
		ticks.forEach((fn) => fn())
		expect(vm.isInternalUpdate).toBe(false)
	})

	it('reports broken JSON without touching the form', () => {
		const vm = modal({ jsonData: '{oops', isInternalUpdate: false, error: null, formData: { keep: 1 }, $nextTick: (fn) => fn() })

		vm.updateFormFromJson()

		expect(vm.error).toBe('Invalid JSON format')
		expect(vm.formData).toEqual({ keep: 1 })
		expect(vm.isInternalUpdate).toBe(false)
	})

	it('skips both directions while an update is in flight', () => {
		const vm = modal({ jsonData: '{"a":2}', isInternalUpdate: true, formData: { a: 1 }, $nextTick: jest.fn() })

		vm.updateFormFromJson()
		vm.updateJsonFromForm()

		expect(vm.formData).toEqual({ a: 1 })
		expect(vm.jsonData).toBe('{"a":2}')
		expect(vm.$nextTick).not.toHaveBeenCalled()
	})

	it('writes the form back into the JSON editor', () => {
		const vm = modal({ jsonData: '', isInternalUpdate: false, formData: { a: 1 }, $nextTick: (fn) => fn() })

		vm.updateJsonFromForm()

		expect(vm.jsonData).toBe('{\n  "a": 1\n}')
		expect(vm.isInternalUpdate).toBe(false)
	})
})

describe('ViewObject field edits', () => {
	it('sets a field and maps empty input to null and back', () => {
		const vm = modal()

		vm.setFieldValue('name', 'Ada')
		expect(vm.formData.name).toBe('Ada')
		expect(vm.toDisplay(null)).toBe('')
		expect(vm.toDisplay(0)).toBe(0)
		expect(vm.toPayload('')).toBeNull()
		expect(vm.toPayload('x')).toBe('x')
	})

	it('edits array items, creating the array when missing', () => {
		const vm = modal({ formData: { tags: 'not-an-array' } })

		vm.updateArrayItem('list', 1, 'b')
		expect(vm.formData.list).toEqual([undefined, 'b'])

		vm.addArrayItem('tags')
		vm.addArrayItem('tags')
		expect(vm.formData.tags).toEqual(['', ''])

		vm.formData.tags = ['a', 'b', 'c']
		vm.removeArrayItem('tags', 1)
		expect(vm.formData.tags).toEqual(['a', 'c'])

		vm.removeArrayItem('missing', 0)
		expect(vm.formData.missing).toBeUndefined()
	})

	it('keeps the raw object editor text and parses it into the form when valid', () => {
		const vm = modal({ objectEditors: {}, formData: { meta: { old: true } } })

		vm.updateObjectField('meta', '{"new":true}')
		expect(vm.objectEditors.meta).toBe('{"new":true}')
		expect(vm.formData.meta).toEqual({ new: true })

		vm.updateObjectField('meta', '{half')
		expect(vm.objectEditors.meta).toBe('{half')
		expect(vm.formData.meta).toEqual({ new: true })
	})
})

describe('ViewObject file selection and paging', () => {
	it('selects and clears the current page only', () => {
		const vm = modal({ paginatedFiles: [{ id: 1 }, { id: 2 }], selectedAttachments: [2, 9] })

		vm.toggleSelectAllFiles(true)
		expect(vm.selectedAttachments).toEqual([2, 9, 1])

		vm.toggleSelectAllFiles(false)
		expect(vm.selectedAttachments).toEqual([9])
	})

	it('toggles one file without duplicates', () => {
		const vm = modal({ selectedAttachments: [] })

		vm.toggleFileSelection(4, true)
		vm.toggleFileSelection(4, true)
		expect(vm.selectedAttachments).toEqual([4])

		vm.toggleFileSelection(4, false)
		expect(vm.selectedAttachments).toEqual([])
	})

	it('changes page, and goes back to page one on a new page size', () => {
		const vm = modal({ filesCurrentPage: 1, filesPerPage: 10 })

		vm.onFilesPageChanged(3)
		expect(vm.filesCurrentPage).toBe(3)

		vm.onFilesPageSizeChanged(50)
		expect(vm.filesPerPage).toBe(50)
		expect(vm.filesCurrentPage).toBe(1)
	})

	it('closes the modal and opens the audit trails', () => {
		const vm = modal({ closeModal: jest.fn(), $router: { push: jest.fn() } })

		vm.viewAuditTrails()

		expect(vm.closeModal).toHaveBeenCalled()
		expect(vm.$router.push).toHaveBeenCalledWith('/audit-trails')
	})
})

describe('ViewObject file deletion', () => {
	it('deletes the selection in one batch call when the store offers it', async () => {
		objectStore.batchFiles = jest.fn().mockResolvedValue(undefined)
		const vm = modal({ selectedAttachments: [1, 2] })

		await vm.deleteSelectedFiles()

		expect(objectStore.batchFiles).toHaveBeenCalledWith('3-7', 'o-1', 'delete', [1, 2])
		expect(objectStore.deleteFile).not.toHaveBeenCalled()
		expect(vm.selectedAttachments).toEqual([])
		expect(vm.fileIdsLoading).toEqual([])
	})

	it('falls back to one delete per file without a batch action', async () => {
		objectStore.deleteFile.mockResolvedValue(undefined)
		const vm = modal({ selectedAttachments: [1, 2] })

		await vm.deleteSelectedFiles()

		expect(objectStore.deleteFile.mock.calls).toEqual([['3-7', 'o-1', 1], ['3-7', 'o-1', 2]])
		expect(vm.selectedAttachments).toEqual([])
	})

	it('keeps the selection when the deletion fails', async () => {
		objectStore.deleteFile.mockRejectedValue(new Error('locked'))
		const vm = modal({ selectedAttachments: [1] })

		await vm.deleteSelectedFiles()

		expect(vm.selectedAttachments).toEqual([1])
		expect(vm.fileIdsLoading).toEqual([])
		expect(console.error).toHaveBeenCalledWith('Failed to delete selected files:', expect.any(Error))
	})

	it('does nothing without a selection', async () => {
		objectStore.batchFiles = jest.fn()
		const vm = modal({ selectedAttachments: [] })

		await vm.deleteSelectedFiles()
		await vm._runBatchAction('delete', jest.fn())

		expect(objectStore.batchFiles).not.toHaveBeenCalled()
	})

	it('deletes one file and clears only its loading flag', async () => {
		objectStore.deleteFile.mockResolvedValue(undefined)
		const vm = modal({ fileIdsLoading: [8] })

		await vm.deleteFile({ id: 5 })

		expect(objectStore.deleteFile).toHaveBeenCalledWith('3-7', 'o-1', 5)
		expect(vm.fileIdsLoading).toEqual([8])
	})

	it('clears the loading flag when one file fails to delete', async () => {
		objectStore.deleteFile.mockRejectedValue(new Error('gone'))
		const vm = modal({ fileIdsLoading: [] })

		await vm.deleteFile({ id: 5 })

		expect(vm.fileIdsLoading).toEqual([])
		expect(console.error).toHaveBeenCalledWith('Failed to delete file:', expect.any(Error))
	})
})

describe('ViewObject file labels', () => {
	it('opens the label editor with a copy of the labels and the known tags', async () => {
		objectStore.fetchTags.mockResolvedValue(['a', 'b'])
		const file = { id: 5, labels: ['a'] }
		const vm = modal()

		await vm.editFileLabels(file)

		expect(vm.editingLabelsFileId).toBe(5)
		expect(vm.editingLabels).toEqual(['a'])
		expect(vm.editingLabels).not.toBe(file.labels)
		expect(vm.availableLabels).toEqual(['a', 'b'])
	})

	it('falls back to the store tags when the fetch answers no list', async () => {
		objectStore.fetchTags.mockResolvedValue(undefined)
		objectStore.getTags = ['stored']
		const vm = modal()

		await vm.editFileLabels({ id: 6 })

		expect(vm.editingLabels).toEqual([])
		expect(vm.availableLabels).toEqual(['stored'])
	})

	it('cancels the label editor', () => {
		const vm = modal({ editingLabelsFileId: 5, editingLabels: ['a'] })

		vm.cancelFileLabels()

		expect(vm.editingLabelsFileId).toBeNull()
		expect(vm.editingLabels).toEqual([])
	})

	it('saves the labels, refetches the files and closes the editor', async () => {
		updateFileLabels.mockResolvedValue({})
		objectStore.fetchFiles.mockResolvedValue(undefined)
		const file = { id: 5, labels: ['a'] }
		const vm = modal({ editingLabelsFileId: 5, editingLabels: ['a', 'b'], labelsLoading: false })

		await vm.saveFileLabels(file)

		expect(updateFileLabels).toHaveBeenCalledWith({ registerId: 3, schemaId: 7, objectId: 'o-1', fileId: 5, labels: ['a', 'b'] })
		expect(objectStore.fetchFiles).toHaveBeenCalledWith('3-7', 'o-1')
		expect(file.labels).toEqual(['a', 'b'])
		expect(vm.editingLabelsFileId).toBeNull()
		expect(vm.labelsLoading).toBe(false)
	})

	it('reverts the labels and keeps the editor open when saving fails', async () => {
		updateFileLabels.mockRejectedValue(new Error('denied'))
		const file = { id: 5, labels: ['a'] }
		const vm = modal({ editingLabelsFileId: 5, editingLabels: ['b'], labelsLoading: false })

		await vm.saveFileLabels(file)

		expect(file.labels).toEqual(['a'])
		expect(objectStore.fetchFiles).not.toHaveBeenCalled()
		expect(vm.editingLabelsFileId).toBe(5)
		expect(vm.labelsLoading).toBe(false)
	})

	it('reverts to no labels when the file had none', async () => {
		updateFileLabels.mockRejectedValue(new Error('denied'))
		const file = { id: 5 }
		const vm = modal({ editingLabels: ['b'] })

		await vm.saveFileLabels(file)

		expect(file.labels).toEqual([])
	})
})

describe('ViewObject copy', () => {
	it('copies the text and resets the copied flag after two seconds', async () => {
		jest.useFakeTimers()
		const writeText = jest.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const vm = modal({ isCopied: false })

		await vm.copyToClipboard('o-1')
		expect(writeText).toHaveBeenCalledWith('o-1')
		expect(vm.isCopied).toBe(true)

		jest.advanceTimersByTime(2000)
		expect(vm.isCopied).toBe(false)
		jest.useRealTimers()
	})

	it('logs a copy that the browser refuses', async () => {
		Object.defineProperty(navigator, 'clipboard', { value: { writeText: jest.fn().mockRejectedValue(new Error('denied')) }, configurable: true })
		const vm = modal({ isCopied: false })

		await vm.copyToClipboard('o-1')

		expect(vm.isCopied).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Failed to copy text:', expect.any(Error))
	})
})
