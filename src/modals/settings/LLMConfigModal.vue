<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'LLM Configuration')"
		size="large"
		@closing="$emit('closing')">
		<div class="llm-config-content">
			<!-- Info box -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>
					{{
						t(
							'openregister',
							'Configure large language model (LLM) providers for ai-powered features including semantic search, embeddings, and chat.',
						)
					}}
				</p>
			</div>

			<!-- Provider Selection (Two Columns) -->
			<div class="providers-grid">
				<!-- Embedding Provider -->
				<div class="provider-column">
					<h3>{{ t('openregister', 'Embedding Provider') }}</h3>
					<p class="section-description">
						{{
							t(
								'openregister',
								'For vector embeddings and semantic search',
							)
						}}
					</p>

					<NcSelect
						v-model="selectedEmbeddingProvider"
						:options="embeddingProviderOptions"
						label="name"
						:inputLabel="t('openregister', 'Embedding Provider')"
						:placeholder="t('openregister', 'Select provider')"
						@update:modelValue="handleEmbeddingProviderChange">
						<template #option="{ name, description }">
							<div class="provider-option">
								<strong>{{ name }}</strong>
								<small>{{ description }}</small>
							</div>
						</template>
					</NcSelect>
				</div>

				<!-- Chat Provider -->
				<div class="provider-column">
					<h3>{{ t('openregister', 'Chat Provider (RAG)') }}</h3>
					<p class="section-description">
						{{
							t(
								'openregister',
								'For chat and retrieval-augmented generation',
							)
						}}
					</p>

					<NcSelect
						v-model="selectedChatProvider"
						:options="chatProviderOptions"
						label="name"
						:inputLabel="t('openregister', 'Chat Provider')"
						:placeholder="t('openregister', 'Select provider')">
						<template #option="{ name, description }">
							<div class="provider-option">
								<strong>{{ name }}</strong>
								<small>{{ description }}</small>
							</div>
						</template>
					</NcSelect>
				</div>
			</div>

			<!-- Embedding Provider Configuration -->
			<div
				v-if="
					selectedEmbeddingProvider
					&& selectedEmbeddingProvider.id === 'openai'
				"
				class="config-section">
				<h3>{{ t('openregister', 'OpenAI Embedding Configuration') }}</h3>

				<LlmApiKeyField
					id="openai-api-key"
					v-model="openaiConfig.apiKey"
					placeholder="sk-..."
					:hint="t('openregister', 'Your OpenAI API key. Get one at')"
					href="https://platform.openai.com/api-keys"
					linkText="platform.openai.com" />

				<div class="form-group">
					<label for="openai-model">{{
						t('openregister', 'Embedding Model')
					}}</label>
					<NcSelect
						v-model="openaiConfig.model"
						inputLabel="Openai Config Model"
						:options="openaiModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select model')"
						:labelOutside="true">
						<template #option="{ name, dimensions, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small
									>{{ dimensions }} dimensions • {{ cost }}</small
								>
							</div>
						</template>
					</NcSelect>
				</div>

				<div class="form-group">
					<label for="openai-org">{{
						t('openregister', 'Organization ID (Optional)')
					}}</label>
					<input
						id="openai-org"
						v-model="openaiConfig.organizationId"
						type="text"
						autocomplete="off"
						placeholder="org-..."
						class="input-field" />
				</div>
			</div>

			<!-- Ollama Embedding Configuration -->
			<div
				v-if="
					selectedEmbeddingProvider
					&& selectedEmbeddingProvider.id === 'ollama'
				"
				class="config-section">
				<h3>{{ t('openregister', 'Ollama Embedding Configuration') }}</h3>

				<LlmOllamaUrlField
					id="ollama-url"
					v-model="ollamaConfig.url" />

				<LlmOllamaModelSelect
					id="ollama-model"
					v-model="ollamaConfig.model"
					:label="t('openregister', 'Model Name')"
					inputLabel="Ollama Config Model"
					:options="ollamaModelOptions" />
			</div>

			<!-- Fireworks Embedding Configuration -->
			<div
				v-if="
					selectedEmbeddingProvider
					&& selectedEmbeddingProvider.id === 'fireworks'
				"
				class="config-section">
				<h3>
					{{ t('openregister', 'Fireworks AI Embedding Configuration') }}
				</h3>

				<LlmApiKeyField
					id="fireworks-api-key"
					v-model="fireworksConfig.apiKey"
					placeholder="fw_..."
					:hint="t('openregister', 'Your Fireworks AI API key. Get one at')"
					href="https://fireworks.ai"
					linkText="fireworks.ai" />

				<div class="form-group">
					<label for="fireworks-embedding-model">{{
						t('openregister', 'Embedding Model')
					}}</label>
					<NcSelect
						v-model="fireworksConfig.embeddingModel"
						inputLabel="Fireworks Config Embedding Model"
						:options="fireworksEmbeddingModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select model')"
						:labelOutside="true">
						<template #option="{ name, dimensions, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small
									>{{ dimensions }} dimensions • {{ cost }}</small
								>
							</div>
						</template>
					</NcSelect>
				</div>

				<div class="form-group">
					<label for="fireworks-base-url">{{
						t('openregister', 'Base URL (Optional)')
					}}</label>
					<input
						id="fireworks-base-url"
						v-model="fireworksConfig.baseUrl"
						type="text"
						placeholder="https://api.fireworks.ai/inference/v1"
						class="input-field" />
					<small>{{
						t(
							'openregister',
							'Custom API endpoint if using a different region',
						)
					}}</small>
				</div>
			</div>

			<!-- Chat Provider Configuration -->
			<!-- OpenAI Chat Configuration -->
			<div
				v-if="selectedChatProvider && selectedChatProvider.id === 'openai'"
				class="config-section">
				<h3>{{ t('openregister', 'OpenAI Chat Settings') }}</h3>

				<LlmApiKeyField
					v-if="selectedEmbeddingProvider?.id !== 'openai'"
					id="openai-chat-api-key"
					v-model="openaiConfig.apiKey"
					placeholder="sk-..."
					:hint="t('openregister', 'Your OpenAI API key. Get one at')"
					href="https://platform.openai.com/api-keys"
					linkText="platform.openai.com" />

				<div class="form-group">
					<label for="openai-chat-model">{{
						t('openregister', 'Chat Model')
					}}</label>
					<NcSelect
						v-model="openaiConfig.chatModel"
						inputLabel="Openai Config Chat Model"
						:options="openaiChatModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select chat model')"
						:labelOutside="true">
						<template #option="{ name, contextWindow, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small
									>{{ contextWindow }} tokens • {{ cost }}/1M
									tokens</small
								>
							</div>
						</template>
					</NcSelect>
				</div>
			</div>

			<!-- Fireworks Chat Configuration -->
			<div
				v-if="
					selectedChatProvider && selectedChatProvider.id === 'fireworks'
				"
				class="config-section">
				<h3>{{ t('openregister', 'Fireworks AI Chat Settings') }}</h3>

				<LlmApiKeyField
					v-if="selectedEmbeddingProvider?.id !== 'fireworks'"
					id="fireworks-chat-api-key"
					v-model="fireworksConfig.apiKey"
					placeholder="fw_..."
					:hint="t('openregister', 'Your Fireworks AI API key. Get one at')"
					href="https://fireworks.ai"
					linkText="fireworks.ai" />

				<div class="form-group">
					<label for="fireworks-chat-model">{{
						t('openregister', 'Chat Model')
					}}</label>
					<NcSelect
						v-model="fireworksConfig.chatModel"
						inputLabel="Fireworks Config Chat Model"
						:options="fireworksChatModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select chat model')"
						:labelOutside="true">
						<template #option="{ name, contextWindow, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small
									>{{ contextWindow }} tokens • {{ cost }}</small
								>
							</div>
						</template>
					</NcSelect>
				</div>
			</div>

			<!-- Ollama Chat Configuration -->
			<div
				v-if="selectedChatProvider && selectedChatProvider.id === 'ollama'"
				class="config-section">
				<h3>{{ t('openregister', 'Ollama Chat Settings') }}</h3>

				<LlmOllamaUrlField
					v-if="selectedEmbeddingProvider?.id !== 'ollama'"
					id="ollama-chat-url"
					v-model="ollamaConfig.url" />

				<LlmOllamaModelSelect
					id="ollama-chat-model"
					v-model="ollamaConfig.chatModel"
					:label="t('openregister', 'Chat Model')"
					inputLabel="Ollama Config Chat Model"
					:options="ollamaModelOptions" />
			</div>

			<!-- Vector Search Backend -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Vector Search Backend') }}</h3>
				<p class="section-description">
					{{
						t(
							'openregister',
							'Choose how vector similarity calculations are performed for semantic search',
						)
					}}
				</p>

				<div class="form-group">
					<label for="vector-backend">{{
						t('openregister', 'Search Method')
					}}</label>
					<NcSelect
						v-model="selectedVectorBackend"
						inputLabel="Selected Vector Backend"
						:options="vectorBackendOptions"
						label="name"
						:placeholder="t('openregister', 'Select backend')"
						:disabled="loadingBackends">
						<template
							#option="{ name, description, performance, available }">
							<div
								class="backend-option"
								:class="{ 'backend-disabled': !available }">
								<div class="backend-header">
									<strong>{{ name }}</strong>
									<span
										v-if="performance"
										:class="'badge badge-' + performance">
										{{
											performance === 'slow'
												? '🐌 Slow'
												: performance === 'fast'
													? '⚡ Fast'
													: '🚀 Very Fast'
										}}
									</span>
								</div>
								<small>{{ description }}</small>
								<small v-if="!available" class="warning-text"
									>⚠️ Not available</small
								>
							</div>
						</template>
					</NcSelect>
					<small
						v-if="
							selectedVectorBackend
							&& selectedVectorBackend.performanceNote
						"
						class="help-text">
						{{ selectedVectorBackend.performanceNote }}
					</small>
				</div>
			</div>

			<!-- AI Features -->
			<div class="config-section">
				<h3>{{ t('openregister', '✨ AI Features') }}</h3>
				<div class="features-grid">
					<NcCheckboxRadioSwitch
						v-for="feature in aiFeatures"
						:key="feature.id"
						v-model="feature.enabled"
						type="checkbox">
						<span class="feature-label">
							{{ feature.icon }} {{ feature.label }}
						</span>
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>

		<!-- Dialog Actions -->
		<template #actions>
			<div class="actions-left">
				<!-- Test Embedding Provider -->
				<NcButton
					v-if="
						selectedEmbeddingProvider
						&& selectedEmbeddingProvider.id !== 'none'
					"
					variant="secondary"
					:disabled="testingEmbedding || !canTestEmbedding"
					@click="testEmbeddingConnection">
					<template #icon>
						<NcLoadingIcon v-if="testingEmbedding" :size="20" />
						<TestTube v-else :size="20" />
					</template>
					{{
						testingEmbedding
							? t('openregister', 'Testing…')
							: t('openregister', 'Test Embedding')
					}}
				</NcButton>

				<!-- Test Chat Provider -->
				<NcButton
					v-if="selectedChatProvider && selectedChatProvider.id !== 'none'"
					variant="secondary"
					:disabled="testingChat || !canTestChat"
					@click="testChatConnection">
					<template #icon>
						<NcLoadingIcon v-if="testingChat" :size="20" />
						<TestTube v-else :size="20" />
					</template>
					{{
						testingChat
							? t('openregister', 'Testing…')
							: t('openregister', 'Test Chat')
					}}
				</NcButton>

				<!-- Clear All Embeddings -->
				<NcButton
					variant="error"
					:disabled="clearingEmbeddings"
					@click="confirmClearEmbeddings">
					<template #icon>
						<NcLoadingIcon v-if="clearingEmbeddings" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{
						clearingEmbeddings
							? t('openregister', 'Clearing…')
							: t('openregister', 'Clear All Embeddings')
					}}
				</NcButton>

				<!-- Test Results -->
				<div
					v-if="embeddingTestResult"
					class="test-result-inline"
					:class="embeddingTestResult.success ? 'success' : 'error'">
					{{ embeddingTestResult.success ? '✅' : '❌' }} Embedding:
					{{ embeddingTestResult.message }}
				</div>
				<div
					v-if="chatTestResult"
					class="test-result-inline"
					:class="chatTestResult.success ? 'success' : 'error'">
					{{ chatTestResult.success ? '✅' : '❌' }} Chat:
					{{ chatTestResult.message }}
				</div>
			</div>

			<div class="actions-right">
				<SettingsSaveActions
					:saving="saving"
					@cancel="$emit('closing')"
					@save="saveConfiguration" />
			</div>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcSelect,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import LlmApiKeyField from './llm/LlmApiKeyField.vue'
