/**
 * Golden record of the data quality panels that list rows in a table: the
 * queue health page and the golden-record detail panel, in every state.
 *
 * @spec exclude Golden record of client-side quality table markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import GoldenRecordDetail from './GoldenRecordDetail.vue'
import QualityIndex from './QualityIndex.vue'
import QueueHealthIndex from './QueueHealthIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
jest.mock('vue-material-design-icons/AccountBoxOutline.vue', () =>
	mockIcon('AccountBoxOutline'),
)
jest.mock('vue-material-design-icons/ChartBoxOutline.vue', () =>
	mockIcon('ChartBoxOutline'),
)
jest.mock('./RegisterSchemaSelector.vue', () => mockIcon('RegisterSchemaSelector'))
jest.mock('vue-material-design-icons/Webhook.vue', () => mockIcon('Webhook'))
jest.mock('../../modals/mdm/MdmConflictResolutionModal.vue', () =>
	mockIcon('MdmConflictResolutionModal'),
)
jest.mock('../../store/store.js', () => mockStores())

/**
 * The quality store, logging every action called on it.
 *
 * @return {object} The module
 */
function mockStores() {
	const { reactive } = require('vue')
	const log = (globalThis.mockStoreLog = [])
	const state = reactive({})
	const qualityStore = new Proxy(state, {
		get(obj, key) {
			if (typeof key === 'string' && key.startsWith('fetch')) {
				return (...args) => {
					log.push([key, ...args])
					return Promise.resolve([])
				}
			}
			return obj[key]
		},
	})
	return { qualityStore }
}

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = (globalThis.madeStubs = globalThis.madeStubs || {})
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockIcon(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	if (name === 'CnDataTable') {
		return mockTable()
	}
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = {}
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (!/^on[A-Z]/.test(key) && typeof value !== 'function') {
					attrs[key] =
						typeof value === 'object' ? JSON.stringify(value) : value
				}
			}
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => render(`slot-${slot}`, this.$slots[slot]({}))),
			)
		},
	}
}

/**
 * A cell without a slot as the library's formatValue draws it: text cut at
 * 100 characters plus an ellipsis, an empty value as a dash.
 *
 * @param {*} value The cell value
 * @return {string} What the library shows
 */
function mockCell(value) {
	if (value === null || value === undefined || value === '') {
		return '—'
	}
	const text = String(value)
	return text.length > 100 ? `${text.substring(0, 100)}...` : text
}

/**
 * A stand-in for CnDataTable that draws its column labels and cell values
 * (or the `column-<key>` slot), so a golden compares what each cell reads.
 *
 * @return {object} The stub
 */
function mockTable() {
	const { h: render } = require('vue')
	return {
		name: 'CnDataTable',
		inheritAttrs: false,
		props: { columns: Array, rows: Array, rowKey: { type: String, default: 'id' } },
		render() {
			const head = this.columns.map((col) => render('x-th', col.label))
			const body = this.rows.map((row) =>
				render(
					'x-tr',
					{ key: row[this.rowKey] },
					this.columns.map((col) => {
						const slot = this.$slots[`column-${col.key}`]
						return render(
							'x-td',
							slot ? slot({ row, value: row[col.key] }) : mockCell(row[col.key]),
						)
					}),
				),
			)
			const attrs = { ...this.$attrs, rowKey: this.rowKey }
			return render('x-CnDataTable', attrs, [render('x-thead', head), render('x-tbody', body)])
		},
	}
}

const { qualityStore } = jest.requireMock('../../store/store.js')

const QUEUE_STATES = {
	loading: { isLoading: true, webhooks: [], webhookFailures: [] },
	empty: { isLoading: false, webhooks: [], webhookFailures: [] },
	noFailures: {
		isLoading: false,
		webhooks: [
			{ id: 1, name: 'Hook one', url: 'https://one.test' },
			{ id: 2, url: 'https://two.test' },
		],
		webhookStats: { 1: { successful: 4, failed: 0, pendingRetries: 2 } },
		webhookFailures: [],
	},
	failures: {
		isLoading: false,
		webhooks: [{ id: 1, name: 'Hook one' }],
		webhookStats: { 1: { successful: 1, failed: 3 } },
		webhookFailures: [
			{
				id: 7,
				webhook: 1,
				eventClass: 'ObjectCreatedEvent',
				statusCode: 500,
				errorMessage: 'Server error',
				created: '2026-01-02T03:04:05+00:00',
			},
			{ webhook: 1, eventClass: 'ObjectUpdatedEvent' },
		],
	},
}

const GOLDEN_OBJECTS = {
	none: null,
	noProvenance: { id: 'g1', qualityScore: 80 },
	provenance: {
		id: 'g2',
		qualityScore: 91,
		qualityStatus: 'good',
		attributeProvenance: {
			name: { source: 'crm', confidence: 0.9, timestamp: '2026-01-01' },
			email: { winningSource: 'erp', at: '2026-02-02' },
			phone: { override: true, overriddenBy: 'admin', rationale: 'checked' },
			city: 'registry',
			street: {},
		},
	},
}

