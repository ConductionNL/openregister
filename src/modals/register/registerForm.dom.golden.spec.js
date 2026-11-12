import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the register edit form at each place that opens it: the
 * form it renders, what it loads, what saving sends and what follows a save.
 *
 * @spec exclude Golden record of client-side register form markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import RegisterSchemaCard from '../../components/cards/RegisterSchemaCard.vue'
import RegisterSideBar from '../../sidebars/register/RegisterSideBar.vue'
import RegisterDetail from '../../views/register/RegisterDetail.vue'
import RegistersIndex from '../../views/register/RegistersIndex.vue'
import ViewSource from '../source/ViewSource.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
jest.mock('vue3-apexcharts', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('vue-material-design-icons/Api.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Calculator.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/ChartBar.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Database.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Export.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/FileCodeOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/PackageVariantClosed.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/PostOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Sync.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Table.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/TableEye.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../store/store.js', () => ({
	configurationStore: {},
	dashboardStore: {},
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))

const stores = jest.requireMock('../../store/store.js')
const SAVED = {
	title: 'T',
	schemas: [{ id: 7, title: 'object' }, 8],
}
const REGISTER = { id: 1, title: 'Reg', slug: 'reg', schemas: [8, 99] }

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
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
 * A stand-in for a component. CnFormDialog and CnIndexPage hand their form
 * slots the fields of a form; the others render their default slot only.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	const form = {
		fields: [{ key: 'title' }],
		formData: { title: 'Reg', slug: 'reg', description: 'D', schemas: [8, 99] },
		errors: { title: 'bad title' },
		updateField: (key, value) => globalThis.fieldUpdates.push([key, value]),
	}
	const text = (value) =>
		typeof value === 'object' || typeof value === 'function'
			? JSON.stringify(value)
			: value
	return {
		name,
		inheritAttrs: false,
		methods: {
			setResult(result) {
				globalThis.dialogResults.push(result)
			},
			setFormResult(result) {
				globalThis.dialogResults.push(result)
			},
		},
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [key, text(value)]),
			)
			const fill = this.$slots.form || this.$slots['form-fields']
			return render(`x-${name}`, attrs, [
				fill ? fill(form) : this.$slots.default?.({}),
			])
		},
	}
}

/**
 * Anything at all: the parts of a page that the form does not read.
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

/**
 * Stores that answer anything, with the register calls logged.
 *
 * @param {Array} log The log of store calls
 * @param {boolean} failSave Whether saving rejects
 * @return {void}
 */
function resetStores(log, failSave) {
	const call = (name, reply) =>
		jest.fn(async (...args) => {
			log.push([name, ...args])
			if (reply) reply()
		})
	Object.keys(stores).forEach((key) => {
		stores[key] = new Proxy(
			{},
			{ get: (target, prop) => (prop in target ? target[prop] : anything) },
		)
	})
	stores.registerStore.saveRegister = call('saveRegister', () => {
		if (failSave) throw new Error('refused')
	})
	stores.registerStore.refreshRegisterList = call('refreshRegisterList')
	stores.registerStore.registerList = []
	stores.registerStore.getRegisterItem = REGISTER
	stores.schemaStore.schemaList = [{ id: 7, title: 'S7' }, { id: 8, title: 'S8' }]
	stores.schemaStore.refreshSchemaList = call('refreshSchemaList')
	stores.dashboardStore.fetchRegisters = call('fetchRegisters')
	stores.configurationStore.refreshConfigurationList = call('refreshConfigurations')
}

/**
 * A page with its setup bindings supplied and its lifecycle hooks left out.
 *
 * @param {object} page The component
 * @param {boolean} keepMounted Whether the page runs its mounted hook
 * @return {object} The page with stand-in bindings
 */
function hosted(page, keepMounted) {
	const { translate, translatePlural } = jest.requireMock('@nextcloud/l10n')
	const bindings = {
		...page.components,
		...stores,
		n: translatePlural,
		t: translate,
		formatBytes: () => '0',
	}
	return {
		...page,
		mounted: keepMounted ? page.mounted : undefined,
		setup: () => bindings,
	}
}

