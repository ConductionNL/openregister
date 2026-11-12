import { mount } from '@vue/test-utils'
import RangePager from '../../views/quality/RangePager.vue'
/**
 * Golden record of the two offset pagers: their markup on a middle, first
 * and last page, hidden on a single page, and the events their buttons emit.
 *
 * @spec exclude Golden record of client-side pager markup and events.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import OffsetPager from './OffsetPager.vue'

jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
}))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: { type: Boolean, default: false } },
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}))

const pagers = { OffsetPager, RangePager }

describe.each(Object.keys(pagers))('%s', (key) => {
	const component = pagers[key]

	it.each([
		['middle', { offset: 20, limit: 20, total: 45 }],
		['first', { offset: 0, limit: 20, total: 45 }],
		['last', { offset: 40, limit: 20, total: 45 }],
		['single', { offset: 0, limit: 20, total: 20 }],
	])('renders the %s page', (name, props) => {
		expect(mount(component, { props }).html()).toMatchSnapshot()
	})

	it('emits previous and next from its buttons', async () => {
		const wrapper = mount(component, { props: { offset: 20, limit: 20, total: 45 } })
		const buttons = wrapper.findAll('button')
		await buttons[0].trigger('click')
		await buttons[1].trigger('click')
		expect(wrapper.emitted('previous')).toHaveLength(1)
		expect(wrapper.emitted('next')).toHaveLength(1)
	})
})
