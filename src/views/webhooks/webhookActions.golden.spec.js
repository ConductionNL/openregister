/**
 * Golden record of the webhook pages' actions: the row menu of a webhook,
 * what a test delivery sends and reports for each answer and failure, and
 * the log page's request with and without a webhook filter.
 *
 * @spec exclude Golden record of client-side webhook actions.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import WebhookLogsIndex from './WebhookLogsIndex.vue'
import WebhooksIndex from './WebhooksIndex.vue'

/**
 * A stand-in icon that shows its name and size.
 *
 * @param {string} name The icon
 * @return {object} The module
 */
function mockIcon(name) {
	return {
		__esModule: true,
		default: {
			name,
			props: ['size'],
			render() {
				return require('vue').h('i', { class: `icon-${name}`, 'data-size': this.size })
			},
		},
	}
}

/**
 * A stand-in that renders nothing.
 *
 * @return {object} The module
 */
function mockBlank() {
	return { __esModule: true, default: { render: () => null } }
}

jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows'],
		render() {
			return require('vue').h('table', { class: 'cn-data-table', 'data-rows': this.rows.length })
		},
	},
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, t: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('vue-material-design-icons/Webhook.vue', () => mockIcon('Webhook'))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => mockIcon('FileDocumentOutline'))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockBlank())
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => mockIcon('DeleteOutline'))
jest.mock('vue-material-design-icons/Eye.vue', () => mockBlank())
jest.mock('vue-material-design-icons/PauseCircleOutline.vue', () => mockIcon('PauseCircleOutline'))
jest.mock('vue-material-design-icons/PlayOutline.vue', () => mockIcon('PlayOutline'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockBlank())
jest.mock('vue-material-design-icons/Refresh.vue', () => mockBlank())
jest.mock('../../components/shared/ActionMenu.vue', () => mockBlank())
jest.mock('../../components/shared/FilterToggleHeader.vue', () => mockBlank())
jest.mock('../../components/shared/ListActionsBar.vue', () => mockBlank())
jest.mock('../../components/shared/OffsetPager.vue', () => mockBlank())
jest.mock('../../components/WebhooksSidebar.vue', () => mockBlank())
jest.mock('../../services/menuActions.js', () => ({ editAction: (run) => ({ label: 'edit', run }), refreshAction: () => ({}) }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { getTransferData: () => null, setTransferData: jest.fn(), setModal: jest.fn() },
	webhookStore: { loading: false, webhookList: [], refreshWebhookList: jest.fn() },
}))

/**
 * A bare page carrying the page's methods, with the given data.
 *
 * @param {object} component The page
 * @param {object} state The data
 * @return {object} The page
 */
function pageWith(component, state) {
	const page = { ...state }
	for (const [name, method] of Object.entries(component.methods)) {
		page[name] = method.bind(page)
	}
	return page
}

let logged

beforeEach(() => {
	jest.clearAllMocks()
	logged = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => logged.mockRestore())

describe('WebhooksIndex.webhookActions', () => {
	it.each([
		['an enabled webhook', { id: 3, enabled: true }],
		['a disabled webhook', { id: 4, enabled: false }],
	])('offers its row menu for %s', (label, webhook) => {
		const page = pageWith(WebhooksIndex, {})
		const calls = []
		for (const name of ['editWebhook', 'testWebhook', 'viewLogs', 'toggleWebhook', 'deleteWebhook']) {
			page[name] = (...args) => calls.push([name, ...args])
		}
		const actions = page.webhookActions(webhook)
		actions.forEach((action) => action.run())
		expect({
			actions: actions.map(({ label, icon }) => ({ label, icon: icon?.name ?? (icon ? 'icon' : undefined) })),
			calls,
		}).toMatchSnapshot()
	})
})

describe('WebhooksIndex.testWebhook', () => {
	it.each([
		['a delivered test', () => Promise.resolve({ data: { success: true } })],
		['a refused test with a message', () => Promise.resolve({ data: { success: false, message: 'Timeout', error: 'E' } })],
		['a refused test with an error', () => Promise.resolve({ data: { success: false, error: 'Bad gateway' } })],
		['a refused test without words', () => Promise.resolve({ data: {} })],
		['an answer without data', () => Promise.resolve({})],
		['a failure with an error', () => Promise.reject({ response: { data: { error: 'Gone', message: 'M' } } })],
		['a failure with a message', () => Promise.reject({ response: { data: { message: 'Forbidden' } } })],
		['a bare failure', () => Promise.reject(new Error('offline'))],
	])('reports %s and reloads the list', async (label, answer) => {
		const page = pageWith(WebhooksIndex, {})
		const order = []
		page.loadWebhooks = () => order.push('loadWebhooks')
		axios.post.mockImplementation((...args) => {
			order.push(['post', ...args])
			return answer()
		})
		showSuccess.mockImplementation((text) => order.push(['showSuccess', text]))
		showError.mockImplementation((text) => order.push(['showError', text]))
		await page.testWebhook(7)
		expect({ order, logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})
})

describe('WebhookLogsIndex.loadLogs', () => {
	it.each([
		['all webhooks', null, () => Promise.resolve({ data: { results: [{ id: 1 }], total: 9 } })],
		['one webhook', 5, () => Promise.resolve({ data: { results: [{ id: 2 }], total: 1 } })],
		['an empty answer', null, () => Promise.resolve({ data: {} })],
		['a failure', 5, () => Promise.reject(new Error('offline'))],
	])('loads the logs of %s', async (label, selectedWebhookId, answer) => {
		const page = pageWith(WebhookLogsIndex, { loading: false, limit: 20, offset: 40, selectedWebhookId, logsList: ['old'], totalLogs: 3 })
		const seen = []
		axios.get.mockImplementation((...args) => {
			seen.push([args, page.loading])
			return answer()
		})
		await page.loadLogs()
		const data = Object.fromEntries(Object.entries(page).filter(([, value]) => typeof value !== 'function'))
		expect({ data, seen, logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})
})
