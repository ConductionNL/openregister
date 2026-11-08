import { flushPromises, mount } from '@vue/test-utils'
import RuleConditionEditor from './RuleConditionEditor.vue'
import RuleInventoryTable from './RuleInventoryTable.vue'
import RuleRunsPanel from './RuleRunsPanel.vue'
import RuleTrialPanel from './RuleTrialPanel.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the rule tables: what each one shows (header labels and
 * cell texts, independent of the markup that draws them), its empty state,
 * and what its controls emit.
 *
 * @spec exclude Golden record of client-side rule tables.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
}))
jest.mock('@nextcloud/axios', () => ({}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('../../services/rules.js', () => ({
	...jest.requireActual('../../services/rules.js'),
	fetchRuns: jest.fn(),
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = jest.requireActual('vue')
	const { plainStub } = jest.requireActual('../../../tests/unit/support/tableGolden.js')
	return {
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue', 'disabled'],
			emits: ['update:modelValue'],
			render() {
				return h('label', { 'data-stub': 'switch', 'data-checked': String(this.modelValue), 'data-disabled': String(!!this.disabled) }, [
					h('input', { type: 'checkbox', onClick: () => this.$emit('update:modelValue', !this.modelValue) }),
					this.$slots.default?.(),
				])
			},
		},
		NcEmptyContent: {
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			render() {
				return h('div', { 'data-stub': 'empty' }, `${this.name} | ${this.description}`)
			},
		},
		NcLoadingIcon: plainStub('NcLoadingIcon'),
		NcSelect: plainStub('NcSelect'),
		NcTextField: plainStub('NcTextField'),
	}
})
jest.mock('@conduction/nextcloud-vue', () => {
	const { CnDataTableStub } = jest.requireActual('../../../tests/unit/support/tableGolden.js')
	// A cell without a slot as the library's formatValue draws it: text cut at
	// 100 characters plus an ellipsis, an empty value as a dash.
	const formatValue = (value) => {
		if (value === null || value === undefined || value === '') {
			return '—'
		}
		const text = String(value)
		return text.length > 100 ? `${text.substring(0, 100)}...` : text
	}
	return {
		__esModule: true,
		CnDataTable: {
			...CnDataTableStub,
			render() {
				const $slots = { ...this.$slots }
				for (const col of this.columns) {
					$slots[`column-${col.key}`] ||= ({ value }) => formatValue(value)
				}
				return CnDataTableStub.render.call(new Proxy(this, {
					get: (target, key) => (key === '$slots' ? $slots : target[key]),
				}))
			},
		},
	}
})
jest.mock('./RuleTraceView.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Gavel.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/History.vue', () => ({ __esModule: true, default: { render: () => null } }))

const LONG_TEXT = 'A long sentence that runs on. '.repeat(6).trim()

/**
 * The columns of a table that opt in to wrapping.
 *
 * @param {object} wrapper The mounted panel
 * @return {Array<string>} Their keys
 */
function wrappingColumns(wrapper) {
	return wrapper.findComponent({ name: 'CnDataTable' }).props('columns')
		.filter((column) => column.cellClass === 'cn-cell--wrap')
		.map((column) => column.key)
}

const RULES = [
	{ id: 'r1', key: 'total', label: 'Total', order: 1, kind: 'calculation', source: 'x-openregister-calculations', lastRun: '2026-11-01', enabled: true },
	{ id: 'r2', key: 'block', order: 2, kind: 'state-block', source: 'x-state', idle: true, lastError: 'Boom', enabled: false },
	{ id: 'r3', key: 'odd', order: 3, kind: 'unknown-kind', source: 'x-other', enabled: true },
]

describe('RuleInventoryTable', () => {
	it('shows every rule in pipeline order', () => {
		const wrapper = mount(RuleInventoryTable, {
			props: { rules: RULES, kinds: [{ kind: 'calculation' }], selectedId: 'r2', switching: 'r3' },
		})
		expect(tableText(wrapper)).toMatchSnapshot()
		const switches = wrapper.findAll('[data-stub="switch"]')
		expect(switches.map((s) => [s.attributes('data-checked'), s.attributes('data-disabled')])).toEqual([
			['true', 'false'],
			['false', 'false'],
			['true', 'true'],
		])
	})

	it('lets the rule, source and last run cells wrap', () => {
		const wrapper = mount(RuleInventoryTable, { props: { rules: RULES } })
		expect(wrappingColumns(wrapper)).toEqual(['label', 'source', 'lastRun'])
	})

	it('shows the empty state without rules', () => {
		const wrapper = mount(RuleInventoryTable, { props: { rules: [] } })
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.find('[data-stub="empty"]').text()).toMatchSnapshot()
	})

	it('emits select and toggle with the row', async () => {
		const wrapper = mount(RuleInventoryTable, { props: { rules: RULES } })
		await wrapper.findAll('button')[1].trigger('click')
		await wrapper.findAll('input')[0].trigger('click')
		expect(wrapper.emitted('select')).toEqual([[RULES[1]]])
		expect(wrapper.emitted('toggle')).toEqual([[RULES[0], false]])
	})
})

describe('RuleRunsPanel', () => {
	const { fetchRuns } = jest.requireMock('../../services/rules.js')

	it('shows the runs the log returns', async () => {
		fetchRuns.mockResolvedValue({
			runs: [
				{ id: 1, ranAt: '2026-11-01 10:00', verdict: 'pass', objectUuid: 'u-1', operand: 'amount', operandValue: '12' },
				{ id: 2, created: '2026-11-02', verdict: 'fail', message: 'Too low' },
				{ id: 3, verdict: 'skip' },
			],
			total: 9,
		})
		const wrapper = mount(RuleRunsPanel, { props: { ruleId: 'r1' } })
		await flushPromises()
		expect(tableText(wrapper)).toMatchSnapshot()
		expect(wrapper.text()).toContain('t:Showing {shown} of {total}.{"shown":3,"total":9}')
	})

	it('lets the deciding operand wrap', async () => {
		fetchRuns.mockResolvedValue({ runs: [{ id: 1, verdict: 'pass' }], total: 1 })
		const wrapper = mount(RuleRunsPanel, { props: { ruleId: 'r1' } })
		await flushPromises()
		expect(wrappingColumns(wrapper)).toEqual(['operand'])
	})

	it('shows the empty state without runs', async () => {
		fetchRuns.mockResolvedValue({ runs: [], total: 0 })
		const wrapper = mount(RuleRunsPanel, { props: { ruleId: 'r1' } })
		await flushPromises()
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.find('[data-stub="empty"]').text()).toMatchSnapshot()
	})
})

