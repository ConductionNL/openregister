import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the header and the pager of the list pages: their markup,
 * the filter toggle, and what paging forward and back loads.
 *
 * @spec exclude Golden record of client-side list page markup and paging.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EntitiesIndex from './entities/EntitiesIndex.vue'
import FilesIndex from './files/FilesIndex.vue'
import TemplatesIndex from './templates/TemplatesIndex.vue'
import WebhookLogsIndex from './webhooks/WebhookLogsIndex.vue'
import WebhooksIndex from './webhooks/WebhooksIndex.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { delete: jest.fn(), get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars) =>
		vars ? `t:${text} ${JSON.stringify(vars)}` : `t:${text}`,
	translate: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDataTable: mockTable(),
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/AccountOutline.vue', () =>
	mockModule('AccountOutline'),
)
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () =>
	mockModule('AlertCircleOutline'),
)
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockModule('ArrowLeft'))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () =>
	mockModule('DeleteOutline'),
)
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () =>
	mockModule('DotsHorizontal'),
)
jest.mock('vue-material-design-icons/Eye.vue', () => mockModule('Eye'))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => mockModule('EyeOutline'))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () =>
	mockModule('FileDocumentOutline'),
)
jest.mock('vue-material-design-icons/FileOutline.vue', () =>
	mockModule('FileOutline'),
)
jest.mock('vue-material-design-icons/FilterVariant.vue', () =>
	mockModule('FilterVariant'),
)
jest.mock('vue-material-design-icons/PauseCircleOutline.vue', () =>
	mockModule('PauseCircleOutline'),
)
jest.mock('vue-material-design-icons/Pencil.vue', () => mockModule('Pencil'))
jest.mock('vue-material-design-icons/PlayOutline.vue', () =>
	mockModule('PlayOutline'),
)
jest.mock('vue-material-design-icons/Plus.vue', () => mockModule('Plus'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockModule('Refresh'))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	mockModule('TrashCanOutline'),
)
jest.mock('vue-material-design-icons/Webhook.vue', () => mockModule('Webhook'))
jest.mock('../components/FilesSidebar.vue', () => mockModule('FilesSidebar'))
jest.mock('../components/PaginationComponent.vue', () =>
	mockModule('PaginationComponent'),
)
jest.mock('../components/WebhooksSidebar.vue', () => mockModule('WebhooksSidebar'))
jest.mock('../eventBus.js', () => ({
	__esModule: true,
	default: { on: jest.fn(), off: jest.fn(), emit: jest.fn() },
}))
jest.mock('../store/store.js', () => ({
	navigationStore: {
		sidebarState: { entities: true },
		setSidebarState: jest.fn(),
		getTransferData: () => null,
		setTransferData: jest.fn(),
		setModal: jest.fn(),
	},
	webhookStore: { webhookList: [], refreshWebhookList: jest.fn() },
}))

const { navigationStore } = jest.requireMock('../store/store.js')

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
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
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
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(([key]) => !/^on[A-Z]/.test(key)),
			)
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) =>
						render(`slot-${slot}`, this.$slots[slot]({ option: {} })),
					),
			)
		},
	}
}

