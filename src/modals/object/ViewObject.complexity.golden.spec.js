/**
 * Golden record of the record modal's schema merge, field list, schema
 * watcher, row click and value messages, for every branch they take.
 *
 * @spec exclude Golden record of client-side record form helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ViewObject from './ViewObject.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

const mockLog = []

jest.mock('../../store/store.js', () => ({
	objectStore: { objectItem: null },
	navigationStore: {},
	registerStore: { registerItem: null },
	schemaStore: {
		schemaItem: null,
		schemaList: [],
		getSchema: jest.fn(),
	},
	dashboardStore: {},
	organisationStore: {},
}))
jest.mock('@codemirror/lang-json', () => ({
	json: jest.fn(),
	jsonParseLinter: jest.fn(),
}))
jest.mock('@nextcloud/dialogs', () => ({
	showWarning: (text) => mockLog.push(['warning', text]),
}))
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
 * A bare component context with the modal's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state) {
	const ctx = {
		formData: {},
		objectProperties: [],
		selectedProperty: null,
		...state,
	}
	for (const [name, method] of Object.entries(ViewObject.methods)) {
		if (typeof method === 'function' && !(name in ctx)) {
			ctx[name] = method.bind(ctx)
		}
	}
	ctx.selectProperty = (key) => mockLog.push(['select', key])
	ctx.initializeData = () => mockLog.push(['initializeData'])
	ctx.$forceUpdate = () => mockLog.push(['forceUpdate'])
	return ctx
}

const PARENTS = [
	{
		id: 1,
		uuid: 'u-1',
		properties: { a: { type: 'string' }, shared: { type: 'number' } },
	},
	{ id: 2, uuid: 'u-2', properties: { b: { type: 'boolean' } } },
	{ id: 3, uuid: 'u-3' },
]

const SCHEMAS = {
	none: null,
	plain: { id: 9, properties: { own: { type: 'string' } } },
	emptyAllOf: { id: 9, allOf: [], properties: { own: { type: 'string' } } },
	byId: {
		id: 9,
		allOf: [1, { id: 2 }],
		properties: { shared: { type: 'string' } },
	},
	byUuid: { id: 9, allOf: ['u-1', { id: 'u-2' }] },
	byString: { id: 9, allOf: ['1', '3', '404'], properties: null },
}

describe('ViewObject complexity golden', () => {
	beforeEach(() => {
		mockLog.length = 0
		schemaStore.schemaList = PARENTS
		schemaStore.getSchema.mockReset()
		objectStore.objectItem = null
		registerStore.registerItem = null
	})

	it('merges inherited schema properties', () => {
		const out = Object.entries(SCHEMAS).map(([name, schema]) => {
			schemaStore.schemaItem = schema
			return [name, ViewObject.computed.currentSchema.call({})]
		})
		expect(out).toMatchSnapshot()
	})

	it('lists the form fields', () => {
		const items = {
			none: null,
			mixed: {
				'@self': {},
				id: 1,
				own: 'x',
				flag: true,
				count: 3,
				list: [],
				nested: {},
				empty: null,
				missing: undefined,
				text: 's',
			},
		}
		const schemas = {
			none: null,
			bare: {},
			props: { properties: { own: { type: 'number' }, blank: null } },
		}
		const out = []
		for (const [schemaName, schema] of Object.entries(schemas)) {
			for (const [itemName, item] of Object.entries(items)) {
				objectStore.objectItem = item
				out.push([
					schemaName,
					itemName,
					ViewObject.computed.formFields.call({ currentSchema: schema }),
				])
			}
		}
		expect(out).toMatchSnapshot()
	})

	const WATCHED = {
		none: [null, false, false],
		resolved: [{ id: 4, allOf: [1], properties: { a: {} } }, true, true],
		allOfUnresolved: [{ id: 4, allOf: [1] }, true, true],
		oneOfUnresolved: [{ id: 4, oneOf: [1], properties: {} }, false, true],
		anyOfUnresolved: [{ id: 4, anyOf: [1] }, true, false],
		fetchFails: [{ id: 4, allOf: [1] }, true, true, true],
		noId: [{ allOf: [1] }, true, true],
		plain: [{ id: 4 }, false, false],
	}
	for (const [name, [schema, isNewObject, isMounted, fails]] of Object.entries(
		WATCHED,
	)) {
		it(`watches the schema: ${name}`, async () => {
			schemaStore.getSchema.mockImplementation((...args) => {
				mockLog.push(['getSchema', ...args])
				return fails ? Promise.reject(new Error('x')) : Promise.resolve()
			})
			const ctx = context({ isNewObject, isMounted })
			await ViewObject.watch.currentSchema.handler.call(ctx, schema)
			expect(mockLog).toMatchSnapshot()
		})
	}

	it('words the property errors', () => {
		const schema = {
			required: ['req'],
			properties: {
				req: { type: 'string' },
				flagged: { type: 'string', required: true },
				num: { type: 'number' },
				list: { type: 'array' },
				when: { type: 'string', format: 'date-time' },
				fixed: { type: 'string', const: 'k' },
				free: { type: 'string' },
			},
		}
		const cases = [
			['unknown', 'x'],
			['req', null],
			['req', undefined],
			['req', ''],
			['flagged', ''],
			['num', '1'],
			['num', 1],
			['list', []],
			['list', {}],
			['when', 'nope'],
			['when', '2026-01-01T00:00:00Z'],
			['fixed', 'other'],
			['fixed', 'k'],
			['free', 'ok'],
			['free', null],
		]
		const ctx = context({ currentSchema: schema })
		const out = cases.map(([key, value]) => [
			key,
			value,
			ctx.getPropertyErrorMessage(key, value),
		])
		expect(out).toMatchSnapshot()
		expect(
			context({ currentSchema: null }).getPropertyErrorMessage('a', 1),
		).toMatchSnapshot()
	})

	it('turns values into strings', () => {
		const circular = {}
		circular.self = circular
		const values = [
			null,
			undefined,
			's',
			1,
			0,
			true,
			false,
			[1],
			{ a: 1 },
			circular,
			10n,
			Symbol.for('x'),
		]
		const ctx = context({})
		expect(values.map((value) => ctx.getStringValue(value))).toMatchSnapshot()
	})

	const ROW = (tagName, inside = false) => ({
		target: { tagName, closest: (selector) => (inside ? { selector } : null) },
	})
	const ROW_CASES = {
		input: ['s', ROW('INPUT'), {}],
		button: ['s', ROW('BUTTON'), {}],
		insideInput: ['s', ROW('DIV', true), {}],
		alreadySelected: ['s', ROW('TD'), { selectedProperty: 's' }],
		lockedWithWarning: ['s', ROW('TD'), { editable: false, warning: 'locked' }],
		lockedQuiet: ['s', ROW('TD'), { editable: false, warning: '' }],
		stringType: ['s', ROW('TD'), {}],
		integerType: ['i', ROW('TD'), {}],
		freeForm: ['free', ROW('TD'), {}],
		objectType: ['o', ROW('TD'), {}],
		valueFromRows: ['o', ROW('TD'), { formData: {}, rows: [['o', { x: 1 }]] }],
		valueFromForm: ['o', ROW('TD'), { formData: { o: 5 } }],
	}
	for (const [name, [key, event, options]] of Object.entries(ROW_CASES)) {
		it(`clicks a row: ${name}`, () => {
			const ctx = context({
				currentSchema: {
					properties: {
						s: { type: 'string' },
						i: { type: 'integer' },
						o: { type: 'object' },
					},
				},
				selectedProperty: options.selectedProperty ?? null,
				formData: options.formData ?? { s: 'v' },
				objectProperties: options.rows ?? [['s', 'row']],
			})
			ctx.isPropertyEditable = (k, v) => {
				mockLog.push(['editable?', k, v])
				return options.editable ?? true
			}
			ctx.getEditabilityWarning = () => options.warning
			ctx.getPropertyDisplayName = (k) => `<${k}>`
			ctx.handleRowClick(key, event)
			expect(mockLog).toMatchSnapshot()
		})
	}
})
