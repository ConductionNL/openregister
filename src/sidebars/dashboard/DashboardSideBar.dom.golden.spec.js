/**
 * Golden record of the dashboard sidebar: its markup while loading, without
 * statistics and with the system totals and orphaned items tables, and what
 * mounting it asks of the stores.
 *
 * @spec exclude Golden record of client-side dashboard sidebar markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import DashboardSideBar from './DashboardSideBar.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The sidebar mixes <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module, as in the list page
// goldens.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcAppSidebar: mockLibrary().NcAppSidebar,
	NcAppSidebarTab: mockLibrary().NcAppSidebarTab,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
	NcSelect: mockLibrary().NcSelect,
}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({
	__esModule: true,
	default: mockStub('Magnify'),
}))
jest.mock('../../store/store.js', () => mockStores())

/**
 * Stores the sidebar reads, logging every action called on them.
 *
 * @return {object} The module
 */
function mockStores() {
	const { reactive } = jest.requireActual('vue')
	const log = (globalThis.mockStoreLog = [])
	const store = (name, state) => {
		const target = reactive(state)
		return new Proxy(target, {
			get(obj, key) {
				if (typeof key === 'string' && !key.startsWith('__v') && !(key in obj)) {
					return (...args) => {
						log.push([`${name}.${key}`, ...args])
						return Promise.resolve()
					}
				}
				return obj[key]
			},
		})
	}
	return {
		dashboardStore: store('dashboardStore', { loading: false, registers: [] }),
		objectStore: store('objectStore', { metadata: {} }),
		registerStore: store('registerStore', { registerList: [], registerItem: null }),
		schemaStore: store('schemaStore', { schemaList: [], schemaItem: null }),
		navigationStore: store('navigationStore', {}),
	}
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
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(
					([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'object',
				),
			)
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

const { dashboardStore } = jest.requireMock('../../store/store.js')

/**
 * Totals the way the dashboard store lists them.
 *
 * @param {string} title The row title
 * @param {number} base A number to vary the figures by
 * @return {object} The register-shaped totals
 */
function totals(title, base) {
	return {
		title,
		stats: {
			objects: { total: base, size: base * 1024, invalid: base + 1, deleted: base + 2, locked: base + 3 },
			logs: { total: base + 4, size: base * 2048 },
			files: { total: base + 5, size: base * 1048576 },
		},
	}
}

/**
 * The sidebar's markup without comment nodes: template comments and fragment
 * anchors only exist in development builds.
 *
 * @param {object} wrapper The sidebar
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * Mount the sidebar over the given dashboard state.
 *
 * @param {object} state loading and registers
 * @return {Promise<object>} The wrapper
 */
async function mountWith(state) {
	dashboardStore.loading = state.loading
	dashboardStore.registers = state.registers
	globalThis.mockStoreLog.length = 0
	const wrapper = mount(DashboardSideBar)
	await flushPromises()
	return wrapper
}

describe('DashboardSideBar golden', () => {
	it('renders while loading', async () => {
		const wrapper = await mountWith({ loading: true, registers: [] })
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('renders without statistics', async () => {
		const wrapper = await mountWith({ loading: false, registers: [] })
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('renders the totals tables', async () => {
		const wrapper = await mountWith({
			loading: false,
			registers: [
				totals('System Totals', 7),
				{ title: 'A', schemas: [{}, {}] },
				{ title: 'B', schemas: [{}] },
				{ title: 'C' },
				totals('Orphaned Items', 3),
			],
		})
		expect(wrapper.findAll('table.statisticsTable')).toHaveLength(2)
		expect(markup(wrapper)).toMatchSnapshot()
		expect(globalThis.mockStoreLog).toMatchSnapshot()
		expect(wrapper.emitted()).toEqual({})
	})

	it('renders zeros for empty totals', async () => {
		const wrapper = await mountWith({
			loading: false,
			registers: [
				{ title: 'System Totals', stats: {} },
				{ title: 'Orphaned Items' },
			],
		})
		expect(wrapper.findAll('table.statisticsTable')).toHaveLength(2)
		expect(markup(wrapper)).toMatchSnapshot()
	})
})
