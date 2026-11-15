/**
 * The record modal's record scope: fetching a record's files under its
 * register-schema type, seeding the form from a record, a blank record or a
 * template, reading embedded register and schema references, and confirming
 * an explicit register and schema choice. Methods run against a plain `this`
 * and stub stores.
 *
 * @spec exclude Client-side record modal state handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
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

const { objectStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = ViewObject

/**
 * A plain component context: computed values as getters, methods bound.
 *
 * @param {object} state Data, and computed values or methods to replace
 * @return {object} The context
 */
function modal(state = {}) {
	const vm = { formData: {}, jsonData: '' }
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

const BLANK_SELF = {
	id: '',
	uuid: '',
	uri: '',
	relations: '',
	files: '',
	folder: '',
	updated: '',
	created: '',
	locked: null,
	owner: '',
}

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.objectItem = null
	objectStore.objectTypes = []
	objectStore.availableRegistersForNewObject = undefined
	objectStore.availableSchemasForNewObject = undefined
	registerStore.registerItem = null
	schemaStore.schemaItem = null
	schemaStore.schemaList = []
})

describe('ViewObject fetchObjectFiles', () => {
	it('registers the type of embedded references and fetches the files', () => {
		modal().fetchObjectFiles({ '@self': { id: 'o-1', register: { id: 3 }, schema: { id: 7 } } })
		expect(objectStore.registerObjectType).toHaveBeenCalledWith('3-7', 7, 3)
		expect(objectStore.fetchFiles).toHaveBeenCalledWith('3-7', 'o-1')
	})

	it('only fetches when the type is already registered, falling back to the top-level id', () => {
		objectStore.objectTypes = ['3-7']
		modal().fetchObjectFiles({ '@self': { register: 3, schema: 7 }, id: 'top' })
		expect(objectStore.registerObjectType).not.toHaveBeenCalled()
		expect(objectStore.fetchFiles).toHaveBeenCalledWith('3-7', 'top')
	})

	it.each([
		['a register', { '@self': { id: 'o', schema: 7 } }],
		['a schema', { '@self': { id: 'o', register: 3 } }],
		['an object id', { '@self': { register: 3, schema: 7 } }],
		['metadata', {}],
	])('does nothing without %s', (_, item) => {
		modal().fetchObjectFiles(item)
		expect(objectStore.registerObjectType).not.toHaveBeenCalled()
		expect(objectStore.fetchFiles).not.toHaveBeenCalled()
	})
})

describe('ViewObject form seeding', () => {
	it('copies the open record into the form and fetches its files', () => {
		const record = { '@self': { id: 'o-1', register: 3, schema: 7 }, title: 'A' }
		objectStore.objectItem = record
		const vm = modal()
		vm.initializeData()
		expect(vm.formData).toEqual(record)
		expect(vm.formData).not.toBe(record)
		expect(vm.jsonData).toBe(JSON.stringify(record, null, 2))
		expect(objectStore.fetchFiles).toHaveBeenCalledWith('3-7', 'o-1')
	})

	it('seeds a new record with blank metadata and per-property values, fetching nothing', () => {
		const vm = modal({
			currentRegister: { id: 3 },
			currentSchema: {
				id: 7,
				properties: { title: { type: 'string', default: 'Untitled' }, count: { type: 'number', default: 2 } },
			},
		})
		vm.initializeData()
		expect(vm.formData).toEqual({
			'@self': { ...BLANK_SELF, register: 3, schema: 7 },
			title: 'Untitled',
			count: 2,
		})
		expect(JSON.parse(vm.jsonData)).toEqual(vm.formData)
		expect(objectStore.fetchFiles).not.toHaveBeenCalled()
	})

	it('uses empty register and schema ids without a scope', () => {
		const data = modal({ currentRegister: null, currentSchema: null }).blankObjectData()
		expect(data).toEqual({ '@self': { ...BLANK_SELF, register: '', schema: '' } })
	})

	it('starts a record from a template, its values over the blank ones', () => {
		const vm = modal({
			currentRegister: { id: 3 },
			currentSchema: { id: 7, properties: { title: { type: 'string', default: 'x' }, kind: { type: 'string', default: 'a' } } },
		})
		vm.applyRecordTemplate({ values: { kind: 'b', extra: 1 } })
		expect(vm.formData).toEqual({
			'@self': { ...BLANK_SELF, register: 3, schema: 7 },
			title: 'x',
			kind: 'b',
			extra: 1,
		})
	})

	it('keeps the blank record when a template has no values', () => {
		const vm = modal({ currentRegister: { id: 3 }, currentSchema: { id: 7, properties: { title: { type: 'string', default: 'x' } } } })
		vm.applyRecordTemplate({ values: null })
		expect(vm.formData).toEqual({ '@self': { ...BLANK_SELF, register: 3, schema: 7 }, title: 'x' })
	})
})

describe('ViewObject register and schema references', () => {
	it('reads a plain register id and an embedded schema', () => {
		objectStore.objectItem = { '@self': { register: 'r-1', schema: { id: 9 } } }
		expect(modal()._getRegisterSchemaIds()).toEqual({ registerId: 'r-1', schemaId: 9 })
	})

	it('keeps null references as they are', () => {
		objectStore.objectItem = { '@self': { register: null, schema: null } }
		expect(modal()._getRegisterSchemaIds()).toEqual({ registerId: null, schemaId: null })
	})

	it('confirms an explicitly chosen register and schema over the first options', () => {
		objectStore.availableRegistersForNewObject = [{ id: 1 }, { id: 2 }]
		objectStore.availableSchemasForNewObject = [{ id: 5 }, { id: 6 }]
		const initializeData = jest.fn()
		const vm = modal({
			initializeData,
			selectedRegisterForNewObject: { id: 2 },
			selectedSchemaForNewObject: { id: 6 },
			registerSchemaSelectionConfirmed: false,
		})
		vm.confirmRegisterSchemaSelection()
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 2 })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 6 })
		expect(vm.registerSchemaSelectionConfirmed).toBe(true)
		expect(initializeData).toHaveBeenCalledTimes(1)
	})
})
