/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule condition editor: what it says about a rule and how it judges a
 * drafted condition.
 *
 * @spec exclude Unit test of the client-side rule condition editor.
 */
import { mount } from '@vue/test-utils'
import RuleConditionEditor from './RuleConditionEditor.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>',
	},
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'rowKey'],
		render: () => null,
	},
}))

const OPERATORS = [
	{ op: 'and', arity: 'n', description: 'All', category: 'Logic' },
	{ op: 'eq', arity: '2', description: 'Equal', category: 'Compare' },
	{ op: 'var', arity: '1', description: 'Read', category: 'Logic' },
	{ op: 'lit', arity: '1', description: 'Literal', category: 'Values' },
]
const RULE = {
	kind: 'calculation',
	source: 'x-openregister-rules.deadline',
	actions: ['set', 'notify'],
	maxObjects: 500,
	condition: { eq: [{ var: 'status' }, { lit: 'open' }] },
}

/**
 * Mount the editor.
 *
 * @param {object} rule The rule
 * @return {object} The wrapper
 */
function mountEditor(rule = RULE) {
	return mount(RuleConditionEditor, {
		props: {
			rule,
			kinds: [{ kind: 'calculation', description: 'Computes a value.' }],
			actions: [{ action: 'set', description: 'Writes a field.' }],
			operators: OPERATORS,
		},
	})
}

/**
 * Type a draft into the textarea.
 *
 * @param {object} wrapper The wrapper
 * @param {string} text The draft
 * @return {Promise<void>}
 */
async function type(wrapper, text) {
	await wrapper.find('textarea').setValue(text)
}

describe('RuleConditionEditor facts', () => {
	it('describes the kind, source, actions and ceiling', () => {
		const wrapper = mountEditor()
		const dds = wrapper.findAll('dd').map((d) => d.text())
		expect(dds[0]).toBe('calculation Computes a value.')
		expect(dds[1]).toBe('x-openregister-rules.deadline')
		expect(wrapper.findAll('.actionList li').map((l) => l.text())).toEqual([
			'setWrites a field.',
			'notify',
		])
		expect(dds[3]).toBe('500')
	})

	it('says the bulk ceiling applies when the rule declares none', () => {
		const wrapper = mountEditor({ ...RULE, kind: 'other', maxObjects: 0 })
		expect(wrapper.findAll('dd')[0].find('.muted').exists()).toBe(false)
		expect(wrapper.findAll('dd')[3].text()).toBe(
			'None declared. The instance-wide bulk job ceiling applies.',
		)
	})

	it('groups the operator reference by category, in catalogue order', () => {
		const wrapper = mountEditor()
		expect(wrapper.findAll('.operatorCategory h4').map((h) => h.text())).toEqual([
			'Logic',
			'Compare',
			'Values',
		])
		const tables = wrapper.findAllComponents({ name: 'CnDataTable' })
		expect(tables[0].props('rows').map((r) => r.op)).toEqual(['and', 'var'])
		expect(tables[0].props('columns').map((c) => c.key)).toEqual([
			'op',
			'arity',
			'description',
		])
	})
})

describe('RuleConditionEditor draft', () => {
	it('starts from the declared condition and emits that there is no draft', () => {
		const wrapper = mountEditor()
		expect(wrapper.find('textarea').element.value).toBe(
			JSON.stringify(RULE.condition, null, 2),
		)
		expect(wrapper.emitted('update:draft')).toEqual([[null]])
		expect(wrapper.find('.nc-button').exists()).toBe(false)
	})

	it('starts empty for a rule without a condition', () => {
		const wrapper = mountEditor({ ...RULE, condition: null })
		expect(wrapper.find('textarea').element.value).toBe('')
	})

	it('emits a changed, valid draft and accepts it', async () => {
		const wrapper = mountEditor()
		const draft = { and: [{ eq: [{ var: 'a' }, { lit: 1 }] }, true] }
		await type(wrapper, JSON.stringify(draft))
		expect(wrapper.emitted('update:draft').at(-1)).toEqual([draft])
		expect(wrapper.find('.accepted').exists()).toBe(true)
		expect(wrapper.find('.refusal').exists()).toBe(false)
	})

	it('accepts a JSONLogic condition without judging its operators', async () => {
		const wrapper = mountEditor()
		const draft = { '==': [{ var: 'a' }, 1] }
		await type(wrapper, JSON.stringify(draft))
		expect(wrapper.emitted('update:draft').at(-1)).toEqual([draft])
	})

	it('accepts an AST node with more than one key as it stands', async () => {
		const wrapper = mountEditor()
		await type(wrapper, '[{"eq": 1, "and": 2}, 3]')
		expect(wrapper.emitted('update:draft').at(-1)).toEqual([
			[{ eq: 1, and: 2 }, 3],
		])
	})

	it('emits no draft for the declared condition retyped', async () => {
		const wrapper = mountEditor()
		await type(wrapper, JSON.stringify(RULE.condition, null, 2) + '\n')
		expect(wrapper.emitted('update:draft').at(-1)).toEqual([null])
		expect(wrapper.find('.accepted').exists()).toBe(false)
	})

	it('refuses invalid JSON, naming the reason', async () => {
		const wrapper = mountEditor()
		await type(wrapper, '{nope')
		expect(wrapper.find('.refusal').text()).toMatch(/^That is not valid JSON: /)
		expect(wrapper.emitted('update:draft').at(-1)).toEqual([null])
	})

	it('emits no draft for an emptied condition', async () => {
		const wrapper = mountEditor()
		await type(wrapper, '   ')
		expect(wrapper.emitted('update:draft').at(-1)).toEqual([null])
		expect(wrapper.find('.refusal').exists()).toBe(false)
	})

	it('goes back to the declared condition', async () => {
		const wrapper = mountEditor()
		await type(wrapper, '{nope')
		await wrapper.find('.nc-button').trigger('click')
		expect(wrapper.find('textarea').element.value).toBe(
			JSON.stringify(RULE.condition, null, 2),
		)
		expect(wrapper.find('.refusal').exists()).toBe(false)
		expect(wrapper.emitted('update:draft').at(-1)).toEqual([null])
	})

	it('resets when another rule is shown', async () => {
		const wrapper = mountEditor()
		await type(wrapper, '{}')
		await wrapper.setProps({ rule: { ...RULE, condition: { lit: true } } })
		expect(wrapper.find('textarea').element.value).toBe(
			JSON.stringify({ lit: true }, null, 2),
		)
	})
})
