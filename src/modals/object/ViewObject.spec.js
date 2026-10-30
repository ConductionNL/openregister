import ViewObject from './ViewObject.vue'
import { objectStore } from '../../store/store.js'

// Opening the record modal seeds the form: blank fields per schema property
// for a new record, the record itself for an existing one, and the files of
// an existing record are fetched under its register-schema type.

jest.mock('../../store/store.js', () => ({
	objectStore: {
		objectItem: null,
		objectTypes: [],
		registerObjectType: jest.fn(),
		fetchFiles: jest.fn(),
	},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
	dashboardStore: {},
	organisationStore: {},
}))
jest.mock('@codemirror/lang-json', () => ({
	json: jest.fn(),
	jsonParseLinter: jest.fn(),
}))
jest.mock('@nextcloud/dialogs', () => ({ showWarning: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
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

/**
 * A bare component context: the options object's methods, without a mount.
 *
 * @param {object|null} schema The current schema.
 * @return {object} The context
 */
function context(schema) {
	const ctx = {
		currentRegister: { id: 3 },
		currentSchema: schema,
		formData: null,
		jsonData: null,
	}
	for (const [name, method] of Object.entries(ViewObject.methods)) {
		if (typeof method === 'function') {
			ctx[name] = method.bind(ctx)
		}
	}
	return ctx
}

describe('ViewObject.initializeData', () => {
	beforeEach(() => {
		objectStore.objectTypes = []
		objectStore.registerObjectType.mockReset()
		objectStore.fetchFiles.mockReset()
	})

	it('seeds a new record with blank metadata and a value per property', () => {
		objectStore.objectItem = null
		const ctx = context({
			id: 9,
			properties: {
				name: { type: 'string' },
				count: { type: 'number' },
				rank: { type: 'integer' },
				done: { type: 'boolean' },
				tags: { type: 'array' },
				meta: { type: 'object' },
				file: { type: 'file' },
				status: { type: 'string', default: 'open' },
				none: { type: 'number', default: null },
			},
		})

		ctx.initializeData()

		expect(ctx.formData).toEqual({
			'@self': {
				id: '',
				uuid: '',
				uri: '',
				register: 3,
				schema: 9,
				relations: '',
				files: '',
				folder: '',
				updated: '',
				created: '',
				locked: null,
				owner: '',
			},
			name: '',
			count: 0,
			rank: 0,
			done: false,
			tags: [],
			meta: {},
			file: null,
			status: 'open',
			none: null,
		})
		expect(ctx.jsonData).toBe(JSON.stringify(ctx.formData, null, 2))
		expect(objectStore.fetchFiles).not.toHaveBeenCalled()
	})

	it('seeds a new record without a schema', () => {
		objectStore.objectItem = null
		const ctx = context(null)
		ctx.currentRegister = null
		ctx.initializeData()
		expect(Object.keys(ctx.formData)).toEqual(['@self'])
		expect(ctx.formData['@self'].register).toBe('')
		expect(ctx.formData['@self'].schema).toBe('')
	})

	it('copies an existing record and fetches its files under a new type', () => {
		objectStore.objectItem = {
			title: 'x',
			'@self': { id: 'o-1', register: { id: 2 }, schema: 5 },
		}
		const ctx = context({ id: 5 })

		ctx.initializeData()

		expect(ctx.formData).toEqual(objectStore.objectItem)
		expect(ctx.formData).not.toBe(objectStore.objectItem)
		expect(objectStore.registerObjectType).toHaveBeenCalledWith('2-5', 5, 2)
		expect(objectStore.fetchFiles).toHaveBeenCalledWith('2-5', 'o-1')
	})

	it('does not register a type it already knows, and falls back to the root id', () => {
		objectStore.objectTypes = ['2-5']
		objectStore.objectItem = {
			id: 'root',
			'@self': { register: 2, schema: { id: 5 } },
		}
		const ctx = context({ id: 5 })

		ctx.initializeData()

		expect(objectStore.registerObjectType).not.toHaveBeenCalled()
		expect(objectStore.fetchFiles).toHaveBeenCalledWith('2-5', 'root')
	})

	it('fetches nothing without a register, schema or id', () => {
		objectStore.objectItem = { '@self': { register: 2 } }
		context({}).initializeData()
		expect(objectStore.fetchFiles).not.toHaveBeenCalled()
	})
})
