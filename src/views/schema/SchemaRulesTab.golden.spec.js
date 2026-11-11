import SchemaRulesTab from './SchemaRulesTab.vue'
import {
	fetchInventory,
	fetchOperators,
	fetchVocabulary,
	setRuleEnabled,
} from '../../services/rules.js'

/**
 * Golden record of the schema rules tab: the reads its load makes, the
 * vocabulary, rules and operators it keeps, the inventory it re-reads after a
 * switch, and the messages it shows when either fails.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../components/rules/RuleConditionEditor.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/rules/RuleInventoryTable.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/rules/RuleReplayPanel.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/rules/RuleRunsPanel.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/rules/RuleTrialPanel.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../services/rules.js', () => ({
	__esModule: true,
	fetchInventory: jest.fn(),
	fetchOperators: jest.fn(),
	fetchVocabulary: jest.fn(),
	setRuleEnabled: jest.fn(),
	messageFor: (failure, fallback) => failure?.message ? `${fallback} (${failure.message})` : fallback,
}))

const log = []

/**
 * A bare tab carrying the tab's methods.
 *
 * @param {object} state The tab data
 * @return {object} The tab
 */
function tabWith(state) {
	const tab = { schemaReference: 'reg/schema', ...state }
	for (const [name, method] of Object.entries(SchemaRulesTab.methods)) {
		tab[name] = method.bind(tab)
	}
	return tab
}

/**
 * The tab's data, without its methods.
 *
 * @param {object} tab The tab
 * @return {object} The data
 */
function dataOf(tab) {
	return Object.fromEntries(Object.entries(tab).filter(([, value]) => typeof value !== 'function'))
}

/**
 * A mock that records its call with the tab's flags, then answers.
 *
 * @param {string} name The call's name
 * @param {Function} answer The answer
 * @return {Function} The implementation
 */
function recorded(name, answer) {
	return (...args) => {
		log.push([name, ...args])
		return answer()
	}
}

beforeEach(() => {
	log.length = 0
	for (const mock of [fetchInventory, fetchOperators, fetchVocabulary, setRuleEnabled]) {
		mock.mockReset()
	}
})

describe('SchemaRulesTab.load', () => {
	const before = { loading: false, error: 'old', selected: { id: 1 }, draft: {}, rules: ['old'], vocabulary: null, operators: null }

	it.each([
		['a full answer', { kinds: ['k'], verdicts: ['v'], actions: ['a'] }, { rules: [{ id: 1 }] }],
		['empty answers', {}, {}],
	])('keeps %s', async (label, vocabulary, inventory) => {
		fetchVocabulary.mockImplementation(recorded('vocabulary', () => Promise.resolve(vocabulary)))
		fetchInventory.mockImplementation(recorded('inventory', () => Promise.resolve(inventory)))
		fetchOperators.mockImplementation(recorded('operators', () => Promise.resolve(['eq'])))
		const tab = tabWith(before)
		await tab.load()
		expect({ tab: dataOf(tab), log }).toMatchSnapshot()
	})

	it('shows why the rules could not be read and empties them', async () => {
		fetchVocabulary.mockResolvedValue({})
		fetchInventory.mockRejectedValue(new Error('403'))
		fetchOperators.mockResolvedValue([])
		const tab = tabWith(before)
		const loading = []
		fetchVocabulary.mockImplementation(() => {
			loading.push([tab.loading, tab.error, tab.selected, tab.draft])
			return Promise.resolve({})
		})
		await tab.load()
		expect({ tab: dataOf(tab), loading }).toMatchSnapshot()
	})
})

describe('SchemaRulesTab.toggle', () => {
	const rows = [{ id: 1, enabled: false }, { id: 2, enabled: true }]

	it.each([
		['with the switched rule open', { id: 1 }],
		['with another rule open that is gone', { id: 9 }],
		['with no rule open', null],
	])('re-reads the inventory %s', async (label, selected) => {
		setRuleEnabled.mockImplementation(recorded('switch', () => Promise.resolve()))
		fetchInventory.mockImplementation(recorded('inventory', () => Promise.resolve({ rules: rows })))
		const tab = tabWith({ switching: '', error: 'old', rules: [], selected })
		await tab.toggle({ id: 1 }, true)
		expect({ tab: dataOf(tab), log }).toMatchSnapshot()
	})

	it('keeps an empty inventory when the answer has no rules', async () => {
		setRuleEnabled.mockResolvedValue()
		fetchInventory.mockResolvedValue({})
		const tab = tabWith({ switching: '', error: '', rules: ['old'], selected: { id: 1 } })
		await tab.toggle({ id: 1 }, false)
		expect(dataOf(tab)).toMatchSnapshot()
	})

	it.each([
		['the switch', () => setRuleEnabled.mockRejectedValue(new Error('refused'))],
		['the re-read', () => {
			setRuleEnabled.mockResolvedValue()
			fetchInventory.mockRejectedValue(new Error('gone'))
		}],
	])('shows why %s failed', async (label, arrange) => {
		arrange()
		const tab = tabWith({ switching: '', error: '', rules: ['old'], selected: { id: 1 } })
		await tab.toggle({ id: 1 }, true)
		expect(dataOf(tab)).toMatchSnapshot()
	})
})
