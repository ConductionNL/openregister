/**
 * What the LLM configuration modal reads and writes: the form values of stored
 * settings, the settings to save, the connection tests and the vector backends.
 *
 * @spec exclude Mapping between stored LLM settings and the modal's form state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The OpenAI form values of stored OpenAI settings.
 *
 * @param {object} config The stored OpenAI settings.
 * @return {object} The form values.
 */
function openaiFormConfig(config) {
	return {
		apiKey: config.apiKey || '',
		model: config.model || null,
		chatModel: config.chatModel || null,
		organizationId: config.organizationId || '',
	}
}

/**
 * The Ollama form values of stored Ollama settings.
 *
 * @param {object} config The stored Ollama settings.
 * @return {object} The form values.
 */
function ollamaFormConfig(config) {
	return {
		url: config.url || 'http://localhost:11434',
		model: config.model || null,
		chatModel: config.chatModel || null,
	}
}

/**
 * The Fireworks form values of stored Fireworks settings, with the models
 * swapped for their dropdown options when the dropdowns have them.
 *
 * @param {object} vm The LLM configuration modal.
 * @param {object} config The stored Fireworks settings.
 * @return {object} The form values.
 */
function fireworksFormConfig(vm, config) {
	return {
		apiKey: config.apiKey || '',
		embeddingModel: storedModel(
			vm.fireworksEmbeddingModelOptions,
			config.embeddingModel,
		),
		chatModel: storedModel(vm.fireworksChatModelOptions, config.chatModel),
		baseUrl: config.baseUrl || 'https://api.fireworks.ai/inference/v1',
	}
}

/**
 * A stored model as the form holds it: its dropdown option when there is one,
 * else the stored id.
 *
 * @param {Array<object>} options The dropdown options.
 * @param {string|undefined} id The stored model id.
 * @return {object|string|null} The form value.
 */
function storedModel(options, id) {
	return options.find((m) => m.id === id) || id || null
}

/**
 * Select the stored providers.
 *
 * @param {object} vm The LLM configuration modal.
 * @param {object} llmSettings The stored LLM settings.
 * @return {void}
 */
function applyProviders(vm, llmSettings) {
	if (llmSettings.embeddingProvider) {
		vm.selectedEmbeddingProvider = vm.embeddingProviderOptions.find(
			(p) => p.id === llmSettings.embeddingProvider,
		)
	}
	if (llmSettings.chatProvider) {
		vm.selectedChatProvider = vm.chatProviderOptions.find(
			(p) => p.id === llmSettings.chatProvider,
		)
	}
}

/**
 * Fill each provider's form from its stored settings.
 *
 * @param {object} vm The LLM configuration modal.
 * @param {object} llmSettings The stored LLM settings.
 * @return {void}
 */
function applyProviderConfigs(vm, llmSettings) {
	if (llmSettings.openaiConfig) {
		vm.openaiConfig = openaiFormConfig(llmSettings.openaiConfig)
	}
	if (llmSettings.ollamaConfig) {
		vm.ollamaConfig = ollamaFormConfig(llmSettings.ollamaConfig)
	}
	if (llmSettings.fireworksConfig) {
		vm.fireworksConfig = fireworksFormConfig(vm, llmSettings.fireworksConfig)
	}
}

/**
 * Fill the form from stored LLM settings: the providers, each provider's
 * settings, and the enabled features.
 *
 * @param {object} vm The LLM configuration modal.
 * @param {object} llmSettings The stored LLM settings.
 * @return {void}
 */
export function applyLlmSettings(vm, llmSettings) {
	vm.llmEnabled = llmSettings.enabled || false
	applyProviders(vm, llmSettings)
	applyProviderConfigs(vm, llmSettings)
	if (Array.isArray(llmSettings.enabledFeatures)) {
		vm.aiFeatures.forEach((feature) => {
			feature.enabled = llmSettings.enabledFeatures.includes(feature.id)
		})
	}
}

/**
 * Whether Ollama is chosen for embeddings or chat and has a URL, so its models can be listed.
 *
 * @param {object} vm The LLM configuration modal.
 * @return {boolean} True when the Ollama models should be fetched.
 */
export function usesOllama(vm) {
	const chosen =
		vm.selectedEmbeddingProvider?.id === 'ollama'
		|| vm.selectedChatProvider?.id === 'ollama'
	return chosen && Boolean(vm.ollamaConfig.url)
}

/**
 * The id of a model picked as an object, or the model itself when it is
 * already an id.
 *
 * @param {object|string} model The model
 * @return {string} The id
 */
function modelId(model) {
	return model?.id || model
}

/**
 * The message of a failed request: the server's error, else the error's own.
 *
 * @param {Error} error The error
 * @return {string} The message
 */
export function failureText(error) {
	return error.response?.data?.error || error.message
}

/**
 * The OpenAI settings to save.
 *
 * @param {object} config The OpenAI form state
 * @return {object} The settings
 */
function openaiSettings(config) {
	return {
		apiKey: config.apiKey,
		model: modelId(config.model),
		chatModel: modelId(config.chatModel),
		organizationId: config.organizationId,
	}
}

/**
 * The Ollama settings to save.
 *
 * @param {object} config The Ollama form state
 * @return {object} The settings
 */
function ollamaSettings(config) {
	return {
		url: config.url,
		model: modelId(config.model),
		chatModel: modelId(config.chatModel),
	}
}

/**
 * The Fireworks settings to save.
 *
 * @param {object} config The Fireworks form state
 * @return {object} The settings
 */