const LONG_TEXT = 'A long sentence that runs on. '.repeat(6)

const mounted = []

/**
 * The markup without comment nodes: template comments and fragment anchors
 * only exist in development builds.
 *
 * @param {object} wrapper The component
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * The columns of one table that opt in to wrapping.
 *
 * @param {object} wrapper The page
 * @param {number} index Which table on the page
 * @return {Array<string>} Their keys
 */
function wrappingColumns(wrapper, index = 0) {
	return wrapper.findAllComponents({ name: 'CnDataTable' })[index].props('columns')
		.filter((column) => column.cellClass === 'cn-cell--wrap')
		.map((column) => column.key)
}

/**
 * Mount a component over a store state.
 *
 * @param {object} component The component
 * @param {object} state The store state
 * @param {object} props The props
 * @return {Promise<object>} The wrapper
 */
async function mountWith(component, state, props = {}) {
	for (const key of Object.keys(qualityStore)) {
		delete qualityStore[key]
	}
	Object.assign(qualityStore, { selectedRegister: 1, selectedSchema: 2 }, state)
	globalThis.mockStoreLog.length = 0
	const wrapper = mount(component, {
		props,
		global: {
			mocks: {
				t: (app, text, vars) =>
					`t:${text}${vars ? JSON.stringify(vars) : ''}`,
				n: (app, one) => one,
			},
		},
	})
	await flushPromises()
	mounted.push(wrapper)
	return wrapper
}

describe('quality tables golden', () => {
	afterEach(() => {
		while (mounted.length) {
			mounted.pop().unmount()
		}
	})

	for (const [stateName, state] of Object.entries(QUEUE_STATES)) {
		it(`queue health: renders ${stateName}`, async () => {
			const wrapper = await mountWith(QueueHealthIndex, state)
			expect({
				markup: markup(wrapper),
				mounted: globalThis.mockStoreLog,
			}).toMatchSnapshot()
		})
	}

	it('quality: renders the histogram fallback and lowest-quality tables', async () => {
		const wrapper = await mountWith(QualityIndex, {
			hasSelection: true,
			isLoading: false,
			qualityStats: { total: 3, average: 40, histogram: [1, 0, 2] },
			lowQualityObjects: [
				{ id: 'q1', qualityScore: 12, qualityStatus: 'poor' },
				{ id: 'q2', qualityScore: 0 },
			],
			lowQualityTotal: 2,
			lowQualityLimit: 20,
			lowQualityOffset: 0,
		})
		wrapper.vm.chartAvailable = false
		await flushPromises()
		expect(markup(wrapper.find('.histogramSection'))).toMatchSnapshot()
		expect(markup(wrapper.find('.lowestQualitySection'))).toMatchSnapshot()
	})

	it('queue health: keeps long text whole and lets it wrap', async () => {
		const wrapper = await mountWith(QueueHealthIndex, {
			isLoading: false,
			webhooks: [{ id: 1, name: LONG_TEXT }],
			webhookStats: {},
			webhookFailures: [{ id: 7, webhook: LONG_TEXT, eventClass: LONG_TEXT, errorMessage: LONG_TEXT }],
		})
		const cells = wrapper.findAll('x-td').map((cell) => cell.text())
		expect(cells.filter((text) => text === LONG_TEXT.trim())).toHaveLength(4)
		expect(wrappingColumns(wrapper, 0)).toEqual(['name'])
		expect(wrappingColumns(wrapper, 1)).toEqual(['webhook', 'eventClass', 'errorMessage'])
	})

	it('quality: leaves a missing score blank', async () => {
		const wrapper = await mountWith(QualityIndex, {
			hasSelection: true,
			isLoading: false,
			qualityStats: { total: 1, average: 40, histogram: [] },
			lowQualityObjects: [{ id: 'q1', qualityScore: null, qualityStatus: 'poor' }],
			lowQualityTotal: 1,
			lowQualityLimit: 20,
			lowQualityOffset: 0,
		})
		const cells = wrapper.find('.lowestQualitySection').findAll('x-td').map((cell) => cell.text())
		expect(cells).toEqual(['q1', '', 'poor'])
	})

	it('golden record: keeps a long override rationale whole and lets it wrap', async () => {
		const wrapper = await mountWith(GoldenRecordDetail, {}, {
			object: { id: 'g3', attributeProvenance: { phone: { override: true, overriddenBy: 'admin', rationale: LONG_TEXT } } },
		})
		expect(wrapper.findAll('x-td').map((cell) => cell.text())).toContain(LONG_TEXT.trim())
		expect(wrappingColumns(wrapper)).toEqual(['attribute', 'source', 'confidence'])
	})

	for (const [name, object] of Object.entries(GOLDEN_OBJECTS)) {
		it(`golden record: renders ${name}`, async () => {
			const wrapper = await mountWith(GoldenRecordDetail, {}, { object })
			expect(markup(wrapper)).toMatchSnapshot()
		})
	}
})
