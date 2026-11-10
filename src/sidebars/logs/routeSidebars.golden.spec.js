import DeletedSideBar from '../deleted/DeletedSideBar.vue'
/**
 * Golden record of the trail and deleted-items sidebars' shared plumbing:
 * the register and schema pickers, the route query round trip, and applying
 * a route query once the register and schema lists have loaded.
 *
 * @spec exclude Golden record of client-side sidebar filter plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailSideBar from './AuditTrailSideBar.vue'
import { queriesEqual } from '../../services/routeQuery.js'
import SearchTrailSideBar from './SearchTrailSideBar.vue'
import {
	auditTrailStore,
	registerStore,
	schemaStore,
	searchTrailStore,
} from '../../store/store.js'

const mockLog = []

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../eventBus.js', () => ({
	__esModule: true,
	default: {
		emit: (...args) => mockLog.push(['emit', ...args]),
		on: () => {},
		off: () => {},
	},
}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({}))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('../../store/store.js', () => {
	const store = (name) =>
		new Proxy(
			{},
			{
				get: (target, key) =>
					key in target
						? target[key]
						: (...args) =>
								mockLog.push([`${name}.${String(key)}`, ...args]),
				set: (target, key, value) => {
					target[key] = value
					return true
				},
			},
		)
	return {
		auditTrailStore: store('auditTrailStore'),
		searchTrailStore: store('searchTrailStore'),
		deletedStore: store('deletedStore'),
		navigationStore: store('navigationStore'),
		registerStore: store('registerStore'),
		schemaStore: store('schemaStore'),
	}
})

const SIDEBARS = {
	audit: [AuditTrailSideBar, '/audit-trails'],
	search: [SearchTrailSideBar, '/search-trails'],
	deleted: [DeletedSideBar, '/deleted'],
}

const REGISTERS = [
	{ id: 1, title: 'One', schemas: [{ id: 10 }] },
	{ id: 2, title: 'Two', schemas: [{ id: 11 }, { id: 12 }] },
]
const SCHEMAS = [
	{ id: 10, title: 'Ten' },
	{ id: 11, title: 'Eleven' },
	{ id: 12, title: '' },
]

/**
 * Reset the register and schema stores.
 *
 * @param {object} state Lists and selected items
 */
function stores(state) {
	registerStore.registerList = state.registers ?? REGISTERS
	registerStore.registerItem = state.register ?? null
	schemaStore.schemaList = state.schemas ?? SCHEMAS
	schemaStore.schemaItem = state.schema ?? null
	registerStore.setRegisterItem = (item) => {
		mockLog.push(['setRegisterItem', item?.id ?? null])
		registerStore.registerItem = item
	}
	schemaStore.setSchemaItem = (item) => {
		mockLog.push(['setSchemaItem', item?.id ?? null])
		schemaStore.schemaItem = item
	}
}

/**
 * A component's computeds, its mixins' included.
 *
 * @param {object} component The component
 * @return {object} The computeds
 */
function computedOf(component) {
	const fields =
		component.components?.ScopeFilterFields
		|| component.components?.ScopeFiltersTab?.components?.ScopeFilterFields
		|| component.mixins?.find((mixin) => mixin.components?.ScopeFiltersTab)
			?.components.ScopeFiltersTab.components.ScopeFilterFields
	const mixed = [...(component.mixins || []), ...(fields?.mixins || [])].map(
		(mixin) => mixin.computed,
	)
	return Object.assign({}, ...mixed, component.computed)
}

/**
 * A component's methods, its mixins' included.
 *
 * @param {object} component The component
 * @return {object} The methods
 */
function methodsOf(component) {
	const mixed = (component.mixins || []).map((mixin) => mixin.methods)
	return Object.assign({}, ...mixed, component.methods)
}

/**
 * A sidebar context with its data, computeds as getters and methods.
 *
 * @param {object} component The sidebar
 * @param {object} route The route
 * @return {object} The context
 */
function sidebar(component, route) {
	const data = [...(component.mixins || []), component]
		.filter((part) => part.data)
		.map((part) => part.data.call({ t: (a, s) => s }))
	const ctx = { ...Object.assign({}, ...data), $route: route }
	ctx.$router = { replace: (to) => mockLog.push(['router.replace', to]) }
	for (const [name, getter] of Object.entries(computedOf(component))) {
		Object.defineProperty(ctx, name, {
			get: () =>
				(typeof getter === 'function' ? getter : getter.get).call(ctx),
		})
	}
	for (const [name, method] of Object.entries(methodsOf(component))) {
		ctx[name] = method.bind(ctx)
	}
	for (const stub of ['applyFiltersToStore', 'applyFilters', 'loadActivityData']) {
		if (stub in ctx) ctx[stub] = () => mockLog.push([stub])
	}
	return ctx
}

/**
 * A picker bag with its functions applied to sample options.
 *
 * @param {object} bag The NcSelect bag
 * @param {string} key 'register' or 'schema'
 * @return {object} The bag as data
 */
function bagData(bag, key) {
	if (!bag.reduce) return bag
	const samples = [
		...bag.options,
		{ title: '', [key]: { title: 'nested' } },
		{ label: 'labelled' },
		{},
	]
	return {
		options: bag.options.map((o) => ({ ...o, [key]: o[key]?.id })),
		label: bag.label,
		reduced: samples.map((o) => bag.reduce(o)?.id ?? bag.reduce(o) ?? null),
		labels: samples.map((o) => bag.getOptionLabel(o)),
	}
}

