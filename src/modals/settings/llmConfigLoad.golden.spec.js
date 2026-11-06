/**
 * Golden record of the LLM configuration modal's settings load: the form
 * state it fills in, the follow-up model fetch and the error it shows, for
 * every shape of stored LLM settings.
 *
 * @spec exclude Golden record of client-side form hydration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import LLMConfigModal from './LLMConfigModal.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), patch: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TestTube.vue', () => ({}))

const ANSWERS = [
	['empty', { data: {} }],
	[
		'openai',
		{
			data: {
				enabled: true,
				embeddingProvider: 'openai',
				chatProvider: 'openai',
				openaiConfig: {
					apiKey: 'k',
					model: 'm',
					chatModel: 'c',
					organizationId: 'o',
				},
				enabledFeatures: ['text_generation'],
			},
		},
	],
	[
		'ollama',
		{
			data: {
				embeddingProvider: 'ollama',
				chatProvider: 'nope',
				ollamaConfig: { model: 'm' },
				enabledFeatures: 'all',
			},
		},
	],
	[
		'ollama chat without url',
		{ data: { chatProvider: 'ollama', ollamaConfig: { url: '' } } },
	],
	[
		'fireworks known models',
		{
			data: {
				chatProvider: 'fireworks',
				fireworksConfig: {
					apiKey: 'f',
					embeddingModel: 'nomic-ai/nomic-embed-text-v1.5',
					chatModel: 'accounts/fireworks/models/llama-v3p1-8b-instruct',
					baseUrl: '',
				},
			},
		},
	],
	[
		'fireworks unknown models',
		{ data: { fireworksConfig: { embeddingModel: 'x', chatModel: 'y' } } },
	],
	[
		'empty configs',
		{ data: { openaiConfig: {}, ollamaConfig: {}, fireworksConfig: {} } },
	],
	[
		'ollama embedding, no stored url',
		{ data: { embeddingProvider: 'ollama' } },
		{ ollamaUrl: '' },
	],
	['rejected', 'reject'],
]

describe('LLM configuration load golden', () => {
	for (const [name, answer, seed] of ANSWERS) {
		it(`loads ${name}`, async () => {
			jest.clearAllMocks()
			axios.get.mockImplementation(async () => {
				if (answer === 'reject') {
					throw new Error('down')
				}
				return JSON.parse(JSON.stringify(answer))
			})
			const t = (app, text) => `[${text}]`
			const vm = LLMConfigModal.data.call({ t })
			const calls = []
			const logged = []
			jest.spyOn(console, 'error').mockImplementation((...parts) =>
				logged.push(parts.map(String)),
			)
			Object.assign(vm, LLMConfigModal.methods, {
				t,
				fetchOllamaModels: () => calls.push('fetchOllamaModels'),
			})
			if (seed) {
				vm.ollamaConfig.url = seed.ollamaUrl
			}
			const promise = LLMConfigModal.methods.loadConfiguration.call(vm)
			const loadingWhile = vm.loading
			await promise
			console.error.mockRestore()
			expect({
				loadingWhile,
				loadingAfter: vm.loading,
				state: JSON.stringify(
					{
						llmEnabled: vm.llmEnabled,
						selectedEmbeddingProvider: vm.selectedEmbeddingProvider,
						selectedChatProvider: vm.selectedChatProvider,
						openaiConfig: vm.openaiConfig,
						ollamaConfig: vm.ollamaConfig,
						fireworksConfig: vm.fireworksConfig,
						aiFeatures: vm.aiFeatures,
					},
					null,
					1,
				),
				calls,
				requests: axios.get.mock.calls,
				shownErrors: showError.mock.calls,
				logged,
			}).toMatchSnapshot()
		})
	}
})
