import OrganisationsIndex from './OrganisationsIndex.vue'
import { navigationStore, organisationStore } from '../../store/store.js'

/**
 * The organisations index's paging, row state, ownership rules, menu and the
 * modals and links it opens.
 *
 * @spec exclude Client-side behaviour of the organisations list page.
 */

jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnIndexPage: {} }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/AccountMultiplePlus.vue', () => ({ __esModule: true, default: { name: 'AccountMultiplePlus' } }))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Check.vue', () => ({ __esModule: true, default: { name: 'Check' } }))
jest.mock('vue-material-design-icons/OfficeBuilding.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({ __esModule: true, default: { name: 'OpenInNew' } }))
jest.mock('vue-material-design-icons/SwapHorizontal.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../modals/organisation/SwitchOrganisationModal.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../IndexRowDescription.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../services/AppInitializationService.js', () => ({ __esModule: true, reloadAppData: jest.fn() }))
jest.mock('../../services/menuActions.js', () => ({
	__esModule: true,
	deleteAction: (run, options) => ({ label: 'Delete', run, ...options }),
	editAction: (run, options) => ({ label: 'Edit', run, ...options }),
	viewAction: (run) => ({ label: 'View', run }),
}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn(), setTransferData: jest.fn() },
	organisationStore: {
		pagination: {},
		userStats: { total: 0, list: [], active: null },
		setOrganisationItem: jest.fn(),
		setPagination: jest.fn(),
		refreshOrganisationList: jest.fn(() => Promise.resolve()),
	},
}))

/**
 * A bare page state carrying the page's methods and computed getters.
 *
 * @param {object} data The page data
 * @return {object} The page
 */
