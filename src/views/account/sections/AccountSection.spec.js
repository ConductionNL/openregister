/**
 * AccountSection: loads the deactivation status, requests and cancels a deactivation.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import AccountSection from './AccountSection.vue'

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
jest.mock('../../../modals/account/ConfirmDeactivationModal.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'ConfirmDeactivationModal',
			props: { username: String, reason: String, confirmUsername: String },
			emits: ['close', 'confirm', 'update:reason', 'update:confirmUsername'],
			render() {
				return h('div', { class: 'confirm-modal', 'data-username': this.username })
			},
		},
	}
})

const ME = '/index.php/apps/openregister/api/user/me'

/**
 * Mount with the two status reads answering.
 *
 * @param {object} user The /user/me answer.
 * @param {object} status The deactivation-status answer.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(user, status) {
	axios.get.mockImplementation(async (url) => (url === ME ? { data: user } : { data: status }))
	const wrapper = mount(AccountSection)
	await flushPromises()
	return wrapper
}

describe('AccountSection', () => {
	beforeEach(() => jest.clearAllMocks())

	it('shows the request button for an active account and reads both endpoints', async () => {
		const wrapper = await mountWith({ uid: 'alice' }, { status: 'active' })
		expect(axios.get).toHaveBeenCalledWith(ME)
		expect(axios.get).toHaveBeenCalledWith(`${ME}/deactivation-status`)
		expect(wrapper.find('.account-section__active').exists()).toBe(true)
		expect(wrapper.find('.account-section__pending').exists()).toBe(false)
		expect(wrapper.find('.confirm-modal').exists()).toBe(false)
	})

	it('opens the confirmation with the username and submits the reason', async () => {
		axios.post.mockResolvedValue({})
		const wrapper = await mountWith({ uid: 'alice' }, {})
		await wrapper.find('.account-section__active button').trigger('click')
		const modal = wrapper.findComponent({ name: 'ConfirmDeactivationModal' })
		expect(modal.props('username')).toBe('alice')
		modal.vm.$emit('update:reason', 'moving on')
		modal.vm.$emit('update:confirmUsername', 'alice')
		await wrapper.vm.$nextTick()
		expect(modal.props('reason')).toBe('moving on')
		expect(modal.props('confirmUsername')).toBe('alice')
		modal.vm.$emit('confirm')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`${ME}/deactivate`, { reason: 'moving on' })
		expect(wrapper.find('.account-section__pending').exists()).toBe(true)
		expect(wrapper.find('.confirm-modal').exists()).toBe(false)
		expect(wrapper.find('.section__success').text()).toBe('Deactivation request submitted')
	})

	it('closes the confirmation on close without a request', async () => {
		const wrapper = await mountWith({ uid: 'a' }, {})
		await wrapper.find('.account-section__active button').trigger('click')
		wrapper.findComponent({ name: 'ConfirmDeactivationModal' }).vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.confirm-modal').exists()).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('shows the API error when the request fails, else the fallback', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Already pending' } } })
		const wrapper = await mountWith({ uid: 'a' }, {})
		await wrapper.vm.requestDeactivation()
		expect(wrapper.find('.section__error').text()).toBe('Already pending')
		expect(wrapper.find('.account-section__active').exists()).toBe(true)
		axios.post.mockRejectedValueOnce(new Error('x'))
		await wrapper.vm.requestDeactivation()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Failed to request deactivation')
	})

	it('shows a pending request with its time and cancels it', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = await mountWith({ uid: 'a' }, { status: 'pending', pendingRequest: { requestedAt: '2026-01-02T03:04:05Z' } })
		const pending = wrapper.find('.account-section__pending')
		expect(pending.text()).toContain('Requested at')
		expect(pending.text()).toContain(new Date('2026-01-02T03:04:05Z').toLocaleString())
		await pending.find('button').trigger('click')
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith(`${ME}/deactivate`)
		expect(wrapper.find('.account-section__active').exists()).toBe(true)
		expect(wrapper.find('.section__success').text()).toBe('Deactivation request cancelled')
	})

	it('keeps the request pending and shows the fallback when cancelling fails', async () => {
		axios.delete.mockRejectedValue(new Error('x'))
		const wrapper = await mountWith({ uid: 'a' }, { status: 'pending' })
		expect(wrapper.find('.account-section__pending').text()).not.toContain('Requested at')
		await wrapper.find('.account-section__pending button').trigger('click')
		await flushPromises()
		expect(wrapper.find('.account-section__pending').exists()).toBe(true)
		expect(wrapper.find('.section__error').text()).toBe('Failed to cancel deactivation')
	})

	it('defaults to active and an empty username when the reads fail', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(AccountSection)
		await flushPromises()
		expect(wrapper.find('.account-section__active').exists()).toBe(true)
		expect(wrapper.vm.formatDate('')).toBe('')
	})
})
