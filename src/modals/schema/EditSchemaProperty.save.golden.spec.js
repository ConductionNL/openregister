/**
 * Golden test for loading a property into EditSchemaProperty and saving it.
 *
 * initializeSchemaItem() and addSchemaProperty() run against a plain form
 * state and a stubbed schema store. The form state after loading, and the
 * schema handed to saveSchema() after saving, are compared with snapshots.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import EditSchemaProperty from './EditSchemaProperty.vue'
import { schemaStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (p) => p }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))

/**
 * A form: the component's initial data with its methods bound to it.
 *
 * @param {object} overrides State to set after the initial data.
 * @return {object}
 */
function form(overrides = {}) {
	const ctx = EditSchemaProperty.data.call({})
	Object.entries(EditSchemaProperty.methods).forEach(([name, fn]) => {
		ctx[name] = fn.bind(ctx)
	})
	ctx.closeModal = () => {}
	ctx.isDateProperty = false
	return Object.assign(ctx, overrides)
}

/**
 * The form's data fields, without its methods.
 *
 * @param {object} ctx The form.
 * @return {object}
 */
function state(ctx) {
	return JSON.parse(JSON.stringify(ctx))
}

const LOAD_CASES = {
	'no property key': {
		key: null,
		property: null,
	},
	'calculation, facetable true, bare property': {
		key: 'title',
		property: {
			type: 'string',
			calculation: { type: 'number', expression: { '+': [1, 2] } },
			facetable: true,
		},
	},
	'calculation without type or expression, facetable false': {
		key: 'title',
		property: {
			type: 'string',
			calculation: {},
			facetable: false,
			minLength: 2,
			maxLength: 10,
			order: 4,
			oneOf: [{ const: 'a' }],
		},
	},
	'facet config with date options and nested query params': {
		key: 'born',
		property: {
			type: 'string',
			format: 'date',
			facetable: {
				aggregated: false,
				title: 'Born',
				description: 'Birth date',
				order: 0,
				type: 'date_range',
				options: {
					interval: 'year',
					format: 'yyyy',
					useDefaultRanges: false,
					ranges: [{ label: 'Old', from: '1900', to: '1950' }, { label: '', from: '', to: '' }, {}],
				},
			},
			objectConfiguration: { handling: 'related-object', queryParams: 'a=1' },
			fileConfiguration: { maxSize: 5 },
			items: { type: 'object', objectConfiguration: { handling: 'nested', queryParams: 'b=2' } },
		},
	},
	'facet config without options, null order, items without object configuration': {
		key: 'name',
		property: {
			type: 'string',
			facetable: { order: null },
			objectConfiguration: { handling: 'uri' },
			items: { type: 'string' },
		},
	},
	'facet config with options but no ranges and no type': {
		key: 'name',
		property: {
			type: 'string',
			facetable: { aggregated: true, order: 7, options: {} },
			items: { objectConfiguration: {} },
		},
	},
}

describe('EditSchemaProperty initializeSchemaItem', () => {
	Object.entries(LOAD_CASES).forEach(([label, { key, property }]) => {
		it(`loads: ${label}`, () => {
			schemaStore.schemaPropertyKey = key
			schemaStore.schemaItem = { id: 1, properties: property === null ? {} : { [key]: property } }
			const ctx = form()
			ctx.initializeSchemaItem()
			expect(JSON.stringify(state(ctx), null, 1)).toMatchSnapshot()
		})
	})
})