import LlmOllamaModelSelect from './llm/LlmOllamaModelSelect.vue'
import LlmOllamaUrlField from './llm/LlmOllamaUrlField.vue'
import SettingsSaveActions from './SettingsSaveActions.vue'
import { freshState } from '../../services/initialState.js'
import {
	AI_FEATURES,
	CHAT_PROVIDERS,
	EMBEDDING_PROVIDERS,
	FIREWORKS_CHAT_MODELS,
	FIREWORKS_EMBEDDING_MODELS,
	OLLAMA_MODELS,
	OPENAI_CHAT_MODELS,
	OPENAI_EMBEDDING_MODELS,
} from './llmOptions.js'
import {
	applyLlmSettings,
	canTest,
	CONNECTION_TESTS,
	failureText,
	FALLBACK_BACKEND,
	llmSettingsToSave,
	testConfig,
	usesOllama,
	vectorBackends,
} from './llmSettings.js'

/**
 * The state every instance of this component starts from.
 */
const INITIAL_STATE = {
	loading: true,
	saving: false,
	testingEmbedding: false,
	testingChat: false,
	clearingEmbeddings: false,
	embeddingTestResult: null,
	chatTestResult: null,

	selectedEmbeddingProvider: null,
	selectedChatProvider: null,

	openaiConfig: {
		apiKey: '',
		model: null,
		chatModel: null,
		organizationId: '',
	},

	ollamaConfig: {
		url: 'http://localhost:11434',
		model: null,
		chatModel: null,
	},

	fireworksConfig: {
		apiKey: '',
		embeddingModel: null,
		chatModel: null,
		baseUrl: 'https://api.fireworks.ai/inference/v1',
	},

	embeddingProviderOptions: EMBEDDING_PROVIDERS,
	ollamaModelOptions: OLLAMA_MODELS,

	loadingOllamaModels: false,

	chatProviderOptions: CHAT_PROVIDERS,
	openaiModelOptions: OPENAI_EMBEDDING_MODELS,
	openaiChatModelOptions: OPENAI_CHAT_MODELS,
	fireworksEmbeddingModelOptions: FIREWORKS_EMBEDDING_MODELS,
	fireworksChatModelOptions: FIREWORKS_CHAT_MODELS,

	// Vector Search Backend
	loadingBackends: false,
	selectedVectorBackend: null,
	vectorBackendOptions: [],

	aiFeatures: AI_FEATURES,
}