describe('route sidebars golden', () => {
	beforeEach(() => {
		mockLog.length = 0
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	for (const [name, [component, path]] of Object.entries(SIDEBARS)) {
		it(`${name}: register and schema pickers`, () => {
			const out = []
			for (const [label, state] of Object.entries({
				none: {},
				register: { register: REGISTERS[1] },
				both: { register: REGISTERS[0], schema: SCHEMAS[0] },
			})) {
				stores(state)
				const ctx = sidebar(component, { path, query: {} })
				out.push([
					label,
					bagData(ctx.registerOptions, 'register'),
					bagData(ctx.schemaOptions, 'schema'),
					ctx.selectedRegisterValue && {
						...ctx.selectedRegisterValue,
						register: ctx.selectedRegisterValue.register.id,
					},
					ctx.selectedSchemaValue && {
						...ctx.selectedSchemaValue,
						schema: ctx.selectedSchemaValue.schema.id,
					},
				])
			}
			expect(out).toMatchSnapshot()
		})

		it(`${name}: user options`, () => {
			if (name === 'deleted') {
				return
			}
			const trails = [
				{ userName: 'Ann', user: 'a' },
				{ user: 'bob' },
				{ userName: 'Ann' },
				{},
				{ user: '' },
			]
			const out = []
			for (const list of [undefined, [], trails]) {
				auditTrailStore.auditTrailList = list
				searchTrailStore.searchTrailList = list
				const ctx = sidebar(component, { path, query: {} })
				out.push(
					'userOptions' in computedOf(component)
						? ctx.userOptions
						: 'none',
				)
			}
			expect(out).toMatchSnapshot()
		})

		it(`${name}: picker changes`, () => {
			stores({ register: REGISTERS[0], schema: SCHEMAS[0] })
			const ctx = sidebar(component, { path, query: {} })
			ctx.handleRegisterChange(REGISTERS[1])
			ctx.handleSchemaChange(SCHEMAS[2])
			expect(mockLog).toMatchSnapshot()
		})

		it(`${name}: compares queries`, () => {
			const pairs = [
				[{}, {}],
				[{}, undefined],
				[{ a: 1 }, { a: '1' }],
				[{ a: 1 }, { b: 1 }],
				[
					{ a: 1, b: 2 },
					{ b: '2', a: '1' },
				],
				[{ a: 1 }, { a: 1, b: 2 }],
				[{ a: null }, { a: 'null' }],
			]
			expect(pairs.map(([a, b]) => queriesEqual(a, b))).toMatchSnapshot()
		})

		const QUERIES = {
			empty: {},
			full: {
				register: '2',
				schema: '11',
				action: 'create, update,',
				user: 'alice,bob',
				deletedBy: 'carol',
				dateFrom: '2026-01-02T00:00:00.000Z',
				dateTo: 'not a date',
				object: 'o-1',
				onlyWithChanges: '1',
				success: 'true',
				searchTerm: 'term',
				executionTimeFrom: '1',
				executionTimeTo: '9',
				resultCountFrom: '2',
				resultCountTo: '8',
			},
			unknownIds: { register: '99', schema: '98' },
			emptyStrings: { action: '', user: '', deletedBy: '' },
		}
		for (const [label, query] of Object.entries(QUERIES)) {
			it(`${name}: applies the route query ${label}`, () => {
				stores({})
				const ctx = sidebar(component, { path, query })
				ctx.applyQueryParamsFromRoute()
				const state = JSON.parse(
					JSON.stringify(
						Object.fromEntries(
							Object.entries(ctx).filter(
								([k, v]) =>
									typeof v !== 'function' && !k.startsWith('$'),
							),
						),
					),
				)
				expect({
					log: mockLog,
					state,
					query: ctx.buildQueryFromState(),
				}).toMatchSnapshot()
			})
		}

		it(`${name}: waits for the lists before applying the route scope`, () => {
			stores({ registers: [], schemas: [] })
			const ctx = sidebar(component, {
				path,
				query: { register: '1', schema: '10' },
			})
			ctx.applyQueryParamsFromRoute()
			jest.advanceTimersByTime(400)
			registerStore.registerList = REGISTERS
			jest.advanceTimersByTime(200)
			schemaStore.schemaList = SCHEMAS
			jest.advanceTimersByTime(200)
			expect(mockLog).toMatchSnapshot()
		})

		it(`${name}: gives up after ten retries`, () => {
			stores({ registers: [], schemas: [] })
			const ctx = sidebar(component, { path, query: { register: '1' } })
			ctx.applyQueryParamsFromRoute()
			jest.advanceTimersByTime(5000)
			expect(mockLog).toMatchSnapshot()
		})

		it(`${name}: ignores other routes`, () => {
			stores({})
			const ctx = sidebar(component, {
				path: '/elsewhere',
				query: { register: '1' },
			})
			ctx.applyQueryParamsFromRoute()
			ctx.updateRouteQueryFromState()
			expect(mockLog).toEqual([])
		})

		it(`${name}: writes the route query`, () => {
			stores({ register: REGISTERS[0], schema: SCHEMAS[0] })
			const same = sidebar(component, {
				path,
				query: { register: '1', schema: '10' },
			})
			same.updateRouteQueryFromState()
			const other = sidebar(component, { path, query: { register: '2' } })
			other.updateRouteQueryFromState()
			expect(mockLog).toMatchSnapshot()
		})
	}
})
