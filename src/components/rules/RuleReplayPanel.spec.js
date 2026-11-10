/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule replay panel: the preview request it sends and what it shows back.
 *
 * @spec exclude Unit test of the client-side rule replay panel.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import RuleReplayPanel from './RuleReplayPanel.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		template: '<button class="nc-button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['id', 'modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue'],
		template: '<input :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}))

const URL = '/index.php/apps/openregister/api/schemas/7/rules/deadline/replay'

/**
 * Mount the panel for rule "deadline" on schema 7.
 *
 * @return {object} The wrapper
 */
function mountPanel() {
	return mount(RuleReplayPanel, { props: { schema: 7, ruleId: 'deadline' } })
}

/**
 * Press the preview button and let the request settle.
 *
 * @param {object} wrapper The wrapper
 * @return {Promise<void>}
 */
async function preview(wrapper) {
	await wrapper.find('.nc-button').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('RuleReplayPanel', () => {
	it('previews every object when nothing is narrowed', async () => {
		axios.post.mockResolvedValue({
			data: { count: 12, maxObjects: 0, job: { id: 'job-1' } },
		})
		const wrapper = mountPanel()
		await preview(wrapper)
		expect(axios.post).toHaveBeenCalledWith(URL, {
			selection: {},
			justification: '',
		})
		const text = wrapper.find('.replayResult').text()
		expect(text).toContain('This would touch 12 objects.')
		expect(text).toContain('This rule declares no ceiling.')
		expect(text).toContain('Job job-1 is waiting for you to commit it')
		expect(wrapper.find('.nc-button').attributes('disabled')).toBeUndefined()
	})

	it('sends the register, the parsed query and the reason', async () => {
		axios.post.mockResolvedValue({
			data: { count: 2, maxObjects: 50, job: { id: 'j' } },
		})
		const wrapper = mountPanel()
		await wrapper.find('#ruleReplayRegister').setValue('3')
		await wrapper.find('textarea').setValue('{"status": "open"}')
		await wrapper.find('#ruleReplayReason').setValue('termijn changed')
		await preview(wrapper)
		expect(axios.post).toHaveBeenCalledWith(URL, {
			selection: { query: { status: 'open' } },
			justification: 'termijn changed',
			registerId: '3',
		})
		expect(wrapper.find('.replayResult').text()).toContain(
			'The ceiling on this rule is 50.',
		)
	})

	it('disables the button while the preview runs', async () => {
		let release
		axios.post.mockReturnValue(new Promise((r) => (release = r)))
		const wrapper = mountPanel()
		await wrapper.find('.nc-button').trigger('click')
		expect(wrapper.find('.nc-button').attributes('disabled')).toBeDefined()
		release({ data: { count: 0, job: { id: 'j' } } })
		await flushPromises()
		expect(wrapper.find('.nc-button').attributes('disabled')).toBeUndefined()
	})

	it('shows a refusal envelope the server answered with', async () => {
		axios.post.mockResolvedValue({
			data: { ok: false, error: { message: 'Over the ceiling', count: 900 } },
		})
		const wrapper = mountPanel()
		await preview(wrapper)
		expect(wrapper.find('.refusal').text()).toBe('Over the ceiling')
		expect(wrapper.find('.refusalBox .muted').text()).toBe(
			'Nothing was written and no job was created.',
		)
		expect(wrapper.find('.replayResult').exists()).toBe(false)
	})

	it('shows the error envelope of a failed request', async () => {
		axios.post.mockRejectedValue({
			response: { data: { error: { message: 'Forbidden' } } },
		})
		const wrapper = mountPanel()
		await preview(wrapper)
		expect(wrapper.find('.refusal').text()).toBe('Forbidden')
		expect(wrapper.find('.refusalBox .muted').exists()).toBe(false)
	})

	it('names the JSON error of a bad query without sending it', async () => {
		const wrapper = mountPanel()
		await wrapper.find('textarea').setValue('{bad')
		await preview(wrapper)
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('.refusal').text()).toMatch(/JSON/)
	})

	it('falls back to its own message when the failure has none', async () => {
		axios.post.mockRejectedValue({})
		const wrapper = mountPanel()
		await preview(wrapper)
		expect(wrapper.find('.refusal').text()).toBe(
			'The replay could not be previewed.',
		)
	})

	it('clears the outcome when another rule is shown', async () => {
		axios.post.mockResolvedValue({ data: { count: 1, job: { id: 'j' } } })
		const wrapper = mountPanel()
		await preview(wrapper)
		await wrapper.setProps({ ruleId: 'other' })
		expect(wrapper.find('.replayResult').exists()).toBe(false)
		expect(wrapper.find('.refusalBox').exists()).toBe(false)
	})
})
