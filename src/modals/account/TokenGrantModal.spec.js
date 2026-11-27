/**
 * TokenGrantModal: finds an app password by pasting it once, then saves,
 * removes and previews the grant on it.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import TokenGrantModal from './TokenGrantModal.vue'

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
	return {
		__esModule: true,
		NcButton: {
			name: 'NcButton',
			props: { disabled: Boolean, variant: String },
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
		NcModal: {
			name: 'NcModal',
			emits: ['close'],
			render() {
				return h('div', { class: 'nc-modal' }, this.$slots.default?.())
			},
		},
	}
})
jest.mock('../../components/tokens/TokenGrantEditor.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'TokenGrantEditor',
			props: { grant: Object, busy: Boolean, removable: Boolean, previewResult: Object, previewable: Boolean },
			emits: ['save', 'remove', 'preview'],
			render() {
				return h('div', { class: 'editor' })
			},
		},
	}
})

const grant = { verbs: ['read'], expiresAt: '2027-03-01T23:59:59+00:00' }

describe('TokenGrantModal', () => {
	beforeEach(() => jest.clearAllMocks())

	it('resolves the pasted password, then forgets it and shows the editor', async () => {
		axios.post.mockResolvedValue({ data: { tokenId: '42', name: 'Supplier sync', grant: null } })
		const wrapper = mount(TokenGrantModal)
		await wrapper.find('[data-field="password"]').setValue(' secret ')
		await wrapper.find('[data-action="find"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/tokens/grants/resolve', { password: 'secret' })
		expect(wrapper.vm.password).toBe('')
		expect(wrapper.find('h3').text()).toBe('Supplier sync')
		const editor = wrapper.findComponent({ name: 'TokenGrantEditor' })
		expect(editor.props('grant')).toBeNull()
		expect(editor.props('removable')).toBe(false)
	})

	it('says so when the password is not one of yours, and forgets it', async () => {
		axios.post.mockRejectedValue({ response: { status: 404 } })
		const wrapper = mount(TokenGrantModal)
		await wrapper.find('[data-field="password"]').setValue('other')
		await wrapper.find('[data-action="find"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('This is not one of your app passwords')
		expect(wrapper.vm.password).toBe('')
		expect(wrapper.findComponent({ name: 'TokenGrantEditor' }).exists()).toBe(false)
	})

	it('saves the grant with the token name as its label', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const wrapper = mount(TokenGrantModal, { props: { granted: { tokenId: '42', label: 'Supplier sync', grant } } })
		wrapper.findComponent({ name: 'TokenGrantEditor' }).vm.$emit('save', grant)
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/openregister/api/tokens/42/grant', { grant, label: 'Supplier sync' })
		expect(wrapper.emitted('saved')).toEqual([['Grant saved']])
	})

	it('removes the grant', async () => {
		axios.delete.mockResolvedValue({ data: {} })
		const wrapper = mount(TokenGrantModal, { props: { granted: { tokenId: '42', label: null, grant } } })
		expect(wrapper.find('h3').text()).toBe('App password 42')
		wrapper.findComponent({ name: 'TokenGrantEditor' }).vm.$emit('remove')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith('/index.php/apps/openregister/api/tokens/42/grant')
		expect(wrapper.emitted('saved')).toEqual([['Grant removed']])
	})

	it('shows the server reason when a save is refused', async () => {
		axios.put.mockRejectedValue({ response: { data: { error: 'a token grant needs an end date' } } })
		const wrapper = mount(TokenGrantModal, { props: { granted: { tokenId: '42', label: 'x', grant } } })
		wrapper.findComponent({ name: 'TokenGrantEditor' }).vm.$emit('save', grant)
		await flushPromises()

		expect(wrapper.text()).toContain('a token grant needs an end date')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('hands the preview answer to the editor', async () => {
		const answer = { verbs: [{ verb: 'read', holder: true, token: true }], expired: false }
		axios.post.mockResolvedValue({ data: answer })
		const wrapper = mount(TokenGrantModal, { props: { granted: { tokenId: '42', label: 'x', grant } } })
		const question = { grant, register: 'zaken', schema: 'case', object: 'abc' }
		wrapper.findComponent({ name: 'TokenGrantEditor' }).vm.$emit('preview', question)
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/tokens/grants/preview', question)
		expect(wrapper.findComponent({ name: 'TokenGrantEditor' }).props('previewResult')).toEqual(answer)
	})
})