function page(data = {}) {
	const state = { ...data }
	for (const [name, fn] of Object.entries(OrganisationsIndex.methods)) {
		state[name] = fn.bind(state)
	}
	for (const [name, getter] of Object.entries(OrganisationsIndex.computed)) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

const ORGS = Array.from({ length: 5 }, (_, i) => ({ uuid: `u${i}`, name: `Org ${i}` }))

beforeEach(() => {
	jest.clearAllMocks()
	organisationStore.pagination = {}
	organisationStore.userStats = { total: 0, list: [], active: null }
	window.OC = { getCurrentUser: () => ({ uid: 'alice' }) }
})

afterEach(() => {
	delete window.OC
})

describe('OrganisationsIndex paging', () => {
	it('derives the page count from the total and the page size', () => {
		organisationStore.pagination = { page: 2, limit: 2 }
		organisationStore.userStats = { total: 5, list: ORGS }
		expect(page().paginationData).toEqual({ page: 2, pages: 3, total: 5, limit: 2 })
	})

	it('defaults to page 1 of 20 with no organisations', () => {
		expect(page().paginationData).toEqual({ page: 1, pages: 0, total: 0, limit: 20 })
	})

	it('slices the current page out of the user\'s organisations', () => {
		organisationStore.pagination = { page: 3, limit: 2 }
		organisationStore.userStats = { total: 5, list: ORGS }
		expect(page().paginatedOrganisations).toEqual([ORGS[4]])
	})

	it('says none were found without organisations, and loading otherwise', () => {
		expect(page().emptyContentName).toBe('t:No organisations found')
		organisationStore.userStats.total = 2
		expect(page().emptyContentName).toBe('t:Loading organisations...')
	})

	it('starts its columns with name, members, owner and status', () => {
		expect(page().tableColumns.slice(0, 4).map((column) => column.key)).toEqual(['name', 'users', 'owner', 'status'])
		expect(page().tableColumns[0].sortable).toBe(true)
	})

	it('keeps the page size when changing page, and returns to page 1 with a new size', () => {
		organisationStore.pagination = { limit: 50 }
		page().onPageChanged(4)
		expect(organisationStore.setPagination).toHaveBeenCalledWith(4, 50)
		page().onPageSizeChanged(10)
		expect(organisationStore.setPagination).toHaveBeenLastCalledWith(1, 10)
	})

	it('keeps the selected ids', () => {
		const state = page({ selectedOrganisations: [] })
		state.onSelect(['u1', 'u2'])
		expect(state.selectedOrganisations).toEqual(['u1', 'u2'])
	})
})

describe('OrganisationsIndex rows and rights', () => {
	it('marks the active organisation\'s row', () => {
		organisationStore.userStats.active = { uuid: 'u1' }
		const state = page()
		expect(state.getRowClass({ uuid: 'u1' })).toBe('viewTableRow--active')
		expect(state.getRowClass({ uuid: 'u2' })).toBe('')
	})

	it('has no active row without an active organisation', () => {
		expect(page().isActiveOrganisation({ uuid: 'u1' })).toBeFalsy()
	})

	it('names the current Nextcloud user, or unknown without one', () => {
		expect(page().getCurrentUser()).toBe('alice')
		delete window.OC
		expect(page().getCurrentUser()).toBe('unknown')
	})

	it.each([
		[{ owner: 'alice' }, true],
		[{ owner: 'system' }, true],
		[{ owner: 'bob' }, false],
	])('lets the user edit %j: %s', (organisation, allowed) => {
		expect(page().canEditOrganisation(organisation)).toBe(allowed)
	})

	it.each([
		[{ owner: 'alice' }, true],
		[{ owner: 'alice', isDefault: true }, false],
		[{ owner: 'bob' }, false],
	])('lets the user delete %j: %s', (organisation, allowed) => {
		expect(page().canDeleteOrganisation(organisation)).toBe(allowed)
	})

	it.each([
		[2, { owner: 'bob' }, true],
		[1, { owner: 'bob' }, false],
		[2, { owner: 'bob', isDefault: true }, false],
		[2, { owner: 'alice' }, false],
	])('with %i organisations lets the user leave %j: %s', (total, organisation, allowed) => {
		organisationStore.userStats.total = total
		expect(page().canLeaveOrganisation(organisation)).toBe(allowed)
	})
})

describe('OrganisationsIndex menu and modals', () => {
	it('builds view, activate, edit, go-to, add-user and delete in order, hiding what does not apply', () => {
		organisationStore.userStats.active = { uuid: 'u1' }
		const actions = page().organisationActions({ uuid: 'u1', owner: 'bob' })
		expect(actions.map((action) => [action.label, Boolean(action.hidden)])).toEqual([
			['View', false],
			['t:Set as Active', true],
			['Edit', true],
			['t:Go to organisation', true],
			['t:Add User', false],
			['Delete', true],
		])
	})

	it('shows activate, edit, go-to and delete for an owned, inactive organisation with a website', () => {
		const actions = page().organisationActions({ uuid: 'u2', owner: 'alice', website: 'x.nl' })
		expect(actions.every((action) => !action.hidden)).toBe(true)
		expect(actions[1].icon).toEqual({ name: 'Check' })
		expect(actions[3].icon).toEqual({ name: 'OpenInNew' })
		expect(actions[4].icon).toEqual({ name: 'AccountMultiplePlus' })
	})

	it('opens the edit modal for a new and for an existing organisation', () => {
		page().createOrganisation()
		expect(organisationStore.setOrganisationItem).toHaveBeenCalledWith(null)
		expect(navigationStore.setModal).toHaveBeenCalledWith('editOrganisation')
		const actions = page().organisationActions({ uuid: 'u2', owner: 'alice' })
		actions[2].run()
		expect(organisationStore.setOrganisationItem).toHaveBeenLastCalledWith({ uuid: 'u2', owner: 'alice' })
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('editOrganisation')
	})

	it('opens the delete modal with the organisation', () => {
		page().organisationActions({ uuid: 'u2', owner: 'alice' })[5].run()
		expect(organisationStore.setOrganisationItem).toHaveBeenCalledWith({ uuid: 'u2', owner: 'alice' })
		expect(navigationStore.setModal).toHaveBeenCalledWith('deleteOrganisation')
	})

	it('opens the join modal with the organisation uuid', () => {
		page().organisationActions({ uuid: 'u3' })[4].run()
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({ organisationUuid: 'u3' })
		expect(navigationStore.setModal).toHaveBeenCalledWith('joinOrganisation')
	})

	it('opens the manage roles modal with the organisation', () => {
		page().openManageRolesModal({ uuid: 'u3' })
		expect(organisationStore.setOrganisationItem).toHaveBeenCalledWith({ uuid: 'u3' })
		expect(navigationStore.setModal).toHaveBeenCalledWith('manageOrganisationRoles')
	})

	it.each([
		['x.nl', 'https://x.nl'],
		['http://x.nl', 'http://x.nl'],
		['https://x.nl/a', 'https://x.nl/a'],
	])('opens the website %s as %s in a new tab', (website, url) => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		page().goToOrganisation({ website })
		expect(open).toHaveBeenCalledWith(url, '_blank')
		open.mockRestore()
	})

	it('opens nothing for an organisation without a website', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		page().goToOrganisation({})
		expect(open).not.toHaveBeenCalled()
		open.mockRestore()
	})

	it('opens the organisation\'s publication page from View', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		page().organisationActions({ id: 9 })[0].run()
		expect(open).toHaveBeenCalledWith(expect.stringMatching(/\/publicatie\/9$/), '_blank')
		open.mockRestore()
	})
})

describe('OrganisationsIndex refresh', () => {
	it('flags refreshing while the list reloads', async () => {
		const state = page({ isRefreshing: false })
		let during
		organisationStore.refreshOrganisationList.mockImplementation(() => {
			during = state.isRefreshing
			return Promise.resolve()
		})
		await state.handleRefresh()
		expect(during).toBe(true)
		expect(state.isRefreshing).toBe(false)
	})

	it('clears the flag when the reload fails', async () => {
		const state = page({ isRefreshing: false })
		organisationStore.refreshOrganisationList.mockImplementation(() => Promise.reject(new Error('down')))
		await expect(state.handleRefresh()).rejects.toThrow('down')
		expect(state.isRefreshing).toBe(false)
	})
})
