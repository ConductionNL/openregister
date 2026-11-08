import { flushPromises, mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
/**
 * Golden record of the list sidebars bound to a route: their filter fields,
 * what the register and schema pickers do, and how the route and the stores
 * drive the filters.
 *
 * @spec exclude Golden record of client-side sidebar markup and route sync.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeletedSideBar from './deleted/DeletedSideBar.vue'
import AuditTrailSideBar from './logs/AuditTrailSideBar.vue'
import SearchTrailSideBar from './logs/SearchTrailSideBar.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue3-apexcharts', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => mockLibrary().AccountCircle)
jest.mock('vue-material-design-icons/ChartLine.vue', () => mockLibrary().ChartLine)
jest.mock('vue-material-design-icons/CogOutline.vue', () => mockLibrary().CogOutline)
jest.mock('vue-material-design-icons/Delete.vue', () => mockLibrary().Delete)
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => mockLibrary().DatabaseOutline)
jest.mock('vue-material-design-icons/Eye.vue', () => mockLibrary().Eye)
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => mockLibrary().FilterOffOutline)
jest.mock('vue-material-design-icons/FilterOutline.vue', () => mockLibrary().FilterOutline)
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => mockLibrary().MagnifyPlus)
jest.mock('vue-material-design-icons/Monitor.vue', () => mockLibrary().Monitor)
jest.mock('vue-material-design-icons/Pencil.vue', () => mockLibrary().Pencil)
jest.mock('vue-material-design-icons/Plus.vue', () => mockLibrary().Plus)
jest.mock('vue-material-design-icons/TrendingUp.vue', () => mockLibrary().TrendingUp)
jest.mock('../store/store.js', () => ({}))

const stores = jest.requireMock('../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	globalThis.madeStubs = globalThis.madeStubs || {}
	const made = globalThis.madeStubs
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key === 'default') return mockStub('Icon')
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
	const { h: render } = require('vue')
	const text = (value) =>
		typeof value === 'object' || typeof value === 'function'
			? JSON.stringify(value)
			: value
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [key, text(value)]),
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

/**
 * Anything at all: the parts of a store that the filters do not read.
 */
const anything = new Proxy(function() {}, {
	get(target, key) {
		if (key === Symbol.toPrimitive) return () => ''
		if (key === Symbol.iterator) return [][Symbol.iterator].bind([])
		if (key === 'then' || key === 'toJSON') return undefined
		if (key === 'length') return 0
		return anything
	},
	apply: () => anything,
})

const SIDEBARS = {
	deleted: { component: DeletedSideBar, path: '/deleted' },
	auditTrail: { component: AuditTrailSideBar, path: '/audit-trails' },
	searchTrail: { component: SearchTrailSideBar, path: '/search-trails' },
}

/**
 * Stores with reactive scope items and logged scope setters.
 *
 * @param {Array} log The log of calls
 * @return {void}
 */
function resetStores(log) {
	const lenient = (fields) =>
		new Proxy(fields, {
			get: (target, prop) => (prop in target ? target[prop] : anything),
		})
	const scope = reactive({ register: null, schema: null })
	stores.registerStore = lenient({
		get registerItem() {
			return scope.register
		},
		registerList: [{ id: 1, title: 'R', schemas: [{ id: 2, title: 'S' }] }],
		setRegisterItem(value) {
			log.push(['setRegisterItem', value])
			scope.register = value
		},
	})
	stores.schemaStore = lenient({
		get schemaItem() {
			return scope.schema
		},
		schemaList: [{ id: 2, title: 'S' }],
		setSchemaItem(value) {
			log.push(['setSchemaItem', value])
			scope.schema = value
		},
	})
	stores.navigationStore = lenient({ sidebarState: {} })
	stores.auditTrailStore = lenient({ auditTrailList: [] })
	stores.searchTrailStore = lenient({ searchTrailList: [] })
	stores.deletedStore = lenient({ deletedList: [] })
	stores.objectStore = lenient({})
	stores.scope = scope
}

/**
 * Mounts a sidebar with its setup bindings, filters and data loads logged.
 *
 * @param {object} entry The table entry
 * @return {Promise<object>} What the run recorded
 */
async function open(entry) {
	const { translate, translatePlural } = jest.requireMock('@nextcloud/l10n')
	const log = []
	resetStores(log)
	const page = entry.component
	const logged = (name) => () => log.push([name])
	const route = reactive({ path: entry.path, query: {} })
	const bindings = {
		...page.components,
		...stores,
		n: translatePlural,
		t: translate,
	}
	const replace = (to) => {
		log.push(['router.replace', to])
	}
	const quiet = { ...page, mounted: undefined, setup: () => bindings }
	const wrapper = mount(quiet, {
		global: { mocks: { $route: route, $router: { replace } } },
	})
	const vm = wrapper.vm.$.proxy
	for (const name of ['applyFiltersToStore', 'loadActivityData']) {
		if (name in vm) vm.$.ctx[name] = logged(name)
	}
	await flushPromises()
	return { log, route, vm, wrapper }
}

/**
 * The filter section without scoped-style ids and comment nodes.
 *
 * @param {object} wrapper The mounted sidebar
 * @return {string} The markup
 */
function filters(wrapper) {
	const html = wrapper
		.find('.filterSection')
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
	expect(html).toContain('x-ncselect')
	return html
}

/**
 * Picks a value in the select with this id.
 *
 * @param {object} wrapper The mounted sidebar
 * @param {string} id The select id
 * @param {object|null} value The value
 * @return {Promise<void>}
 */
async function pick(wrapper, id, value) {
	wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((select) => select.vm.$attrs.id === id)
		.vm.$emit('update:modelValue', value)
	await flushPromises()
}

describe.each(Object.keys(SIDEBARS))('route sidebar %s', (name) => {
	const entry = SIDEBARS[name]

	it('renders the filters without and with a register', async () => {
		const { wrapper } = await open(entry)
		expect(filters(wrapper)).toMatchSnapshot()
		stores.scope.register = { id: 1, title: 'R', schemas: [{ id: 2, title: 'S' }] }
		await nextTick()
		expect(filters(wrapper)).toMatchSnapshot()
	})

	it('picks a register, then a schema', async () => {
		const { log, wrapper } = await open(entry)
		await pick(wrapper, 'registerSelect', { id: 1, title: 'R', schemas: [] })
		await pick(wrapper, 'schemaSelect', { id: 2, title: 'S' })
		expect(log).toMatchSnapshot()
	})

	it('follows a store change', async () => {
		const { log } = await open(entry)
		stores.scope.schema = { id: 2 }
		await flushPromises()
		expect(log).toMatchSnapshot()
	})

	it('follows the route on its own path only', async () => {
		const { route, vm } = await open(entry)
		route.query = { dateFrom: '2026-01-02T00:00:00.000Z' }
		await flushPromises()
		const onPath = vm.dateFrom
		route.path = '/other'
		route.query = { dateFrom: '2027-01-02T00:00:00.000Z' }
		await flushPromises()
		expect([onPath, vm.dateFrom]).toMatchSnapshot()
	})

	it('writes the query only when it differs, on its own path', async () => {
		const { log, route, vm } = await open(entry)
		log.length = 0
		vm.updateRouteQueryFromState()
		stores.scope.register = { id: 1, schemas: [] }
		vm.updateRouteQueryFromState()
		route.path = '/other'
		vm.updateRouteQueryFromState()
		expect(log.filter(([call]) => call === 'router.replace')).toMatchSnapshot()
	})
})
