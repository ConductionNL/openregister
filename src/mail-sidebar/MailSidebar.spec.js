/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail panel shell: its head, the mail's subject, collapsing, the idle
 * state and the fold-out of what was found in the mail.
 *
 * @spec exclude Unit test of the client-side mail sidebar shell.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import MailSidebar from './MailSidebar.vue'

const mockObserved = {}
jest.mock('./composables/useMailObserver.js', () => ({
	useMailObserver: jest.fn(() => mockObserved),
}))
jest.mock('./composables/useAttachmentDrag.js', () => ({
	useAttachmentDrag: jest.fn(),
}))
jest.mock('./composables/useSubjectLine.js', () => ({
	useSubjectLine: jest.fn(),
}))
const mockAddToObject = {}
jest.mock('./composables/useAddToObject.js', () => ({
	useAddToObject: jest.fn((label, onAdd) => {
		mockAddToObject.label = label
		mockAddToObject.onAdd = onAdd
	}),
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => vars[key] ?? m),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', count),
}))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button type="button" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
}))

const mockLoadObjects = jest.fn()
const mockUnlinkNow = jest.fn(() => Promise.resolve(true))
const mockAddAttachment = jest.fn()
const mockSearchFor = jest.fn()
jest.mock('./components/ObjectsTab.vue', () => ({
	name: 'ObjectsTab',
	props: ['accountId', 'messageId'],
	emits: ['count', 'objects'],
	methods: {
		loadObjects: () => mockLoadObjects(),
		unlinkNow: (uuid) => mockUnlinkNow(uuid),
		addAttachment: (attachment) => mockAddAttachment(attachment),
	},
	render: () => null,
}))
jest.mock('./components/ActionsTab.vue', () => ({
	name: 'ActionsTab',
	props: ['accountId', 'messageId'],
	emits: ['linked'],
	methods: { searchFor: (term) => mockSearchFor(term) },
	render: () => null,
}))
jest.mock('./components/EntitiesTab.vue', () => ({
	name: 'EntitiesTab',
	props: ['accountId', 'messageId'],
	emits: ['search'],
	render: () => null,
}))

jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({ name: 'ChevronDown', render: () => null }))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({ name: 'ChevronUp', render: () => null }))
jest.mock('vue-material-design-icons/Check.vue', () => ({ name: 'Check', render: () => null }))
jest.mock('vue-material-design-icons/Close.vue', () => ({ name: 'Close', render: () => null }))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({ name: 'LinkVariant', render: () => null }))
jest.mock('vue-material-design-icons/TextSearch.vue', () => ({ name: 'TextSearch', render: () => null }))

const KEY = 'openregister-mail-sidebar-collapsed'

/**
 * Mount the sidebar viewing the given message.
 *
 * @param {number|null} messageId The message in view
 * @return {Promise<object>} The wrapper, once the subject request settled
 */
async function mountSidebar(messageId = 42) {
	mockObserved.accountId = ref(1)
	mockObserved.messageId = ref(messageId)
	mockObserved.isMessageView = ref(messageId !== null)
	const wrapper = mount(MailSidebar)
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	localStorage.clear()
	jest.clearAllMocks()
	axios.get.mockResolvedValue({ data: { subject: 'Street light on Lindelaan' } })
})

