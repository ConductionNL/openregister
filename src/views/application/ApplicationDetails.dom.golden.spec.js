/**
 * Golden record of the application detail view: its header, details and the
 * configuration, register and schema cards, with and without linked items.
 *
 * @spec exclude Golden record of client-side view markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
const { normalise, stubModule } = require('../__mocks__/goldenDom.js')

const ICONS = [
	'AlertCircleOutline',
	'ApplicationOutline',
	'ArrowLeft',
	'Cog',
	'Database',
	'FileOutline',
	'InformationOutline',
	'OfficeBuilding',
	'Pencil',
	'TrashCanOutline',
]
ICONS.forEach((name) =>
	jest.mock(`vue-material-design-icons/${name}.vue`, () => stubModule(name)),
)
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => `t:${text}`,
	translate: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/vue', () => require('../__mocks__/goldenDom.js').library())
jest.mock('../../store/store.js', () => ({
	applicationStore: { applicationItem: null, loading: false, error: null },
	navigationStore: { setModal: () => {}, setDialog: () => {} },
}))

const ApplicationDetails = require('./ApplicationDetails.vue').default
const stores = jest.requireMock('../../store/store.js')

/**
 * The view rendered for an application.
 *
 * @param {object} item The application
 * @return {string} The markup
 */
function render(item) {
	stores.applicationStore.applicationItem = item
	const bindings = {
		...ApplicationDetails.components,
		...stores,
		t: (app, text) => `t:${text}`,
		n: (app, one) => `n:${one}`,
	}
	const wrapper = mount(
		{ ...ApplicationDetails, setup: () => bindings, mounted() {} },
		{ global: { config: { warnHandler: () => {} } } },
	)
	return normalise(wrapper)
}

const APPLICATION = {
	id: 3,
	name: 'Permits',
	description: 'Permit handling',
	version: '1.2.0',
	active: true,
	organisation: 'org-1',
	created: null,
	updated: null,
}

describe('application details', () => {
	it.each([
		[
			'with linked items',
			{
				...APPLICATION,
				configurations: [1, 2],
				registers: [1],
				schemas: [1, 2, 3],
			},
		],
		[
			'without linked items',
			{ ...APPLICATION, configurations: [], registers: [], schemas: [] },
		],
		['with linked items unset', { ...APPLICATION, active: false }],
	])('renders %s', (name, item) => {
		const html = render(item)
		expect(html).toContain('detailsCard')
		expect(html).toMatchSnapshot()
	})
})
