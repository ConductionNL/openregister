/**
 * Golden record of ViewWebhookLog::retryWebhook: the retry request, the
 * toasts, the close and the refresh event, for every answer.
 *
 * @spec exclude Golden record of client-side modal orchestration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ViewWebhookLog from './ViewWebhookLog.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({ t: (app, s) => s }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../store/store.js', () => ({ navigationStore: {} }))

const ANSWERS = {
	delivered: () => Promise.resolve({ data: { success: true } }),
	failedWithMessage: () =>
		Promise.resolve({ data: { success: false, message: 'Timeout' } }),
	failedWithError: () =>
		Promise.resolve({ data: { success: false, error: 'Refused' } }),
	failedPlain: () => Promise.resolve({ data: { success: 'yes' } }),
	noData: () => Promise.resolve({}),
	thrownWithError: () =>
		Promise.reject({ response: { data: { error: 'Gone', message: 'm' } } }),
	thrownWithMessage: () =>
		Promise.reject({ response: { data: { message: 'Busy' } } }),
	thrownPlain: () => Promise.reject(new Error('offline')),
}

describe('ViewWebhookLog.retryWebhook (golden)', () => {
	for (const [answerName, answer] of Object.entries(ANSWERS)) {
		it(`retries, answering ${answerName}`, async () => {
			const log = []
			axios.post.mockImplementation((...args) => {
				log.push(['post', ...args])
				return answer()
			})
			showError.mockImplementation((...args) =>
				log.push(['showError', ...args]),
			)
			showSuccess.mockImplementation((...args) =>
				log.push(['showSuccess', ...args]),
			)
			const listener = () => log.push(['webhook-log-retried'])
			window.addEventListener('webhook-log-retried', listener)
			const ctx = { logItem: { id: 4 }, retrying: false }
			ctx.closeModal = () => log.push(['closeModal'])
			await ViewWebhookLog.methods.retryWebhook.call(ctx)
			window.removeEventListener('webhook-log-retried', listener)
			expect({ log, retrying: ctx.retrying }).toMatchSnapshot()
		})
	}

	it.each([[null], [{}]])('does nothing for the log item %j', async (logItem) => {
		axios.post.mockClear()
		const ctx = { logItem, retrying: false }
		await ViewWebhookLog.methods.retryWebhook.call(ctx)
		expect(axios.post).not.toHaveBeenCalled()
		expect(ctx.retrying).toBe(false)
	})
})
