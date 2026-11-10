/**
 * Golden record of EditWebhook's form plumbing: the selections a stored
 * webhook loads into the form, the form a new webhook starts with, and the
 * payload, messages and state a save leaves behind.
 */
import { showSuccess } from '@nextcloud/dialogs'
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

import { navigationStore, webhookStore } from '../../store/store.js'

const { methods } = EditWebhook

/**
 * A fresh component context.
 *
 * @param {object} overrides State to set.
 * @return {object} The context.
 */
function context(overrides = {}) {
	const ctx = {
		...EditWebhook.data(),
		mappingOptions: [
			{ value: 1, label: 'One' },
			{ value: 2, label: 'Two' },
		],
		...overrides,
	}
	for (const [name, method] of Object.entries(methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * Run one method and describe what it left behind.
 *
 * @param {string} name The method.
 * @param {object} ctx The context.
 * @return {Promise<string>} The trace as JSON text.
 */
async function trace(name, ctx) {
	showSuccess.mockClear()
	navigationStore.setModal.mockClear()
	const errors = jest.spyOn(console, 'error').mockImplementation(() => {})
	let thrown = null
	try {
		await methods[name].call(ctx)
	} catch (error) {
		thrown = String(error)
	}
	const logged = errors.mock.calls.map((call) =>
		call.map((arg) => (arg instanceof Error ? `Error:${arg.message}` : arg)),
	)
	errors.mockRestore()
	const state = Object.fromEntries(
		Object.entries(ctx).filter(
			([key, value]) =>
				typeof value !== 'function' && !key.endsWith('Options'),
		),
	)
	return JSON.stringify(
		{
			state,
			thrown,
			logged,
			configurationShared:
				ctx.configuration === ctx.webhookItem?.configuration,
			saved: webhookStore.saveWebhook.mock.calls,
			success: showSuccess.mock.calls,
			modal: navigationStore.setModal.mock.calls,
		},
		(key, value) => (value === undefined ? '(undefined)' : value),
	)
}

const STORED = [
	{},
	{ mapping: 2 },
	{ mapping: 9, method: 'PUT', retryPolicy: 'linear' },
	{ method: 'TRACE', retryPolicy: 'never' },
	{ method: '', retryPolicy: '' },
	{ method: 'GET', events: [] },
	{ events: ['A\\Event', 'B\\Event'] },
	{ events: [''] },
	{ events: 'A\\Event' },
	{ events: null, configuration: {} },
	{ configuration: { sendCloudEvent: false, eventProperty: '' } },
	{
		configuration: {
			waitForResponse: true,
			eventProperty: 'object.uuid',
			responseMapping: { a: 'b' },
		},
	},
	{
		mapping: 1,
		method: 'DELETE',
		retryPolicy: 'fixed',
		events: ['C\\Event'],
		configuration: { eventProperty: 'x' },
	},
]

const ITEMS = [
	{ name: 'Hook', url: 'https://x', method: 'POST' },
	{
		id: 5,
		name: 'Hook',
		url: 'https://x',
		method: 'PUT',
		enabled: false,
		maxRetries: 7,
		timeout: 9,
		retryPolicy: 'linear',
		secret: 's',
		headers: { A: '1' },
		filters: { b: 2 },
		configuration: { sendCloudEvent: false },
		mapping: 0,
	},
	{
		id: 0,
		enabled: 0,
		maxRetries: 0,
		timeout: 0,
		retryPolicy: '',
		secret: '',
		headers: null,
		filters: null,
		configuration: null,
		mapping: null,
	},
	{ id: 'abc', enabled: true, mapping: undefined },
]

describe('EditWebhook form plumbing (golden)', () => {
	it('loadExistingSelections matches the recorded selections', async () => {
		const traces = [await trace('loadExistingSelections', context())]
		for (const stored of STORED) {
			traces.push(
				await trace(
					'loadExistingSelections',
					context({
						webhookItem: stored,
						selectedEvent: 'kept',
						selectedEventProperty: 'kept',
						selectedMethod: 'kept',
						selectedRetryPolicy: 'kept',
					}),
				),
			)
		}
		traces.push(
			await trace(
				'loadExistingSelections',
				context({
					webhookItem: { method: 'TRACE' },
					httpMethodOptions: [{ value: 'GET' }, { value: 'PUT' }],
					retryPolicyOptions: [],
				}),
			),
		)
		expect(traces).toMatchSnapshot()
	})

	it('initializeWebhook matches the recorded form', async () => {
		const traces = []
		const transfers = [
			null,
			{},
			{ webhook: null },
			{ webhook: { id: 3, method: 'PATCH', events: ['E'] } },
		]
		for (const transfer of transfers) {
			navigationStore.getTransferData.mockReturnValue(transfer)
			traces.push(await trace('initializeWebhook', context()))
		}
		navigationStore.getTransferData.mockReturnValue(null)
		traces.push(
			await trace(
				'initializeWebhook',
				context({ httpMethodOptions: [{ value: 'GET' }] }),
			),
		)
		expect(traces).toMatchSnapshot()
	})

	it('saveWebhook matches the recorded payloads', async () => {
		const traces = []
		const answers = [
			{ data: { id: 1 } },
			{ data: null },
			{},
			new Error('boom'),
			Object.assign(new Error('bad'), {
				response: { data: { error: 'Invalid URL' } },
			}),
			Object.assign(new Error('bad'), { response: { data: {} } }),
		]
		for (const item of ITEMS) {
			for (const event of [null, '', 'A\\Event']) {
				webhookStore.saveWebhook.mockReset()
				webhookStore.saveWebhook.mockResolvedValue({ data: { ok: true } })
				traces.push(
					await trace(
						'saveWebhook',
						context({ webhookItem: { ...item }, selectedEvent: event }),
					),
				)
			}
		}
		for (const result of answers) {
			webhookStore.saveWebhook.mockReset()
			if (result instanceof Error) {
				webhookStore.saveWebhook.mockRejectedValue(result)
			} else {
				webhookStore.saveWebhook.mockResolvedValue(result)
			}
			for (const item of [ITEMS[0], ITEMS[1]]) {
				traces.push(
					await trace(
						'saveWebhook',
						context({ webhookItem: { ...item }, error: 'old' }),
					),
				)
			}
		}
		webhookStore.saveWebhook.mockReset()
		traces.push(await trace('saveWebhook', context({ webhookItem: null })))
		expect(traces).toMatchSnapshot()
	})
})
