/**
 * Golden record of the data quality pages: their markup without a selection,
 * while loading, empty and with rows across pages; the store reads on mount,
 * on a new selection and on paging.
 *
 * @spec exclude Golden record of client-side quality page markup and paging.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import DuplicatesIndex from './DuplicatesIndex.vue'
import MasterEntitiesIndex from './MasterEntitiesIndex.vue'
import MergeOperationsIndex from './MergeOperationsIndex.vue'
import QualityIndex from './QualityIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
jest.mock('vue-material-design-icons/AccountMultipleOutline.vue', () =>
	mockIcon('AccountMultipleOutline'),
)
jest.mock('vue-material-design-icons/ChartBoxOutline.vue', () =>
	mockIcon('ChartBoxOutline'),
)
jest.mock('vue-material-design-icons/ContentDuplicate.vue', () =>
	mockIcon('ContentDuplicate'),
)
jest.mock('vue-material-design-icons/Merge.vue', () => mockIcon('Merge'))
jest.mock('vue-material-design-icons/Undo.vue', () => mockIcon('Undo'))
jest.mock('vue-material-design-icons/UndoVariant.vue', () => mockIcon('UndoVariant'))
jest.mock('../../modals/mdm/MdmMergeWizardModal.vue', () =>
	mockIcon('MdmMergeWizardModal'),
)
jest.mock('./GoldenRecordDetail.vue', () => mockIcon('GoldenRecordDetail'))
jest.mock('./RegisterSchemaSelector.vue', () => mockIcon('RegisterSchemaSelector'))
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
					return Promise.resolve()
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
 * A stand-in for a component: its tag, plain attributes and slots. Click
 * listeners are kept so the golden can press buttons.
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
				if (key === 'onClick') {
					attrs.onClick = value
				} else if (!/^on[A-Z]/.test(key) && typeof value !== 'function') {
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

const EMPTY = {
	hasSelection: true,
	isLoading: false,
	qualityStats: { total: 0 },
	lowQualityObjects: [],
	lowQualityTotal: 0,
	lowQualityLimit: 20,
	lowQualityOffset: 0,
	duplicates: [],
	duplicatesTotal: 0,
	duplicatesLimit: 20,
	duplicatesOffset: 0,
	masterEntities: [],
	goldenRecord: null,
	mergeOperations: [],
	mergeOperationsTotal: 0,
	mergeOperationsLimit: 20,
	mergeOperationsOffset: 0,
}

const STATES = {
	noSelection: { ...EMPTY, hasSelection: false },
	loading: { ...EMPTY, isLoading: true },
	empty: EMPTY,
	middlePage: {
		hasSelection: true,
		isLoading: false,
		qualityStats: {
			total: 5,
			average: 61.256,
			buckets: { good: 1, fair: 2, poor: 2 },
			histogram: [0, 1, 2, 0, 2],
		},
		lowQualityObjects: [
			{ id: 'q1', title: 'Q one', qualityScore: 12, missing: ['a'] },
			{ id: 'q2', title: 'Q two', qualityScore: 30, missing: [] },
		],
		lowQualityTotal: 45,
		lowQualityLimit: 20,
		lowQualityOffset: 20,
		duplicates: [
			{
				objectA: 'a1',
				objectB: 'b1',
				score: 0.9,
				matchedOn: ['name', 'email'],
			},
			{ objectA: 'a2', objectB: 'b2', score: 0.7 },
		],
		duplicatesTotal: 45,
		duplicatesLimit: 20,
		duplicatesOffset: 20,
		masterEntities: [
			{ id: 'm1', title: 'M one', qualityScore: 80, sourceCount: 2 },
		],
		goldenRecord: null,
		mergeOperations: [
			{
				id: 'op1',
				from: 'x',
				into: 'y',
				created: '2026-01-01',
				reversed: false,
			},
		],
		mergeOperationsTotal: 45,
		mergeOperationsLimit: 20,
		mergeOperationsOffset: 20,
	},
}

const mounted = []

const PAGES = {
	quality: QualityIndex,
	duplicates: DuplicatesIndex,
	masterEntities: MasterEntitiesIndex,
	mergeOperations: MergeOperationsIndex,
}

/**
 * The page's markup without comment nodes: template comments and fragment
 * anchors only exist in development builds.
 *
 * @param {object} wrapper The page
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * Mount a page over a store state.
 *
 * @param {object} page The page component
 * @param {object} state The store state
 * @return {Promise<object>} The wrapper
 */
async function mountWith(page, state) {
	for (const key of Object.keys(qualityStore)) {
		delete qualityStore[key]
	}
	Object.assign(qualityStore, { selectedRegister: 1, selectedSchema: 2 }, state)
	globalThis.mockStoreLog.length = 0
	const wrapper = mount(page, {
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

describe('quality pages golden', () => {
	afterEach(() => {
		while (mounted.length) {
			mounted.pop().unmount()
		}
	})

	for (const [name, page] of Object.entries(PAGES)) {
		for (const [stateName, state] of Object.entries(STATES)) {
			it(`${name}: renders ${stateName}`, async () => {
				const wrapper = await mountWith(page, state)
				expect(markup(wrapper).length).toBeGreaterThan(0)
				expect({
					markup: markup(wrapper),
					mounted: globalThis.mockStoreLog,
				}).toMatchSnapshot()
			})
		}

		it(`${name}: reloads on a new selection and pages`, async () => {
			const wrapper = await mountWith(page, STATES.middlePage)
			const log = globalThis.mockStoreLog
			const steps = { mounted: [...log] }
			log.length = 0
			const picks = 'selectedObject' in wrapper.vm
			if (picks) {
				wrapper.vm.selectedObject = { id: 'picked' }
			}
			qualityStore.selectedSchema = 3
			await flushPromises()
			steps.selection = [...log]
			if (picks) {
				steps.selectedObject = wrapper.vm.selectedObject
			}
			const pager = wrapper.find('.pagination')
			steps.pager = pager.exists()
			if (pager.exists()) {
				const buttons = pager.findAll('x-NcButton')
				for (const [index, button] of buttons.entries()) {
					log.length = 0
					await button.trigger('click')
					await flushPromises()
					steps[`button${index}`] = [...log]
				}
			}
			expect(steps).toMatchSnapshot()
			expect(Object.keys(wrapper.emitted())).toMatchSnapshot()
		})
	}
})