export default {
	name: 'LLMConfigModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcCheckboxRadioSwitch,
		InformationOutline,
		TestTube,
		Delete,
		LlmApiKeyField,
		LlmOllamaModelSelect,
		LlmOllamaUrlField,
		SettingsSaveActions,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},
	},

	data() {
		return freshState(INITIAL_STATE)
	},

	computed: {
		/**
		 * @spec exclude UI state helper — enables the embedding test when its provider is configured.
		 */
		canTestEmbedding() {
			return canTest(this, CONNECTION_TESTS.embedding)
		},

		/**
		 * @spec exclude UI state helper — enables the chat test when its provider is configured.
		 */
		canTestChat() {
			return canTest(this, CONNECTION_TESTS.chat)
		},
	},

	watch: {
		// Fetch Ollama models when Ollama is selected
		/**
		 * @param {object|null} newVal
		 * @spec exclude UI watcher — fetches Ollama models when the embedding provider is Ollama.
		 */
		selectedEmbeddingProvider(newVal) {
			if (newVal?.id === 'ollama' && this.ollamaConfig.url) {
				this.fetchOllamaModels()
			}
		},

		/**
		 * @param {object|null} newVal
		 * @spec exclude UI watcher — fetches Ollama models when the chat provider is Ollama.
		 */
		selectedChatProvider(newVal) {
			if (newVal?.id === 'ollama' && this.ollamaConfig.url) {
				this.fetchOllamaModels()
			}
		},

		// Refetch models when URL changes
		'ollamaConfig.url': function (newVal) {
			if (
				newVal
				&& (this.selectedEmbeddingProvider?.id === 'ollama'
					|| this.selectedChatProvider?.id === 'ollama')
			) {
				this.fetchOllamaModels()
			}
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook — loads LLM config and available backends on mount.
	 */
	mounted() {
		this.loadConfiguration()
		this.loadAvailableBackends()
	},

	methods: {
		/**
		 * @spec openspec/specs/platform-administration-modals/spec.md
		 */
		async loadConfiguration() {
			this.loading = true

			try {
				await this.readSettings()
			} catch (error) {
				this.loadFailed(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fill the form from the stored settings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/platform-administration-modals/spec.md
		 */
		async readSettings() {
			const response = await axios.get(
				generateUrl('/apps/openregister/api/settings/llm'),
			)
			applyLlmSettings(this, response.data)
			if (usesOllama(this)) {
				this.fetchOllamaModels()
			}
		},

		/**
		 * Show that the stored settings could not be read.
		 *
		 * @param {Error} error The failure
		 * @return {void}
		 * @spec exclude Error display for a failed settings read; UI plumbing.
		 */
		loadFailed(error) {
			console.error('Failed to load LLM configuration:', error)
			showError(this.t('openregister', 'Failed to load LLM configuration'))
		},

		/**
		 * @spec exclude UI event handler — clears the embedding test result on provider change.
		 */
		handleEmbeddingProviderChange() {
			this.embeddingTestResult = null
		},

		/**
		 * @spec exclude Modal action plumbing — tests the embedding provider connection.
		 */
		testEmbeddingConnection() {
			return this.runConnectionTest(CONNECTION_TESTS.embedding)
		},

		/**
		 * @spec exclude Modal action plumbing — tests the chat provider connection.
		 */
		testChatConnection() {
			return this.runConnectionTest(CONNECTION_TESTS.chat)
		},

		/**
		 * Test one provider connection and tell the user how it went.
		 *
		 * @param {object} test One of CONNECTION_TESTS
		 * @return {Promise<void>}
		 * @spec exclude Modal action plumbing — reports a provider connection test.
		 */
		async runConnectionTest(test) {
			const error = await this.testConnection(test)
			if (error) {
				showError(test.failedText(this, failureText(error)))
				return
			}
			showSuccess(test.connectedText(this))
		},

		/**
		 * Test one provider connection and record the outcome.
		 *
		 * @param {object} test One of CONNECTION_TESTS
		 * @return {Promise<Error|null>} The failure, or null when it connected
		 * @spec exclude Modal action plumbing — posts a provider connection test.
		 */
		async testConnection(test) {
			this[test.busy] = true
			this[test.result] = null
			try {
				await this.postConnectionTest(test)
				this[test.result] = { success: true, message: 'Connected' }
				return null
			} catch (error) {
				this[test.result] = { success: false, message: 'Failed' }
				return error
			} finally {
				this[test.busy] = false
			}
		},

		/**
		 * Post the selected provider's settings to a connection test.
		 *
		 * @param {object} test One of CONNECTION_TESTS
		 * @return {Promise<object>} The response
		 * @spec exclude Modal action plumbing — posts a provider connection test.
		 */
		postConnectionTest(test) {
			return axios.post(generateUrl(test.url), {
				provider: this[test.selected]?.id,
				config: testConfig(this, test),
				...test.sample,
			})
		},

		/**
		 * @spec exclude Modal save plumbing — posts LLM configuration to the settings endpoint.
		 */
		async saveConfiguration() {
			this.saving = true
			try {
				await this.writeSettings()
			} catch (error) {
				this.saveFailed(error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Store the form and close.
		 *
		 * @return {Promise<void>}
		 * @spec exclude Modal save plumbing — posts LLM configuration to the settings endpoint.
		 */
		async writeSettings() {
			await axios.patch(
				generateUrl('/apps/openregister/api/settings/llm'),
				llmSettingsToSave(this),
			)
			showSuccess(
				this.t('openregister', 'LLM configuration saved successfully'),
			)
			this.$emit('closing')
		},

		/**
		 * Show that the settings could not be saved.
		 *
		 * @param {Error} error The failure
		 * @return {void}
		 * @spec exclude Error display for a failed save; UI plumbing.
		 */
		saveFailed(error) {
			showError(
				this.t('openregister', 'Failed to save configuration: {error}', {
					error: failureText(error),
				}),
			)
		},

		/**
		 * @spec exclude Modal data-load plumbing — fetches available Ollama models for the picker.
		 */
		async fetchOllamaModels() {
			if (!this.ollamaConfig.url || this.loadingOllamaModels) {
				return
			}
			this.loadingOllamaModels = true
			try {
				await this.readOllamaModels()
			} catch {
				// Keep the fixed list.
			} finally {
				this.loadingOllamaModels = false
			}
		},

		/**
		 * Offer the models Ollama reports, when it reports any.
		 *
		 * @return {Promise<void>}
		 * @spec exclude Modal data-load plumbing — fetches available Ollama models for the picker.
		 */
		async readOllamaModels() {
			const response = await axios.get(
				generateUrl('/apps/openregister/api/llm/ollama-models'),
			)
			if (response.data.success && response.data.models?.length > 0) {
				this.ollamaModelOptions = response.data.models
			}
		},

		/**
		 * @spec exclude UI event handler — prompts before clearing all embeddings.
		 */
		confirmClearEmbeddings() {
			// Use native browser confirm to avoid focus-trap conflicts with nested modals
			const message = this.t(
				'openregister',
				'This will permanently delete ALL embeddings (vectors) from the database. You will need to re-vectorize all objects and files. This action cannot be undone.\n\nAre you sure you want to continue?',
			)

			if (confirm(message)) {
				this.clearAllEmbeddings()
			}
		},

		/**
		 * @spec exclude Modal action plumbing — clears all stored embeddings via the API.
		 */
		async clearAllEmbeddings() {
			this.clearingEmbeddings = true
			try {
				const response = await axios.delete(
					generateUrl('/apps/openregister/api/vectors/clear-all'),
				)
				this.clearAnswered(response.data)
			} catch (error) {
				this.clearFailed(failureText(error))
			} finally {
				this.clearingEmbeddings = false
			}
		},

		/**
		 * Report how the server answered the clear request.
		 *
		 * @param {object} data The response body
		 * @return {void}
		 * @spec exclude Modal action plumbing — reports the cleared embeddings.
		 */
		clearAnswered(data) {
			if (!data.success) {
				this.clearFailed(data.error || 'Unknown error')
				return
			}
			showSuccess(
				this.t(
					'openregister',
					'Successfully deleted {count} embeddings. Please re-vectorize your data.',
					{ count: data.deleted },
				),
			)
			// Emit event to parent to refresh stats
			this.$emit('embeddings-cleared')
		},

		/**
		 * Show why the embeddings were not cleared.
		 *
		 * @param {string} error The reason
		 * @return {void}
		 * @spec exclude Error display for a failed clear; UI plumbing.
		 */
		clearFailed(error) {
			showError(
				this.t('openregister', 'Failed to clear embeddings: {error}', {
					error,
				}),
			)
		},

		/**
		 * Load available vector search backends
		 *
		 * @spec exclude Modal data-load plumbing — fetches available LLM backends for the pickers.
		 */
		async loadAvailableBackends() {
			this.loadingBackends = true
			try {
				await this.fetchBackends()
			} catch (error) {
				console.error('Failed to load vector backends:', error)
				this.vectorBackendOptions = [{ ...FALLBACK_BACKEND }]
				this.selectedVectorBackend = this.vectorBackendOptions[0]
			} finally {
				this.loadingBackends = false
			}
		},

		/**
		 * Offer the backends the server reports and select the stored one.
		 *
		 * @return {Promise<void>}
		 * @spec exclude Modal data-load plumbing — fetches available LLM backends for the pickers.
		 */
		async fetchBackends() {
			const dbResponse = await axios.get(
				generateUrl('/apps/openregister/api/settings/database'),
			)
			const backends = vectorBackends(dbResponse.data)
			this.vectorBackendOptions = backends
			const llmResponse = await axios.get(
				generateUrl('/apps/openregister/api/settings/llm'),
			)
			const vectorBackend = llmResponse.data.vectorConfig?.backend || 'php'
			this.selectedVectorBackend =
				backends.find((b) => b.id === vectorBackend) || backends[0]
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.llm-config-content {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.info-box {
	display: flex;
	gap: 12px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	margin-bottom: 24px;
	align-items: flex-start;

	p {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}
}

.providers-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 24px;
	margin-bottom: 32px;
}

.provider-column,
.config-section {
	h3 {
		margin: 0 0 8px 0;
		font-size: 16px;
		font-weight: 600;
	}
}

.provider-column {
	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}

.config-section {
	margin-bottom: 32px;

	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
	}
}

@include form-group;

.provider-option,
.model-option {
	@include stacked-select-option;
}

.backend-option {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;

	&.backend-disabled {
		opacity: 0.5;
	}

	.backend-header {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.badge {
		padding: 2px 8px;
		border-radius: 12px;
		font-size: 11px;
		font-weight: 500;

		&.badge-slow {
			background: var(--color-warning);
			color: white;
		}

		&.badge-fast {
			background: var(--color-success);
			color: white;
		}

		&.badge-very_fast {
			background: var(--color-primary-element);
			color: white;
		}
	}

	small {
		color: var(--color-text-maxcontrast);
		font-size: 12px;

		&.warning-text {
			color: var(--color-warning);
			font-weight: 500;
		}
	}
}

.help-text {
	margin-top: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.features-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
	gap: 12px;
}

.feature-label {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Actions layout */
@include deep-dialog-actions;

.actions-left {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

@include actions-right;

.test-result-inline {
	padding: 8px 12px;
	border-radius: 6px;
	font-size: 13px;
	font-weight: 500;

	&.success {
		background: var(--color-success);
		color: white;
	}

	&.error {
		background: var(--color-error);
		color: white;
	}
}

@media (max-width: 768px) {
	.providers-grid {
		grid-template-columns: 1fr;
	}

	:deep(.dialog__actions) {
		flex-direction: column;
		align-items: stretch;
	}

	.actions-left,
	.actions-right {
		width: 100%;
		justify-content: center;
	}
}
</style>
