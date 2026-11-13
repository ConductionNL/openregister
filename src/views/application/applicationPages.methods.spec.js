import ApplicationDetails from './ApplicationDetails.vue'
import ApplicationsIndex from './ApplicationsIndex.vue'
import { applicationStore, navigationStore } from '../../store/store.js'

/**
 * The applications index's paging, empty state, menu and refresh, and the
 * application detail page's linked-item cards.
 *
 * @spec exclude Client-side behaviour of the application list and detail pages.
 */

jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnIndexPage: {} }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../services/listDateTime.js', () => ({ __esModule: true, listDateTime: () => '' }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../IndexRowDescription.vue', () => ({ __esModule: true, default: {} }))
jest.mock('./ActiveStatus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Cog.vue', () => ({ __esModule: true, default: { name: 'Cog' } }))
jest.mock('vue-material-design-icons/Database.vue', () => ({ __esModule: true, default: { name: 'Database' } }))
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({ __esModule: true, default: { name: 'FileOutline' } }))
jest.mock('../../services/menuActions.js', () => ({
	__esModule: true,
	deleteAction: (run) => ({ label: 'Delete', run }),
	editAction: (run) => ({ label: 'Edit', run }),
}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	applicationStore: {
		pagination: {},
		applicationList: [],
		applicationItem: {},
		error: null,
		setApplicationItem: jest.fn(),
		setPagination: jest.fn(),
		refreshApplicationList: jest.fn(() => Promise.resolve()),
	},
}))

/**
 * A bare page state carrying the component's methods and computed getters.
 *
 * @param {object} component The page component
 * @param {object} data The page data
 * @return {object} The page
 */
function page(component, data = {}) {
	const state = { t: (app, text) => `this.t:${text}`, ...data }
	for (const [name, fn] of Object.entries(component.methods)) {
		state[name] = fn.bind(state)
	}
	for (const [name, getter] of Object.entries(component.computed)) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

const APPS = Array.from({ length: 5 }, (_, i) => ({ id: i + 1 }))

beforeEach(() => {
	jest.clearAllMocks()
	applicationStore.pagination = {}
	applicationStore.applicationList = []
	applicationStore.error = null
})

describe('ApplicationsIndex', () => {
	it('pages the application list from the store', () => {
		applicationStore.pagination = { page: 2, limit: 2 }
		applicationStore.applicationList = APPS
		const state = page(ApplicationsIndex)
		expect(state.paginationData).toEqual({ page: 2, pages: 3, total: 5, limit: 2 })
		expect(state.paginatedApplications).toEqual([{ id: 3 }, { id: 4 }])
	})

	it('defaults to page 1 of 20', () => {
		expect(page(ApplicationsIndex).paginationData).toEqual({ page: 1, pages: 0, total: 0, limit: 20 })
	})

	it('shows the store error first, then empty, then loading', () => {
		applicationStore.error = 'Boom'
		expect(page(ApplicationsIndex).emptyContentName).toBe('Boom')
		applicationStore.error = null
		expect(page(ApplicationsIndex).emptyContentName).toBe('t:No applications found')
		applicationStore.applicationList = APPS
		expect(page(ApplicationsIndex).emptyContentName).toBe('t:Loading applications...')
	})

	it('lists its columns in order, with name and created sortable', () => {
		const columns = page(ApplicationsIndex).tableColumns
		expect(columns.map((column) => column.key)).toEqual(['name', 'version', 'active', 'configurations', 'registers', 'schemas', 'created'])
		expect(columns.filter((column) => column.sortable).map((column) => column.key)).toEqual(['name', 'created'])
	})

	it('opens the edit modal and the delete dialog for the application', () => {
		const [edit, remove] = page(ApplicationsIndex).applicationActions({ id: 7 })
		edit.run()
		expect(applicationStore.setApplicationItem).toHaveBeenCalledWith({ id: 7 })
		expect(navigationStore.setModal).toHaveBeenCalledWith('editApplication')
		remove.run()
		expect(applicationStore.setApplicationItem).toHaveBeenCalledTimes(2)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteApplication')
	})

	it('keeps the page size when changing page, and returns to page 1 with a new size', () => {
		applicationStore.pagination = { limit: 30 }
		page(ApplicationsIndex).onPageChanged(2)
		expect(applicationStore.setPagination).toHaveBeenCalledWith(2, 30)
		page(ApplicationsIndex).onPageSizeChanged(5)
		expect(applicationStore.setPagination).toHaveBeenLastCalledWith(1, 5)
	})

	it('keeps the selected ids', () => {
		const state = page(ApplicationsIndex, { selectedApplications: [] })
		state.onSelect([1, 2])
		expect(state.selectedApplications).toEqual([1, 2])
	})

	it('flags refreshing while the list reloads, and clears it after a failure', async () => {
		const state = page(ApplicationsIndex, { isRefreshing: false })
		let during
		applicationStore.refreshApplicationList.mockImplementationOnce(() => {
			during = state.isRefreshing
			return Promise.resolve()
		})
		await state.handleRefresh()
		expect(during).toBe(true)
		expect(state.isRefreshing).toBe(false)
		applicationStore.refreshApplicationList.mockImplementationOnce(() => Promise.reject(new Error('down')))
		await expect(state.handleRefresh()).rejects.toThrow('down')
		expect(state.isRefreshing).toBe(false)
	})
})

describe('ApplicationDetails linked-item cards', () => {
	it('builds the configurations, registers and schemas cards from the application', () => {
		applicationStore.applicationItem = { configurations: [1], registers: [2, 3], schemas: [] }
		expect(page(ApplicationDetails).linkedItemCards).toEqual([
			{
				key: 'configurations',
				icon: { name: 'Cog' },
				title: 'this.t:Configurations',
				unit: 'this.t:configuration(s)',
				empty: 'this.t:No configurations found for this application.',
				items: [1],
			},
			{
				key: 'registers',
				icon: { name: 'Database' },
				title: 'this.t:Registers',
				unit: 'this.t:register(s)',
				empty: 'this.t:No registers found for this application.',
				items: [2, 3],
			},
			{
				key: 'schemas',
				icon: { name: 'FileOutline' },
				title: 'this.t:Schemas',
				unit: 'this.t:schema(s)',
				empty: 'this.t:No schemas found for this application.',
				items: [],
			},
		])
	})

	it('passes missing lists through as undefined', () => {
		applicationStore.applicationItem = {}
		expect(page(ApplicationDetails).linkedItemCards.map((card) => card.items)).toEqual([undefined, undefined, undefined])
	})
})
