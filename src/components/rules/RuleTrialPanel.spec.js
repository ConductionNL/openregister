/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule trial panel: the dry-run request it sends and what it shows back.
 *
 * @spec exclude Unit test of the client-side rule trial panel.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import RuleTrialPanel from './RuleTrialPanel.vue'

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
jest.mock('./RuleTraceView.vue', () => ({
	name: 'RuleTraceView',
	props: ['trace', 'verdicts'],
	render: () => null,
}))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		template: '<button class="nc-button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'value'],
		emits: ['update:modelValue'],
		template: '<label class="radio" @click="$emit(\'update:modelValue\', value)"><slot /></label>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['id', 'modelValue'],
		emits: ['update:modelValue'],
		template: '<input :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows'],
		template: `<table><tr v-for="row in rows" class="write">
			<td v-for="col in columns" :class="col.key"><slot :name="'column-' + col.key" :value="row[col.key]" /></td>
		</tr></table>`,
	},
}))

const URL = '/index.php/apps/openregister/api/schemas/7/rules/deadline/evaluate'

/**
 * Mount the panel for rule "deadline" on schema 7.
 *
 * @param {object} props Extra props
 * @return {object} The wrapper
 */
function mountPanel(props = {}) {
	return mount(RuleTrialPanel, {
		props: { schema: 7, ruleId: 'deadline', verdicts: ['fired'], ...props },
	})
}

/**
 * Press the run button and let the request settle.
 *
 * @param {object} wrapper The wrapper
 * @return {Promise<void>}
 */
async function run(wrapper) {
	await wrapper.find('.nc-button').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('RuleTrialPanel', () => {
	it('tries an empty sample and shows the trace and the writes', async () => {
		const trace = [{ step: 1 }]
		axios.post.mockResolvedValue({
			data: { trace, writes: { due: '2026-02-01', meta: { a: 1 }, gone: null, n: 3 } },
		})
		const wrapper = mountPanel()
		expect(wrapper.find('.nc-button').text()).toBe('Run it')
		await run(wrapper)
		expect(axios.post).toHaveBeenCalledWith(URL, { object: {} })
		expect(wrapper.findComponent({ name: 'RuleTraceView' }).props()).toEqual({
			trace,
			verdicts: ['fired'],
		})
		expect(wrapper.findAll('td.id').map((c) => c.text())).toEqual([
			'due',
			'meta',
			'gone',
			'n',
		])
		expect(wrapper.findAll('td.value').map((c) => c.text())).toEqual([
			'2026-02-01',
			'{"a":1}',
			'null',
			'3',
		])
	})

	it('says the rule writes nothing when it has no writes', async () => {
		axios.post.mockResolvedValue({ data: { trace: [], writes: {} } })
		const wrapper = mountPanel()
		await run(wrapper)
		expect(wrapper.find('.trialResult .muted').text()).toBe(
			'Nothing. This rule writes no value.',
		)
	})

	it('sends the pasted sample', async () => {
		axios.post.mockResolvedValue({ data: { trace: [] } })
		const wrapper = mountPanel()
		await wrapper.find('textarea').setValue('{"bedrag": 900}')
		await run(wrapper)
		expect(axios.post).toHaveBeenCalledWith(URL, { object: { bedrag: 900 } })
	})

	it('refuses a sample that is not JSON without sending it', async () => {
		const wrapper = mountPanel()
		await wrapper.find('textarea').setValue('{bad')
		await run(wrapper)
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('.trialField .refusal').text()).toMatch(
			/^That is not valid JSON: /,
		)
	})

	it('tries a stored object by register and id', async () => {
		axios.post.mockResolvedValue({ data: { trace: [] } })
		const wrapper = mountPanel()
		await wrapper.findAll('.radio')[1].trigger('click')
		expect(wrapper.find('textarea').exists()).toBe(false)
		await wrapper.find('#ruleTrialRegister').setValue('reg')
		await wrapper.find('#ruleTrialObject').setValue('obj-1')
		await run(wrapper)
		expect(axios.post).toHaveBeenCalledWith(URL, {
			register: 'reg',
			objectId: 'obj-1',
		})
	})

	it('runs a draft condition instead of the declared one', async () => {
		axios.post.mockResolvedValue({ data: { trace: [] } })
		const draft = { lit: true }
		const wrapper = mountPanel({ draft })
		expect(wrapper.find('.nc-button').text()).toBe('Run the draft')
		expect(wrapper.find('.draftNotice').exists()).toBe(true)
		await run(wrapper)
		expect(axios.post).toHaveBeenCalledWith(URL, {
			object: {},
			rule: { expression: draft },
		})
	})

	it('shows the refusal of an unsuccessful trial', async () => {
		axios.post.mockResolvedValueOnce({ data: { ok: false, error: { message: 'No such rule' } } })
		const wrapper = mountPanel()
		await run(wrapper)
		expect(wrapper.find('.refusal').text()).toBe('No such rule')
		expect(wrapper.find('.trialResult').exists()).toBe(false)
		axios.post.mockResolvedValueOnce({ data: { ok: false } })
		await run(wrapper)
		expect(wrapper.find('.refusal').text()).toBe('The rule could not be tried.')
	})

	it('shows why a failed trial failed and enables the button again', async () => {
		axios.post.mockRejectedValue(new Error('Network down'))
		const wrapper = mountPanel()
		await run(wrapper)
		expect(wrapper.find('.refusal').text()).toBe('Network down')
		expect(wrapper.find('.nc-button').attributes('disabled')).toBeUndefined()
	})

	it('drops the outcome when the rule or the draft changes', async () => {
		axios.post.mockResolvedValue({ data: { trace: [] } })
		const wrapper = mountPanel()
		await run(wrapper)
		await wrapper.setProps({ draft: { lit: 1 } })
		expect(wrapper.find('.trialResult').exists()).toBe(false)
		axios.post.mockRejectedValue(new Error('x'))
		await run(wrapper)
		await wrapper.setProps({ ruleId: 'other' })
		expect(wrapper.find('.refusal').exists()).toBe(false)
	})
})
