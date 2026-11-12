/**
 * NotificationsSection: loads and saves the notification preferences.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import NotificationsSection from './NotificationsSection.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	const button = {
		name: 'NcButton',
		props: { disabled: Boolean, variant: String },
		emits: ['click'],
		render() {
			return h('button', { class: 'nc-button', 'data-variant': this.variant, disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	}
	const select = {
		name: 'NcSelect',
		props: { modelValue: null, options: Array },
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: 'nc-select' })
		},
	}
	const toggle = {
		name: 'NcCheckboxRadioSwitch',
		props: { modelValue: null },
		emits: ['update:modelValue'],
		render() {
			return h('label', { class: 'nc-switch' }, this.$slots.default?.())
		},
	}
	const avatar = {
		name: 'NcAvatar',
		props: { user: String },
		render() {
			return h('span', { class: 'nc-avatar', 'data-user': this.user })
		},
	}
	return { __esModule: true, NcButton: button, NcSelect: select, NcCheckboxRadioSwitch: toggle, NcAvatar: avatar }
})

const URL = '/index.php/apps/openregister/api/user/me/notifications'

describe('NotificationsSection', () => {
	beforeEach(() => jest.clearAllMocks())

	it('shows loading, then a toggle per preference merged with the stored values', async () => {
		let resolve
		axios.get.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = mount(NotificationsSection)
		expect(wrapper.find('.section__loading').exists()).toBe(true)
		resolve({ data: { assignments: false, emailDigest: 'weekly' } })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(URL)
		const toggles = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(toggles).toHaveLength(4)
		expect(toggles[1].props('modelValue')).toBe(false)
		expect(toggles[0].props('modelValue')).toBe(true)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBe('weekly')
	})

	it('saves the changed preference and keeps the server answer', async () => {
		axios.get.mockResolvedValue({ data: {} })
		axios.put.mockResolvedValue({ data: { emailDigest: 'none' } })
		const wrapper = mount(NotificationsSection)
		await flushPromises()
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.$emit('update:modelValue', false)
		await flushPromises()
		expect(axios.put).toHaveBeenCalledWith(URL, expect.objectContaining({ objectChanges: false, emailDigest: 'daily' }))
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBe('none')
		expect(wrapper.find('.section__success').text()).toBe('Preferences saved')
	})

	it('uses the defaults when the read fails and reports a failed save', async () => {
		axios.get.mockRejectedValue(new Error('x'))
		axios.put.mockRejectedValue(new Error('x'))
		const wrapper = mount(NotificationsSection)
		await flushPromises()
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBe('daily')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'weekly')
		await flushPromises()
		expect(axios.put).toHaveBeenCalledWith(URL, expect.objectContaining({ emailDigest: 'weekly' }))
		expect(wrapper.find('.section__error').text()).toBe('Failed to save preferences')
	})
})
