/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail sidebar shell: collapsing, and which tab it opens for an email.
 *
 * @spec exclude Unit test of the client-side mail sidebar shell.
 */
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import MailSidebar from './MailSidebar.vue'

const mockObserved = {}
jest.mock('./composables/useMailObserver.js', () => ({
	useMailObserver: jest.fn(() => mockObserved),
}))
jest.mock('./composables/useAttachmentDrag.js', () => ({
	useAttachmentDrag: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({
	NcAppSidebar: {
		name: 'NcAppSidebar',
		props: ['active', 'name', 'subname', 'compact'],
		emits: ['close', 'update:active'],
		template: '<aside><slot name="description" /><slot /></aside>',
	},
	NcAppSidebarTab: { name: 'NcAppSidebarTab', template: '<section><slot /></section>' },
}))

const mockLoadObjects = jest.fn()
jest.mock('./components/ObjectsTab.vue', () => ({
	name: 'ObjectsTab',
	props: ['accountId', 'messageId'],
	emits: ['count', 'switchTab'],
	methods: { loadObjects: () => mockLoadObjects() },
	render: () => null,
}))
jest.mock('./components/ActionsTab.vue', () => ({
	name: 'ActionsTab',
	props: ['accountId', 'messageId'],
	emits: ['linked'],
	render: () => null,
}))
jest.mock('./components/EntitiesTab.vue', () => ({
	name: 'EntitiesTab',
	props: ['accountId', 'messageId'],
	render: () => null,
}))

jest.mock('vue-material-design-icons/AccountMultiple.vue', () => ({ name: 'AccountMultiple', render: () => null }))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({ name: 'LinkVariant', render: () => null }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ name: 'Plus', render: () => null }))

const KEY = 'openregister-mail-sidebar-collapsed'

/**
 * Mount the sidebar viewing the given message.
 *
 * @param {number|null} messageId The message in view
 * @return {object} The wrapper
 */
function mountSidebar(messageId = 42) {
	mockObserved.accountId = ref(1)
	mockObserved.messageId = ref(messageId)
	mockObserved.isMessageView = ref(messageId !== null)
	return mount(MailSidebar)
}

beforeEach(() => {
	localStorage.clear()
	jest.clearAllMocks()
})

describe('MailSidebar', () => {
	it('passes the mockObserved ids to its tabs', () => {
		const wrapper = mountSidebar()
		for (const name of ['ObjectsTab', 'ActionsTab', 'EntitiesTab']) {
			const tab = wrapper.findComponent({ name })
			expect(tab.props()).toEqual({ accountId: 1, messageId: 42 })
		}
		expect(wrapper.findComponent({ name: 'NcAppSidebar' }).props('name')).toBe(
			'Connections',
		)
	})

	it('hints to pick an email only when none is open', () => {
		expect(mountSidebar().find('.or-mail-sidebar__hint').exists()).toBe(false)
		expect(mountSidebar(null).find('.or-mail-sidebar__hint').text()).toBe(
			'Select an email to see its connections',
		)
	})

	it('collapses on close and remembers it', async () => {
		const wrapper = mountSidebar()
		await wrapper.findComponent({ name: 'NcAppSidebar' }).vm.$emit('close')
		expect(localStorage.getItem(KEY)).toBe('true')
		expect(wrapper.findComponent({ name: 'NcAppSidebar' }).exists()).toBe(false)
		await wrapper.find('.or-mail-sidebar__collapsed-toggle').trigger('click')
		expect(localStorage.getItem(KEY)).toBe('false')
		expect(wrapper.findComponent({ name: 'NcAppSidebar' }).exists()).toBe(true)
	})

	it('starts collapsed when it was left collapsed', () => {
		localStorage.setItem(KEY, 'true')
		const wrapper = mountSidebar()
		expect(wrapper.find('.or-mail-sidebar__collapsed-toggle').exists()).toBe(true)
	})

	it('opens the Connect tab for an email without connections', async () => {
		const wrapper = mountSidebar()
		await wrapper.findComponent({ name: 'ObjectsTab' }).vm.$emit('count', 0)
		expect(wrapper.vm.activeTab).toBe('actions')
		expect(wrapper.classes()).toContain('or-mail-sidebar-root--connections-disabled')
	})

	it('opens Connections for an email with connections, then decides only once', async () => {
		const wrapper = mountSidebar()
		const objects = wrapper.findComponent({ name: 'ObjectsTab' })
		await objects.vm.$emit('count', 2)
		expect(wrapper.vm.activeTab).toBe('objects')
		wrapper.vm.switchTab('entities')
		await objects.vm.$emit('count', 3)
		expect(wrapper.vm.activeTab).toBe('entities')
	})

	it('moves to Connect when the last connection is removed while viewing it', async () => {
		const wrapper = mountSidebar()
		const objects = wrapper.findComponent({ name: 'ObjectsTab' })
		await objects.vm.$emit('count', 1)
		await objects.vm.$emit('count', 0)
		expect(wrapper.vm.activeTab).toBe('actions')
	})

	it('re-arms the tab choice when another email is selected', async () => {
		const wrapper = mountSidebar()
		const objects = wrapper.findComponent({ name: 'ObjectsTab' })
		await objects.vm.$emit('count', 1)
		wrapper.vm.switchTab('entities')
		mockObserved.messageId.value = 43
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.objectsCount).toBeNull()
		await objects.vm.$emit('count', 0)
		expect(wrapper.vm.activeTab).toBe('actions')
	})

	it('switches tab when the Connections tab asks', async () => {
		const wrapper = mountSidebar()
		await wrapper.findComponent({ name: 'ObjectsTab' }).vm.$emit('switchTab', 'actions')
		expect(wrapper.vm.activeTab).toBe('actions')
	})

	it('reloads the connections after a link', async () => {
		const wrapper = mountSidebar()
		await wrapper.findComponent({ name: 'ActionsTab' }).vm.$emit('linked')
		expect(mockLoadObjects).toHaveBeenCalledTimes(1)
	})
})