describe('MailSidebar', () => {
	it('shows a caption head and the subject of the open mail', async () => {
		const wrapper = await mountSidebar()
		expect(axios.get).toHaveBeenCalledWith('/apps/mail/api/messages/42', { timeout: 10000 })
		expect(wrapper.find('.or-mail-panel__caption').text()).toBe('Connections')
		expect(wrapper.find('.or-mail-panel__subject').text()).toBe('Street light on Lindelaan')
	})

	it('leaves the subject out when Mail does not answer', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = await mountSidebar()
		expect(wrapper.find('.or-mail-panel__subject').exists()).toBe(false)
	})

	it('asks for no subject while no email is open', async () => {
		await mountSidebar(null)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads the subject again when another email is opened', async () => {
		const wrapper = await mountSidebar()
		axios.get.mockResolvedValue({ data: { subject: 'Planning' } })
		mockObserved.messageId.value = 43
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith('/apps/mail/api/messages/43', { timeout: 10000 })
		expect(wrapper.find('.or-mail-panel__subject').text()).toBe('Planning')
	})

	it('passes the observed ids to its sections', async () => {
		const wrapper = await mountSidebar()
		await wrapper.find('.or-mail-panel__found-toggle').trigger('click')
		for (const name of ['ObjectsTab', 'ActionsTab', 'EntitiesTab']) {
			const tab = wrapper.findComponent({ name })
			expect(tab.props()).toEqual({ accountId: 1, messageId: 42 })
		}
	})

	it('marks the panel idle only while no email is open', async () => {
		expect((await mountSidebar()).classes()).not.toContain('or-mail-sidebar-root--idle')
		expect((await mountSidebar(null)).classes()).toContain('or-mail-sidebar-root--idle')
	})

	it('folds out what was found in the mail, and folds it in for the next mail', async () => {
		const wrapper = await mountSidebar()
		const toggle = wrapper.find('.or-mail-panel__found-toggle')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		expect(wrapper.findComponent({ name: 'EntitiesTab' }).exists()).toBe(false)
		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('true')
		expect(wrapper.findComponent({ name: 'EntitiesTab' }).exists()).toBe(true)
		mockObserved.messageId.value = 43
		await flushPromises()
		expect(wrapper.findComponent({ name: 'EntitiesTab' }).exists()).toBe(false)
	})

	it('closes from its head and opens again from the pressed Connections button, and remembers it', async () => {
		const wrapper = await mountSidebar()
		const button = wrapper.find('.or-mail-connections-button')
		expect(button.attributes('aria-pressed')).toBe('true')
		await wrapper.find('.or-mail-panel__head button').trigger('click')
		expect(localStorage.getItem(KEY)).toBe('true')
		expect(wrapper.find('aside.or-mail-sidebar').exists()).toBe(false)
		expect(button.attributes('aria-pressed')).toBe('false')
		await button.trigger('click')
		expect(localStorage.getItem(KEY)).toBe('false')
		expect(wrapper.find('aside.or-mail-sidebar').exists()).toBe(true)
	})

	it('starts closed when it was left closed', async () => {
		localStorage.setItem(KEY, 'true')
		const wrapper = await mountSidebar()
		expect(wrapper.find('aside.or-mail-sidebar').exists()).toBe(false)
		expect(wrapper.find('.or-mail-connections-button').attributes('aria-pressed')).toBe('false')
	})

	it('counts the connections on the button, and forgets the count for the next mail', async () => {
		const wrapper = await mountSidebar()
		expect(wrapper.find('.or-mail-connections-button__count').exists()).toBe(false)
		await wrapper.findComponent({ name: 'ObjectsTab' }).vm.$emit('count', 2)
		expect(wrapper.find('.or-mail-connections-button__count').text()).toBe('2')
		expect(wrapper.find('.or-mail-connections-button__count').attributes('aria-label')).toBe('2 connections')
		mockObserved.messageId.value = 43
		await flushPromises()
		expect(wrapper.find('.or-mail-connections-button__count').exists()).toBe(false)
	})

	it('says what was connected, with Undo that takes it back', async () => {
		const wrapper = await mountSidebar()
		await wrapper.findComponent({ name: 'ActionsTab' }).vm.$emit('linked', {
			schema: { title: 'Case' },
			object: { id: 'u-1', identifier: '2026-4833' },
		})
		expect(wrapper.find('.or-mail-panel__connected').text()).toContain('Connected to case #2026-4833')
		await wrapper.find('.or-mail-panel__undo').trigger('click')
		await flushPromises()
		expect(mockUnlinkNow).toHaveBeenCalledWith('u-1')
		expect(wrapper.find('.or-mail-panel__connected').exists()).toBe(false)
	})

	it('keeps the line when Undo fails, and names a connection without a number plainly', async () => {
		mockUnlinkNow.mockResolvedValueOnce(false)
		const wrapper = await mountSidebar()
		await wrapper.findComponent({ name: 'ActionsTab' }).vm.$emit('linked', { schema: { title: 'Case' }, object: { uuid: 'u-2' } })
		expect(wrapper.find('.or-mail-panel__connected').text()).toContain('Connected to case')
		await wrapper.find('.or-mail-panel__undo').trigger('click')
		await flushPromises()
		expect(wrapper.find('.or-mail-panel__connected').exists()).toBe(true)
	})

	it('hands a found value to the search, and labels the attachment action by the connected kind', async () => {
		const wrapper = await mountSidebar()
		await wrapper.find('.or-mail-panel__found-toggle').trigger('click')
		await wrapper.findComponent({ name: 'EntitiesTab' }).vm.$emit('search', 'Lindelaan 14')
		expect(mockSearchFor).toHaveBeenCalledWith('Lindelaan 14')
		expect(mockAddToObject.label.value).toBe('')
		await wrapper.findComponent({ name: 'ObjectsTab' }).vm.$emit('objects', [{ uuid: 'u-1', schema: 'Case' }])
		expect(mockAddToObject.label.value).toBe('Add to case')
		mockAddToObject.onAdd({ fileName: 'a.pdf' })
		expect(mockAddAttachment).toHaveBeenCalledWith({ fileName: 'a.pdf' })
	})

	it('reloads the connections after a link', async () => {
		const wrapper = await mountSidebar()
		await wrapper.findComponent({ name: 'ActionsTab' }).vm.$emit('linked')
		expect(mockLoadObjects).toHaveBeenCalledTimes(1)
	})
})
