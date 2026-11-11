/**
 * Golden record of the dashboard and register sidebars' and the register
 * import modal's pickers, and of the import modal's file, submit and
 * completeness steps.
 *
 * @spec exclude Golden record of client-side picker and import plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ImportRegister from '../../modals/register/ImportRegister.vue'
import StatisticsScopeFilter from '../StatisticsScopeFilter.vue'
import RegistersSideBar from './RegistersSideBar.vue'
import { registerStore, schemaStore } from '../../store/store.js'

const mockLog = []
globalThis.mockStoreLog = mockLog

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/Import.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/ChartBar.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/PackageVariantClosed.vue', () => ({}))
jest.mock('vue-material-design-icons/Table.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))
jest.mock('../../store/store.js', () => {
	const store = (name) =>
		new Proxy(
			{},
			{
				get: (target, key) =>
					key in target
						? target[key]
						: (...args) =>
								(globalThis.mockStoreLog || []).push([
									`${name}.${String(key)}`,
									...args,
								]),
				set: (target, key, value) => {
					target[key] = value
					return true
				},
			},
		)
	return {
		dashboardStore: store('dashboardStore'),
		navigationStore: store('navigationStore'),
		objectStore: store('objectStore'),
		registerStore: store('registerStore'),
		schemaStore: store('schemaStore'),
	}
})

const REGISTERS = [
	{ id: 1, title: 'One', schemas: [{ id: 10 }] },
	{ id: 2, title: 'Two', schemas: [{ id: 11 }, { id: 12 }] },
	{ id: 3, title: 'Three' },
]
const SCHEMAS = [
	{ id: 10, title: 'Ten' },
	{ id: 11, title: 'Eleven' },
	{ id: 12, title: '' },
]

/**
 * Reset the register and schema stores.
 *
 * @param {object} state Selected items
 */
function stores(state) {
	registerStore.registerList = REGISTERS
	registerStore.registerItem = state.register ?? null
	schemaStore.schemaList = SCHEMAS
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
	const mixed = (component.mixins || []).map((mixin) => mixin.computed)
	return Object.assign({}, ...mixed, component.computed)
}

/**
 * A component context with its data, computeds as getters and methods.
 *
 * @param {object} component The component
 * @param {object} state State to put over its data
 * @return {object} The context
 */
