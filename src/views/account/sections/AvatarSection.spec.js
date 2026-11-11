/**
 * AvatarSection: shows the avatar controls when the backend allows, uploads and removes.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import AvatarSection from './AvatarSection.vue'

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

const URL = '/index.php/apps/openregister/api/user/me/avatar'

describe('AvatarSection', () => {
	beforeEach(() => jest.clearAllMocks())

	it('shows the avatar of the signed-in user', async () => {
		axios.get.mockResolvedValue({ data: { uid: 'bob' } })
		const wrapper = mount(AvatarSection)
		await flushPromises()
		expect(wrapper.find('.nc-avatar').attributes('data-user')).toBe('bob')
		expect(wrapper.find('.section__disabled').exists()).toBe(false)
	})

	it('hides the controls when the backend cannot change avatars', async () => {
		axios.get.mockResolvedValue({ data: { uid: 'bob', backendCapabilities: { avatar: false } } })
		const wrapper = mount(AvatarSection)
		await flushPromises()
		expect(wrapper.find('.section__disabled').exists()).toBe(true)
		expect(wrapper.find('.nc-avatar').exists()).toBe(false)
	})

	it('keeps the controls when the user read fails', async () => {
		axios.get.mockRejectedValue(new Error('x'))
		const wrapper = mount(AvatarSection)
		await flushPromises()
		expect(wrapper.find('.avatar-section').exists()).toBe(true)
	})

	it('opens the file picker from the upload button', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = mount(AvatarSection)
		await flushPromises()
		const click = jest.spyOn(wrapper.vm.$refs.fileInput, 'click').mockImplementation(() => {})
		await wrapper.findAll('button')[0].trigger('click')
		expect(click).toHaveBeenCalled()
	})

	it('uploads the picked file with its content type', async () => {
		axios.get.mockResolvedValue({ data: {} })
		axios.post.mockResolvedValue({})
		const wrapper = mount(AvatarSection)
		await flushPromises()
		const buffer = new ArrayBuffer(3)
		const file = { type: 'image/png', arrayBuffer: async () => buffer }
		await wrapper.vm.uploadAvatar({ target: { files: [file] } })
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(URL, buffer, { headers: { 'Content-Type': 'image/png' } })
		expect(wrapper.find('.section__success').text()).toBe('Avatar updated successfully')
	})

	it('does nothing without a file and reports a failed upload', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = mount(AvatarSection)
		await flushPromises()
		await wrapper.vm.uploadAvatar({ target: { files: [] } })
		expect(axios.post).not.toHaveBeenCalled()
		axios.post.mockRejectedValue({ response: { data: { error: 'Too big' } } })
		await wrapper.vm.uploadAvatar({ target: { files: [{ type: 'image/png', arrayBuffer: async () => new ArrayBuffer(1) }] } })
		await flushPromises()
		expect(wrapper.find('.section__error').text()).toBe('Too big')
	})

	it('removes the avatar and reports a failure with the fallback', async () => {
		axios.get.mockResolvedValue({ data: {} })
		axios.delete.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('x'))
		const wrapper = mount(AvatarSection)
		await flushPromises()
		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith(URL)
		expect(wrapper.find('.section__success').text()).toBe('Avatar removed')
		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()
		expect(wrapper.find('.section__error').text()).toBe('Failed to remove avatar')
	})
})
