/**
 * The file configuration section's jurisdiction pattern sets: the stored
 * codes are taken over on load, shown as options, sent back on save, and
 * left out of a save made before the load so the server keeps its default.
 *
 * @spec openspec/changes/detection-dutch-licence-plates
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import FileConfiguration from './FileConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `[${text}]` }))

const { computed, methods } = FileConfiguration

/**
 * A section instance with its methods and pattern set computeds bound to it and a
 * settings store whose every call is a jest mock.
 *
 * @param {object} store The store methods to provide
 * @return {object} The instance
 */
function section(store = {}) {
	const vm = { ...FileConfiguration.data(), settingsStore: store }
	for (const [name, fn] of Object.entries(methods)) vm[name] = fn.bind(vm)
	for (const name of ['entityPatternSetOptions', 'selectedPatternSets']) {
		Object.defineProperty(vm, name, { get: computed[name].bind(vm) })
	}
	return vm
}

/**
 * A store that loads the given file settings and saves whatever it is sent.
 *
 * @param {object} settings The stored file settings
 * @return {object} The store
 */
function storeWith(settings) {
	return {
		getFileSettings: jest.fn().mockResolvedValue(settings),
		getObjectSettings: jest.fn().mockResolvedValue({}),
		saveFileSettings: jest.fn().mockResolvedValue({}),
		getAnonymisationBackendState: jest.fn().mockResolvedValue(null),
	}
}

beforeEach(() => {
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	console.error.mockRestore()
})

describe('FileConfiguration jurisdiction pattern sets', () => {
	it('offers one option per known set, labelled', () => {
		expect(section().entityPatternSetOptions).toEqual([
			{ id: 'nl', label: '[Netherlands: licence plates and BSN]' },
		])
	})

	it('takes over the stored codes on load and shows them selected', async () => {
		const vm = section(storeWith({ entityPatternSets: ['nl', 'xx', 3] }))

		await vm.loadSettings()

		expect(vm.fileSettings.entityPatternSets).toEqual(['nl', 'xx'])
		expect(vm.selectedPatternSets).toEqual([
			{ id: 'nl', label: '[Netherlands: licence plates and BSN]' },
			{ id: 'xx', label: 'xx' },
		])
	})

	it('keeps nothing selected when the server sends no list', async () => {
		const vm = section(storeWith({ entityPatternSets: 'nl' }))

		await vm.loadSettings()

		expect(vm.fileSettings.entityPatternSets).toBeNull()
		expect(vm.selectedPatternSets).toEqual([])
	})

	it('sends the chosen codes when they change', async () => {
		const store = storeWith({ entityPatternSets: [] })
		const vm = section(store)
		await vm.loadSettings()

		await vm.savePatternSets([{ id: 'nl', label: 'NL' }])

		expect(store.saveFileSettings).toHaveBeenCalledTimes(1)
		expect(store.saveFileSettings.mock.calls[0][0].entityPatternSets).toEqual(['nl'])
	})

	it('sends an empty list when every set is cleared', async () => {
		const store = storeWith({ entityPatternSets: ['nl'] })
		const vm = section(store)
		await vm.loadSettings()

		await vm.savePatternSets(null)

		expect(store.saveFileSettings.mock.calls[0][0].entityPatternSets).toEqual([])
	})

	it('leaves the key out of a save made before the stored codes were loaded', async () => {
		const store = storeWith({})
		const vm = section(store)

		await vm.saveSettings()

		expect(store.saveFileSettings).toHaveBeenCalledTimes(1)
		expect('entityPatternSets' in store.saveFileSettings.mock.calls[0][0]).toBe(false)
	})
})
