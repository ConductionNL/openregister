/**
 * PasswordSection: hides the form when the backend cannot change passwords,
 * submits both passwords, clears them on success and shows the result.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PasswordSection from './PasswordSection.vue'

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
		props: { disabled: Boolean, variant: String, type: String },
		emits: ['click'],
		render() {
			return h('button', { class: 'nc-button', type: this.type || 'button', 'data-variant': this.variant, disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	}
	const text = {
		name: 'NcTextField',
		props: { modelValue: null, disabled: Boolean, id: String, type: String, label: String },
		emits: ['update:modelValue'],
		render() {
			return h('input', { class: 'nc-text', id: this.id, value: this.modelValue, disabled: this.disabled, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	}
	return { __esModule: true, NcButton: button, NcTextField: text }
})

const ME = '/index.php/apps/openregister/api/user/me'

/**
 * Mount with the /user/me read answering or failing.
 *
 * @param {object|Error} me The /user/me answer, or an error to reject with.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(me) {
	if (me instanceof Error) {
		axios.get.mockRejectedValue(me)
	} else {
		axios.get.mockResolvedValue({ data: me })
	}
	const wrapper = mount(PasswordSection)
	await flushPromises()
	return wrapper
}

/**
 * Fill both password fields.
 *
 * @param {object} wrapper The mounted section.
 * @param {string} current The current password.
 * @param {string} next The new password.
 * @return {Promise<void>}
 */
async function fill(wrapper, current, next) {
	await wrapper.find('#current-password').setValue(current)
	await wrapper.find('#new-password').setValue(next)
}

describe('PasswordSection', () => {
	beforeEach(() => jest.clearAllMocks())

	it('shows the form when the backend supports password changes', async () => {
		const wrapper = await mountWith({ backendCapabilities: { password: true } })
		expect(axios.get).toHaveBeenCalledWith(ME)
		expect(wrapper.find('form').exists()).toBe(true)
		expect(wrapper.find('.section__disabled').exists()).toBe(false)
	})

	it('replaces the form with a notice when the backend cannot change passwords', async () => {
		const wrapper = await mountWith({ backendCapabilities: { password: false } })
		expect(wrapper.find('form').exists()).toBe(false)
		expect(wrapper.find('.section__disabled').text()).toBe('Password changes are not supported by your authentication provider.')
	})

	it.each([[{}], [{ backendCapabilities: {} }], [null], [new Error('down')]])('defaults to showing the form for %p', async (me) => {
		const wrapper = await mountWith(me)
		expect(wrapper.vm.canChangePassword).toBe(true)
		expect(wrapper.find('form').exists()).toBe(true)
	})

	it('disables the submit button until both passwords are given', async () => {
		const wrapper = await mountWith({})
		const submit = () => wrapper.find('button[type="submit"]')
		expect(submit().attributes('disabled')).toBeDefined()
		await wrapper.find('#current-password').setValue('old')
		expect(submit().attributes('disabled')).toBeDefined()
		await wrapper.find('#new-password').setValue('new')
		expect(submit().attributes('disabled')).toBeUndefined()
	})

	it('submits both passwords, clears the fields and shows the API message', async () => {
		axios.put.mockResolvedValue({ data: { message: 'Changed' } })
		const wrapper = await mountWith({})
		await fill(wrapper, 'old-pw', 'new-pw')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put).toHaveBeenCalledWith(`${ME}/password`, { currentPassword: 'old-pw', newPassword: 'new-pw' })
		expect(wrapper.vm.currentPassword).toBe('')
		expect(wrapper.vm.newPassword).toBe('')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.section__success').text()).toBe('Changed')
	})

	it('falls back to the default success text when the API gives no message', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const wrapper = await mountWith({})
		await fill(wrapper, 'a', 'b')
		await wrapper.vm.changePassword()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__success').text()).toBe('Password updated successfully')
	})

	it('keeps the fields and shows the API error when the change fails', async () => {
		axios.put.mockRejectedValue({ response: { data: { error: 'Wrong current password' } } })
		const wrapper = await mountWith({})
		await fill(wrapper, 'old', 'new')
		await wrapper.vm.changePassword()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Wrong current password')
		expect(wrapper.vm.currentPassword).toBe('old')
		expect(wrapper.vm.newPassword).toBe('new')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the fallback error when the API gives none', async () => {
		axios.put.mockRejectedValue(new Error('network'))
		const wrapper = await mountWith({})
		await fill(wrapper, 'old', 'new')
		await wrapper.vm.changePassword()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Failed to change password')
	})

	it('disables the fields while the change is in flight', async () => {
		let resolve
		axios.put.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = await mountWith({})
		await fill(wrapper, 'old', 'new')
		const pending = wrapper.vm.changePassword()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('#current-password').attributes('disabled')).toBeDefined()
		expect(wrapper.find('#new-password').attributes('disabled')).toBeDefined()
		expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
		resolve({ data: {} })
		await pending
		await wrapper.vm.$nextTick()
		expect(wrapper.find('#current-password').attributes('disabled')).toBeUndefined()
	})
})
