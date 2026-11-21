/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The migration pack sample-row preview: debounced runs, the exact request,
 * the written fields, row problems and refusals, and stale answers dropped.
 *
 * @spec exclude Unit test of the client-side migration pack preview panel.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PackPreviewPanel, { PREVIEW_DEBOUNCE_MS } from './PackPreviewPanel.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn(), get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin' }) }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		template: '<button class="test" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows'],
		template: `<table><tr v-for="row in rows">
			<td v-for="col in columns" :class="col.key"><slot :name="'column-' + col.key" :row="row" :value="row[col.key]">{{ row[col.key] }}</slot></td>
		</tr></table>`,
	},
}))

const URL = '/index.php/apps/openregister/api/migration-packs/preview'
const DEFINITION = { name: 'pack' }

/**
 * Mount the panel.
 *
 * @param {object} props Overrides
 * @return {object} The wrapper
 */
function mountPanel(props = {}) {
	return mount(PackPreviewPanel, {
		props: { definition: DEFINITION, columns: ['naam', 'leeftijd'], ...props },
	})
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
})
afterEach(() => {
	jest.useRealTimers()
})

describe('PackPreviewPanel', () => {
	it('asks for a mapping with a source column when there is none', () => {
		const wrapper = mountPanel({ columns: [] })
		expect(wrapper.find('.pack-preview__empty').exists()).toBe(true)
		expect(wrapper.find('button.test').attributes('disabled')).toBeDefined()
	})

	it('runs once, debounced, after typing a sample value', async () => {
		axios.post.mockResolvedValue({ data: { rows: [{ data: { name: 'Ann', age: 4 } }] } })
		const wrapper = mountPanel()
		const inputs = wrapper.findAll('td.value input')
		expect(inputs[0].attributes('aria-label')).toBe('Sample value for naam')
		await inputs[0].setValue('Ann')
		await inputs[1].setValue('4')
		jest.advanceTimersByTime(PREVIEW_DEBOUNCE_MS - 1)
		expect(axios.post).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		await flushPromises()
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(URL, {
			definition: DEFINITION,
			rows: [{ naam: 'Ann', leeftijd: '4' }],
		})
		expect(wrapper.findAll('td.name').map((c) => c.text())).toEqual(['name', 'age'])
		expect(wrapper.findAll('td.value code').map((c) => c.text())).toEqual(['"Ann"', '4'])
	})

	it('does not run for an all-empty sample', async () => {
		const wrapper = mountPanel()
		await wrapper.findAll('td.value input')[0].setValue('')
		jest.advanceTimersByTime(PREVIEW_DEBOUNCE_MS * 2)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('lists the problems of the row, with a dash for a missing side', async () => {
		axios.post.mockResolvedValue({
			data: { rows: [{ errors: [{ source: 'naam', message: 'Too long' }, { target: 'age', message: 'Not a number' }] }] },
		})
		const wrapper = mountPanel()
		await wrapper.find('button.test').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('No fields: every mapping was empty or failed.')
		expect(wrapper.findAll('td.source').map((c) => c.text())).toEqual(['naam', '-'])
		expect(wrapper.findAll('td.target').map((c) => c.text())).toEqual(['-', 'age'])
		expect(wrapper.findAll('td.message').map((c) => c.text())).toEqual(['Too long', 'Not a number'])
	})

	it('shows a refused test with its details', async () => {
		axios.post.mockRejectedValue({
			response: { status: 400, data: { message: 'Bad pack', details: { errors: ['field x'] } } },
		})
		const wrapper = mountPanel()
		await wrapper.find('button.test').trigger('click')
		await flushPromises()
		const note = wrapper.find('.note[data-type="error"]')
		expect(note.find('li').text()).toBe('field x')
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('drops an answer that a newer run overtook', async () => {
		let first
		axios.post
			.mockImplementationOnce(() => new Promise((resolve) => (first = resolve)))
			.mockResolvedValueOnce({ data: { rows: [{ data: { v: 'new' } }] } })
		const wrapper = mountPanel()
		const button = wrapper.find('button.test')
		await button.trigger('click')
		await button.trigger('click')
		await flushPromises()
		first({ data: { rows: [{ data: { v: 'old' } }] } })
		await flushPromises()
		expect(wrapper.findAll('td.value code').map((c) => c.text())).toEqual(['"new"'])
	})

	it('clears the result and blocks testing when the definition goes away', async () => {
		axios.post.mockResolvedValue({ data: { rows: [] } })
		const wrapper = mountPanel({ blockedText: 'Fix the mapping' })
		await wrapper.find('button.test').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Fields the import would write')
		await wrapper.setProps({ definition: null })
		expect(wrapper.text()).not.toContain('Fields the import would write')
		expect(wrapper.find('.note[data-type="warning"]').text()).toBe('Fix the mapping')
		await wrapper.vm.run()
		expect(axios.post).toHaveBeenCalledTimes(1)
	})

	it('runs again when the definition changes and a sample is typed', async () => {
		axios.post.mockResolvedValue({ data: { rows: [] } })
		const wrapper = mountPanel()
		await wrapper.findAll('td.value input')[0].setValue('Ann')
		jest.advanceTimersByTime(PREVIEW_DEBOUNCE_MS)
		await flushPromises()
		await wrapper.setProps({ definition: { name: 'pack2' } })
		jest.advanceTimersByTime(PREVIEW_DEBOUNCE_MS)
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith(URL, expect.objectContaining({ definition: { name: 'pack2' } }))
	})
})