/**
 * A stand-in for CnDataTable that draws its column labels, each cell (or its
 * `column-<key>` slot) and the row actions, so a golden compares what each
 * cell reads.
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
			const actions = this.$slots['row-actions']
			const head = this.columns.map((col) => render('x-th', col.label))
			if (actions) {
				head.push(render('x-th', this.$slots['actions-header']?.()))
			}
			const body = this.rows.map((row) => {
				const cells = this.columns.map((col) => {
					const slot = this.$slots[`column-${col.key}`]
					return render(
						'x-td',
						slot ? slot({ row, value: row[col.key] }) : String(row[col.key] ?? '—'),
					)
				})
				if (actions) {
					cells.push(render('x-td', actions({ row })))
				}
				return render('x-tr', { key: row[this.rowKey] }, cells)
			})
			return render('x-CnDataTable', this.$attrs, [
				render('x-thead', head),
				render('x-tbody', body),
			])
		},
	}
}

const PAGES = {
	entities: {
		component: EntitiesIndex,
		rows: {
			entitiesList: [{ id: 1, type: 'person', value: 'Jan', category: 'pii' }],
			totalEntities: 1,
			selectedEntities: [1],
		},
	},
	files: {
		component: FilesIndex,
		total: 'totalFiles',
		load: 'loadFiles',
		rows: {
			filesList: [
				{
					id: 1,
					fileName: 'a.pdf',
					extractionStatus: 'failed',
					extractionError: 'bad',
				},
				{ id: 2, fileName: 'b.pdf', extractionStatus: 'completed' },
			],
			totalFiles: 2,
		},
	},
	templates: {
		component: TemplatesIndex,
		total: 'totalTemplates',
		load: 'loadTemplates',
		rows: {
			templatesList: [{ id: 1, name: 'Letter', type: 'docx' }],
			totalTemplates: 1,
		},
	},
	webhooks: {
		component: WebhooksIndex,
		total: 'totalWebhooks',
		webhooks: [
			{
				id: 1,
				name: 'On',
				url: 'https://a.example',
				method: 'POST',
				enabled: true,
			},
			{
				id: 2,
				name: 'Off',
				url: 'https://b.example',
				method: 'POST',
				enabled: false,
			},
		],
	},
	webhookLogs: {
		component: WebhookLogsIndex,
		total: 'totalLogs',
		load: 'loadLogs',
		rows: { logsList: [{ id: 1, webhookId: 1, success: true }], totalLogs: 1 },
	},
}

const RECORDED =
	/^(refresh(?!Action)|retry|show|view|edit|test|toggle(?!Sidebar|Sort)|delete)/
const calls = []

/**
 * A call argument for the record: rows by id.
 *
 * @param {*} value The argument
 * @return {*} The id of a row, else the value
 */
function summary(value) {
	return value?.id ?? value
}

/**
 * Mounts a page without its data loads; the load method is logged.
 *
 * @param {object} page The table entry
 * @return {Promise<object>} What the run recorded
 */
async function open(page) {
	const log = []
	const methods = { ...page.component.methods }
	if (page.load) {
		methods[page.load] = () => log.push(page.load)
	}
	for (const name of Object.keys(methods).filter((key) => RECORDED.test(key))) {
		methods[name] = (...args) => calls.push([name, ...args.map(summary)])
	}
	const wrapper = mount(
		{ ...page.component, methods, mounted: undefined },
		{
			global: {
				mocks: {
					$route: { params: {}, query: {} },
					$router: { push: jest.fn() },
					t: jest.requireMock('@nextcloud/l10n').t,
				},
				config: { warnHandler: () => {} },
			},
		},
	)
	await flushPromises()
	return { log, vm: wrapper.vm.$.proxy, wrapper }
}

/**
 * The markup of one part without scoped-style ids and comment nodes.
 *
 * @param {object} wrapper The mounted page
 * @param {string} selector The part
 * @return {string} The markup
 */
function part(wrapper, selector) {
	const html = wrapper
		.find(selector)
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+<\/slot-default>/g, '</slot-default>')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
	expect(html).not.toBe('')
	return html
}

/**
 * The page body with the menu chrome left out: the toggle's name and icon are
 * those of a closed menu and differ per wrapper, its entries are what counts.
 *
 * @param {object} wrapper The mounted page
 * @return {string} The markup
 */
function body(wrapper) {
	return part(wrapper, '.viewContainer')
		.replace(/<x-ncactions[^>]*>/g, '<x-ncactions>')
		.replace(
			/<slot-icon>\s*<x-dotshorizontal[^>]*><\/x-dotshorizontal>\s*<\/slot-icon>/g,
			'',
		)
		.replace(/ class=""/g, '')
		.replace(/\s+/g, ' ')
		.replace(/> ?</g, '>\n<')
}