function instance(component, state = {}) {
	const data = component.data ? component.data.call({ t: (a, s) => s }) : {}
	const ctx = { ...data, ...state }
	for (const [name, getter] of Object.entries(computedOf(component))) {
		if (!(name in state)) {
			Object.defineProperty(ctx, name, {
				get: () =>
					(typeof getter === 'function' ? getter : getter.get).call(ctx),
			})
		}
	}
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
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

const COMPONENTS = {
	import: ImportRegister,
	// The dashboard and registers sidebars both render these pickers.
	dashboard: StatisticsScopeFilter,
	registers: StatisticsScopeFilter,
}

/**
 * A file the way an input hands it over.
 *
 * @param {string} name The name
 * @param {number} size The size in bytes
 * @return {File} The file
 */
function file(name, size = 1) {
	return new File(['x'.repeat(size)], name)
}

describe('scope pickers golden', () => {
	beforeEach(() => {
		mockLog.length = 0
	})

	for (const [name, component] of Object.entries(COMPONENTS)) {
		it(`${name}: register and schema pickers`, () => {
			const out = []
			for (const [label, state] of Object.entries({
				none: {},
				register: { register: REGISTERS[1] },
				noSchemas: { register: REGISTERS[2] },
				both: { register: REGISTERS[0], schema: SCHEMAS[0] },
			})) {
				stores(state)
				const ctx = instance(component)
				let schemaBag
				try {
					schemaBag = bagData(ctx.schemaOptions, 'schema')
				} catch (error) {
					schemaBag = `throws ${error.constructor.name}`
				}
				out.push([
					label,
					bagData(ctx.registerOptions, 'register'),
					schemaBag,
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
	}

	it('registers: object stats breakdown', () => {
		const ctx = instance(RegistersSideBar)
		const sources = [
			null,
			{},
			{ stats: {} },
			{ stats: { objects: {} } },
			{
				stats: {
					objects: { size: 2048, invalid: 1, deleted: 2, locked: 3 },
				},
			},
			{ stats: { objects: { size: 0, invalid: 0, deleted: 4 } } },
		]
		expect(
			sources.map((source) => ctx.objectsBreakdown(source)),
		).toMatchSnapshot()
	})

	it('import: file selection', () => {
		const ctx = instance(ImportRegister)
		const out = []
		for (const files of [
			[],
			[file('a.json')],
			[file('a.exe')],
			[file('b.XLSX')],
			[file('noext')],
		]) {
			ctx.handleFileUpload({ target: { files } })
			out.push([ctx.selectedFile?.name ?? null, ctx.error])
		}
		expect(out).toMatchSnapshot()
	})

	it('import: completeness', () => {
		const out = []
		for (const name of [null, 'a.json', 'a.xlsx', 'a.xls', 'a.csv', 'a.txt']) {
			for (const [register, schema] of [
				[null, null],
				[{ id: 1 }, null],
				[{ id: 1 }, { id: 2 }],
			]) {
				const ctx = instance(ImportRegister, {
					selectedRegisterValue: register,
					selectedSchemaValue: schema,
				})
				ctx.selectedFile = name ? file(name) : null
				out.push([
					name,
					Boolean(register),
					Boolean(schema),
					ctx.checkDataCompleted(),
				])
			}
		}
		expect(out).toMatchSnapshot()
	})

	const IMPORTS = {
		noFile: [null, null],
		invalidType: [file('a.exe'), null],
		small: [file('a.json'), { responseData: { summary: { created: 1 } } }],
		large: [file('a.json', 600 * 1024), { summary: { created: 2 } }],
		noSummary: [file('a.json'), {}],
		fails: [file('a.json'), new Error('rejected')],
		failsQuietly: [file('a.json'), new Error('')],
	}
	for (const [label, [chosen, reply]] of Object.entries(IMPORTS)) {
		it(`import: submit ${label}`, async () => {
			jest.spyOn(console, 'error').mockImplementation(() => {})
			registerStore.importRegister = (f, onStatus) => {
				mockLog.push(['importRegister', f.name, f.size])
				onStatus('beat')
				return reply instanceof Error
					? Promise.reject(reply)
					: Promise.resolve(reply)
			}
			const ctx = instance(ImportRegister)
			ctx.selectedFile = chosen
			const pending = ctx.importRegister()
			const during = { loading: ctx.loading, heartbeat: ctx.isHeartbeatActive }
			await pending
			expect({
				log: mockLog,
				during,
				after: {
					loading: ctx.loading,
					heartbeat: ctx.isHeartbeatActive,
					heartbeatStatus: ctx.heartbeatStatus,
					success: ctx.success,
					error: ctx.error,
					importSummary: ctx.importSummary,
					importResults: ctx.importResults,
				},
			}).toMatchSnapshot()
			jest.restoreAllMocks()
		})
	}

	for (const [label, [option, refresh]] of Object.entries({
		cleared: [null, null],
		withSchemas: [REGISTERS[1], 'ok'],
		noSchemas: [REGISTERS[2], 'ok'],
		refreshFails: [REGISTERS[0], new Error('down')],
	})) {
		it(`import: register change ${label}`, async () => {
			jest.spyOn(console, 'error').mockImplementation(() => {})
			stores({})
			schemaStore.refreshSchemaList = () => {
				mockLog.push(['refreshSchemaList'])
				return refresh instanceof Error
					? Promise.reject(refresh)
					: Promise.resolve()
			}
			const ctx = instance(ImportRegister)
			await ctx.handleRegisterChange(option)
			expect({
				log: mockLog,
				schemaLoading: ctx.schemaLoading,
			}).toMatchSnapshot()
			jest.restoreAllMocks()
		})
	}

	it('import: close resets the form', () => {
		const ctx = instance(ImportRegister)
		Object.assign(ctx, {
			selectedFile: 'f',
			loading: true,
			success: true,
			error: 'e',
			includeObjects: false,
			validation: false,
			events: true,
			rbac: false,
			multi: false,
			importSummary: 1,
			importResults: 2,
			expandedSheets: { a: 1 },
			expandedErrors: { b: 1 },
			isHeartbeatActive: true,
			heartbeatStatus: 's',
		})
		ctx.closeModal()
		const state = Object.fromEntries(
			Object.entries(ctx).filter(([, v]) => typeof v !== 'function'),
		)
		expect({ log: mockLog, state }).toMatchSnapshot()
	})
})
