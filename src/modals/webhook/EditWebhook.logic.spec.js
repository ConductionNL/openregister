/**
 * The webhook modal's form logic: each field writes the webhook (creating it
 * when absent), headers and filters round-trip between text and objects, the
 * event picker resets the event property, mappings and events load from the
 * API, and saving closes the modal or shows the error. Methods run against a
 * plain `this` with stub stores and HTTP.
 *
 * @spec exclude Client-side webhook form handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import EditWebhook from './EditWebhook.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), getTransferData: jest.fn() },
	webhookStore: { saveWebhook: jest.fn() },
}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../components/shared/FormCheckboxField.vue', () => ({}))
jest.mock('../../components/shared/FormSelectField.vue', () => ({}))
jest.mock('../../components/shared/SelectOption.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Tune.vue', () => ({}))
jest.mock('vue-material-design-icons/Webhook.vue', () => ({}))

const { navigationStore, webhookStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = EditWebhook

/**
 * The modal's initial state with computed getters and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function modal(state = {}) {
	const vm = EditWebhook.data.call({})
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in state)) vm[name] = fn.bind(vm)
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

describe('EditWebhook fields', () => {
	it('writes the plain fields, creating the webhook when absent', () => {
		const vm = modal()
		vm.updateName('Hook')
		vm.webhookItem = null
		vm.updateUrl('https://x')
		vm.webhookItem = null
		vm.updateEnabled(true)
		vm.updateSecret('')
		expect(vm.webhookItem.secret).toBeNull()
		vm.updateSecret('s3cret')
		expect(vm.webhookItem).toMatchObject({ enabled: true, secret: 's3cret' })
	})

	it('stores the method and retry policy with defaults', () => {
		const vm = modal()
		vm.updateMethod({ value: 'PUT' })
		expect(vm.webhookItem.method).toBe('PUT')
		vm.updateMethod(null)
		expect(vm.webhookItem.method).toBe('POST')
		vm.webhookItem = null
		vm.updateRetryPolicy({ value: 'linear' })
		expect(vm.webhookItem.retryPolicy).toBe('linear')
		vm.updateRetryPolicy(null)
		expect(vm.webhookItem.retryPolicy).toBe('exponential')
	})

	it('parses retries and timeout, falling back to the defaults', () => {
		const vm = modal()
		vm.updateMaxRetries('5')
		vm.updateTimeout('abc')
		expect(vm.webhookItem.maxRetries).toBe(5)
		expect(vm.webhookItem.timeout).toBe(30)
		vm.webhookItem = null
		vm.updateMaxRetries('')
		vm.updateTimeout('12')
		expect(vm.webhookItem).toMatchObject({ maxRetries: 3, timeout: 12 })
	})

	it('picks an event and clears its property', () => {
		const vm = modal({ selectedEventProperty: { value: 'old' } })
		vm.updateEvent({ value: 'ObjectCreated' })
		expect(vm.webhookItem.events).toEqual(['ObjectCreated'])
		expect(vm.selectedEvent).toBe('ObjectCreated')
		expect(vm.selectedEventProperty).toBeNull()
		expect(vm.webhookItem.configuration.eventProperty).toBeNull()

		vm.updateEvent('ObjectDeleted')
		expect(vm.webhookItem.events).toEqual(['ObjectDeleted'])
		vm.updateEvent(null)
		expect(vm.webhookItem.events).toEqual([])
		expect(vm.selectedEvent).toBeNull()
	})

	it('stores the event property and the configuration flags', () => {
		const vm = modal()
		vm.updateEventProperty({ value: 'uuid' })
		expect(vm.webhookItem.configuration.eventProperty).toBe('uuid')
		vm.updateEventProperty(null)
		expect(vm.webhookItem.configuration.eventProperty).toBeNull()
		vm.updateSendCloudEvent(true)
		vm.updateWaitForResponse(true)
		vm.updateAllowPrivateTargets(false)
		expect(vm.configuration).toMatchObject({ sendCloudEvent: true, waitForResponse: true, allowPrivateTargets: false })
		expect(vm.webhookItem.configuration).toMatchObject({ sendCloudEvent: true, waitForResponse: true, allowPrivateTargets: false })
	})

	it('picks a mapping and drops a stale preview', () => {
		const vm = modal({ webhookItem: {}, preview: { old: true } })
		vm.updateMapping({ value: 4 })
		expect(vm.webhookItem.mapping).toBe(4)
		expect(vm.preview).toBeNull()
		vm.updateMapping(null)
		expect(vm.webhookItem.mapping).toBeNull()
	})
})

describe('EditWebhook headers and filters', () => {
	it('parses header lines, keeping colons in the value', () => {
		const vm = modal()
		vm.updateHeaders('X-A: 1\nAuthorization: Bearer a:b\nnot a header\n')
		expect(vm.webhookItem.headers).toEqual({ 'X-A': '1', Authorization: 'Bearer a:b' })
		vm.updateHeaders('   ')
		expect(vm.webhookItem.headers).toEqual({})
	})

	it('shows the headers as lines', () => {
		expect(modal({ webhookItem: { headers: { a: '1', b: '2' } } }).headersText).toBe('a: 1\nb: 2')
		expect(modal({ webhookItem: { headers: 'bad' } }).headersText).toBe('')
		expect(modal().headersPlaceholder).toContain('X-Custom-Header')
		expect(modal().filtersPlaceholder).toContain('objectType')
	})

	it('parses filters, splitting comma lists', () => {
		const vm = modal()
		vm.updateFilters('action: created, updated\nobjectType: object\njunk')
		expect(vm.webhookItem.filters).toEqual({ action: ['created', 'updated'], objectType: 'object' })
		vm.webhookItem = null
		vm.updateFilters('')
		expect(vm.webhookItem.filters).toEqual({})
	})

	it('shows the filters as lines, joining lists', () => {
		expect(modal({ webhookItem: { filters: { action: ['a', 'b'], type: 'x' } } }).filtersText).toBe('action: a, b\ntype: x')
		expect(modal({ webhookItem: {} }).filtersText).toBe('')
	})
})

describe('EditWebhook events and mappings', () => {
	it('lists the properties of the selected event', () => {
		const vm = modal({ availableEvents: [{ class: 'E', properties: ['a', 'b'] }, { class: 'F' }] })
		expect(vm.eventPropertyOptions).toEqual([])
		vm.selectedEvent = 'E'
		expect(vm.eventPropertyOptions).toEqual([{ value: 'a', label: 'a' }, { value: 'b', label: 'b' }])
		vm.selectedEvent = 'F'
		expect(vm.eventPropertyOptions).toEqual([])
	})

	it('loads the events as options', async () => {
		axios.get.mockResolvedValueOnce({ data: { events: [{ class: 'E', name: 'Created', category: 'Object', description: 'd', type: 't', properties: ['p'] }] } })
		const vm = modal()
		await vm.loadAvailableEvents()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/webhooks/events')
		expect(vm.eventOptions).toEqual([{ value: 'E', label: 'Created (Object)', description: 'd', category: 'Object', type: 't', properties: ['p'] }])
		expect(vm.loadingEvents).toBe(false)

		axios.get.mockRejectedValueOnce(new Error('x'))
		await vm.loadAvailableEvents()
		expect(vm.loadingEvents).toBe(false)
		expect(consoleError).toHaveBeenCalled()
	})

	it('loads the mappings as options, empty on failure', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 1, name: 'M' }, { id: 2 }] } })
		const vm = modal()
		await vm.loadMappings()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/mappings', { params: { _limit: 500 } })
		expect(vm.mappingOptions).toEqual([{ value: 1, label: 'M' }, { value: 2, label: '2' }])

		axios.get.mockRejectedValueOnce(new Error('x'))
		await vm.loadMappings()
		expect(vm.mappingOptions).toEqual([])
		expect(vm.loadingMappings).toBe(false)
	})

	it('previews the payload for the mapping and event', async () => {
		axios.post.mockResolvedValueOnce({ data: { body: 1 } })
		const vm = modal({ webhookItem: { mapping: 3, configuration: { a: 1 } }, selectedEvent: 'E' })
		await vm.previewPayload()
		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/webhooks/preview', { mapping: 3, configuration: { a: 1 }, event: 'E' })
		expect(vm.preview).toEqual({ body: 1 })

		axios.post.mockRejectedValueOnce(new Error('x'))
		vm.webhookItem = {}
		vm.selectedEvent = null
		await vm.previewPayload()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/openregister/api/webhooks/preview', { mapping: null, configuration: {}, event: undefined })
		expect(vm.previewError).toBe('Could not preview the payload')
		expect(vm.previewing).toBe(false)
	})
})

describe('EditWebhook opening, saving and closing', () => {
	it('starts a new webhook with the default method and retry policy', () => {
		navigationStore.getTransferData.mockReturnValue(null)
		const vm = modal()
		vm.initializeWebhook()
		expect(vm.webhookItem).toBeTruthy()
		expect(vm.selectedRetryPolicy).toBe(vm.retryPolicyOptions[0])
		expect(vm.selectedEvent).toBeNull()
	})

	it('edits a copy of a transferred webhook', () => {
		const webhook = { id: 5, name: 'Hook', method: 'PUT' }
		navigationStore.getTransferData.mockReturnValue({ webhook })
		const loadExistingSelections = jest.fn()
		const vm = modal({ loadExistingSelections })
		vm.initializeWebhook()
		expect(vm.webhookItem).toEqual(webhook)
		expect(vm.webhookItem).not.toBe(webhook)
		expect(loadExistingSelections).toHaveBeenCalled()
	})

	it('loads events and mappings before initialising, and re-initialises on reopening', async () => {
		const vm = modal({ loadAvailableEvents: jest.fn(), loadMappings: jest.fn(), initializeWebhook: jest.fn() })
		await EditWebhook.created.call(vm)
		expect(vm.loadAvailableEvents).toHaveBeenCalled()
		expect(vm.loadMappings).toHaveBeenCalled()
		expect(vm.initializeWebhook).toHaveBeenCalledTimes(1)
		EditWebhook.watch['navigationStore.modal'].call(vm, 'editWebhook')
		EditWebhook.watch['navigationStore.modal'].call(vm, 'other')
		expect(vm.initializeWebhook).toHaveBeenCalledTimes(2)
	})

	it('saves a new webhook, confirms it and closes', async () => {
		webhookStore.saveWebhook.mockResolvedValueOnce({ data: { id: 9 } })
		const vm = modal({ webhookItem: { name: 'Hook', url: 'https://x' } })
		expect(vm.isValid).toBe(true)
		await vm.saveWebhook()
		expect(webhookStore.saveWebhook).toHaveBeenCalled()
		expect(showSuccess).toHaveBeenCalledWith('Webhook created successfully')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm.webhookItem).toBeNull()
		expect(vm.loading).toBe(false)
	})

	it('confirms an update and keeps the modal open without data', async () => {
		webhookStore.saveWebhook.mockResolvedValueOnce({ data: { id: 5 } })
		await modal({ webhookItem: { id: 5, name: 'H', url: 'u' } }).saveWebhook()
		expect(showSuccess).toHaveBeenCalledWith('Webhook updated successfully')

		webhookStore.saveWebhook.mockResolvedValueOnce({ data: null })
		const vm = modal({ webhookItem: { name: 'H', url: 'u' } })
		await vm.saveWebhook()
		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
		expect(vm.webhookItem).not.toBeNull()
	})

	it('shows the server error or a fallback on a failed save', async () => {
		webhookStore.saveWebhook.mockRejectedValueOnce({ response: { data: { error: 'URL not allowed' } } })
		const vm = modal({ webhookItem: { name: 'H', url: 'u' } })
		await vm.saveWebhook()
		expect(vm.error).toBe('URL not allowed')
		webhookStore.saveWebhook.mockRejectedValueOnce(new Error('x'))
		await vm.saveWebhook()
		expect(vm.error).toBe('Failed to save webhook')
	})

	it('is invalid without a name or url', () => {
		expect(modal().isValid).toBe(false)
		expect(modal({ webhookItem: { name: 'H', url: ' ' } }).isValid).toBe(false)
	})
})