const SITES = {
	card: {
		page: RegisterSchemaCard,
		props: { item: REGISTER, type: 'register' },
		open: (vm) => vm.openEdit(),
		dialog: 'CnFormDialog',
	},
	source: {
		page: ViewSource,
		open: (vm) => vm.editRegister(REGISTER),
		dialog: 'CnFormDialog',
	},
	sidebar: {
		page: RegisterSideBar,
		open: (vm) => {
			vm.showEditDialog = true
		},
		dialog: 'CnFormDialog',
	},
	detail: {
		page: RegisterDetail,
		open: (vm) => {
			vm.showEditDialog = true
		},
		dialog: 'CnFormDialog',
	},
	index: {
		page: RegistersIndex,
		keepMounted: true,
		open: () => {},
		dialog: 'CnIndexPage',
		save: 'edit',
	},
}

/**
 * Mounts a site and opens its form.
 *
 * @param {object} site The site
 * @param {boolean} failSave Whether saving rejects
 * @return {Promise<object>} What the run recorded
 */
async function run(site, failSave = false) {
	const log = []
	globalThis.dialogResults = []
	globalThis.fieldUpdates = []
	resetStores(log, failSave)
	const wrapper = mount(hosted(site.page, site.keepMounted), {
		props: site.props,
		global: {
			mocks: { $route: { params: {}, query: {} }, $router: { push: jest.fn() }, t: (a, s) => `t:${s}` },
			config: { compilerOptions: {} },
			stubs: { teleport: true },
		},
	})
	site.open(wrapper.vm.$.proxy)
	await flushPromises()
	await wrapper.vm.$nextTick()
	const dialog = wrapper.findComponent({ name: site.dialog })
	return { log, wrapper, dialog }
}

/**
 * The markup without scoped-style ids and comment nodes, with the older
 * spelling of a select that stays open.
 *
 * @param {string} html The markup
 * @return {string} The markup
 */
function normalised(html) {
	return html
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/ closeonselect="false"/g, ' keepopen="true"')
		.replace(/<!--[\s\S]*?-->/g, '')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
}

describe.each(Object.keys(SITES))('register form at %s', (name) => {
	it('renders the form and loads the schemas once', async () => {
		const { log, dialog } = await run(SITES[name])
		const html = normalised(dialog.html())
		expect(html).toContain('x-ncselect')
		expect(html).toMatchSnapshot()
		expect(log.filter(([call]) => call === 'refreshSchemaList')).toHaveLength(1)
	})

	it('saves schema ids and refreshes what the place shows', async () => {
		const { log, wrapper, dialog } = await run(SITES[name])
		log.length = 0
		dialog.vm.$emit(SITES[name].save || 'confirm', SAVED)
		await flushPromises()
		expect({
			log,
			results: globalThis.dialogResults,
			refreshed: wrapper.emitted('refresh') ?? null,
		}).toMatchSnapshot()
	})

	it('reports a refused save on the dialog', async () => {
		const { log, wrapper, dialog } = await run(SITES[name], true)
		log.length = 0
		dialog.vm.$emit(SITES[name].save || 'confirm', SAVED)
		await flushPromises()
		expect({
			log,
			results: globalThis.dialogResults,
			refreshed: wrapper.emitted('refresh') ?? null,
		}).toMatchSnapshot()
	})

	it('passes field edits to the form and closes', async () => {
		const { wrapper, dialog } = await run(SITES[name])
		const fields = dialog.findAllComponents({ name: 'NcTextField' })
		fields[1].vm.$emit('update:modelValue', 'slug2')
		dialog.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', [1])
		expect(globalThis.fieldUpdates).toEqual([['slug', 'slug2'], ['schemas', [1]]])
		if (name === 'index') return
		dialog.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'CnFormDialog' }).exists()).toBe(false)
	})
})
