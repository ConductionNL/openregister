/**
 * Golden record of EditWebhook's configuration-flag setters and its reset on
 * close: which configuration both copies hold after each setter, and the form
 * a close leaves behind.
 *
 * @spec exclude Golden record of client-side form plumbing.
 */
import EditWebhook from './EditWebhook.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn(), getTransferData: jest.fn() },
	webhookStore: { saveWebhook: jest.fn() },
}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Tune.vue', () => ({}))
jest.mock('vue-material-design-icons/Webhook.vue', () => ({}))

const { methods } = EditWebhook

/**
 * A fresh component context with the methods bound.
 *
 * @param {object} overrides State to set.
 * @return {object} The context.
 */
function context(overrides = {}) {
	const ctx = { ...EditWebhook.data(), ...overrides }
	for (const [name, fn] of Object.entries(methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

const SETTERS = ['updateSendCloudEvent', 'updateWaitForResponse', 'updateAllowPrivateTargets']
const STARTS = [
	{ webhookItem: null },
	{ webhookItem: {} },
	{ webhookItem: { configuration: { sendCloudEvent: true, eventProperty: 'x' } } },
]

describe('EditWebhook configuration flags (golden)', () => {
	it('each setter writes both copies of the configuration', () => {
		const out = []
		for (const setter of SETTERS) {
			for (const start of STARTS) {
				for (const value of [true, false]) {
					const ctx = context(JSON.parse(JSON.stringify(start)))
					ctx[setter](value)
					out.push({ setter, start, value, configuration: ctx.configuration, webhookItem: ctx.webhookItem })
				}
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('closeModal resets the form', () => {
		const ctx = context({
			loading: true,
			error: 'x',
			webhookItem: { name: 'a' },
			selectedMethod: { value: 'GET' },
			configuration: { sendCloudEvent: false, waitForResponse: true },
		})
		ctx.closeModal()
		expect({
			loading: ctx.loading,
			error: ctx.error,
			webhookItem: ctx.webhookItem,
			selectedMethod: ctx.selectedMethod,
			selectedEvent: ctx.selectedEvent,
			selectedEventProperty: ctx.selectedEventProperty,
			selectedRetryPolicy: ctx.selectedRetryPolicy,
			configuration: ctx.configuration,
			data: EditWebhook.data().configuration,
		}).toMatchSnapshot()
	})
})
