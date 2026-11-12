/**
 * Golden record of the file configuration section's OpenAnonymiser hint: the
 * text shown for each probe error code.
 *
 * @spec exclude Golden record of client-side wording.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import FileConfiguration from './FileConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
		patch: jest.fn(),
		delete: jest.fn(),
	},
}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn((app, text) => `[${text}]`),
	t: jest.fn((app, text) => `[${text}]`),
	n: jest.fn((app, one, many) => many),
}))

describe('file configuration hints golden', () => {
	it('words the OpenAnonymiser hint per probe error', () => {
		const hint = FileConfiguration.computed.openAnonymiserHint
		expect(
			[
				'appapi_missing',
				'exapp_not_installed',
				'exapp_disabled',
				'other',
				'',
				null,
				undefined,
				'constructor',
				'toString',
			].map((code) => [
				String(code),
				hint.call({ openAnonymiserError: code }),
			]),
		).toMatchSnapshot()
	})
})

const SETTINGS = [
	['none', null],
	['empty', {}],
	[
		'strings',
		{
			extractionScope: 'all',
			textExtractor: 'dolphin',
			extractionMode: 'immediate',
			entityRecognitionMethod: 'presidio',
			includeInSearch: false,
			maxFileSize: 5,
			chunkSize: 50,
			chunkOverlap: 0,
			chunkingStrategy: 'FIXED_SIZE',
			batchSize: 2,
			dolphinApiEndpoint: 'http://d',
			dolphinApiKey: 'k',
			entityRecognitionEnabled: true,
			presidioApiEndpoint: 'http://p',
			openAnonymiserApiEndpoint: 'http://o',
			openAnonymiserSource: 'external',
			openAnonymiserUsername: 'u',
			openAnonymiserPasswordSet: true,
			enabledFileTypes: ['pdf', 'docx'],
		},
	],
	[
		'objects and unknown ids',
		{
			extractionScope: { id: 'nope' },
			textExtractor: { id: 'nope' },
			extractionMode: { id: 'nope' },
			entityRecognitionMethod: { id: 'nope' },
			includeInSearch: null,
			openAnonymiserPasswordSet: 'yes',
			enabledFileTypes: [],
		},
	],
	[
		'known objects',
		{
			extractionScope: { id: 'all' },
			textExtractor: { id: 'dolphin' },
			extractionMode: { id: 'immediate' },
			entityRecognitionMethod: { id: 'presidio' },
		},
	],
	['rejected', 'reject'],
]

describe('file configuration settings load golden', () => {
	for (const [name, settings] of SETTINGS) {
		it(`loads ${name}`, async () => {
			const vm = FileConfiguration.data.call({ t: (app, text) => text })
			const logged = []
			jest.spyOn(console, 'error').mockImplementation((...parts) =>
				logged.push(parts.map(String)),
			)
			const calls = []
			Object.assign(vm, {
				settingsStore: {
					getFileSettings: async () => {
						if (settings === 'reject') {
							throw new Error('down')
						}
						return settings
					},
				},
				loadObjectSettings: async () => calls.push('loadObjectSettings'),
			})
			await FileConfiguration.methods.loadSettings.call(vm)
			console.error.mockRestore()
			expect({
				fileSettings: JSON.stringify(vm.fileSettings, null, 1),
				fileTypes: vm.fileTypes,
				calls,
				logged,
			}).toMatchSnapshot()
		})
	}
})
