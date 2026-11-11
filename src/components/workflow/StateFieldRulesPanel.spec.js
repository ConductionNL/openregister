/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The read-only view of a schema's per-status field rules.
 *
 * @spec exclude Unit test of the client-side field rules panel.
 */
import { mount } from '@vue/test-utils'
import StateFieldRulesPanel from './StateFieldRulesPanel.vue'

jest.mock('@nextcloud/vue', () => ({
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="empty">{{ name }}</div>',
	},
}))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () => ({
	name: 'FormatListChecks',
	render: () => null,
}))

/**
 * Mount the panel for a schema with the given states.
 *
 * @param {object} states The lifecycle states
 * @return {object} The wrapper
 */
function mountPanel(states) {
	return mount(StateFieldRulesPanel, {
		props: {
			schema: { configuration: { 'x-openregister-lifecycle': { states } } },
		},
		global: {
			mocks: {
				t: (app, text, vars = {}) =>
					text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
			},
		},
	})
}

describe('StateFieldRulesPanel', () => {
	it('shows the empty state for a schema without states', () => {
		const wrapper = mount(StateFieldRulesPanel, {
			props: { schema: {} },
			global: { mocks: { t: (app, text) => text } },
		})
		expect(wrapper.find('.empty').text()).toBe('No field rules declared')
	})

	it('lists each rule with its kind, fields and scope', () => {
		const wrapper = mountPanel({
			open: {
				fields: {
					hidden: [{ fields: ['iban', 'bsn'], groups: ['admin', 'audit'] }],
					readOnly: [{ field: 'amount', groups: 'finance', when: { x: 1 } }],
					required: [{ fields: 'reason', condition: { y: 1 } }],
				},
			},
		})
		const rules = wrapper.findAll('.state-field-rules-panel__rules li')
		expect(rules.map((r) => r.text().replace(/\s+/g, ' '))).toEqual([
			'Hidden iban, bsn for admin, audit',
			'Read only amount for financewhen its condition holds',
			'Required reason for everyonewhen its condition holds',
		])
		expect(wrapper.find('h4').text()).toBe('open')
	})

	it('shows the entry, exit and condition of a status and that it is off', () => {
		const wrapper = mountPanel({
			closed: { enabled: false, entry: { a: 1 }, exit: { b: 1 }, condition: { c: 1 } },
		})
		expect(wrapper.find('.state-field-rules-panel__off').text()).toBe('switched off')
		expect(wrapper.findAll('.state-field-rules-panel__meta li')).toHaveLength(3)
		expect(wrapper.find('.state-field-rules-panel__rules').exists()).toBe(false)
	})

	it('leaves out statuses and rules that declare nothing usable', () => {
		const wrapper = mountPanel({
			bare: {},
			junk: 'x',
			odd: {
				fields: {
					hidden: 'not-a-list',
					readOnly: [null, { groups: ['g'] }, { fields: [] }],
				},
			},
			nofields: { fields: 'x', entry: { a: 1 } },
		})
		expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['nofields'])
		expect(wrapper.find('.state-field-rules-panel__off').exists()).toBe(false)
	})
})

describe('StateFieldRulesPanel rules (golden)', () => {
	it('reads every spelling of a rule, in kind and declaration order', () => {
		const rules = StateFieldRulesPanel.methods.rulesOf({
			required: [{ field: 'b', groups: 'g1' }, { fields: [] }],
			hidden: [
				{ fields: ['x', 'y'], groups: ['a', 'b'], when: { eq: 1 } },
				null,
				'not a rule',
				{ field: 'z' },
				{ fields: 'w', condition: 'c' },
			],
			readOnly: 'not a list',
			extra: [{ field: 'ignored' }],
		})
		expect(JSON.stringify(rules)).toBe(
			JSON.stringify([
				{ id: 'hidden-0', kind: 'hidden', fields: ['x', 'y'], groups: ['a', 'b'], conditional: true },
				{ id: 'hidden-3', kind: 'hidden', fields: ['z'], groups: [], conditional: false },
				{ id: 'hidden-4', kind: 'hidden', fields: ['w'], groups: [], conditional: true },
				{ id: 'required-0', kind: 'required', fields: ['b'], groups: ['g1'], conditional: false },
			]),
		)
	})

	it.each([null, undefined, 'x', 3])('reads no rules from %p', (fields) => {
		expect(StateFieldRulesPanel.methods.rulesOf(fields)).toEqual([])
	})
})
