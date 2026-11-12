/**
 * Golden record of what the search sidebar saves as a view and offers in its
 * register and schema pickers.
 *
 * @spec exclude Golden record of client-side saved-view plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SearchSideBar from './SearchSideBar.vue'
import { registerStore, schemaStore, viewsStore } from '../../store/store.js'

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
	objectStore: {},
	registerStore: { registerList: [] },
	schemaStore: { schemaList: [] },
	viewsStore: {
		activeView: null,
		createView: jest.fn(),
		updateView: jest.fn(),
		fetchViews: jest.fn(),
	},
}))

/**
 * A bare instance of the sidebar with a picked search.
 *
 * @return {object} The instance
 */
function sidebar() {
	const vm = {
		...SearchSideBar.methods,
		t: (app, text, vars) =>
			vars ? text.replace('{error}', vars.error) : text,
		selectedRegisters: [1],
		selectedSchemas: [11],
		searchTerms: ['alpha'],
		facetFilters: { status: ['open'] },
		enabledFacets: { status: true },
		activeViewName: '',
	}
	for (const [name, fn] of Object.entries(SearchSideBar.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	return vm
}

const CONFIGURATION = {
	registers: [1],
	schemas: [11],
	searchTerms: ['alpha'],
	facetFilters: { status: ['open'] },
	enabledFacets: { status: true },
}

describe('SearchSideBar saved views', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		globalThis.OC = { Notification: { showTemporary: jest.fn() } }
		jest.spyOn(console, 'error').mockImplementation(() => {})
		viewsStore.activeView = null
	})

	it('saves the query of the search as a new view', async () => {
		viewsStore.createView.mockResolvedValue({ id: 5, name: 'Mine' })
		const vm = sidebar()
		Object.assign(vm, {
			viewName: ' Mine ',
			viewDescription: 'd',
			viewIsPublic: true,
			viewIsDefault: true,
			showSaveForm: true,
		})

		await vm.saveView()

		expect(viewsStore.createView).toHaveBeenCalledWith({
			name: 'Mine',
			description: 'd',
			isPublic: true,
			isDefault: true,
			configuration: CONFIGURATION,
		})
		expect(viewsStore.fetchViews).toHaveBeenCalledTimes(1)
		expect(viewsStore.activeView).toEqual({ id: 5, name: 'Mine' })
		expect(vm.activeViewName).toBe('Mine')
		expect(vm.showSaveForm).toBe(false)
		expect(vm.viewIsPublic).toBe(false)
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith(
			'View saved successfully!',
		)
	})

	it('reports a view that could not be saved', async () => {
		viewsStore.createView.mockRejectedValue(new Error('nope'))
		const vm = sidebar()
		vm.viewName = 'Mine'

		await vm.saveView()

		expect(OC.Notification.showTemporary).toHaveBeenCalledWith(
			'Failed to save view: nope',
		)
	})

	it('saves nothing without a name', async () => {
		const vm = sidebar()
		vm.viewName = '  '

		await vm.saveView()

		expect(viewsStore.createView).not.toHaveBeenCalled()
	})

	it('writes the query of the search onto the active view', async () => {
		viewsStore.activeView = {
			uuid: 'u-1',
			name: 'Old',
			description: 'kept',
			isPublic: false,
			isDefault: true,
		}
		viewsStore.updateView.mockResolvedValue({ uuid: 'u-1', name: 'New' })
		const vm = sidebar()
		vm.activeViewName = ' New '

		await vm.updateActiveView()

		expect(viewsStore.updateView).toHaveBeenCalledWith('u-1', {
			name: 'New',
			description: 'kept',
			isPublic: false,
			isDefault: true,
			configuration: CONFIGURATION,
		})
		expect(vm.activeViewName).toBe('New')
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith(
			'View updated successfully!',
		)
	})

	it('offers the registers and the schemas of the picked registers', () => {
		registerStore.registerList = [
			{ id: 1, title: 'R1', description: 'r', schemas: [{ id: 11 }] },
			{ id: 2, title: 'R2', schemas: [{ id: 12 }] },
		]
		schemaStore.schemaList = [
			{ id: 11, title: 'S1', description: 's' },
			{ id: 12, title: 'S2' },
		]
		const vm = sidebar()

		for (const bag of [vm.registerOptions, vm.schemaOptions]) {
			expect(bag.label).toBe('title')
			expect(bag.reduce({ value: 3 })).toBe(3)
			expect(bag.getOptionLabel({ title: 'T', label: 'L' })).toBe('T')
			expect(bag.getOptionLabel({ label: 'L' })).toBe('L')
			expect(bag.getOptionLabel({})).toBe('')
		}
		expect(vm.registerOptions.options.map((o) => o.value)).toEqual([1, 2])
		expect(vm.registerOptions.options[0]).toMatchObject({
			label: 'R1',
			title: 'R1',
			description: 'r',
		})
		expect(vm.schemaOptions.options).toEqual([
			{
				value: 11,
				label: 'S1',
				title: 'S1',
				description: 's',
				schema: schemaStore.schemaList[0],
			},
		])
		vm.selectedRegisters = []
		expect(vm.schemaOptions).toEqual({ options: [] })
	})
})
