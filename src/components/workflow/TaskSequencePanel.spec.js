/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The read-only view of a schema's declared approval chains.
 *
 * @spec exclude Unit test of the client-side approval chain panel.
 */
import { mount } from '@vue/test-utils'
import TaskSequencePanel from './TaskSequencePanel.vue'

jest.mock('@nextcloud/vue', () => ({
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="empty">{{ name }}</div>',
	},
}))
jest.mock('vue-material-design-icons/CheckDecagramOutline.vue', () => ({
	name: 'CheckDecagramOutline',
	render: () => null,
}))

/**
 * Mount the panel for a schema with the given chains.
 *
 * @param {object} chains The approval chains
 * @return {object} The wrapper
 */
function mountPanel(chains) {
	return mount(TaskSequencePanel, {
		props: {
			schema: { configuration: { 'x-openregister-approval-chains': chains } },
		},
		global: {
			mocks: {
				t: (app, text, vars = {}) =>
					text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
			},
		},
	})
}

/**
 * The texts of the list items under a class.
 *
 * @param {object} wrapper The wrapper
 * @param {string} cls The list class
 * @return {Array<string>} The texts, whitespace collapsed
 */
function lines(wrapper, cls) {
	return wrapper.findAll(`.${cls} li`).map((l) => l.text().replace(/\s+/g, ' '))
}

describe('TaskSequencePanel', () => {
	it('shows the empty state without chains', () => {
		const wrapper = mountPanel(undefined)
		expect(wrapper.find('.empty').text()).toBe('No approval chains declared')
	})

	it('describes a full chain and its approvers in order', () => {
		const wrapper = mountPanel({
			payout: {
				transition: 'approve',
				amountField: 'bedrag',
				onApprove: 'advanceTransition',
				approvers: [{ role: 'manager' }, { role: 'cfo', minAmount: 10000 }],
			},
		})
		expect(wrapper.find('h4').text()).toBe('payout')
		expect(lines(wrapper, 'task-sequence-panel__meta')).toEqual([
			'Gated transition: approve',
			'Tier routing on field: bedrag',
			'The requester may not decide their own approval.',
			'The transition is applied automatically once every step approves.',
		])
		expect(lines(wrapper, 'task-sequence-panel__steps')).toEqual([
			'Role manager',
			'Role cfo from amount 10000',
		])
	})

	it('shows the gaps of a sparse chain and skips non-objects', () => {
		const wrapper = mountPanel({
			sparse: { separationOfDuties: false, approvers: [{}] },
			broken: 'x',
			bad: { approvers: 'nope' },
		})
		expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['sparse', 'bad'])
		expect(lines(wrapper, 'task-sequence-panel__meta')).toEqual([
			'Gated transition: ?',
			'Gated transition: ?',
			'The requester may not decide their own approval.',
		])
		expect(lines(wrapper, 'task-sequence-panel__steps')).toEqual(['Role ?'])
	})
})
