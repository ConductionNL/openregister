/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail sidebar's actions tab: which schemas it offers, the search UI,
 * connecting an email to an object and creating one from the email.
 *
 * @spec exclude Unit test of the client-side mail sidebar actions tab.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import ActionsTab from './ActionsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
}))
jest.mock('../../dialogs/mail/CreateConnectedObjectDialog.vue', () => ({
	name: 'CreateConnectedObjectDialog',
	props: ['show', 'schema', 'initialData', 'saving'],
	emits: ['cancel', 'confirm'],
	render: () => null,
}))

const LEADS = {
	id: 5,
	title: 'Leads',
	configuration: {
		linkedTypes: ['mail'],
		mailObjectTemplate: {
			title: 'Re: {{subject}}',
			from: '{{senderName}} <{{sender}}>',
			due: '{{date30}}',
			ref: '{{mailRef}}',
			keep: '{{unknown}}',
			amount: 3,
		},
	},
}
const NOTES = {
	id: 6,
	title: 'Notes',
	configuration: { linkedTypes: ['mail'], mailObjectTemplate: {} },
}
const FILES = { id: 7, title: 'Files', configuration: { linkedTypes: ['file'] } }
const BARE = { id: 8, title: 'Bare' }
const REGISTER = { id: 2, schemas: [5, 6] }
const LEADS_OBJECTS = '/index.php/apps/openregister/api/objects/2/5'
const BODY_URL = '/index.php/apps/mail/api/messages/42/body'

let objects
let envelope

/**
 * Serve the creation requests, the object lists and the mail body.
 *
 * @param {Array} schemas The schemas the schemas endpoint returns
 * @return {void}
 */
function serve(schemas = [LEADS, NOTES, FILES, BARE]) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/api/schemas')) {
			return Promise.resolve({ data: { results: schemas } })
		}
		if (url.endsWith('/api/registers')) {
			return Promise.resolve({ data: [REGISTER] })
		}
		if (url === BODY_URL) {
			return Promise.resolve({ data: { data: envelope } })
		}
		return Promise.resolve({ data: { results: objects } })
	})
}

/**
 * Mount the tab and let its creation requests settle.
 *
 * @param {object} props The props
 * @return {Promise<object>} The wrapper
 */
async function mountTab(props = { accountId: 1, messageId: 42 }) {
	const wrapper = mount(ActionsTab, { props })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	objects = [{ id: 'u1', '@self': { name: 'Acme' } }]
	envelope = {
		subject: 'Offer',
		from: [{ email: 'a@b.nl', label: 'Anna' }],
		dateInt: 1767225600, // 2026-01-01T00:00:00Z
		body: '<p>Hello <b>there</b></p>',
		hasHtmlBody: true,
	}
	serve()
})

afterEach(() => {
	jest.useRealTimers()
})

describe('ActionsTab schemas', () => {
	it('shows the loading text until the schemas arrive', () => {
		const wrapper = mount(ActionsTab, { props: { accountId: 1, messageId: 42 } })
		expect(wrapper.find('.or-tab-loading').exists()).toBe(true)
	})

	it('offers only schemas that link to mail, one block each', async () => {
		const wrapper = await mountTab()
		const labels = wrapper.findAll('.or-action-label').map((l) => l.text())
		expect(labels).toEqual(['Connect to Leads', 'Connect to Notes'])
		expect(wrapper.find('.or-tab-loading').exists()).toBe(false)
	})

	it('offers a create button only for a schema with a non-empty template', async () => {
		const wrapper = await mountTab()
		const buttons = wrapper.findAll('.or-action-create')
		expect(buttons).toHaveLength(1)
		expect(buttons[0].text()).toBe('New Leads from this email')
	})

	it('says there is nothing to connect to when no schema links to mail', async () => {
		serve([FILES])
		const wrapper = await mountTab()
		expect(wrapper.find('.or-tab-empty').text()).toBe(
			'There is nothing set up to connect this email to yet.',
		)
	})

	it('pages through schemas until a page comes back short', async () => {
		const full = Array.from({ length: 500 }, (_, i) => ({ id: 1000 + i }))
		let page = 0
		axios.get.mockImplementation((url, config) => {
			if (url.endsWith('/api/schemas')) {
				page = config.params._page
				return Promise.resolve({ data: page === 1 ? full : [NOTES] })
			}
			return Promise.resolve({ data: { results: [REGISTER] } })
		})
		const wrapper = await mountTab()
		const schemaCalls = axios.get.mock.calls.filter(([u]) =>
			u.endsWith('/api/schemas'),
		)
		expect(schemaCalls.map(([, c]) => c.params)).toEqual([
			{ _limit: 500, _page: 1 },
			{ _limit: 500, _page: 2 },
		])
		expect(wrapper.vm.schemas).toEqual([NOTES])
	})

	it('ends up empty, not loading, when the schemas request fails', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = await mountTab()
		expect(wrapper.find('.or-tab-loading').exists()).toBe(false)
		expect(wrapper.find('.or-tab-empty').exists()).toBe(true)
	})
})

