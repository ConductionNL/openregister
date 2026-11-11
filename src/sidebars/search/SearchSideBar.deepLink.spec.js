/**
 * A /tables deep link applies its scope once the register and schema lists are
 * there, however long they take.
 *
 * @spec exclude Client-side component state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexSidebar: {} }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Filter.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Star.vue', () => ({}))
jest.mock('vue-material-design-icons/StarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => ({}))
jest.mock('../../modals/view/DeleteView.vue', () => ({}))
jest.mock('../../modals/view/EditView.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: { clearSearchCollection: jest.fn() },
	registerStore: { registerList: [] },
	schemaStore: { schemaList: [] },
	viewsStore: {},
}))

const { objectStore, registerStore, schemaStore } = jest.requireMock(
	'../../store/store.js',
)

/**
 * A sidebar instance on a route, as far as the route handling reads it.
 *
 * @param {string} path The route path.
 * @param {object} query The route query.
 * @return {object} The instance.
 */
function sidebar(path = '/tables', query = { register: '1', schema: '2' }) {
	return {
		...SearchSideBar.methods,
		...SearchSideBar.data(),
		$route: { path, query },
		performSearchWithFacets: jest.fn(),
	}
}

/**
 * Fire the watcher of a store path the way Vue does: through a name on the instance.
 *
 * @param {object} instance The sidebar.
 * @param {string} path The watched path.
 * @return {void}
 */
function fire(instance, path) {
	instance[SearchSideBar.watch[path]]()
}

describe('SearchSideBar deep link', () => {
	beforeEach(() => {
		registerStore.registerList = []
		schemaStore.schemaList = []
		objectStore.clearSearchCollection.mockClear()
	})

	it('watches stores the instance really has', () => {
		const state = SearchSideBar.data()
		for (const path of [
			'registerStore.registerList',
			'schemaStore.schemaList',
		]) {
			expect(SearchSideBar.watch[path]).toBeDefined()
			expect(state[path.split('.')[0]]).toBeDefined()
		}
	})

	it('searches once both lists have arrived, in either order', () => {
		for (const [first, second] of [
			['registerStore.registerList', 'schemaStore.schemaList'],
			['schemaStore.schemaList', 'registerStore.registerList'],
		]) {
			registerStore.registerList = []
			schemaStore.schemaList = []
			const instance = sidebar()
			instance.applyQueryParamsFromRoute()
			expect(instance.performSearchWithFacets).not.toHaveBeenCalled()

			const lists = {
				'registerStore.registerList': () => {
					registerStore.registerList = [{ id: 1 }]
				},
				'schemaStore.schemaList': () => {
					schemaStore.schemaList = [{ id: 2 }]
				},
			}
			lists[first]()
			fire(instance, first)
			expect(instance.performSearchWithFacets).not.toHaveBeenCalled()

			lists[second]()
			fire(instance, second)
			expect(instance.selectedRegisters).toEqual([1])
			expect(instance.selectedSchemas).toEqual([2])
			expect(instance.performSearchWithFacets).toHaveBeenCalledTimes(1)
		}
	})

	it('does not search again when a list refreshes after the scope is applied', () => {
		registerStore.registerList = [{ id: 1 }]
		schemaStore.schemaList = [{ id: 2 }]
		const instance = sidebar()
		instance.applyQueryParamsFromRoute()

		fire(instance, 'schemaStore.schemaList')

		expect(instance.performSearchWithFacets).toHaveBeenCalledTimes(1)
	})

	it('leaves other routes and scopeless links alone', () => {
		registerStore.registerList = [{ id: 1 }]
		schemaStore.schemaList = [{ id: 2 }]
		for (const instance of [sidebar('/registers'), sidebar('/tables', {})]) {
			fire(instance, 'registerStore.registerList')
			expect(instance.performSearchWithFacets).not.toHaveBeenCalled()
		}
	})

	it('empties the table when the link names ids nothing answers to', () => {
		registerStore.registerList = [{ id: 7 }]
		schemaStore.schemaList = [{ id: 8 }]
		const instance = sidebar()

		instance.applyQueryParamsFromRoute()

		expect(instance.performSearchWithFacets).not.toHaveBeenCalled()
		expect(objectStore.clearSearchCollection).toHaveBeenCalled()
	})
})
