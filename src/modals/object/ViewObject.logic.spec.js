/**
 * The record modal's derived state and helpers: the new-record scope, the
 * property rows, the schema with inherited properties, file paging and
 * selection, metadata rows, the relation context, titles, property messages
 * and the inline-edit gate. Computed values and methods run against a plain
 * `this` and stub stores.
 *
 * @spec exclude Client-side record modal state handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showWarning } from '@nextcloud/dialogs'
import ViewObject from './ViewObject.vue'

jest.mock('../../store/store.js', () => ({
	objectStore: {
		objectItem: null,
		objectTypes: [],
		files: null,
		registerObjectType: jest.fn(),
		fetchFiles: jest.fn(),
		setObjectItem: jest.fn(),
	},
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: { registerItem: null, setRegisterItem: jest.fn(), getRegister: jest.fn() },
	schemaStore: { schemaItem: null, schemaList: [], setSchemaItem: jest.fn(), getSchema: jest.fn() },
	dashboardStore: {},
	organisationStore: {},
}))
jest.mock('@codemirror/lang-json', () => ({ json: jest.fn(), jsonParseLinter: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({ showWarning: jest.fn() }))
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

const { objectStore, navigationStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')
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
	objectStore.objectItem = null
	objectStore.objectTypes = []
	objectStore.files = null
	objectStore.availableRegistersForNewObject = undefined
	objectStore.availableSchemasForNewObject = undefined
	registerStore.registerItem = null
	schemaStore.schemaItem = null
	schemaStore.schemaList = []
})

describe('ViewObject new-record scope', () => {
	it('asks for a register and schema only for a new record with a choice', () => {
		objectStore.availableRegistersForNewObject = [{ id: 1 }, { id: 2 }]
		objectStore.availableSchemasForNewObject = [{ id: 5 }]
		expect(modal({ registerSchemaSelectionConfirmed: false }).showRegisterSchemaSelection).toBe(true)
		expect(modal({ registerSchemaSelectionConfirmed: true }).showRegisterSchemaSelection).toBe(false)

		objectStore.availableRegistersForNewObject = [{ id: 1 }]
		expect(modal({ registerSchemaSelectionConfirmed: false }).showRegisterSchemaSelection).toBe(false)

		objectStore.objectItem = { '@self': { id: 'x' } }
		objectStore.availableRegistersForNewObject = [{ id: 1 }, { id: 2 }]
		expect(modal({ registerSchemaSelectionConfirmed: false }).showRegisterSchemaSelection).toBe(false)
	})

	it('can proceed with a single option or a made choice', () => {
		objectStore.availableRegistersForNewObject = [{ id: 1 }]
		objectStore.availableSchemasForNewObject = [{ id: 5 }, { id: 6 }]
		expect(modal().canProceedToProperties).toBeFalsy()
		expect(modal({ selectedSchemaForNewObject: { id: 6 } }).canProceedToProperties).toBeTruthy()
		expect(modal().availableRegisters).toEqual([{ id: 1 }])
	})

	it('confirms the chosen scope, defaulting to the first options', () => {
		objectStore.availableRegistersForNewObject = [{ id: 1 }]
		objectStore.availableSchemasForNewObject = [{ id: 5 }]
		const initializeData = jest.fn()
		const vm = modal({ initializeData, selectedRegisterForNewObject: null, selectedSchemaForNewObject: null })
		vm.confirmRegisterSchemaSelection()
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 1 })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 5 })
		expect(vm.registerSchemaSelectionConfirmed).toBe(true)
		expect(initializeData).toHaveBeenCalled()
	})
})

describe('ViewObject property rows and schema', () => {
	it('lists the record fields and then the schema fields it lacks', () => {
		objectStore.objectItem = { '@self': {}, id: 1, title: 'A' }
		const vm = modal({ currentSchema: { properties: { title: { type: 'string' }, count: { type: 'integer', default: 3 } } } })
		const rows = vm.objectProperties
		expect(rows[0]).toEqual(['title', 'A'])
		expect(rows.map(([key]) => key)).toEqual(['title', 'count'])
	})

	it('lists only the schema fields for a new record', () => {
		const vm = modal({ currentSchema: { properties: { a: { type: 'string' } } } })
		expect(vm.objectProperties.map(([key]) => key)).toEqual(['a'])
		expect(modal({ currentSchema: null }).objectProperties).toEqual([])
	})

	it('merges inherited allOf properties under the own ones', () => {
		schemaStore.schemaList = [{ id: 1, uuid: 'p', properties: { a: { title: 'parent' }, b: { title: 'b' } } }]
		schemaStore.schemaItem = { id: 2, allOf: ['p', { id: 1 }], properties: { a: { title: 'own' } } }
		expect(modal().currentSchema.properties).toEqual({ a: { title: 'own' }, b: { title: 'b' } })

		schemaStore.schemaItem = { id: 3, properties: { x: {} } }
		expect(modal().currentSchema).toBe(schemaStore.schemaItem)
	})

	it('reads the register languages only when they are a list', () => {
		registerStore.registerItem = { languages: ['nl', 'en'] }
		expect(modal().registerLanguages).toEqual(['nl', 'en'])
		registerStore.registerItem = { languages: 'nl' }
		expect(modal().registerLanguages).toEqual([])
	})

	it('derives form fields from the schema and typed record values', () => {
		objectStore.objectItem = { '@self': {}, id: 1, flag: true, n: 2, list: [], obj: {}, s: 'x', nothing: null, known: 'k' }
		const fields = modal({ currentSchema: { properties: { known: { type: 'string', title: 'Known' }, blank: null } } }).formFields
		expect(fields.known).toEqual({ type: 'string', title: 'Known' })
		expect(fields.blank).toEqual({ type: 'string' })
		expect(fields.flag.type).toBe('boolean')
		expect(fields.n.type).toBe('number')
		expect(fields.list.type).toBe('array')
		expect(fields.obj.type).toBe('object')
		expect(fields.s).toEqual({ type: 'string', title: 's', description: 'Property: s' })
		expect(fields.nothing.type).toBe('string')
		expect(fields['@self']).toBeUndefined()
		expect(fields.id).toBeUndefined()
	})

	it('shows the record as formatted JSON', () => {
		objectStore.objectItem = { a: 1 }
		expect(modal().editorContent).toBe('{\n  "a": 1\n}')
	})
})

describe('ViewObject files', () => {
	it('pages the files and counts the pages', () => {
		objectStore.files = { results: [{ id: 1 }, { id: 2 }, { id: 3 }] }
		expect(modal({ filesCurrentPage: 2 }).paginatedFiles).toEqual([{ id: 3 }])
		expect(modal().filesTotalPages).toBe(2)
		objectStore.files = null
		expect(modal().filesTotalPages).toBe(0)
	})

	it('tells all, some or no files on the page are selected', () => {
		objectStore.files = { results: [{ id: 1 }, { id: 2 }, { id: 3 }] }
		expect(modal({ selectedAttachments: [1, 2] }).allFilesSelected).toBe(true)
		expect(modal({ selectedAttachments: [1] }).allFilesSelected).toBe(false)
		expect(modal({ selectedAttachments: [1] }).someFilesSelected).toBe(true)
		expect(modal({ selectedAttachments: [1, 2] }).someFilesSelected).toBe(false)
		expect(modal().someFilesSelected).toBe(false)
		expect(modal({ fileIdsLoading: [1] }).loading).toBe(true)
	})

	it('reads register and schema ids from references or objects, and registers the file type', () => {
		objectStore.objectItem = { '@self': { id: 'o-1', register: { id: 3 }, schema: 7 } }
		const vm = modal()
		expect(vm._getRegisterSchemaIds()).toEqual({ registerId: 3, schemaId: 7 })
		expect(vm._getFileParams()).toEqual({ type: '3-7', objectId: 'o-1' })
		expect(objectStore.registerObjectType).toHaveBeenCalledWith('3-7', 7, 3)

		objectStore.objectTypes = ['3-7']
		jest.clearAllMocks()
		vm._getFileParams()
		expect(objectStore.registerObjectType).not.toHaveBeenCalled()
	})

	it('opens a file in the Files app at its folder', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => {})
		modal().openFile({ id: 9, path: '/admin/files/Docs/a b/x.pdf' })
		expect(open).toHaveBeenCalledWith('/index.php/apps/files/files/9?dir=%2FDocs%2Fa%20b&openfile=true', '_blank')
		open.mockRestore()
	})

	it('shortens long file names', () => {
		const vm = modal()
		expect(vm.truncateFileName('')).toBe('')
		expect(vm.truncateFileName('short.pdf')).toBe('short.pdf')
		expect(vm.truncateFileName('a'.repeat(30))).toBe('a'.repeat(22) + '...')
	})
})

describe('ViewObject metadata and titles', () => {
	it('lists the metadata rows with Not set for empty values', () => {
		const vm = modal({
			registerTitle: 'Reg',
			schemaTitle: '',
			formData: { id: 5, '@self': { version: '1.0', validation: null, owner: 'ann' } },
		})
		const rows = Object.fromEntries(vm.metadataProperties.map(([label, value]) => [label, value]))
		expect(rows).toMatchObject({
			ID: 5,
			Register: 'Reg',
			Schema: 'Not set',
			Version: '1.0',
			Created: 'Not set',
			Validation: 'Not validated',
			Owner: 'ann',
		})
		expect(vm.metadataProperties[0][2]).toBe(true)
	})

	it('reports validation as valid or invalid and has no rows without a record', () => {
		const valid = modal({ formData: { '@self': { validation: { ok: 1 } } } }).metadataProperties
		expect(valid.find(([label]) => label === 'Validation')[1]).toBe('Valid')
		const invalid = modal({ formData: { '@self': { validation: false } } }).metadataProperties
		expect(invalid.find(([label]) => label === 'Validation')[1]).toBe('Invalid')
		expect(modal({ formData: null }).metadataProperties).toEqual([])
	})

	it('titles a new record and an existing one with its schema', () => {
		expect(modal().getModalTitle()).toBe('Add Object')
		objectStore.objectItem = { '@self': { id: 1, name: 'Case 1' } }
		expect(modal({ currentSchema: { title: 'Case' } }).getModalTitle()).toBe('Case 1 (Case)')
		objectStore.objectItem = { '@self': { id: 2 }, name: 'Named' }
		expect(modal({ currentSchema: null }).getModalTitle()).toBe('Named (Unknown Schema)')
	})

	it('loads the register and schema titles, or Not set without a record', async () => {
		const vm = modal()
		await vm.loadTitles()
		expect(vm.registerTitle).toBe('Not set')
		expect(vm.schemaTitle).toBe('Not set')

		objectStore.objectItem = { '@self': { register: 1, schema: 2 } }
		registerStore.getRegister.mockResolvedValue({ title: 'Reg' })
		schemaStore.getSchema.mockResolvedValue(null)
		await vm.loadTitles()
		expect(registerStore.getRegister).toHaveBeenCalledWith(1)
		expect(vm.registerTitle).toBe('Reg')
		expect(vm.schemaTitle).toBe('Not set')
	})

	it('builds the relation context from the record or the current items', () => {
		expect(modal().relationContext).toBeNull()
		objectStore.objectItem = { '@self': { uuid: 'u-1' } }
		registerStore.registerItem = { id: 3 }
		schemaStore.schemaItem = { id: 4 }
		expect(modal().relationContext).toEqual({ register: 3, schema: 4, id: 'u-1' })
		objectStore.objectItem = { '@self': { id: 9, register: 1 } }
		schemaStore.schemaItem = null
		expect(modal().relationContext).toBeNull()
	})

	it('closes the modal only when the dialog closes', () => {
		const closeModal = jest.fn()
		const vm = modal({ closeModal })
		vm.handleDialogClose(true)
		expect(closeModal).not.toHaveBeenCalled()
		vm.handleDialogClose(false)
		expect(closeModal).toHaveBeenCalled()
	})

	it('resets the form and clears the modal and dialog on close', () => {
		const timer = setTimeout(() => {}, 10000)
		const vm = modal({ propertyChangeDebounceTimers: { a: timer }, error: 'x', activeTab: 3 })
		vm.closeModal()
		expect(vm.error).toBeNull()
		expect(vm.activeTab).toBe(0)
		expect(vm.propertyChangeDebounceTimers).toEqual({})
		expect(vm.registerSchemaSelectionConfirmed).toBe(false)
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(null)
	})

	it('opens a related record in the modal', () => {
		modal().viewRelatedObject({ id: 4 })
		expect(objectStore.setObjectItem).toHaveBeenCalledWith({ id: 4 })
		expect(navigationStore.setModal).toHaveBeenCalledWith('viewObject')
	})
})

describe('ViewObject property messages', () => {
	const schema = {
		required: ['title'],
		properties: {
			title: { type: 'string' },
			count: { type: 'number' },
			when: { type: 'string', format: 'date-time' },
			kind: { type: 'string', const: 'case' },
		},
	}

	it('names the first problem of a property value', () => {
		const vm = modal({ currentSchema: schema })
		expect(vm.getPropertyErrorMessage('title', '')).toBe("Required property 'title' is missing or empty")
		expect(vm.getPropertyErrorMessage('count', 'x')).toBe("Property 'count' should be number but is string")
		expect(vm.getPropertyErrorMessage('when', 'not a date')).toBe("Property 'when' should be a valid date-time format")
		expect(vm.getPropertyErrorMessage('kind', 'other')).toBe("Property 'kind' should be 'case' but is 'other'")
		expect(vm.getPropertyErrorMessage('count', 3)).toBe("Property 'count' has an invalid value")
		expect(vm.getPropertyErrorMessage('ghost', 1)).toContain("Property 'ghost' is not defined in the current schema")
	})

	it('explains warnings and new properties', () => {
		const vm = modal()
		expect(vm.getPropertyWarningMessage('x')).toContain("Property 'x' exists in the object")
		expect(vm.getPropertyNewMessage('y')).toContain("Property 'y' is defined in the schema")
	})

	it('classes a property as warning, new, valid or invalid', () => {
		objectStore.objectItem = { title: 'A', count: 'x' }
		const vm = modal({ currentSchema: schema })
		expect(vm.getPropertyValidationClass('@self', {})).toBe('')
		expect(vm.getPropertyValidationClass('ghost', 1)).toBe('property-warning')
		expect(vm.getPropertyValidationClass('when', null)).toBe('property-new')
		expect(vm.getPropertyValidationClass('title', 'A')).toBe('property-valid')
		expect(vm.getPropertyValidationClass('count', 'x')).toBe('property-invalid')
	})

	it('renders values as strings', () => {
		const vm = modal()
		expect(vm.getStringValue(null)).toBe('')
		expect(vm.getStringValue(undefined)).toBe('')
		expect(vm.getStringValue(4)).toBe('4')
		expect(vm.getStringValue({ a: 1 })).toBe('{"a":1}')
		const circular = {}
		circular.self = circular
		expect(vm.getStringValue(circular)).toBe('[object Object]')
		expect(vm.formatValue({ a: 1 })).toBe('{\n  "a": 1\n}')
	})

	it('shows the edited value and marks it', () => {
		const vm = modal({ formData: { a: 'new' } })
		expect(vm.propertyValue('a', 'old')).toBe('new')
		expect(vm.propertyValue('b', 'old')).toBe('old')
		expect(vm.editedSuffix('a')).toBe(' (edited)')
		expect(vm.editedSuffix('b')).toBe('')
	})
})

describe('ViewObject inline editing', () => {
	const schema = { properties: { title: { type: 'string' }, tags: { type: 'array' } } }

	it('ignores clicks on inline controls and on the selected property', () => {
		const selectProperty = jest.fn()
		const vm = modal({ currentSchema: schema, selectProperty, selectedProperty: 'title' })
		const input = { tagName: 'INPUT', closest: () => null }
		vm.handleRowClick('tags', { target: input })
		vm.handleRowClick('title', { target: { tagName: 'TD', closest: () => null } })
		vm.handleRowClick('tags', { target: { tagName: 'SPAN', closest: () => ({}) } })
		expect(selectProperty).not.toHaveBeenCalled()
	})

	it('selects a simple property and warns for a complex one', () => {
		const selectProperty = jest.fn()
		const vm = modal({ currentSchema: schema, selectProperty })
		vm.editInline('title')
		expect(selectProperty).toHaveBeenCalledWith('title')
		vm.editInline('untyped')
		expect(selectProperty).toHaveBeenCalledWith('untyped')

		vm.getPropertyDisplayName = (key) => key.toUpperCase()
		vm.editInline('tags')
		expect(showWarning).toHaveBeenCalledWith(expect.stringContaining("Property 'TAGS' has type 'array'"))
	})

	it('warns instead of editing a property that is not editable', () => {
		const editInline = jest.fn()
		const vm = modal({
			currentSchema: schema,
			editInline,
			selectedProperty: null,
			objectProperties: [['title', 'A']],
			isPropertyEditable: () => false,
			getEditabilityWarning: (key, value) => `no ${key}=${value}`,
		})
		vm.handleRowClick('title', { target: { tagName: 'TD', closest: () => null } })
		expect(editInline).not.toHaveBeenCalled()
		expect(showWarning).toHaveBeenCalledWith('no title=A')

		vm.isPropertyEditable = () => true
		vm.handleRowClick('title', { target: { tagName: 'TD', closest: () => null } })
		expect(editInline).toHaveBeenCalledWith('title')
	})
})
