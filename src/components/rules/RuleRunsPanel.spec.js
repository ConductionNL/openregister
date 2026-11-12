/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule run log: the filters it sends and the rows it shows.
 *
 * @spec exclude Unit test of the client-side rule run log panel.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import RuleRunsPanel from './RuleRunsPanel.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('vue-material-design-icons/History.vue', () => ({
	name: 'HistoryIcon',
	render: () => null,
}))
jest.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="empty">{{ name }}</div>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		render: () => null,
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['id', 'modelValue'],
		emits: ['update:modelValue'],
		render: () => null,
	},
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows'],
		template: `<table><tr v-for="row in rows" class="run">
			<td v-for="col in columns" :class="col.key"><slot :name="'column-' + col.key" :row="row" /></td>
		</tr></table>`,
	},
}))

const URL = '/index.php/apps/openregister/api/rules/deadline/runs'
const RUNS = [
	{
		ranAt: '2026-01-01 10:00',
		verdict: 'fired',
		objectUuid: 'o-1',
		operand: 'status',
		operandValue: 'open',
	},
	{ created: '2026-01-02 09:00', verdict: 'error', message: 'bad input' },
]

/**
 * Mount the run log for rule "deadline" and let it load.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountPanel() {
	const wrapper = mount(RuleRunsPanel, {
		props: {
			ruleId: 'deadline',
			verdicts: [{ verdict: 'fired' }, { verdict: 'error' }],
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The text of every cell of a column.
 *
 * @param {object} wrapper The wrapper
 * @param {string} key The column key
 * @return {Array<string>} The texts
 */
function column(wrapper, key) {
	return wrapper.findAll(`td.${key}`).map((td) => td.text())
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('RuleRunsPanel', () => {
	it('loads the runs of the rule without filters and lists them', async () => {
		axios.get.mockResolvedValue({ data: { runs: RUNS, total: 2 } })
		const wrapper = await mountPanel()
		expect(axios.get).toHaveBeenCalledWith(URL, { params: {} })
		expect(column(wrapper, 'ranAt')).toEqual(['2026-01-01 10:00', '2026-01-02 09:00'])
		expect(column(wrapper, 'objectUuid')).toEqual(['o-1', ''])
		expect(column(wrapper, 'operand')).toEqual(['statusread as open', 'bad input'])
		expect(wrapper.text()).not.toContain('Showing')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: 'fired', label: 'fired' },
			{ id: 'error', label: 'error' },
		])
	})

	it('says how many of the total are shown', async () => {
		axios.get.mockResolvedValue({ data: { runs: RUNS, total: 40 } })
		const wrapper = await mountPanel()
		expect(wrapper.text()).toContain('Showing 2 of 40.')
	})

	it('reloads with the verdict and dates chosen', async () => {
		axios.get.mockResolvedValue({ data: { runs: [] } })
		const wrapper = await mountPanel()
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', {
			id: 'fired',
			label: 'fired',
		})
		const [since, until] = wrapper.findAllComponents({ name: 'NcTextField' })
		since.vm.$emit('update:modelValue', '2026-01-01')
		until.vm.$emit('update:modelValue', '2026-02-01')
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(URL, {
			params: { verdict: 'fired', since: '2026-01-01', until: '2026-02-01' },
		})
	})

	it('shows the empty state when no run matches', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = await mountPanel()
		expect(wrapper.find('.empty').text()).toBe('No runs match')
		expect(wrapper.vm.total).toBe(0)
	})

	it('shows the spinner while loading', () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(RuleRunsPanel, { props: { ruleId: 'deadline' } })
		return wrapper.vm.$nextTick().then(() => {
			expect(wrapper.find('.loading').exists()).toBe(true)
		})
	})

	it('shows why the log could not be read and empties it', async () => {
		axios.get.mockResolvedValueOnce({ data: { runs: RUNS, total: 2 } })
		const wrapper = await mountPanel()
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'No access' } } })
		await wrapper.vm.load()
		expect(wrapper.find('.refusal').text()).toBe('No access')
		expect(wrapper.findAll('.run')).toHaveLength(0)
	})

	it('loads nothing without a rule, and reloads for another rule', async () => {
		axios.get.mockResolvedValue({ data: { runs: [] } })
		const wrapper = await mountPanel()
		await wrapper.setProps({ ruleId: '' })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(1)
		await wrapper.setProps({ ruleId: 'other' })
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(
			'/index.php/apps/openregister/api/rules/other/runs',
			{ params: {} },
		)
	})
})
