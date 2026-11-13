/**
 * The schema rules tab mounted: the loading state, the inventory it hands the
 * table, the detail of a selected rule with its panels, the draft the
 * condition editor reports, and closing the detail again.
 *
 * @spec exclude Client-side rules tab markup and event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SchemaRulesTab from './SchemaRulesTab.vue'
import { fetchInventory, fetchOperators, fetchVocabulary } from '../../services/rules.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppContentDetails: mockStub('NcAppContentDetails', []),
	NcLoadingIcon: mockStub('NcLoadingIcon', []),
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return require('vue').h('button', { onClick: () => this.$emit('click') }, this.$slots.default())
		},
	},
}))
jest.mock('../../components/rules/RuleConditionEditor.vue', () => ({ __esModule: true, default: mockStub('RuleConditionEditor', ['rule', 'operators']) }))
jest.mock('../../components/rules/RuleInventoryTable.vue', () => ({ __esModule: true, default: mockStub('RuleInventoryTable', ['rules', 'kinds', 'selectedId']) }))
jest.mock('../../components/rules/RuleReplayPanel.vue', () => ({ __esModule: true, default: mockStub('RuleReplayPanel', ['schema', 'ruleId']) }))
jest.mock('../../components/rules/RuleRunsPanel.vue', () => ({ __esModule: true, default: mockStub('RuleRunsPanel', ['ruleId', 'verdicts']) }))
jest.mock('../../components/rules/RuleTrialPanel.vue', () => ({ __esModule: true, default: mockStub('RuleTrialPanel', ['schema', 'ruleId', 'draft']) }))
jest.mock('../../services/rules.js', () => ({
	__esModule: true,
	fetchInventory: jest.fn(),
	fetchOperators: jest.fn(),
	fetchVocabulary: jest.fn(),
	setRuleEnabled: jest.fn(),
	messageFor: (failure, fallback) => fallback,
}))

/**
 * A stand-in that renders its tag, the named props as JSON attributes and its
 * default slot.
 *
 * @param {string} name The component name
 * @param {Array<string>} props The props it shows
 * @return {object} The stand-in
 */
function mockStub(name, props) {
	return {
		name,
		props,
		render() {
			const attrs = Object.fromEntries(props.map((prop) => [`data-${prop.toLowerCase()}`, JSON.stringify(this[prop] ?? null)]))
			return require('vue').h(`x-${name}`, attrs, this.$slots.default ? this.$slots.default() : [])
		},
	}
}

const VOCABULARY = { kinds: ['validation', 'calculation'], verdicts: ['pass'], actions: ['block'] }
const RULES = [
	{ id: 'r1', key: 'age-check', label: 'Age check', kind: 'validation' },
	{ id: 'r2', key: 'total', kind: 'calculation' },
]

beforeEach(() => {
	jest.clearAllMocks()
	fetchVocabulary.mockResolvedValue(VOCABULARY)
	fetchInventory.mockResolvedValue({ rules: RULES })
	fetchOperators.mockResolvedValue(['eq'])
})

describe('SchemaRulesTab mounted', () => {
	it('shows the loading icon until the rules are read, then the inventory', async () => {
		const wrapper = mount(SchemaRulesTab, { props: { schema: { id: 3, slug: 'people' } } })

		expect(wrapper.find('x-NcLoadingIcon').exists()).toBe(true)
		expect(wrapper.find('x-RuleInventoryTable').exists()).toBe(false)

		await flushPromises()

		expect(fetchInventory).toHaveBeenCalledWith('people')
		expect(wrapper.find('x-NcLoadingIcon').exists()).toBe(false)
		expect(wrapper.find('h2').text()).toBe('Rules')
		const table = wrapper.find('x-RuleInventoryTable')
		expect(JSON.parse(table.attributes('data-rules'))).toEqual(RULES)
		expect(JSON.parse(table.attributes('data-selectedid'))).toBe('')
		expect(wrapper.find('.ruleDetail').exists()).toBe(false)
		expect(wrapper.find('.refusal').exists()).toBe(false)
	})

	it('shows why the rules could not be read', async () => {
		fetchInventory.mockRejectedValue(new Error('down'))
		const wrapper = mount(SchemaRulesTab, { props: { schema: { id: 3 } } })
		await flushPromises()

		expect(wrapper.find('.refusal').text()).toBe('The rules for this schema could not be read.')
		expect(JSON.parse(wrapper.find('x-RuleInventoryTable').attributes('data-rules'))).toEqual([])
	})

	it('opens a selected validation rule with its editor, trial and runs, but no replay', async () => {
		const wrapper = mount(SchemaRulesTab, { props: { schema: { id: 3, uuid: 'u-3' } } })
		await flushPromises()

		wrapper.findComponent({ name: 'RuleInventoryTable' }).vm.$emit('select', RULES[0])
		await flushPromises()

		expect(wrapper.find('.ruleDetailHeader h2').text()).toBe('Age check')
		expect(JSON.parse(wrapper.find('x-RuleInventoryTable').attributes('data-selectedid'))).toBe('r1')
		expect(JSON.parse(wrapper.find('x-RuleConditionEditor').attributes('data-operators'))).toEqual(['eq'])
		expect(JSON.parse(wrapper.find('x-RuleTrialPanel').attributes('data-schema'))).toBe('u-3')
		expect(JSON.parse(wrapper.find('x-RuleRunsPanel').attributes('data-ruleid'))).toBe('r1')
		expect(wrapper.find('x-RuleReplayPanel').exists()).toBe(false)
	})

	it('adds the replay panel for a calculation and titles it by key without a label', async () => {
		const wrapper = mount(SchemaRulesTab, { props: { schema: { id: 3 } } })
		await flushPromises()

		wrapper.findComponent({ name: 'RuleInventoryTable' }).vm.$emit('select', RULES[1])
		await flushPromises()

		expect(wrapper.find('.ruleDetailHeader h2').text()).toBe('total')
		expect(JSON.parse(wrapper.find('x-RuleReplayPanel').attributes('data-schema'))).toBe(3)
		expect(JSON.parse(wrapper.find('x-RuleReplayPanel').attributes('data-ruleid'))).toBe('r2')
	})

	it('hands the editor\'s draft to the trial panel and closes the detail', async () => {
		const wrapper = mount(SchemaRulesTab, { props: { schema: { id: 3 } } })
		await flushPromises()
		wrapper.findComponent({ name: 'RuleInventoryTable' }).vm.$emit('select', RULES[0])
		await flushPromises()

		wrapper.findComponent({ name: 'RuleConditionEditor' }).vm.$emit('update:draft', { when: 'age > 18' })
		await flushPromises()

		expect(JSON.parse(wrapper.find('x-RuleTrialPanel').attributes('data-draft'))).toEqual({ when: 'age > 18' })

		await wrapper.find('.ruleDetailHeader button').trigger('click')

		expect(wrapper.find('.ruleDetail').exists()).toBe(false)
		expect(JSON.parse(wrapper.find('x-RuleInventoryTable').attributes('data-selectedid'))).toBe('')
	})
})
