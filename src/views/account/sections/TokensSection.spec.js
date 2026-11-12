/**
 * TokensSection: lists the personal API tokens, creates one (with an
 * optional expiry) and reveals its secret once, revokes one, and copies the
 * revealed secret.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import TokensSection from './TokensSection.vue'

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
jest.mock('../../../modals/account/CreateTokenModal.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'CreateTokenModal',
			props: { tokenName: String, tokenExpires: String },
			emits: ['close', 'create', 'update:tokenName', 'update:tokenExpires'],
			render() {
				return h('div', { class: 'create-modal' })
			},
		},
	}
})
jest.mock('../../../modals/account/CreatedTokenModal.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'CreatedTokenModal',
			props: { token: String },
			emits: ['close', 'copy'],
			render() {
				return h('div', { class: 'created-modal', 'data-token': this.token })
			},
		},
	}
})

const TOKENS = '/index.php/apps/openregister/api/user/me/tokens'

/**
 * Mount with the token list answering.
 *
 * @param {Array|null} tokens The list answer.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(tokens) {
	axios.get.mockResolvedValue({ data: tokens })
	const wrapper = mount(TokensSection)
	await flushPromises()
	return wrapper
}

describe('TokensSection', () => {
	beforeEach(() => jest.clearAllMocks())

	it('lists the tokens with name, preview and expiry', async () => {
		const wrapper = await mountWith([
			{ id: 1, name: 'CI', preview: 'abcd…', expires: '2027-01-02T00:00:00Z' },
			{ id: 2, name: 'Script', preview: 'wxyz…' },
		])
		expect(axios.get).toHaveBeenCalledWith(TOKENS)
		const items = wrapper.findAll('.tokens-section__item')
		expect(items).toHaveLength(2)
		expect(items[0].find('strong').text()).toBe('CI')
		expect(items[0].find('.tokens-section__preview').text()).toBe('abcd…')
		expect(items[0].find('.tokens-section__expires').text()).toContain(new Date('2027-01-02T00:00:00Z').toLocaleDateString())
		expect(items[1].find('.tokens-section__expires').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('No API tokens.')
	})

	it.each([[[]], [null]])('shows the empty notice for a %p answer', async (answer) => {
		const wrapper = await mountWith(answer)
		expect(wrapper.vm.tokens).toEqual([])
		expect(wrapper.text()).toContain('No API tokens.')
	})

	it('swallows a failed load and shows the empty notice', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(TokensSection)
		await flushPromises()
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.text()).toContain('No API tokens.')
		expect(wrapper.find('.section__error').exists()).toBe(false)
	})

	it('shows the loading line instead of the list while loading', async () => {
		let resolve
		axios.get.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = mount(TokensSection)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__loading').exists()).toBe(true)
		expect(wrapper.find('.tokens-section__list').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('No API tokens.')
		resolve({ data: [] })
		await flushPromises()
		expect(wrapper.find('.section__loading').exists()).toBe(false)
	})

	it('opens the create modal and binds its fields', async () => {
		const wrapper = await mountWith([])
		expect(wrapper.find('.create-modal').exists()).toBe(false)
		await wrapper.find('button').trigger('click')
		const modal = wrapper.findComponent({ name: 'CreateTokenModal' })
		expect(modal.exists()).toBe(true)
		modal.vm.$emit('update:tokenName', 'CI')
		modal.vm.$emit('update:tokenExpires', '30d')
		await wrapper.vm.$nextTick()
		expect(modal.props('tokenName')).toBe('CI')
		expect(modal.props('tokenExpires')).toBe('30d')
		modal.vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.create-modal').exists()).toBe(false)
	})

	it('creates a token with an expiry, reveals it, resets the form and reloads', async () => {
		axios.post.mockResolvedValue({ data: { token: 'secret-123' } })
		const wrapper = await mountWith([])
		wrapper.vm.showCreateModal = true
		wrapper.vm.newTokenName = 'CI'
		wrapper.vm.newTokenExpires = '30d'
		await wrapper.vm.$nextTick()
		axios.get.mockResolvedValue({ data: [{ id: 9, name: 'CI', preview: 'sec…' }] })
		wrapper.findComponent({ name: 'CreateTokenModal' }).vm.$emit('create')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(TOKENS, { name: 'CI', expiresIn: '30d' })
		expect(wrapper.find('.create-modal').exists()).toBe(false)
		expect(wrapper.find('.created-modal').attributes('data-token')).toBe('secret-123')
		expect(wrapper.vm.newTokenName).toBe('')
		expect(wrapper.vm.newTokenExpires).toBe('')
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.findAll('.tokens-section__item')).toHaveLength(1)
	})

	it('sends no expiresIn when no expiry was chosen', async () => {
		axios.post.mockResolvedValue({ data: { token: 't' } })
		const wrapper = await mountWith([])
		wrapper.vm.newTokenName = 'Script'
		await wrapper.vm.createToken()
		expect(axios.post).toHaveBeenCalledWith(TOKENS, { name: 'Script' })
	})

	it('keeps the create modal open and shows the error when creating fails', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'Name taken' } } })
		const wrapper = await mountWith([])
		wrapper.vm.showCreateModal = true
		wrapper.vm.newTokenName = 'CI'
		await wrapper.vm.createToken()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Name taken')
		expect(wrapper.vm.showCreateModal).toBe(true)
		expect(wrapper.vm.newTokenName).toBe('CI')
		expect(wrapper.vm.createdToken).toBeNull()
		axios.post.mockRejectedValue(new Error('x'))
		await wrapper.vm.createToken()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Failed to create token')
	})

	it('revokes a token by id and reloads the list', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = await mountWith([{ id: 7, name: 'Old', preview: 'o…' }])
		axios.get.mockResolvedValue({ data: [] })
		await wrapper.find('.tokens-section__item button').trigger('click')
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith(`${TOKENS}/7`)
		expect(wrapper.find('.section__success').text()).toBe('Token revoked')
		expect(wrapper.findAll('.tokens-section__item')).toHaveLength(0)
	})

	it('shows the error and does not reload when revoking fails', async () => {
		axios.delete.mockRejectedValue(new Error('x'))
		const wrapper = await mountWith([{ id: 7, name: 'Old', preview: 'o…' }])
		await wrapper.vm.revokeToken(7)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Failed to revoke token')
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('copies the revealed token and closes the reveal modal', async () => {
		const writeText = jest.fn(() => Promise.resolve())
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = await mountWith([])
		wrapper.vm.createdToken = 'secret-1'
		await wrapper.vm.$nextTick()
		wrapper.findComponent({ name: 'CreatedTokenModal' }).vm.$emit('copy')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('secret-1')
		expect(wrapper.find('.section__success').text()).toBe('Token copied to clipboard')
		wrapper.findComponent({ name: 'CreatedTokenModal' }).vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.created-modal').exists()).toBe(false)
	})

	it('reports a failed copy as an error', async () => {
		Object.defineProperty(navigator, 'clipboard', { value: { writeText: jest.fn(() => Promise.reject(new Error('denied'))) }, configurable: true })
		const wrapper = await mountWith([])
		wrapper.vm.createdToken = 's'
		await wrapper.vm.copyToken()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Failed to copy token')
	})

	it('formats an empty date as an empty string', async () => {
		const wrapper = await mountWith([])
		expect(wrapper.vm.formatDate('')).toBe('')
		expect(wrapper.vm.formatDate(null)).toBe('')
	})
})
