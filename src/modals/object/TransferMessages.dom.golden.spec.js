/**
 * Golden record of the whole markup of the transfer report's message
 * sections: warnings only, errors only, both, and neither.
 *
 * @spec exclude Golden record of client-side report sections.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import TransferMessages from './TransferMessages.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))

const CASES = {
	warningsOnly: { warnings: ['slow', 'deprecated'] },
	errorsOnly: { errors: ['boom'] },
	both: { warnings: ['careful'], errors: ['failed', 'stopped'] },
	neither: {},
	emptyArrays: { warnings: [], errors: [] },
}

describe('TransferMessages markup (golden)', () => {
	for (const [name, result] of Object.entries(CASES)) {
		it(`renders ${name}`, () => {
			const wrapper = mount(TransferMessages, { propsData: { result } })
			expect(wrapper.html()).toMatchSnapshot()
		})
	}

	it('lists every warning and every error as its own line', () => {
		const wrapper = mount(TransferMessages, {
			propsData: { result: { warnings: ['w1', 'w2'], errors: ['e1'] } },
		})
		const warnings = wrapper.findAll('.warning-text').map((w) => w.text())
		const errors = wrapper.findAll('.error-text').map((w) => w.text())
		expect(warnings).toEqual(['w1', 'w2'])
		expect(errors).toEqual(['e1'])
	})

	it('shows no section when the result carries neither', () => {
		const wrapper = mount(TransferMessages, { propsData: { result: {} } })
		expect(wrapper.findAll('.report-section')).toHaveLength(0)
	})
})
