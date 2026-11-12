/**
 * The webhook log modal's display: the log copied from the transfer data
 * with a request body derived from the payload, the webhook list, and the
 * name, date and JSON formatting. Methods run against a plain `this` with
 * stub HTTP and navigation store.
 *
 * @spec exclude Client-side webhook log modal display.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ViewWebhookLog from './ViewWebhookLog.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, s) => s }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { getTransferData: jest.fn(), setModal: jest.fn(), clearTransferData: jest.fn() },
}))

const { navigationStore } = jest.requireMock('../../store/store.js')

/**
 * A plain modal instance with its data and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function modal(state = {}) {
	const vm = ViewWebhookLog.data()
	for (const [name, fn] of Object.entries(ViewWebhookLog.methods)) {
		if (!(name in state)) vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

beforeEach(() => jest.clearAllMocks())

describe('ViewWebhookLog display', () => {
	it('copies the transferred log and derives the request body from the payload', () => {
		const log = { id: 1, payload: { a: 1 } }
		navigationStore.getTransferData.mockReturnValue({ log })
		const vm = modal()
		vm.loadLogData()
		expect(vm.logItem).toEqual({ id: 1, payload: { a: 1 }, requestBody: '{"a":1}' })
		expect(log.requestBody).toBeUndefined()

		navigationStore.getTransferData.mockReturnValue({ log: { id: 2, payload: 'raw', requestBody: '' } })
		vm.loadLogData()
		expect(vm.logItem.requestBody).toBe('raw')

		navigationStore.getTransferData.mockReturnValue({ log: { id: 3, payload: 'p', requestBody: 'kept' } })
		vm.loadLogData()
		expect(vm.logItem.requestBody).toBe('kept')
	})

	it('leaves the log empty without transfer data', () => {
		navigationStore.getTransferData.mockReturnValue(null)
		const vm = modal()
		vm.loadLogData()
		expect(vm.logItem).toBeNull()
	})

	it('loads the webhook list when the API answers with a list', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 1, name: 'Orders' }] })
		const vm = modal()
		await vm.loadWebhooks()
		expect(vm.webhooksList).toEqual([{ id: 1, name: 'Orders' }])
		axios.get.mockResolvedValueOnce({ data: { results: [] } })
		await vm.loadWebhooks()
		expect(vm.webhooksList).toEqual([{ id: 1, name: 'Orders' }])
		axios.get.mockRejectedValueOnce(new Error('x'))
		await expect(vm.loadWebhooks()).resolves.toBeUndefined()
	})

	it('loads the log and webhooks on mount', () => {
		const vm = modal({ loadLogData: jest.fn(), loadWebhooks: jest.fn() })
		ViewWebhookLog.mounted.call(vm)
		expect(vm.loadLogData).toHaveBeenCalled()
		expect(vm.loadWebhooks).toHaveBeenCalled()
		expect(ViewWebhookLog.computed.navigationStore.call(vm)).toBe(navigationStore)
	})

	it('names webhooks and formats dates and JSON', () => {
		const vm = modal({ webhooksList: [{ id: 1, name: 'Orders' }] })
		expect(vm.getWebhookName(1)).toBe('Orders')
		expect(vm.getWebhookName(2)).toBe('#2')
		expect(vm.formatDate('')).toBe('-')
		expect(vm.formatDate('2026-01-02T03:04:05Z')).toBe(new Date('2026-01-02T03:04:05Z').toLocaleString())
		expect(vm.formatJson('')).toBe('-')
		expect(vm.formatJson('{"a":1}')).toBe('{\n  "a": 1\n}')
		expect(vm.formatJson('not json')).toBe('not json')
	})

	it('closes when the dialog closes and clears the transfer data', () => {
		const vm = modal({ logItem: { id: 1 } })
		vm.handleDialogClose(true)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		vm.handleDialogClose(false)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.clearTransferData).toHaveBeenCalled()
		expect(vm.logItem).toBeNull()
	})
})
