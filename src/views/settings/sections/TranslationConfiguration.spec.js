/**
 * The Translation section shows the stored choice and the providers Nextcloud
 * reports, and never sends the confirmation unless Task Processing is chosen.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import TranslationConfiguration from './TranslationConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))

const URL = '/gen/apps/openregister/api/settings/translation'

/**
 * A section instance over plain state.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(state = {}) {
	const vm = { ...TranslationConfiguration.data(), ...state }
	for (const [name, method] of Object.entries(TranslationConfiguration.methods)) {
		vm[name] = method.bind(vm)
	}
	vm.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('TranslationConfiguration', () => {
	it('shows the stored choice and the providers with where they run', async () => {
		axios.get.mockResolvedValue({
			data: {
				provider: 'taskprocessing',
				allowExternal: true,
				providers: [
					{ taskType: 'core:text2text:translate', id: 'translate2:translate', name: 'Local translation', local: true },
					{ taskType: 'core:text2text', id: 'integration_openai-text2text', name: 'OpenAI', local: false },
				],
			},
		})
		const vm = section()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith(URL)
		expect(vm.useTaskProcessing).toBe(true)
		expect(vm.allowExternal).toBe(true)
		expect(vm.providers.map((provider) => vm.providerLine(provider))).toEqual([
			'Local translation runs on this server',
			'OpenAI runs outside this server',
		])
		expect(vm.loading).toBe(false)
	})

	it('starts off with nothing confirmed by default', async () => {
		axios.get.mockResolvedValue({ data: { provider: 'identity', allowExternal: false, providers: 'none' } })
		const vm = section({ useTaskProcessing: true, allowExternal: true })

		await vm.load()

		expect(vm.useTaskProcessing).toBe(false)
		expect(vm.allowExternal).toBe(false)
		expect(vm.providers).toEqual([])
	})

	it('saves Task Processing with the confirmation', async () => {
		axios.put.mockResolvedValue({ data: { provider: 'taskprocessing', allowExternal: true, providers: [] } })
		const vm = section({ useTaskProcessing: true, allowExternal: true })

		await vm.save()

		expect(axios.put).toHaveBeenCalledWith(URL, { provider: 'taskprocessing', allowExternal: true })
		expect(showSuccess).toHaveBeenCalledWith('Translation settings saved successfully')
		expect(vm.saving).toBe(false)
	})

	it('saving without the confirmation keeps translation_allow_external false', async () => {
		axios.put.mockResolvedValue({ data: { provider: 'taskprocessing', allowExternal: false, providers: [] } })
		const vm = section({ useTaskProcessing: true, allowExternal: false })

		await vm.save()

		expect(axios.put).toHaveBeenCalledWith(URL, { provider: 'taskprocessing', allowExternal: false })
		expect(vm.allowExternal).toBe(false)
	})

	it('never sends a confirmation with the identity provider', async () => {
		axios.put.mockResolvedValue({ data: { provider: 'identity', allowExternal: false, providers: [] } })
		const vm = section({ useTaskProcessing: false, allowExternal: true })

		await vm.save()

		expect(axios.put).toHaveBeenCalledWith(URL, { provider: 'identity', allowExternal: false })
		expect(vm.allowExternal).toBe(false)
	})

	it('reports a failed load and a failed save', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		axios.put.mockRejectedValue(new Error('offline'))
		const vm = section()

		await vm.load()
		await vm.save()

		expect(showError).toHaveBeenNthCalledWith(1, 'Failed to load settings: offline')
		expect(showError).toHaveBeenNthCalledWith(2, 'Failed to save settings')
		expect(vm.loading).toBe(false)
		expect(vm.saving).toBe(false)
	})
})