const SAVE_CASES = {
	'facet off, edit with rename, not required, untyped items dropped': {
		key: 'old',
		schemaItem: { id: 3, required: ['old', 'new', 'other'], properties: { old: { type: 'string' }, keep: { type: 'number' } } },
		form: { propertyTitle: 'new', facetableEnabled: false },
		properties: { required: false, order: '2', minLength: '1.5', maximum: 'x' },
	},
	'facet on with the default config': {
		key: null,
		schemaItem: { id: 3, required: ['a'], properties: {} },
		form: { propertyTitle: 'a', facetableEnabled: true },
		properties: { required: false, items: { type: 'string' } },
	},
	'facet with title, description and order, date histogram with format': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: {
			propertyTitle: 'born',
			facetableEnabled: true,
			isDateProperty: true,
			facetType: 'date_histogram',
			facetInterval: 'week',
			facetFormat: 'ww',
			facetConfig: { aggregated: true, title: ' Born ', description: ' When ', order: '3' },
		},
		properties: { required: true, items: { $ref: '#/x' } },
	},
	'date histogram without format': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: { propertyTitle: 'born', facetableEnabled: true, isDateProperty: true, facetType: 'date_histogram', facetInterval: 'day', facetFormat: '' },
	},
	'date range with custom ranges': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: {
			propertyTitle: 'born',
			facetableEnabled: true,
			isDateProperty: true,
			facetType: 'date_range',
			facetUseDefaultRanges: false,
			facetCustomRanges: [{ label: 'A', from: '1', to: '2' }, { label: 'B', from: '', to: '9' }, { label: '', from: '1', to: '2' }, { label: 'C' }],
		},
	},
	'date range with default ranges': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: { propertyTitle: 'born', facetableEnabled: true, isDateProperty: true, facetType: 'date_range', facetUseDefaultRanges: true },
	},
	'date range without defaults and no custom ranges': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: { propertyTitle: 'born', facetableEnabled: true, isDateProperty: true, facetType: 'date_range', facetUseDefaultRanges: false, facetCustomRanges: [] },
	},
	'other facet type on a date property': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: { propertyTitle: 'born', facetableEnabled: true, isDateProperty: true, facetType: 'terms' },
	},
	'facet type set on a non-date property': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: { propertyTitle: 'n', facetableEnabled: true, isDateProperty: false, facetType: 'date_range' },
	},
	'not aggregated, unparseable order': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: { propertyTitle: 'n', facetableEnabled: true, facetConfig: { aggregated: false, title: '   ', description: '', order: 'abc' } },
	},
	'blank title only, null order': {
		key: null,
		schemaItem: { id: 3, properties: {} },
		form: { propertyTitle: 'n', facetableEnabled: true, facetConfig: { aggregated: true, title: '  ', description: '  ', order: null } },
	},
	'not required but no required list': {
		key: 'n',
		schemaItem: { id: 3, required: 'n', properties: { n: {} } },
		form: { propertyTitle: 'n' },
		properties: { required: false },
	},
}

/**
 * Save the form and wait for the store promise to settle.
 *
 * @param {object} ctx The form.
 * @return {Promise<void>}
 */
async function save(ctx) {
	ctx.addSchemaProperty()
	for (let tick = 0; tick < 10; tick++) {
		await Promise.resolve()
	}
}

describe('EditSchemaProperty addSchemaProperty', () => {
	beforeEach(() => {
		jest.useFakeTimers()
	})
	afterEach(() => {
		jest.useRealTimers()
	})

	Object.entries(SAVE_CASES).forEach(([label, c]) => {
		it(`saves: ${label}`, async () => {
			schemaStore.schemaPropertyKey = c.key
			schemaStore.schemaItem = JSON.parse(JSON.stringify(c.schemaItem))
			schemaStore.saveSchema = jest.fn(() => Promise.resolve({ response: { ok: true } }))
			const ctx = form(c.form)
			Object.assign(ctx.properties, c.properties || {})
			await save(ctx)
			expect(schemaStore.saveSchema).toHaveBeenCalledTimes(1)
			expect(JSON.stringify({
				saved: schemaStore.saveSchema.mock.calls[0][0],
				storeProperties: schemaStore.schemaItem.properties,
				loading: ctx.loading,
				success: ctx.success,
				error: ctx.error,
				closeScheduled: ctx.closeModalTimeout !== undefined && ctx.closeModalTimeout !== null,
			}, null, 1)).toMatchSnapshot()
		})
	})

	it('refuses a schema without an id', async () => {
		schemaStore.schemaPropertyKey = 'n'
		schemaStore.schemaItem = { properties: { n: {} } }
		schemaStore.saveSchema = jest.fn()
		const ctx = form({ propertyTitle: 'n' })
		await save(ctx)
		expect(schemaStore.saveSchema).not.toHaveBeenCalled()
		expect([ctx.loading, ctx.success, ctx.error, schemaStore.schemaItem.properties]).toEqual([
			false, false, 'Schema item could not be created, missing schema id', {},
		])
	})

	it('records a failed save', async () => {
		schemaStore.schemaPropertyKey = null
		schemaStore.schemaItem = { id: 3, properties: {} }
		schemaStore.saveSchema = jest.fn(() => Promise.reject(new Error('down')))
		const ctx = form({ propertyTitle: 'n' })
		await save(ctx)
		expect([ctx.loading, ctx.success, String(ctx.error)]).toEqual([false, false, 'Error: down'])
	})
})
