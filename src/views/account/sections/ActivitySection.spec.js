/**
 * ActivitySection: loads the user's activity, filters by type and pages.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ActivitySection from './ActivitySection.vue'

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

const URL = '/index.php/apps/openregister/api/user/me/activity'

describe('ActivitySection', () => {
	beforeEach(() => jest.clearAllMocks())

	it('shows a loading line, then lists the first page', async () => {
		let resolve
		axios.get.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = mount(ActivitySection)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__loading').exists()).toBe(true)
		resolve({ data: { results: [{ id: 1, type: 'create', summary: 'Made X', timestamp: '2026-01-01T00:00:00Z' }, { id: 2, type: 'update', summary: 'Changed', timestamp: null }], total: 2 } })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(URL, { params: { _limit: 25, _offset: 0 } })
		const items = wrapper.findAll('.activity-section__item')
		expect(items).toHaveLength(2)
		expect(items[0].find('.activity-section__summary').text()).toBe('Made X')
		expect(items[0].find('.activity-section__time').text()).toBe(new Date('2026-01-01T00:00:00Z').toLocaleString())
		expect(items[1].find('.activity-section__time').text()).toBe('')
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('pages with Load more while more remain', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { results: [{ id: 1 }], total: 2 } })
			.mockResolvedValueOnce({ data: { results: [{ id: 2 }], total: 2 } })
		const wrapper = mount(ActivitySection)
		await flushPromises()
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(URL, { params: { _limit: 25, _offset: 25 } })
		expect(wrapper.findAll('.activity-section__item')).toHaveLength(2)
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('reloads from the start with the chosen type', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 1 }], total: 1 } })
		const wrapper = mount(ActivitySection)
		await flushPromises()
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'delete')
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(URL, { params: { _limit: 25, _offset: 0, type: 'delete' } })
		expect(wrapper.findAll('.activity-section__item')).toHaveLength(1)
	})

	it('shows the empty message when the read fails or finds nothing', async () => {
		axios.get.mockRejectedValue(new Error('x'))
		const wrapper = mount(ActivitySection)
		await flushPromises()
		expect(wrapper.text()).toContain('No activity found.')
		axios.get.mockResolvedValue({ data: {} })
		await wrapper.vm.loadActivity()
		await flushPromises()
		expect(wrapper.vm.total).toBe(0)
		expect(wrapper.text()).toContain('No activity found.')
	})
})
