/**
 * The configuration modal's form logic: each field writes the configuration
 * item (creating it when absent), linked entities are stored by numeric id,
 * an existing configuration fills the selections, and saving closes the
 * modal or shows the error. Methods run against a plain `this` and stub stores.
 *
 * @spec exclude Client-side configuration form handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditConfiguration from './EditConfiguration.vue'

jest.mock('../../store/store.js', () => ({
	applicationStore: { applicationList: [], refreshApplicationList: jest.fn() },
	configurationStore: { configurationItem: null, saveConfiguration: jest.fn() },
	navigationStore: { setModal: jest.fn() },
	organisationStore: { organisationList: [], refreshOrganisationList: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/CloudSync.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('../../components/shared/FormCheckboxField.vue', () => ({}))
jest.mock('../../components/shared/FormSelectField.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))

const { applicationStore, configurationStore, navigationStore, organisationStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = EditConfiguration

/**
 * The modal's initial state with computed getters and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function modal(state = {}) {
	const vm = EditConfiguration.data.call({})
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
	configurationStore.configurationItem = null
	applicationStore.applicationList = []
	organisationStore.organisationList = []
	global.fetch = jest.fn()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
	delete global.fetch
})

describe('EditConfiguration fields', () => {
	it.each([
		['updateTitle', 'T', 'title', 'T'],
		['updateDescription', 'D', 'description', 'D'],
		['updateVersion', '2.0.0', 'version', '2.0.0'],
		['updateApp', 'myapp', 'app', 'myapp'],
		['updateSourceUrl', 'https://x', 'sourceUrl', 'https://x'],
		['updateLocalVersion', '1.1.0', 'localVersion', '1.1.0'],
		['updateAutoUpdate', true, 'autoUpdate', true],
		['updateGithubRepo', 'org/repo', 'githubRepo', 'org/repo'],
		['updateGithubBranch', 'dev', 'githubBranch', 'dev'],
		['updateGithubPath', 'a/b.json', 'githubPath', 'a/b.json'],
	])('%s writes %s, creating the item when absent', (method, value, field, stored) => {
		modal()[method](value)
		expect(configurationStore.configurationItem[field]).toBe(stored)
	})

	it('stores the app as the empty string when cleared', () => {
		modal().updateApp(null)
		expect(configurationStore.configurationItem.app).toBe('')
	})

	it('stores the type and source type values with their defaults', () => {
		const vm = modal()
		vm.updateType({ value: 'manual' })
		expect(configurationStore.configurationItem.type).toBe('manual')
		expect(vm.selectedType).toEqual({ value: 'manual' })
		vm.updateType(null)
		expect(configurationStore.configurationItem.type).toBe('default')

		vm.updateSourceType({ value: 'github' })
		expect(configurationStore.configurationItem.sourceType).toBe('github')
		vm.updateSourceType(null)
		expect(configurationStore.configurationItem.sourceType).toBe('local')
	})

	it('stores the application by uuid', () => {
		const vm = modal()
		vm.updateApplication({ uuid: 'app-1' })
		expect(configurationStore.configurationItem.application).toBe('app-1')
		expect(vm.selectedApplication).toEqual({ uuid: 'app-1' })
		vm.updateApplication(null)
		expect(configurationStore.configurationItem.application).toBe('')
	})

	it.each([
		['updateRegisters', 'registers', 'selectedRegisters'],
		['updateSchemas', 'schemas', 'selectedSchemas'],
		['updateObjects', 'objects', 'selectedObjects'],
		['updateSources', 'sources', 'selectedSources'],
		['updateAgents', 'agents', 'selectedAgents'],
		['updateViews', 'views', 'selectedViews'],
		['updateManagedApplications', 'applications', 'selectedManagedApplications'],
	])('%s stores numeric ids and keeps the selection', (method, field, selected) => {
		const vm = modal()
		const value = [{ id: '3' }, { id: 7 }]
		vm[method](value)
		expect(configurationStore.configurationItem[field]).toEqual([3, 7])
		expect(vm[selected]).toBe(value)
	})

	it('stores the notification group values', () => {
		const vm = modal()
		vm.updateNotificationGroups([{ value: 'admin' }, { value: 'users' }])
		expect(configurationStore.configurationItem.notificationGroups).toEqual(['admin', 'users'])
	})

	it('is valid only with a non-blank title', () => {
		expect(modal().isValid).toBe(false)
		configurationStore.configurationItem = { title: '  ' }
		expect(modal().isValid).toBe(false)
		configurationStore.configurationItem = { title: 'Mine' }
		expect(modal().isValid).toBe(true)
	})

	it('offers the source types, types and notification groups', () => {
		const vm = modal()
		expect(vm.sourceTypeOptions.map((o) => o.value)).toEqual(['local', 'github', 'gitlab', 'url'])
		expect(vm.typeOptions[0]).toEqual({ value: 'default', label: 'Default', description: 'Standard configuration type' })
		expect(vm.notificationGroupOptions.map((o) => o.value)).toEqual(['admin', 'users'])
	})
})

describe('EditConfiguration opening', () => {
	it('starts a new local configuration and refreshes empty owner lists', async () => {
		const vm = modal({ searchAllEntities: jest.fn() })
		await EditConfiguration.created.call(vm)
		expect(organisationStore.refreshOrganisationList).toHaveBeenCalled()
		expect(applicationStore.refreshApplicationList).toHaveBeenCalled()
		expect(vm.searchAllEntities).toHaveBeenCalled()
		expect(configurationStore.configurationItem).toMatchObject({ title: '', type: 'default', version: '1.0.0', sourceType: 'local', isLocal: true, githubBranch: 'main' })
		expect(vm.selectedType.value).toBe('default')
		expect(vm.selectedSourceType.value).toBe('local')
	})

	it('loads the selections of an existing configuration', async () => {
		applicationStore.applicationList = [{ uuid: 'app-1', name: 'App' }]
		organisationStore.organisationList = [{ id: 1 }]
		configurationStore.configurationItem = { application: 'app-1', type: 'manual', sourceType: 'url', notificationGroups: ['users', 'ghost'] }
		const vm = modal({ searchAllEntities: jest.fn(), loadLinkedSelection: jest.fn() })
		await EditConfiguration.created.call(vm)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(organisationStore.refreshOrganisationList).not.toHaveBeenCalled()
		expect(vm.selectedApplication).toEqual({ uuid: 'app-1', name: 'App' })
		expect(vm.selectedType.value).toBe('manual')
		expect(vm.selectedSourceType.value).toBe('url')
		expect(vm.selectedNotificationGroups).toStrictEqual([{ value: 'users', label: 'All Users' }])
		expect(vm.loadLinkedSelection).toHaveBeenCalledTimes(7)
	})

	it('defaults an unknown type and drops an unknown source type', () => {
		const vm = modal()
		vm.loadSettingsSelections({ type: 'weird', sourceType: 'ftp', application: 'missing' })
		expect(vm.selectedType.value).toBe('default')
		expect(vm.selectedSourceType).toBeNull()
		expect(vm.selectedApplication).toBeNull()
	})

	it('fetches the linked entities by id', async () => {
		global.fetch.mockImplementation(async (url) => ({ json: async () => ({ url }) }))
		const vm = modal()
		await vm.loadLinkedSelection({ registers: [1, 2] }, { key: 'registers', target: 'selectedRegisters', loading: 'loadingRegisters' })
		expect(vm.selectedRegisters).toEqual([
			{ url: '/index.php/apps/openregister/api/registers/1' },
			{ url: '/index.php/apps/openregister/api/registers/2' },
		])
		expect(vm.loadingRegisters).toBe(false)
	})

	it('skips empty links and keeps going after a failed fetch', async () => {
		const vm = modal()
		await vm.loadLinkedSelection({ views: [] }, { key: 'views', target: 'selectedViews', loading: 'loadingViews' })
		expect(global.fetch).not.toHaveBeenCalled()

		global.fetch.mockRejectedValue(new Error('down'))
		await vm.loadLinkedSelection({ views: [1] }, { key: 'views', target: 'selectedViews', loading: 'loadingViews' })
		expect(vm.selectedViews).toEqual([])
		expect(vm.loadingViews).toBe(false)
		expect(consoleError).toHaveBeenCalled()
	})

	it('does nothing to load without a configuration item', async () => {
		const vm = modal({ loadSettingsSelections: jest.fn() })
		await vm.loadExistingSelections()
		expect(vm.loadSettingsSelections).not.toHaveBeenCalled()
	})

	it('searches every linkable entity', () => {
		const searches = ['searchRegisters', 'searchSchemas', 'searchSources', 'searchAgents', 'searchViews', 'searchApplications']
		const stubs = Object.fromEntries(searches.map((name) => [name, jest.fn()]))
		modal(stubs).searchAllEntities()
		searches.forEach((name) => expect(stubs[name]).toHaveBeenCalledWith(''))
	})
})

describe('EditConfiguration saving and closing', () => {
	it('saves the item and closes the modal', async () => {
		configurationStore.configurationItem = { title: 'T' }
		const vm = modal({ selectedRegisters: [{ id: 1 }] })
		await vm.saveConfiguration()
		expect(configurationStore.saveConfiguration).toHaveBeenCalledWith({ title: 'T' })
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm.selectedRegisters).toEqual([])
		expect(vm.loading).toBe(false)
	})

	it('keeps the modal open with the error on a failed save', async () => {
		configurationStore.saveConfiguration.mockRejectedValueOnce(new Error('Conflict'))
		const vm = modal()
		await vm.saveConfiguration()
		expect(vm.error).toBe('Conflict')
		expect(navigationStore.setModal).not.toHaveBeenCalled()

		configurationStore.saveConfiguration.mockRejectedValueOnce({})
		await vm.saveConfiguration()
		expect(vm.error).toBe('Failed to save configuration')
	})

	it('closes through the dialog and clears every selection', () => {
		const vm = modal({ selectedApplication: { uuid: 'a' }, selectedViews: [1], error: 'x' })
		vm.handleDialogClose()
		expect(vm.selectedApplication).toBeNull()
		expect(vm.selectedViews).toEqual([])
		expect(vm.error).toBeNull()
	})
})