/**
 * Runs every menu entry on the page and logs what it reaches.
 *
 * @param {object} wrapper The mounted page
 * @return {Promise<Array>} Per entry its text and the page methods it called
 */
async function clicks(wrapper) {
	const seen = []
	const entries = wrapper.findAllComponents({ name: 'NcActionButton' })
	for (const entry of entries) {
		calls.length = 0
		await entry.vm.$attrs.onClick()
		seen.push([entry.text(), ...calls])
	}
	return seen
}

/**
 * Clicks a pager button.
 *
 * @param {object} wrapper The mounted page
 * @param {number} index 0 for previous, 1 for next
 * @return {Promise<void>}
 */
async function page(wrapper, index) {
	wrapper
		.find('.pagination')
		.findAllComponents({ name: 'NcButton' })
		[index].vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	axios.get.mockResolvedValue({ data: { results: [], total: 0 } })
	navigationStore.setSidebarState.mockClear()
})

describe.each(Object.keys(PAGES))('list page %s', (name) => {
	const entry = PAGES[name]

	it('renders its header', async () => {
		const { wrapper } = await open(entry)
		if (name === 'webhookLogs') return
		expect(part(wrapper, '.viewHeader')).toMatchSnapshot()
	})

	it('toggles the filters', async () => {
		if (name === 'webhookLogs') return
		const { vm, wrapper } = await open(entry)
		const before = vm.sidebarOpen
		wrapper
			.find('.viewHeader')
			.findComponent({ name: 'NcButton' })
			.vm.$emit('click')
		await flushPromises()
		expect({
			before,
			after: vm.sidebarOpen,
			stored: navigationStore.setSidebarState.mock.calls,
			html: part(wrapper, '.viewHeader'),
		}).toMatchSnapshot()
	})

	it('pages forward and back within the total', async () => {
		if (!entry.total) return
		jest.requireMock('../store/store.js').webhookStore.webhookList = Array.from(
			{ length: 25 },
			(unused, id) => ({ id, name: `w${id}` }),
		)
		const { log, vm, wrapper } = await open(entry)
		const seen = []
		vm.limit = 10
		vm.loading = false
		if (name !== 'webhooks') vm[entry.total] = 25
		vm.offset = 0
		await flushPromises()
		if (!wrapper.find('.pagination').exists()) {
			seen.push('no pager')
		} else {
			for (const index of [0, 1, 1, 1, 0, 0, 0]) {
				await page(wrapper, index)
				seen.push([vm.offset, part(wrapper, '.pagination')])
			}
		}
		expect({ seen, log }).toMatchSnapshot()
	})

	it('renders loading, empty and filled, and its menus reach the page', async () => {
		if (!entry.rows && !entry.webhooks) return
		const store = jest.requireMock('../store/store.js').webhookStore
		store.webhookList = []
		const { vm, wrapper } = await open(entry)
		const shown = {}
		vm.loading = true
		await flushPromises()
		shown.loading = body(wrapper)
		vm.loading = false
		await flushPromises()
		shown.empty = body(wrapper)
		store.webhookList = entry.webhooks || []
		const filled = await open(entry)
		Object.assign(filled.vm, entry.rows || {})
		filled.vm.loading = false
		await flushPromises()
		shown.filled = body(filled.wrapper)
		expect(shown.filled).toContain('x-ncactionbutton')
		expect(shown).toMatchSnapshot()
		expect(await clicks(filled.wrapper)).toMatchSnapshot()
	})

	it('hides the pager on one page', async () => {
		if (!entry.total || name === 'webhooks') return
		const { vm, wrapper } = await open(entry)
		vm[entry.total] = vm.limit
		await flushPromises()
		expect(wrapper.find('.pagination').exists()).toBe(false)
	})
})
