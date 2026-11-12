import { flushPromises, mount } from '@vue/test-utils'
import EntitiesIndex from './EntitiesIndex.vue'
import EntityDetail from './EntityDetail.vue'

/**
 * Golden record of the entity statistics tables: the card table on the
 * entities index (cards view) and the information table on the entity
 * detail page, as markup with the scoped-style attributes stripped.
 *
 * @spec exclude Golden record of client-side entity statistics markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('../../services/displayDateTime.js', () => ({
	__esModule: true,
	displayDateTime: (value) => `date:${value}`,
}))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => mockIcon('AccountOutline'))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => mockIcon('EyeOutline'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockIcon('Refresh'))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => mockIcon('AlertCircleOutline'))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockIcon('ArrowLeft'))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => mockIcon('DatabaseOutline'))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => mockIcon('FileDocumentOutline'))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => mockIcon('LinkVariantOff'))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => mockIcon('TextBoxOutline'))
jest.mock('../../components/PaginationComponent.vue', () => mockIcon('PaginationComponent'))
jest.mock('../../components/shared/ActionMenu.vue', () => mockIcon('ActionMenu'))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => mockIcon('FilterToggleHeader'))
jest.mock('../../components/shared/ListActionsBar.vue', () => mockIcon('ListActionsBar'))
jest.mock('../../components/shared/ViewModeSwitch.vue', () => mockIcon('ViewModeSwitch'))
jest.mock('../../services/menuActions.js', () => ({ __esModule: true, refreshAction: () => ({}) }))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { on: () => {}, off: () => {}, emit: () => {} } }))
jest.mock('../../store/store.js', () => ({ navigationStore: { sidebarState: {} } }))

/**
 * A stand-in icon module.
 *
 * @param {string} name The icon
 * @return {object} The module
 */
function mockIcon(name) {
	return { __esModule: true, default: { name, render: () => null } }
}

const ENTITIES = [
	{ id: 1, value: 'Jan', type: 'person', category: 'pii', detectedAt: '2026-01-02', confidence: 0.875, relationCount: 3, source: 'ocr' },
	{ id: 2, value: 'Acme', type: 'org', category: 'business', detectedAt: null, confidence: 0, relationCount: 0 },
]

/**
 * The statistics tables of a wrapper, without scoped-style attributes.
 *
 * @param {object} wrapper The mounted view
 * @return {string[]} One HTML string per table
 */
function statsTables(wrapper) {
	return wrapper.findAll('table.statisticsTable')
		.map((table) => table.html().replace(/ data-v-[0-9a-f]+=""/g, '').replace(/\s*<!--v-if-->/g, ''))
}

describe('entity statistics tables', () => {
	beforeEach(() => {
		jest.requireMock('@nextcloud/axios').default.get.mockReset()
	})

	it('draws a card table per entity on the index cards view', async () => {
		jest.requireMock('@nextcloud/axios').default.get.mockResolvedValue({
			data: { success: true, data: ENTITIES, count: 2 },
		})
		const wrapper = mount(EntitiesIndex, {
			global: { mocks: { $router: { push: jest.fn() } } },
		})
		await flushPromises()
		wrapper.vm.viewMode = 'cards'
		await flushPromises()
		const tables = statsTables(wrapper)
		expect(tables).toHaveLength(2)
		expect(tables).toMatchSnapshot()
	})

	it.each([
		['with a source', ENTITIES[0]],
		['without a source or confidence', ENTITIES[1]],
	])('draws the detail information table %s', async (name, entity) => {
		jest.requireMock('@nextcloud/axios').default.get.mockResolvedValue({
			data: { success: true, data: entity, relations: [] },
		})
		const wrapper = mount(EntityDetail, {
			global: { mocks: { $route: { params: { id: entity.id } }, $router: { push: jest.fn() } } },
		})
		await flushPromises()
		const tables = statsTables(wrapper)
		expect(tables).toHaveLength(1)
		expect(tables).toMatchSnapshot()
	})
})
