/**
 * The file configuration section's review gate setting (REQ-RRS-002, task
 * 2.4): the stored `anonymisation.requireReview` is taken over on load, sent
 * back on save, and left out of a save made before the load so the gate is
 * never switched off by accident. The note on the switch names the
 * unattended pipelines that wait once it is on.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-nothing-is-written-until-a-person-has-checked-it-req-rrs-002-carried-from-filinq-req-rwb-02
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

const { methods } = FileConfiguration

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

describe('FileConfiguration review gate setting', () => {
	it('takes over a stored gate that is on', async () => {
		const vm = section(storeWith({ anonymisation: { requireReview: true } }))

		await vm.loadSettings()

		expect(vm.fileSettings.requireReview).toBe(true)
	})

	it('reads a gate that was never set as off once loaded', async () => {
		const vm = section(storeWith({ anonymisation: { confidenceThreshold: 0.5 } }))

		await vm.loadSettings()

		expect(vm.fileSettings.requireReview).toBe(false)
	})

	it('sends the switch back on save', async () => {
		const store = storeWith({ anonymisation: { requireReview: false } })
		const vm = section(store)
		await vm.loadSettings()

		vm.fileSettings.requireReview = true
		await vm.saveSettings()

		expect(store.saveFileSettings).toHaveBeenCalledWith(
			expect.objectContaining({ anonymisation: { requireReview: true } }),
		)
	})

	it('leaves the gate out of a save made before the load', async () => {
		const store = storeWith({})
		const vm = section(store)

		await vm.saveSettings()

		expect(store.saveFileSettings.mock.calls[0][0]).not.toHaveProperty('anonymisation')
	})

	it('labels the switch and warns about unattended pipelines', () => {
		const template = FileConfiguration.template
			?? require('fs').readFileSync(require('path').join(__dirname, 'FileConfiguration.vue'), 'utf8')

		expect(template).toContain('data-testid="require-review-switch"')
		expect(template).toContain('Unattended pipelines, such as the Woo pipeline of OpenCatalogi')
	})
})
