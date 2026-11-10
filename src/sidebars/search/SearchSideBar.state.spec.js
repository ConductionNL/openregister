/**
 * The search sidebar's starting state. The stores are live and circular, so
 * they are shared with the instance and kept out of the copied state.
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
jest.mock('../../store/store.js', () => {
	const circular = () => {
		const store = {}
		store.self = store
		return store
	}
	return {
		navigationStore: circular(),
		objectStore: circular(),
		registerStore: circular(),
		schemaStore: circular(),
		viewsStore: circular(),
	}
})

const stores = jest.requireMock('../../store/store.js')

describe('SearchSideBar state', () => {
	it('starts with the live stores, not copies', () => {
		const state = SearchSideBar.data()
		expect(state.objectStore).toBe(stores.objectStore)
		expect(state.navigationStore).toBe(stores.navigationStore)
		expect(state.registerStore).toBe(stores.registerStore)
		expect(state.schemaStore).toBe(stores.schemaStore)
		expect(state.viewsStore).toBe(stores.viewsStore)
	})

	it('gives every instance its own selection', () => {
		const first = SearchSideBar.data()
		first.selectedRegisters.push(1)
		expect(SearchSideBar.data().selectedRegisters).toEqual([])
	})
})
