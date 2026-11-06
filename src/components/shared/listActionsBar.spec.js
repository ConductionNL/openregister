/**
 * The list bar's slots and entries, and the cards/table switch.
 *
 * @spec exclude Client-side presentational components.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import ListActionsBar from './ListActionsBar.vue'
import ViewModeSwitch from './ViewModeSwitch.vue'

jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars) => (vars ? `${text} ${vars.count}` : text),
	translate: (app, text) => text,
}))
jest.mock('@nextcloud/vue', () => ({
	NcActionButton: {
		name: 'NcActionButton',
		template: '<button><slot /></button>',
	},
	NcActions: {
		name: 'NcActions',
		props: ['inline', 'forceName', 'menuName', 'primary'],
		template: '<div><slot /></div>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		template: '<label><slot /></label>',
	},
}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))

describe('ListActionsBar', () => {
	it('leaves out an empty summary and a zero selection', () => {
		const wrapper = mount(ListActionsBar)
		expect(wrapper.find('.viewTotalCount').exists()).toBe(false)
		expect(wrapper.find('.viewIndicator').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcActions' }).exists()).toBe(false)
	})

	it('shows the summary, the selection and the slot before the entries', () => {
		const wrapper = mount(ListActionsBar, {
			props: {
				summary: 'Showing 2',
				selectedCount: 3,
				actions: [
					{ label: 'One', icon: { render: () => null }, run: () => {} },
					{ label: 'Also', icon: { render: () => null }, run: () => {} },
					{
						label: 'Two',
						icon: { render: () => null },
						run: () => {},
						hidden: true,
					},
				],
			},
			slots: { default: '<i class="extra" />' },
		})
		expect(wrapper.find('.viewTotalCount').text()).toBe('Showing 2')
		expect(wrapper.find('.viewIndicator').text()).toBe('({count} selected 3)')
		const actions = wrapper.find('.viewActions').element.children
		expect(actions[0].className).toBe('extra')
		const menu = wrapper.findComponent({ name: 'NcActions' })
		expect(menu.props()).toMatchObject({
			inline: 2,
			forceName: true,
			menuName: 'Actions',
		})
		expect(menu.text()).toBe('One Also')
	})
})

describe('ViewModeSwitch', () => {
	it('passes the mode on and reports a change from either button', () => {
		const wrapper = mount(ViewModeSwitch, {
			props: {
				modelValue: 'cards',
				cardsTitle: 'As cards',
				tableTitle: 'As table',
			},
		})
		const [cards, table] = wrapper.findAllComponents({
			name: 'NcCheckboxRadioSwitch',
		})
		expect(cards.attributes('title')).toBe('As cards')
		expect(table.attributes('title')).toBe('As table')
		cards.vm.$emit('update:modelValue', 'cards')
		table.vm.$emit('update:modelValue', 'table')
		expect(wrapper.emitted('update:modelValue')).toEqual([['cards'], ['table']])
	})
})
