import { flushPromises, mount } from '@vue/test-utils'
import TemplatesIndex from './TemplatesIndex.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the templates table: what it shows (header labels and
 * cell texts, independent of the markup that draws it), its row menu, and the
 * placeholder empty state.
 *
 * @spec exclude Golden record of the client-side templates table.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDataTable: jest.requireActual('../../../tests/unit/support/tableGolden.js').CnDataTableStub,
}))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../services/menuActions.js', () => ({ refreshAction: () => ({ label: 'Refresh' }) }))
jest.mock('../../services/displayDateTime.js', () => ({ displayDateTime: (value) => (value ? `date:${value}` : '-') }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({
	__esModule: true,
	default: {
		name: 'ActionMenu',
		props: ['actions'],
		render() {
			const { h } = jest.requireActual('vue')
			return h('menu', this.actions.map((a) => h('button', { class: 'menuItem' }, a.label)))
		},
	},
}))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/OffsetPager.vue', () => ({ __esModule: true, default: { render: () => null } }))

describe('TemplatesIndex table', () => {
	it('shows every template with its menu', async () => {
		const wrapper = mount(TemplatesIndex)
		await flushPromises()
		await wrapper.setData({
			templatesList: [
				{ id: 1, name: 'Letter', type: 'docx', description: 'A letter', updatedAt: '2026-11-01' },
				{ id: 2, name: 'Theme', type: 'css' },
			],
			totalTemplates: 2,
		})
		expect(tableText(wrapper)).toMatchSnapshot()
	})

	it('shows the placeholder without templates', async () => {
		const wrapper = mount(TemplatesIndex)
		await flushPromises()
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.find('[data-stub="empty"]').text()).toMatchSnapshot()
	})
})