describe('RuleTrialPanel', () => {
	it('shows what the rule would have written', async () => {
		const wrapper = mount(RuleTrialPanel, { props: { ruleId: 'r1' } })
		await wrapper.setData({ result: { trace: [], writes: { total: 12, label: 'x', nested: { a: 1 } } } })
		expect(tableText(wrapper)).toMatchSnapshot()
	})

	it('lets the property and written value wrap', async () => {
		const wrapper = mount(RuleTrialPanel, { props: { ruleId: 'r1' } })
		await wrapper.setData({ result: { trace: [], writes: { total: 12 } } })
		expect(wrappingColumns(wrapper)).toEqual(['id', 'value'])
	})

	it('says nothing is written without writes', async () => {
		const wrapper = mount(RuleTrialPanel, { props: { ruleId: 'r1' } })
		await wrapper.setData({ result: { trace: [], writes: {} } })
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.text()).toContain('t:Nothing. This rule writes no value.')
	})
})

describe('RuleConditionEditor', () => {
	it('lists the operators per category', () => {
		const wrapper = mount(RuleConditionEditor, {
			props: {
				rule: { id: 'r1', kind: 'calculation', condition: { '>': [{ var: 'a' }, 1] } },
				operators: [
					{ op: '>', arity: '2', category: 'Comparison', description: 'Greater than' },
					{ op: 'var', arity: '1', category: 'Data', description: 'Reads a field' },
					{ op: '==', arity: '2', category: 'Comparison', description: 'Equals' },
				],
			},
		})
		expect(tableText(wrapper)).toMatchSnapshot()
	})

	it('keeps a long operator description whole and lets it wrap', () => {
		const wrapper = mount(RuleConditionEditor, {
			props: {
				rule: { id: 'r1', kind: 'calculation', condition: {} },
				operators: [{ op: 'map', arity: '2', category: 'Data', description: LONG_TEXT }],
			},
		})
		expect(wrapper.findAll('td').map((cell) => cell.text())).toContain(LONG_TEXT)
		expect(wrappingColumns(wrapper)).toEqual(['description'])
	})
})
