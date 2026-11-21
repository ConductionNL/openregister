/**
 * ExportSection: downloads the personal data export as a dated JSON file and
 * reports success, rate limiting or failure.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ExportSection from './ExportSection.vue'

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

const EXPORT = '/index.php/apps/openregister/api/user/me/export'

describe('ExportSection', () => {
	let createObjectURL
	let revokeObjectURL
	let click

	beforeEach(() => {
		jest.clearAllMocks()
		createObjectURL = jest.fn(() => 'blob:export')
		revokeObjectURL = jest.fn()
		window.URL.createObjectURL = createObjectURL
		window.URL.revokeObjectURL = revokeObjectURL
		click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
			click.lastAnchor = { href: this.getAttribute('href'), download: this.getAttribute('download'), attached: document.body.contains(this) }
		})
	})

	afterEach(() => {
		click.mockRestore()
		jest.useRealTimers()
	})

	it('downloads the export as a dated JSON file and reports success', async () => {
		jest.useFakeTimers({ now: new Date('2026-03-04T10:00:00Z'), doNotFake: ['nextTick', 'setImmediate', 'queueMicrotask'] })
		axios.get.mockResolvedValue({ data: '{"a":1}' })
		const wrapper = mount(ExportSection)
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(EXPORT, { responseType: 'blob' })
		expect(createObjectURL).toHaveBeenCalledTimes(1)
		expect(createObjectURL.mock.calls[0][0]).toBeInstanceOf(Blob)
		expect(click).toHaveBeenCalledTimes(1)
		expect(click.lastAnchor).toEqual({ href: 'blob:export', download: 'openregister-export-2026-03-04.json', attached: true })
		expect(document.body.querySelector('a[download]')).toBeNull()
		expect(revokeObjectURL).toHaveBeenCalledWith('blob:export')
		expect(wrapper.find('.section__success').text()).toBe('Export downloaded successfully')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows Exporting… and disables the button while the export runs', async () => {
		let resolve
		axios.get.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = mount(ExportSection)
		expect(wrapper.find('button').text()).toBe('Export my data')
		const pending = wrapper.vm.exportData()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('button').text()).toBe('Exporting...')
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()
		resolve({ data: '' })
		await pending
		await wrapper.vm.$nextTick()
		expect(wrapper.find('button').text()).toBe('Export my data')
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('reports rate limiting on a 429', async () => {
		axios.get.mockRejectedValue({ response: { status: 429 } })
		const wrapper = mount(ExportSection)
		await wrapper.vm.exportData()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Export is rate limited. Please try again later.')
		expect(click).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
	})

	it.each([[{ response: { status: 500, data: { error: 'boom' } } }], [new Error('network')]])('reports a generic failure for %p', async (error) => {
		axios.get.mockRejectedValue(error)
		const wrapper = mount(ExportSection)
		await wrapper.vm.exportData()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.section__error').text()).toBe('Failed to export data')
		expect(createObjectURL).not.toHaveBeenCalled()
	})

	it('clears an earlier message when a new export starts', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const wrapper = mount(ExportSection)
		await wrapper.vm.exportData()
		expect(wrapper.vm.message).toBe('Failed to export data')
		let resolve
		axios.get.mockReturnValue(new Promise((r) => { resolve = r }))
		const pending = wrapper.vm.exportData()
		expect(wrapper.vm.message).toBe('')
		resolve({ data: '' })
		await pending
		expect(wrapper.vm.isError).toBe(false)
	})
})
