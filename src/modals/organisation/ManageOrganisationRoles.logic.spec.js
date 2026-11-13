/**
 * The organisation roles modal: the groups still on offer, change detection,
 * reading the organisation's roles, loading Nextcloud groups, adding and
 * removing roles, and saving them. Methods run against a plain `this` with
 * stub stores and HTTP.
 *
 * @spec exclude Client-side organisation role selection.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ManageOrganisationRoles from './ManageOrganisationRoles.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AccountGroup.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	organisationStore: { organisationItem: null, saveOrganisation: jest.fn() },
}))

const { navigationStore, organisationStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = ManageOrganisationRoles

/**
 * A plain modal instance with computed getters and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function modal(state = {}) {
	const vm = { ...ManageOrganisationRoles.data(), t: (app, s) => s }
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in state)) vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
	organisationStore.organisationItem = null
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	consoleError.mockRestore()
})

describe('ManageOrganisationRoles', () => {
	it('offers only the groups not yet chosen, labelled by name', () => {
		const vm = modal({ availableGroups: [{ id: 'a', name: 'A' }, { id: 'b', name: 'B' }], selectedRoles: [{ id: 'a' }] })
		expect(vm.availableGroupOptions).toEqual([{ id: 'b', name: 'B', label: 'B' }])
	})

	it('sees a change in the chosen roles regardless of order', () => {
		const vm = modal({ originalRoles: [{ id: 'a' }, { id: 'b' }], selectedRoles: [{ id: 'b' }, { id: 'a' }] })
		expect(vm.hasChanges).toBe(false)
		vm.selectedRoles = [{ id: 'a' }]
		expect(vm.hasChanges).toBe(true)
	})

	it('reads the roles of the organisation being edited', () => {
		organisationStore.organisationItem = { uuid: 'o-1', name: 'Org', roles: [{ id: 'admin' }] }
		const vm = modal()
		vm.initializeOrganisationItem()
		expect(vm.organisationItem.name).toBe('Org')
		expect(vm.selectedRoles).toEqual([{ id: 'admin' }])
		expect(vm.originalRoles).toEqual([{ id: 'admin' }])
		expect(vm.selectedRoles).not.toBe(organisationStore.organisationItem.roles)

		organisationStore.organisationItem = { uuid: 'o-2', roles: null }
		vm.initializeOrganisationItem()
		expect(vm.selectedRoles).toEqual([])
	})

	it('keeps the blank organisation without a uuid', () => {
		organisationStore.organisationItem = { name: 'No uuid' }
		const vm = modal()
		vm.initializeOrganisationItem()
		expect(vm.organisationItem).toEqual({ name: '', roles: [] })
	})

	it('loads the Nextcloud groups as options', async () => {
		axios.get.mockResolvedValueOnce({ data: { ocs: { data: { groups: ['admin', 'staff'] } } } })
		const vm = modal()
		await vm.loadNextcloudGroups()
		expect(axios.get).toHaveBeenCalledWith('/ocs/v1.php/cloud/groups?format=json', { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } })
		expect(vm.availableGroups).toEqual([{ id: 'admin', name: 'admin', userCount: 0 }, { id: 'staff', name: 'staff', userCount: 0 }])
		expect(vm.loadingGroups).toBe(false)
	})

	it('reports a failed group load', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const vm = modal()
		await vm.loadNextcloudGroups()
		expect(vm.error).toBe('Failed to load Nextcloud groups')
		expect(vm.loadingGroups).toBe(false)
	})

	it('adds a role once and removes it again', () => {
		const vm = modal({ roleToAdd: { id: 'staff' } })
		vm.addRole({ id: 'staff', name: 'Staff', userCount: 3, extra: true })
		vm.addRole({ id: 'staff', name: 'Staff' })
		vm.addRole(null)
		expect(vm.selectedRoles).toEqual([{ id: 'staff', name: 'Staff', userCount: 3 }])
		expect(vm.roleToAdd).toBeNull()
		vm.removeRole({ id: 'staff' })
		expect(vm.selectedRoles).toEqual([])
	})

	it('saves the roles on the organisation and closes after a delay', async () => {
		const vm = modal({ organisationItem: { uuid: 'o-1', name: 'Org', roles: [] }, selectedRoles: [{ id: 'a' }] })
		await vm.saveRoles()
		expect(organisationStore.saveOrganisation).toHaveBeenCalledWith({ uuid: 'o-1', name: 'Org', roles: [{ id: 'a' }] })
		expect(vm.success).toBe(true)
		expect(vm.originalRoles).toEqual([{ id: 'a' }])
		expect(vm.loading).toBe(false)
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('shows the error of a failed save', async () => {
		organisationStore.saveOrganisation.mockRejectedValueOnce(new Error('Forbidden'))
		const vm = modal()
		await vm.saveRoles()
		expect(vm.error).toBe('Forbidden')
		organisationStore.saveOrganisation.mockRejectedValueOnce({})
		await vm.saveRoles()
		expect(vm.error).toBe('Failed to save roles')
	})

	it('closes through the dialog and resets the notices', () => {
		const vm = modal({ success: true, error: 'x' })
		vm.handleDialogClose()
		expect(vm.success).toBe(false)
		expect(vm.error).toBeNull()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})
