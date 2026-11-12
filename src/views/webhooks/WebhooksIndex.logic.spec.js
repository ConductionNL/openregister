/**
 * The webhooks list: filtering by text and status, paging, the event
 * properties of the chosen events, the row actions, and the test, toggle,
 * delete, log and edit handlers with their notices. Computed values and
 * methods run against a plain `this` with a stub webhook store and HTTP.
 *
 * @spec exclude Client-side webhooks list handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import WebhooksIndex from './WebhooksIndex.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, s) => s, translate: (app, s) => s }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => ({ name: 'DeleteOutline' }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ name: 'FileDocumentOutline' }))
jest.mock('vue-material-design-icons/PauseCircleOutline.vue', () => ({ name: 'PauseCircleOutline' }))
jest.mock('vue-material-design-icons/PlayOutline.vue', () => ({ name: 'PlayOutline' }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Webhook.vue', () => ({}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({}))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({}))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({}))
jest.mock('../../components/shared/OffsetPager.vue', () => ({}))
jest.mock('../../components/WebhooksSidebar.vue', () => ({}))
jest.mock('../ListTableStates.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setTransferData: jest.fn(), setModal: jest.fn() },
	webhookStore: {
		webhookList: [],
		loading: false,
		refreshWebhookList: jest.fn(),
		toggleWebhookEnabled: jest.fn(),
		deleteWebhook: jest.fn(),
	},
}))

const { navigationStore, webhookStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = WebhooksIndex

/**
 * A plain list instance with computed getters and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function list(state = {}) {
	const vm = { ...WebhooksIndex.data.call({}), $router: { push: jest.fn() } }
	for (const [name, fn] of Object.entries(computed)) {
		if (!(name in state)) Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (typeof fn === 'function' && !(name in state)) vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

const HOOKS = [
	{ id: 1, name: 'Orders', url: 'https://shop/orders', enabled: true },
	{ id: 2, name: 'Audit', url: 'https://log/audit', enabled: false },
	{ id: 3, name: 'Shop sync', url: 'https://erp/x', enabled: true },
]

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	webhookStore.webhookList = HOOKS
	webhookStore.loading = false
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => consoleError.mockRestore())

describe('WebhooksIndex filtering and paging', () => {
	it('filters on name or url, case-insensitive, and on status', () => {
		expect(list({ searchQuery: 'SHOP' }).filteredWebhooks.map((w) => w.id)).toEqual([1, 3])
		expect(list({ enabledFilter: false }).filteredWebhooks.map((w) => w.id)).toEqual([2])
		expect(list({ searchQuery: 'shop', enabledFilter: true }).totalWebhooks).toBe(2)
		expect(list().totalWebhooks).toBe(3)
	})

	it('pages the filtered list', () => {
		expect(list({ limit: 2, offset: 0 }).webhooksList.map((w) => w.id)).toEqual([1, 2])
		expect(list({ limit: 2, offset: 2 }).webhooksList.map((w) => w.id)).toEqual([3])
	})

	it('resets to the first page on a new search or status', () => {
		const vm = list({ offset: 50 })
		vm.handleSearchUpdate('x')
		expect(vm).toMatchObject({ searchQuery: 'x', offset: 0 })
		vm.offset = 50
		vm.handleEnabledUpdate(true)
		expect(vm).toMatchObject({ enabledFilter: true, offset: 0 })
	})

	it('reads the store loading flag and names the columns', () => {
		webhookStore.loading = true
		const vm = list()
		expect(vm.loading).toBe(true)
		expect(vm.webhookColumns.map((c) => c.key)).toEqual(['name', 'url', 'method', 'enabled', 'lastTriggeredAt', 'successRate'])
	})

	it('collects the distinct properties of the chosen events', () => {
		const vm = list({
			newWebhook: { events: ['A', 'B', 'C'] },
			availableEvents: [{ class: 'A', properties: ['id', 'uuid'] }, { class: 'B', properties: ['uuid', 'title'] }],
		})
		expect(vm.selectedEventProperties).toEqual([
			{ value: 'id', label: 'id' },
			{ value: 'uuid', label: 'uuid' },
			{ value: 'title', label: 'title' },
		])
		expect(list({ newWebhook: { events: [] } }).selectedEventProperties).toEqual([])
	})
})

describe('WebhooksIndex row actions', () => {
	it('offers edit, test, logs, enable or disable, and delete', () => {
		const handlers = { editWebhook: jest.fn(), testWebhook: jest.fn(), viewLogs: jest.fn(), toggleWebhook: jest.fn(), deleteWebhook: jest.fn() }
		const vm = list(handlers)
		const actions = vm.webhookActions(HOOKS[0])
		expect(actions.map((a) => a.label).slice(1)).toEqual(['Test', 'View Logs', 'Disable', 'Delete'])
		actions.forEach((action) => action.run())
		expect(handlers.editWebhook).toHaveBeenCalledWith(HOOKS[0])
		expect(handlers.testWebhook).toHaveBeenCalledWith(1)
		expect(handlers.viewLogs).toHaveBeenCalledWith(1)
		expect(handlers.toggleWebhook).toHaveBeenCalledWith(HOOKS[0])
		expect(handlers.deleteWebhook).toHaveBeenCalledWith(1)
		expect(vm.webhookActions(HOOKS[1])[3].label).toBe('Enable')
		expect(vm.webhookActions(HOOKS[1])[3].icon.name).toBe('PlayOutline')
	})
})

describe('WebhooksIndex handlers', () => {
	it('loads the list on mount and reports a failed load', async () => {
		const vm = list({ loadWebhooks: jest.fn() })
		WebhooksIndex.mounted.call(vm)
		expect(vm.loadWebhooks).toHaveBeenCalled()

		webhookStore.refreshWebhookList.mockRejectedValueOnce(new Error('x'))
		await list().loadWebhooks()
		expect(showError).toHaveBeenCalledWith('Failed to load webhooks')
		const refresh = list({ loadWebhooks: jest.fn() })
		refresh.refreshWebhooks()
		expect(refresh.loadWebhooks).toHaveBeenCalled()
	})

	it('reports a successful and a failed test delivery and reloads', async () => {
		const loadWebhooks = jest.fn()
		axios.post.mockResolvedValueOnce({ data: { success: true } })
		await list({ loadWebhooks }).testWebhook(7)
		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/webhooks/7/test')
		expect(showSuccess).toHaveBeenCalledWith('Test webhook sent successfully')

		axios.post.mockResolvedValueOnce({ data: { success: false, message: 'HTTP 500' } })
		await list({ loadWebhooks }).testWebhook(7)
		expect(showError).toHaveBeenLastCalledWith('HTTP 500')

		axios.post.mockResolvedValueOnce({ data: {} })
		await list({ loadWebhooks }).testWebhook(7)
		expect(showError).toHaveBeenLastCalledWith('Test webhook delivery failed')
		expect(loadWebhooks).toHaveBeenCalledTimes(3)
	})

	it('reports a test request that throws', async () => {
		const loadWebhooks = jest.fn()
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Blocked target' } } })
		await list({ loadWebhooks }).testWebhook(1)
		expect(showError).toHaveBeenLastCalledWith('Blocked target')
		axios.post.mockRejectedValueOnce(new Error('offline'))
		await list({ loadWebhooks }).testWebhook(1)
		expect(showError).toHaveBeenLastCalledWith('Failed to test webhook')
		expect(loadWebhooks).toHaveBeenCalledTimes(2)
	})

	it('toggles and deletes through the store with a notice', async () => {
		const vm = list()
		await vm.toggleWebhook(HOOKS[0])
		expect(webhookStore.toggleWebhookEnabled).toHaveBeenCalledWith(HOOKS[0])
		expect(showSuccess).toHaveBeenCalledWith('Webhook updated')
		await vm.deleteWebhook(2)
		expect(webhookStore.deleteWebhook).toHaveBeenCalledWith(2)
		expect(showSuccess).toHaveBeenCalledWith('Webhook deleted')

		webhookStore.toggleWebhookEnabled.mockRejectedValueOnce(new Error('x'))
		webhookStore.deleteWebhook.mockRejectedValueOnce(new Error('x'))
		await vm.toggleWebhook(HOOKS[0])
		await vm.deleteWebhook(2)
		expect(showError).toHaveBeenCalledWith('Failed to update webhook')
		expect(showError).toHaveBeenCalledWith('Failed to delete webhook')
	})

	it('opens the logs, the create dialog and the edit dialog', () => {
		const vm = list()
		vm.viewLogs(3)
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({ webhookId: 3 })
		expect(vm.$router.push).toHaveBeenCalledWith('/webhooks/logs')
		vm.openCreateDialog()
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({ webhook: null })
		vm.editWebhook(HOOKS[1])
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({ webhook: HOOKS[1] })
		expect(navigationStore.setModal).toHaveBeenCalledWith('editWebhook')
	})

	it('formats the success rate and shortens long urls', () => {
		const vm = list()
		expect(vm.formatSuccessRate({ totalDeliveries: 0 })).toBe('-')
		expect(vm.formatSuccessRate({ totalDeliveries: 3, successfulDeliveries: 2 })).toBe('67%')
		expect(vm.truncateUrl('')).toBe('')
		expect(vm.truncateUrl('https://short')).toBe('https://short')
		expect(vm.truncateUrl('https://' + 'a'.repeat(50))).toBe(('https://' + 'a'.repeat(50)).substring(0, 37) + '...')
	})
})
