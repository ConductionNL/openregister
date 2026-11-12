/**
 * Golden record of the applications list's status cells: the card's status
 * line and the table's active column, for an active and an inactive row.
 *
 * @spec exclude Golden record of client-side list markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'

jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnIndexPage: {
		name: 'CnIndexPage',
		props: ['objects'],
		render() {
			return require('vue').h(
				'div',
				{ class: 'index' },
				this.objects.flatMap((object) => [
					require('vue').h('div', { class: 'card' }, this.$slots.card({ object })),
					require('vue').h('div', { class: 'cell' }, this.$slots['column-active']({ row: object })),
				]),
			)
		},
	},
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => `t:${text}`,
	translate: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/vue', () => require('../__mocks__/goldenDom.js').library())
jest.mock('vue-material-design-icons/ApplicationOutline.vue', () => require('../__mocks__/goldenDom.js').stubModule('ApplicationOutline'))
jest.mock('../../components/shared/ActionMenu.vue', () => require('../__mocks__/goldenDom.js').stubModule('ActionMenu'))
jest.mock('../../services/menuActions.js', () => ({ deleteAction: () => ({}), editAction: () => ({}) }))
jest.mock('../../store/store.js', () => ({
	applicationStore: { applicationList: [], loading: false, viewMode: 'cards', pagination: { page: 1, limit: 20 } },
	navigationStore: { setModal: () => {}, setDialog: () => {} },
}))

const { normalise } = require('../__mocks__/goldenDom.js')
const ApplicationsIndex = require('./ApplicationsIndex.vue').default
const stores = jest.requireMock('../../store/store.js')

describe('applications list status', () => {
	it('renders the card and column status of an active and an inactive row', () => {
		stores.applicationStore.applicationList = [
			{ id: 1, name: 'On', active: true },
			{ id: 2, name: 'Off', active: false },
		]
		const bindings = {
			...ApplicationsIndex.components,
			...stores,
			t: (app, text) => `t:${text}`,
			listDateTime: (value) => value,
		}
		const wrapper = mount(
			{ ...ApplicationsIndex, setup: () => bindings, mounted() {} },
			{ global: { config: { warnHandler: () => {} } } },
		)
		expect(normalise(wrapper)).toMatchSnapshot()
	})
})