describe('ActionsTab search', () => {
	it('shows the initial results once the input is focused', async () => {
		const wrapper = await mountTab()
		expect(wrapper.find('.or-action-results').exists()).toBe(false)
		await wrapper.find('.or-action-input').trigger('focus')
		const items = wrapper.findAll('.or-action-result-name')
		expect(items.map((i) => i.text())).toContain('Acme')
	})

	it('names an object by the first name-like field it has', async () => {
		const wrapper = await mountTab()
		const name = wrapper.vm.objectName
		expect(name({ _name: 'n1', title: 't' })).toBe('n1')
		expect(name({ title: 't', name: 'n' })).toBe('t')
		expect(name({ name: 'n', naam: 'nl' })).toBe('n')
		expect(name({ naam: 'nl', id: 'i' })).toBe('nl')
		expect(name({ id: 'i' })).toBe('i')
	})

	it('debounces typing into one search request after 300ms', async () => {
		jest.useFakeTimers()
		const wrapper = await mountTab()
		axios.get.mockClear()
		const input = wrapper.find('.or-action-input')
		await input.setValue('ac')
		await input.setValue('acm')
		jest.advanceTimersByTime(299)
		expect(axios.get).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(LEADS_OBJECTS, {
			params: { _search: 'acm', _limit: 20 },
			timeout: 10000,
		})
		expect(wrapper.vm.searching[5]).toBe(false)
	})

	it('shows the searching hint while a search is in flight', async () => {
		const wrapper = await mountTab()
		let release
		axios.get.mockImplementation(
			() => new Promise((resolve) => (release = resolve)),
		)
		wrapper.vm.searchTerms[5] = 'x'
		const pending = wrapper.vm.searchObjects(LEADS)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.or-action-searching').exists()).toBe(true)
		release({ data: [] })
		await pending
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.or-action-searching').exists()).toBe(false)
	})

	it('keeps searching off after a failed search', async () => {
		const wrapper = await mountTab()
		axios.get.mockRejectedValue(new Error('x'))
		wrapper.vm.searchTerms[5] = 'x'
		await wrapper.vm.searchObjects(LEADS)
		expect(wrapper.vm.searching[5]).toBe(false)
		expect(console.error).toHaveBeenCalledWith(
			'[ActionsTab] Search failed:',
			expect.any(Error),
		)
	})

	it('does not search a schema without a register', async () => {
		const wrapper = await mountTab()
		axios.get.mockClear()
		await wrapper.vm.searchObjects({ id: 99 })
		await wrapper.vm.loadInitialResults({ id: 99 })
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('logs, not throws, when the initial list fails', async () => {
		const wrapper = await mountTab()
		axios.get.mockRejectedValue(new Error('x'))
		await wrapper.vm.loadInitialResults(LEADS)
		expect(console.error).toHaveBeenCalledWith(
			'[ActionsTab] Initial load failed for',
			'Leads',
			expect.any(Error),
		)
	})
})

describe('ActionsTab connect', () => {
	it('posts the mail reference to the object and emits linked', async () => {
		const wrapper = await mountTab()
		await wrapper.find('.or-action-input').trigger('focus')
		axios.post.mockResolvedValue({ data: {} })
		await wrapper.find('.or-action-result').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/u1/_linked/mail',
			{ id: '1/42' },
		)
		expect(showSuccess).toHaveBeenCalledWith('Connected to Acme')
		expect(wrapper.emitted('linked')).toHaveLength(1)
		expect(wrapper.vm.visibleResults[5]).toBe(false)
		expect(wrapper.vm.linking[5]).toBe(false)
	})

	it('shows the connecting spinner while the link is posted', async () => {
		const wrapper = await mountTab()
		let release
		axios.post.mockImplementation(
			() => new Promise((resolve) => (release = resolve)),
		)
		const pending = wrapper.vm.linkObject(LEADS, { uuid: 'u2' })
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.or-action-connecting').text()).toBe('Connecting…')
		expect(wrapper.find('.or-action-block--busy').exists()).toBe(true)
		release({})
		await pending
	})

	it('reports a failed link and emits nothing', async () => {
		const wrapper = await mountTab()
		axios.post.mockRejectedValue(new Error('no'))
		await wrapper.vm.linkObject(LEADS, { _uuid: 'u3' })
		expect(showError).toHaveBeenCalledWith('Failed to connect')
		expect(wrapper.emitted('linked')).toBeUndefined()
		expect(wrapper.vm.linking[5]).toBe(false)
	})

	it('does not link without an object id or without a message', async () => {
		const wrapper = await mountTab({ accountId: 1, messageId: null })
		await wrapper.vm.linkObject(LEADS, { id: 'u1' })
		const other = await mountTab()
		await other.vm.linkObject(LEADS, {})
		expect(axios.post).not.toHaveBeenCalled()
	})
})

describe('ActionsTab create from email', () => {
	it('prefills the dialog from the template and the email', async () => {
		const wrapper = await mountTab()
		await wrapper.find('.or-action-create').trigger('click')
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(BODY_URL, { timeout: 10000 })
		const dialog = wrapper.findComponent({ name: 'CreateConnectedObjectDialog' })
		expect(dialog.props('show')).toBe(true)
		expect(dialog.props('schema')).toEqual(LEADS)
		expect(dialog.props('initialData')).toEqual({
			title: 'Re: Offer',
			from: 'Anna <a@b.nl>',
			due: '2026-01-31',
			ref: '1/42',
			keep: '{{unknown}}',
			amount: 3,
		})
	})

	it('builds the placeholders from a plain-text email', async () => {
		const wrapper = await mountTab()
		const p = wrapper.vm.buildPlaceholders({
			subject: 'S',
			from: [{ email: 'x@y.nl' }],
			dateInt: 1767225600,
			body: '  text  ',
		})
		expect(p).toEqual({
			subject: 'S',
			sender: 'x@y.nl',
			senderName: 'x@y.nl',
			date: '2026-01-01',
			date30: '2026-01-31',
			datetime: '2026-01-01T00:00:00.000Z',
			preview: 'text',
			messageId: '42',
			mailRef: '1/42',
		})
	})

	it('strips html and falls back to now for an email without a date', async () => {
		jest.useFakeTimers({ now: new Date('2026-03-01T12:00:00Z') })
		const wrapper = await mountTab()
		const p = wrapper.vm.buildPlaceholders({
			body: '<p>Hi <b>you</b></p>',
			hasHtmlBody: true,
		})
		expect(p.preview).toBe('Hi you')
		expect(p.date).toBe('2026-03-01')
		expect(p.sender).toBe('')
		expect(p.subject).toBe('')
	})

	it('reads the email body once per message', async () => {
		const wrapper = await mountTab()
		await wrapper.vm.fetchEnvelope()
		await wrapper.vm.fetchEnvelope()
		expect(axios.get.mock.calls.filter(([u]) => u === BODY_URL)).toHaveLength(1)
	})

	it('reports an unreadable email and leaves the dialog shut', async () => {
		const wrapper = await mountTab()
		axios.get.mockRejectedValue(new Error('gone'))
		await wrapper.vm.openCreate(LEADS)
		expect(showError).toHaveBeenCalledWith('Could not read this email')
		expect(wrapper.vm.createDialog.show).toBe(false)
		expect(wrapper.vm.creating[5]).toBe(false)
	})

	it('does not open without a message or a register', async () => {
		const wrapper = await mountTab({ accountId: 1, messageId: null })
		await wrapper.vm.openCreate(LEADS)
		const other = await mountTab()
		await other.vm.openCreate({ ...LEADS, id: 99 })
		expect(axios.get).not.toHaveBeenCalledWith(BODY_URL, expect.anything())
		expect(other.vm.createDialog.show).toBe(false)
	})

	it('creates the object, connects the email and closes the dialog', async () => {
		const wrapper = await mountTab()
		await wrapper.vm.openCreate(LEADS)
		axios.post
			.mockResolvedValueOnce({ data: { id: 'new1', title: 'T' } })
			.mockResolvedValueOnce({})
		const dialog = wrapper.findComponent({ name: 'CreateConnectedObjectDialog' })
		dialog.vm.$emit('confirm', { title: 'T' })
		await flushPromises()
		expect(axios.post.mock.calls[0]).toEqual([
			LEADS_OBJECTS,
			{ title: 'T' },
			{ timeout: 15000 },
		])
		expect(axios.post.mock.calls[1]).toEqual([
			'/index.php/apps/openregister/api/objects/new1/_linked/mail',
			{ id: '1/42' },
		])
		expect(wrapper.vm.createDialog.show).toBe(false)
		expect(wrapper.vm.createSaving).toBe(false)
	})

	it('keeps the dialog open and reports a failed create', async () => {
		const wrapper = await mountTab()
		await wrapper.vm.openCreate(LEADS)
		axios.post.mockRejectedValue({ response: { data: { error: 'bad' } } })
		await wrapper.vm.submitCreate({ title: 'T' })
		expect(showError).toHaveBeenCalledWith('Failed to create Leads from email')
		expect(wrapper.vm.createDialog.show).toBe(true)
		expect(wrapper.vm.createSaving).toBe(false)
	})

	it('does not submit without a dialog schema or its register', async () => {
		const wrapper = await mountTab()
		await wrapper.vm.submitCreate({})
		wrapper.vm.createDialog = { show: true, schema: { id: 99 }, data: {} }
		await wrapper.vm.submitCreate({})
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('closes on cancel, but not while saving', async () => {
		const wrapper = await mountTab()
		await wrapper.vm.openCreate(LEADS)
		wrapper.vm.createSaving = true
		const dialog = wrapper.findComponent({ name: 'CreateConnectedObjectDialog' })
		dialog.vm.$emit('cancel')
		expect(wrapper.vm.createDialog.show).toBe(true)
		wrapper.vm.createSaving = false
		dialog.vm.$emit('cancel')
		expect(wrapper.vm.createDialog).toEqual({ show: false, schema: null, data: {} })
	})
})
