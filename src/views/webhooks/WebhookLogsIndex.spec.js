/**
 * The webhook delivery log list: the webhook filter options, loading the
 * webhooks and the logs with paging and the filter, the previous and next
 * page bounds, and the name, event and text labels. Hooks and methods run
 * against a plain `this` with stub HTTP and navigation store.
 *
 * @spec exclude Client-side webhook log list handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import WebhookLogsIndex from './WebhookLogsIndex.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, s) => s, translate: (app, s) => s }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({}))
jest.mock('../../components/shared/OffsetPager.vue', () => ({}))
jest.mock('../ListTableStates.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { getTransferData: jest.fn(), setTransferData: jest.fn(), setModal: jest.fn() },
}))

const { navigationStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = WebhookLogsIndex

/**
 * A plain list instance with computed getters and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function list(state = {}) {
	const vm = { ...WebhookLogsIndex.data(), $router: { push: jest.fn() } }
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (typeof fn === 'function' && !(name in state)) vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => consoleError.mockRestore())

describe('WebhookLogsIndex', () => {
	it('offers all webhooks first, then each by name or url', () => {
		const vm = list({ webhooksList: [{ id: 1, name: 'Orders' }, { id: 2, url: 'https://x' }] })
		expect(vm.webhookOptions).toEqual([
			{ value: null, label: 'All Webhooks' },
			{ value: 1, label: 'Orders' },
			{ value: 2, label: 'https://x' },
		])
		expect(vm.logColumns.map((c) => c.key)).toContain('statusCode')
	})

	it('takes the webhook filter from the transfer data and listens for retries', () => {
		navigationStore.getTransferData.mockReturnValue({ webhookId: 4 })
		const add = jest.spyOn(window, 'addEventListener')
		const remove = jest.spyOn(window, 'removeEventListener')
		const vm = list({ loadWebhooks: jest.fn(), loadLogs: jest.fn() })
		WebhookLogsIndex.mounted.call(vm)
		expect(vm.selectedWebhookId).toBe(4)
		expect(vm.loadWebhooks).toHaveBeenCalled()
		expect(add).toHaveBeenCalledWith('webhook-log-retried', vm.loadLogs)
		WebhookLogsIndex.beforeUnmount.call(vm)
		expect(remove).toHaveBeenCalledWith('webhook-log-retried', vm.loadLogs)
		add.mockRestore()
		remove.mockRestore()
	})

	it('loads the webhooks, empty on a missing list, logging a failure', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 1 }] } })
		const vm = list()
		await vm.loadWebhooks()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/webhooks')
		expect(vm.webhooksList).toEqual([{ id: 1 }])
		axios.get.mockResolvedValueOnce({ data: {} })
		await vm.loadWebhooks()
		expect(vm.webhooksList).toEqual([])
		axios.get.mockRejectedValueOnce(new Error('x'))
		await vm.loadWebhooks()
		expect(consoleError).toHaveBeenCalled()
	})

	it('loads a page of logs, filtered by webhook when one is chosen', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 9 }], total: 120 } })
		const vm = list({ offset: 50 })
		await vm.loadLogs()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/webhooks/logs', { params: { limit: 50, offset: 50 } })
		expect(vm.logsList).toEqual([{ id: 9 }])
		expect(vm.totalLogs).toBe(120)
		expect(vm.loading).toBe(false)

		vm.handleWebhookFilterChange(3)
		expect(vm.offset).toBe(0)
		expect(axios.get).toHaveBeenLastCalledWith('/apps/openregister/api/webhooks/logs', { params: { limit: 50, offset: 0, webhook_id: 3 } })
	})

	it('keeps the list and clears loading when the logs fail', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const vm = list({ logsList: [{ id: 1 }] })
		await vm.loadLogs()
		expect(vm.logsList).toEqual([{ id: 1 }])
		expect(vm.loading).toBe(false)
	})

	it('pages within the bounds of the total', () => {
		const loadLogs = jest.fn()
		const vm = list({ loadLogs, totalLogs: 120 })
		vm.previousPage()
		expect(loadLogs).not.toHaveBeenCalled()
		vm.nextPage()
		vm.nextPage()
		expect(vm.offset).toBe(100)
		vm.nextPage()
		expect(vm.offset).toBe(100)
		vm.previousPage()
		expect(vm.offset).toBe(50)
		expect(loadLogs).toHaveBeenCalledTimes(3)
		vm.refreshLogs()
		expect(loadLogs).toHaveBeenCalledTimes(4)
	})

	it('labels webhooks, events and long text', () => {
		const vm = list({ webhooksList: [{ id: 1, name: 'Orders' }, { id: 2, url: 'https://x' }] })
		expect(vm.getWebhookName(1)).toBe('Orders')
		expect(vm.getWebhookName(2)).toBe('https://x')
		expect(vm.getWebhookName(5)).toBe('#5')
		expect(vm.truncateEventClass('OCA\\OpenRegister\\Event\\ObjectCreatedEvent')).toBe('ObjectCreatedEvent')
		expect(vm.truncateEventClass('')).toBe('-')
		expect(vm.truncateText('', 5)).toBe('')
		expect(vm.truncateText('short', 5)).toBe('short')
		expect(vm.truncateText('longer text', 6)).toBe('longer...')
	})

	it('opens a log and goes back to the webhooks', () => {
		const vm = list()
		vm.viewLogDetails({ id: 7 })
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({ log: { id: 7 } })
		expect(navigationStore.setModal).toHaveBeenCalledWith('viewWebhookLog')
		vm.goBack()
		expect(vm.$router.push).toHaveBeenCalledWith('/webhooks')
	})
})
