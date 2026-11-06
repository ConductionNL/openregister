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
	t: (app, text, vars) => (vars ? `t:${text} ${JSON.stringify(vars)}` : `t:${text}`),
	translate: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/AccountOutline.vue', () => mockModule('AccountOutline'))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => mockModule('AlertCircleOutline'))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockModule('ArrowLeft'))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => mockModule('DeleteOutline'))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => mockModule('DotsHorizontal'))
jest.mock('vue-material-design-icons/Eye.vue', () => mockModule('Eye'))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => mockModule('EyeOutline'))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => mockModule('FileDocumentOutline'))
jest.mock('vue-material-design-icons/FileOutline.vue', () => mockModule('FileOutline'))
jest.mock('vue-material-design-icons/FilterVariant.vue', () => mockModule('FilterVariant'))
jest.mock('vue-material-design-icons/PauseCircleOutline.vue', () => mockModule('PauseCircleOutline'))
jest.mock('vue-material-design-icons/Pencil.vue', () => mockModule('Pencil'))
jest.mock('vue-material-design-icons/PlayOutline.vue', () => mockModule('PlayOutline'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockModule('Plus'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockModule('Refresh'))
jest.mock('vue-material-design-icons/Webhook.vue', () => mockModule('Webhook'))
jest.mock('../components/FilesSidebar.vue', () => mockModule('FilesSidebar'))
jest.mock('../components/PaginationComponent.vue', () => mockModule('PaginationComponent'))
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
					.map((slot) => render(`slot-${slot}`, this.$slots[slot]({ option: {} }))),
			)
		},
	}
}

const PAGES = {
	entities: { component: EntitiesIndex },
	files: { component: FilesIndex, total: 'totalFiles', load: 'loadFiles' },
	templates: {
		component: TemplatesIndex,
		total: 'totalTemplates',
		load: 'loadTemplates',
	},
	webhooks: { component: WebhooksIndex, total: 'totalWebhooks' },
	webhookLogs: { component: WebhookLogsIndex, total: 'totalLogs', load: 'loadLogs' },
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

	it('hides the pager on one page', async () => {
		if (!entry.total || name === 'webhooks') return
		const { vm, wrapper } = await open(entry)
		vm[entry.total] = vm.limit
		await flushPromises()
		expect(wrapper.find('.pagination').exists()).toBe(false)
	})
})