function fireworksSettings(config) {
	return {
		apiKey: config.apiKey,
		embeddingModel: modelId(config.embeddingModel),
		chatModel: modelId(config.chatModel),
		baseUrl: config.baseUrl,
	}
}

/**
 * The two connection tests: where each posts, which form state it reads and
 * writes, and the provider settings it sends.
 */
export const CONNECTION_TESTS = {
	embedding: {
		busy: 'testingEmbedding',
		result: 'embeddingTestResult',
		selected: 'selectedEmbeddingProvider',
		url: '/apps/openregister/api/vectors/test-embedding',
		sample: { testText: 'This is a test embedding generation.' },
		failedText: (vm, error) =>
			vm.t('openregister', 'Embedding test failed: {error}', { error }),
		connectedText: (vm) =>
			vm.t('openregister', 'Embedding provider connection successful!'),
		configs: {
			openai: (vm) => ({
				apiKey: vm.openaiConfig.apiKey,
				model: modelId(vm.openaiConfig.model),
			}),
			fireworks: (vm) => ({
				apiKey: vm.fireworksConfig.apiKey,
				model: modelId(vm.fireworksConfig.embeddingModel),
				baseUrl: vm.fireworksConfig.baseUrl,
			}),
			ollama: (vm) => ({
				url: vm.ollamaConfig.url,
				model: modelId(vm.ollamaConfig.model),
			}),
		},
	},
	chat: {
		busy: 'testingChat',
		result: 'chatTestResult',
		selected: 'selectedChatProvider',
		url: '/apps/openregister/api/llm/test-chat',
		sample: { testMessage: 'Hello! Please respond with a brief greeting.' },
		failedText: (vm, error) =>
			vm.t('openregister', 'Chat test failed: {error}', { error }),
		connectedText: (vm) =>
			vm.t('openregister', 'Chat provider connection successful!'),
		configs: {
			openai: (vm) => ({
				apiKey: vm.openaiConfig.apiKey,
				model: modelId(vm.openaiConfig.chatModel),
			}),
			fireworks: (vm) => ({
				apiKey: vm.fireworksConfig.apiKey,
				model: modelId(vm.fireworksConfig.chatModel),
				baseUrl: vm.fireworksConfig.baseUrl,
			}),
			ollama: (vm) => ({
				url: vm.ollamaConfig.url,
				model: modelId(vm.ollamaConfig.chatModel),
			}),
		},
	},
}

/**
 * The settings a connection test sends for the selected provider.
 *
 * @param {object} vm The LLM configuration modal.
 * @param {object} test One of CONNECTION_TESTS
 * @return {object} The settings, empty without a known provider
 */
export function testConfig(vm, test) {
	const provider = vm[test.selected]?.id
	return Object.hasOwn(test.configs, provider) ? test.configs[provider](vm) : {}
}

/**
 * Whether the selected provider has a credential or URL and a model to test.
 *
 * @param {object} vm The LLM configuration modal.
 * @param {object} test One of CONNECTION_TESTS
 * @return {boolean} True when the test can run
 */
export function canTest(vm, test) {
	const config = testConfig(vm, test)
	return Boolean((config.apiKey || config.url) && config.model)
}

/**
 * The LLM settings to save. Models are picked as objects; the backend expects
 * their ids.
 *
 * @param {object} vm The LLM configuration modal.
 * @return {object} The settings
 */
export function llmSettingsToSave(vm) {
	return {
		embeddingProvider: vm.selectedEmbeddingProvider?.id,
		chatProvider: vm.selectedChatProvider?.id,
		openaiConfig: openaiSettings(vm.openaiConfig),
		ollamaConfig: ollamaSettings(vm.ollamaConfig),
		fireworksConfig: fireworksSettings(vm.fireworksConfig),
		vectorConfig: { backend: vm.selectedVectorBackend?.id || 'php' },
		enabledFeatures: vm.aiFeatures.filter((f) => f.enabled).map((f) => f.id),
	}
}

/**
 * The vector backend that is always there.
 */
const PHP_BACKEND = {
	id: 'php',
	name: 'PHP Cosine Similarity',
	description: 'Always available, but slow for large datasets (>500 vectors)',
	performance: 'slow',
	available: true,
	performanceNote: 'Calculates similarity in PHP. Suitable for small datasets.',
}

/**
 * The only vector backend offered when the backends cannot be loaded.
 */
export const FALLBACK_BACKEND = {
	id: 'php',
	name: 'PHP Cosine Similarity',
	description: 'Always available fallback',
	performance: 'slow',
	available: true,
}

/**
 * The vector backends to offer: PHP, plus the database when it reports itself.
 *
 * @param {object} data The database settings response
 * @return {Array<object>} The backends
 */
export function vectorBackends(data) {
	const backends = [{ ...PHP_BACKEND }]
	if (data.success && data.database) {
		backends.push(databaseBackend(data.database))
	}
	return backends
}

/**
 * The database vector backend, available when it supports vectors.
 *
 * @param {object} db The database description
 * @return {object} The backend
 */
function databaseBackend(db) {
	return {
		id: 'database',
		name: db.type + ' + pgvector',
		description: db.vectorSupport
			? 'Fast database-level vector search (Recommended)'
			: 'PostgreSQL with pgvector extension required',
		performance: db.vectorSupport ? 'fast' : null,
		available: db.vectorSupport,
		performanceNote: db.performanceNote,
	}
}
