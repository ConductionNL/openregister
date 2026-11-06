/**
 * Golden record of when the LLM connection tests can run, which Ollama answers
 * replace the fixed model list, and what a refused clear shows.
 *
 * @spec exclude Golden record of client-side LLM form checks.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import LLMConfigModal from './LLMConfigModal.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TestTube.vue', () => ({}))

/**
 * A modal context with the modal's methods on it.
 *
 * @param {object} state The state over the initial state
 * @return {object} The context
 */
function modal(state) {
	return {
		...LLMConfigModal.data.call({}),
		...LLMConfigModal.methods,
		t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		$emit: jest.fn(),
		...state,
	}
}

const FORMS = {
	openai: ['openaiConfig', 'apiKey', 'model', 'chatModel'],
	fireworks: ['fireworksConfig', 'apiKey', 'embeddingModel', 'chatModel'],
	ollama: ['ollamaConfig', 'url', 'model', 'chatModel'],
}

describe('LLM connection test readiness', () => {
	describe.each(Object.keys(FORMS))('%s', (id) => {
		const [form, access, embeddingModel, chatModel] = FORMS[id]
		it.each([
			['', '', false],
			['k', '', false],
			['', 'm', false],
			['k', 'm', true],
		])('access %j, model %j', (key, model, ready) => {
			const fields = { [access]: key }
			const embedding = modal({
				selectedEmbeddingProvider: { id },
				[form]: { [embeddingModel]: model, ...fields },
			})
			const chat = modal({
				selectedChatProvider: { id },
				[form]: { [chatModel]: model, ...fields },
			})
			expect(LLMConfigModal.computed.canTestEmbedding.call(embedding)).toBe(
				ready,
			)
			expect(LLMConfigModal.computed.canTestChat.call(chat)).toBe(ready)
		})
	})

	it.each([[null], [{ id: 'none' }], [{ id: 'other' }]])(
		'not for provider %j',
		(provider) => {
			const vm = modal({
				selectedEmbeddingProvider: provider,
				selectedChatProvider: provider,
			})
			expect(LLMConfigModal.computed.canTestEmbedding.call(vm)).toBe(false)
			expect(LLMConfigModal.computed.canTestChat.call(vm)).toBe(false)
		},
	)
})

describe('LLM Ollama model list', () => {
	it.each([
		['replaced by reported models', { success: true, models: [{ id: 'm' }] }, 1],
		['kept when none are reported', { success: true, models: [] }, 10],
		['kept without a models key', { success: true }, 10],
		['kept on an unsuccessful answer', { models: [{ id: 'm' }] }, 10],
	])('%s', async (name, data, length) => {
		axios.get.mockResolvedValueOnce({ data })
		const vm = modal()
		await vm.fetchOllamaModels()
		expect(vm.ollamaModelOptions).toHaveLength(length)
		expect(vm.loadingOllamaModels).toBe(false)
	})

	it('keeps the list when the request fails', async () => {
		axios.get.mockRejectedValueOnce(new Error('down'))
		const vm = modal()
		await vm.fetchOllamaModels()
		expect(vm.ollamaModelOptions).toHaveLength(10)
		expect(vm.loadingOllamaModels).toBe(false)
	})

	it.each([
		['without a url', { ollamaConfig: { url: '' } }],
		['while loading', { loadingOllamaModels: true }],
	])('does not fetch %s', async (name, state) => {
		axios.get.mockClear()
		await modal(state).fetchOllamaModels()
		expect(axios.get).not.toHaveBeenCalled()
	})
})

describe('LLM clear embeddings', () => {
	it.each([
		['the reason', { success: false, error: 'busy' }, 'busy'],
		['a default', { success: false }, 'Unknown error'],
	])('shows %s when refused', async (name, data, reason) => {
		showError.mockClear()
		axios.delete.mockResolvedValueOnce({ data })
		const vm = modal()
		await vm.clearAllEmbeddings()
		expect(showError.mock.calls[0][0]).toBe(
			`Failed to clear embeddings: {error} ${JSON.stringify({ error: reason })}`,
		)
		expect(vm.$emit).not.toHaveBeenCalled()
		expect(vm.clearingEmbeddings).toBe(false)
	})
})
