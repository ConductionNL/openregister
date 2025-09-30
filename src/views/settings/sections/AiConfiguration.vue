<template>
	<NcSettingsSection name="AI Configuration"
		description="Configure AI functionality for automatic text generation and embeddings using LLPhant framework">
		<div class="ai-options">
			<!-- Save and Test Buttons -->
			<div class="section-header-inline">
				<span />
				<div class="button-group">
					<NcButton
						type="secondary"
						:disabled="loading || saving || testingConnection"
						@click="testAiConnection">
						<template #icon>
							<NcLoadingIcon v-if="testingConnection" :size="20" />
							<TestTube v-else :size="20" />
						</template>
						Test Connection
					</NcButton>
					<NcButton
						type="primary"
						:disabled="loading || saving || testingConnection"
						@click="saveSettings">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<Save v-else :size="20" />
						</template>
						Save
					</NcButton>
				</div>
			</div>

			<!-- Section Description -->
			<div class="section-description-full">
				<p class="main-description">
					AI functionality enables automatic text generation and vector embeddings for objects using the 
					<a href="https://github.com/LLPhant/LLPhant" target="_blank" rel="noopener noreferrer">LLPhant framework</a>.
					This allows for semantic search, content analysis, and AI-powered features. Objects will automatically 
					generate searchable text representations and vector embeddings for enhanced search capabilities.
				</p>
				<p class="toggle-status">
					<strong>Current Status:</strong>
					<span :class="aiOptions.enabled ? 'status-enabled' : 'status-disabled'">
						{{ aiOptions.enabled ? 'AI functionality enabled' : 'AI functionality disabled' }}
					</span>
				</p>
			</div>

			<!-- Enable AI Toggle -->
			<div class="option-section">
				<NcCheckboxRadioSwitch
					:checked.sync="aiOptions.enabled"
					:disabled="saving"
					type="switch">
					{{ aiOptions.enabled ? 'AI functionality enabled' : 'AI functionality disabled' }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- AI Configuration -->
			<div v-if="aiOptions.enabled" class="ai-configuration">
				<h4>🤖 AI Provider Configuration</h4>
				<p class="option-description">
					Configure your AI provider and model settings. Different providers offer different capabilities and pricing models.
				</p>

				<div class="ai-config-grid">
					<!-- AI Provider Selection -->
					<div class="config-row">
						<label class="config-label">
							<strong>AI Provider</strong>
							<p class="config-description">Choose your AI service provider</p>
						</label>
						<div class="config-input">
							<NcSelect
								v-model="aiOptions.provider"
								:options="aiProviderOptions"
								:disabled="loading || saving"
								placeholder="Select AI Provider"
								label="label"
								track-by="id"
								class="ai-select-field" />
						</div>
					</div>

					<!-- Provider-specific Configuration -->
					<template v-if="aiOptions.provider">
						<!-- API Configuration -->
						<div class="config-section">
							<h5>🔑 API Configuration</h5>
							
							<div class="config-row">
								<label class="config-label">
									<strong>API Key</strong>
									<p class="config-description">Your {{ getCurrentProviderLabel() }} API key</p>
								</label>
								<div class="config-input">
									<input
										v-model="aiOptions.apiKey"
										type="password"
										:disabled="loading || saving"
										:placeholder="getApiKeyPlaceholder()"
										class="ai-input-field">
								</div>
							</div>

							<div v-if="needsBaseUrl()" class="config-row">
								<label class="config-label">
									<strong>Base URL</strong>
									<p class="config-description">{{ getBaseUrlDescription() }}</p>
								</label>
								<div class="config-input">
									<input
										v-model="aiOptions.baseUrl"
										type="text"
										:disabled="loading || saving"
										:placeholder="getBaseUrlPlaceholder()"
										class="ai-input-field">
								</div>
							</div>
						</div>

						<!-- Model Configuration -->
						<div class="config-section">
							<h5>🧠 Model Configuration</h5>
							
							<div class="config-row">
								<label class="config-label">
									<strong>Language Model</strong>
									<p class="config-description">Model used for text generation and analysis</p>
								</label>
								<div class="config-input">
									<NcSelect
										v-model="aiOptions.model"
										:options="getCurrentLanguageModels()"
										:disabled="loading || saving"
										placeholder="Select Language Model"
										label="label"
										track-by="id"
										class="ai-select-field" />
								</div>
							</div>

							<div class="config-row">
								<label class="config-label">
									<strong>Embedding Model</strong>
									<p class="config-description">Model used for generating vector embeddings</p>
								</label>
								<div class="config-input">
									<NcSelect
										v-model="aiOptions.embeddingModel"
										:options="getCurrentEmbeddingModels()"
										:disabled="loading || saving"
										placeholder="Select Embedding Model"
										label="label"
										track-by="id"
										class="ai-select-field"
										@input="updateEmbeddingDimensions" />
								</div>
							</div>

							<div class="config-row">
								<label class="config-label">
									<strong>Embedding Dimensions</strong>
									<p class="config-description">Vector dimensions for embeddings (automatically set by model)</p>
								</label>
								<div class="config-input">
									<input
										v-model.number="aiOptions.embeddingDimensions"
										type="number"
										:disabled="true"
										class="ai-input-field readonly">
								</div>
							</div>
						</div>

						<!-- Generation Settings -->
						<div class="config-section">
							<h5>⚙️ Generation Settings</h5>
							
							<div class="config-row">
								<label class="config-label">
									<strong>Max Tokens</strong>
									<p class="config-description">Maximum tokens for text generation (1-8000)</p>
								</label>
								<div class="config-input">
									<input
										v-model.number="aiOptions.maxTokens"
										type="number"
										min="1"
										max="8000"
										:disabled="loading || saving"
										class="ai-input-field">
								</div>
							</div>

							<div class="config-row">
								<label class="config-label">
									<strong>Temperature</strong>
									<p class="config-description">Creativity level (0.0 = deterministic, 1.0 = very creative)</p>
								</label>
								<div class="config-input">
									<input
										v-model.number="aiOptions.temperature"
										type="number"
										min="0"
										max="1"
										step="0.1"
										:disabled="loading || saving"
										class="ai-input-field">
								</div>
							</div>

							<div class="config-row">
								<label class="config-label">
									<strong>Timeout (seconds)</strong>
									<p class="config-description">Request timeout for AI operations</p>
								</label>
								<div class="config-input">
									<input
										v-model.number="aiOptions.timeout"
										type="number"
										min="5"
										max="300"
										:disabled="loading || saving"
										class="ai-input-field">
								</div>
							</div>
						</div>

						<!-- Processing Options -->
						<div class="config-section">
							<h5>🔄 Processing Options</h5>
							
							<div class="option-section">
								<NcCheckboxRadioSwitch
									:checked.sync="aiOptions.enableAutoTextGeneration"
									:disabled="saving"
									type="switch">
									Automatically generate text representations for objects
								</NcCheckboxRadioSwitch>
							</div>

							<div class="option-section">
								<NcCheckboxRadioSwitch
									:checked.sync="aiOptions.enableAutoEmbedding"
									:disabled="saving"
									type="switch">
									Automatically generate vector embeddings for semantic search
								</NcCheckboxRadioSwitch>
							</div>

							<div class="config-row">
								<label class="config-label">
									<strong>Batch Size</strong>
									<p class="config-description">Number of objects to process in each batch</p>
								</label>
								<div class="config-input">
									<input
										v-model.number="aiOptions.batchSize"
										type="number"
										min="1"
										max="1000"
										:disabled="loading || saving"
										class="ai-input-field">
								</div>
							</div>

							<div class="config-row">
								<label class="config-label">
									<strong>Retry Attempts</strong>
									<p class="config-description">Number of retry attempts for failed AI requests</p>
								</label>
								<div class="config-input">
									<input
										v-model.number="aiOptions.retryAttempts"
										type="number"
										min="0"
										max="10"
										:disabled="loading || saving"
										class="ai-input-field">
								</div>
							</div>

							<div class="option-section">
								<NcCheckboxRadioSwitch
									:checked.sync="aiOptions.enableLogging"
									:disabled="saving"
									type="switch">
									Enable detailed AI operation logging
								</NcCheckboxRadioSwitch>
							</div>
						</div>
					</template>
				</div>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'

import { NcSettingsSection, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'

import TestTube from 'vue-material-design-icons/TestTube.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'

/**
 * @class AiConfiguration
 * @module Components/Settings
 * @package OpenRegister
 * 
 * AI configuration component for managing AI provider settings, models, and processing options.
 * Integrates with LLPhant framework for text generation and embedding capabilities.
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
 */
export default {
	name: 'AiConfiguration',
	
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		TestTube,
		Save,
	},

	computed: {
		...mapStores(useSettingsStore),

		/**
		 * Get AI options from store
		 */
		aiOptions: {
			get() {
				return this.settingsStore.aiOptions
			},
			set(value) {
				this.settingsStore.aiOptions = value
			},
		},

		/**
		 * Get AI provider options from store
		 */
		aiProviderOptions() {
			return this.settingsStore.aiProviderOptions
		},

		/**
		 * Check if we're currently loading
		 */
		loading() {
			return this.settingsStore.loading
		},

		/**
		 * Check if we're currently saving
		 */
		saving() {
			return this.settingsStore.saving
		},

		/**
		 * Check if we're currently testing connection
		 */
		testingConnection() {
			return this.settingsStore.testingConnection
		},
	},

	methods: {
		/**
		 * Save AI settings
		 */
		async saveSettings() {
			try {
				await this.settingsStore.updateAiSettings(this.aiOptions)
			} catch (error) {
				console.error('Failed to save AI settings:', error)
			}
		},

		/**
		 * Test AI connection
		 */
		async testAiConnection() {
			try {
				await this.settingsStore.testAiConnection()
			} catch (error) {
				console.error('AI connection test failed:', error)
			}
		},

		/**
		 * Get current provider label
		 */
		getCurrentProviderLabel() {
			const provider = this.aiProviderOptions.find(p => p.id === this.aiOptions.provider)
			return provider ? provider.label : 'AI Provider'
		},

		/**
		 * Get API key placeholder based on provider
		 */
		getApiKeyPlaceholder() {
			switch (this.aiOptions.provider) {
				case 'openai':
					return 'sk-...'
				case 'anthropic':
					return 'sk-ant-...'
				case 'azure':
					return 'Your Azure API key'
				case 'ollama':
					return 'Not required for local Ollama'
				default:
					return 'Enter your API key'
			}
		},

		/**
		 * Check if provider needs base URL configuration
		 */
		needsBaseUrl() {
			return ['ollama', 'azure'].includes(this.aiOptions.provider)
		},

		/**
		 * Get base URL description
		 */
		getBaseUrlDescription() {
			switch (this.aiOptions.provider) {
				case 'ollama':
					return 'Ollama server URL (e.g., http://localhost:11434)'
				case 'azure':
					return 'Azure OpenAI endpoint URL'
				default:
					return 'API base URL'
			}
		},

		/**
		 * Get base URL placeholder
		 */
		getBaseUrlPlaceholder() {
			switch (this.aiOptions.provider) {
				case 'ollama':
					return 'http://localhost:11434'
				case 'azure':
					return 'https://your-resource.openai.azure.com'
				default:
					return 'https://api.example.com'
			}
		},

		/**
		 * Get language models for current provider
		 */
		getCurrentLanguageModels() {
			return this.settingsStore.languageModelOptions[this.aiOptions.provider] || []
		},

		/**
		 * Get embedding models for current provider
		 */
		getCurrentEmbeddingModels() {
			return this.settingsStore.embeddingModelOptions[this.aiOptions.provider] || []
		},

		/**
		 * Update embedding dimensions when model changes
		 */
		updateEmbeddingDimensions(selectedModel) {
			if (selectedModel && selectedModel.dimensions) {
				this.aiOptions.embeddingDimensions = selectedModel.dimensions
			}
		},
	},
}
</script>

<style scoped>
.ai-options {
	padding: 0;
}

.section-header-inline {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.button-group {
	display: flex;
	gap: 8px;
}

.section-description-full {
	margin-bottom: 20px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.main-description {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
	line-height: 1.5;
}

.main-description a {
	color: var(--color-primary);
	text-decoration: none;
}

.main-description a:hover {
	text-decoration: underline;
}

.toggle-status {
	margin: 0;
	font-size: 14px;
}

.status-enabled {
	color: var(--color-success);
	font-weight: 500;
}

.status-disabled {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.option-section {
	margin: 20px 0;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border-dark);
}

.ai-configuration {
	margin-top: 20px;
}

.ai-configuration h4 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 18px;
}

.config-section {
	margin: 24px 0;
	padding: 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.config-section h5 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
}

.ai-config-grid {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.config-row {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 20px;
	align-items: start;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.config-row:last-child {
	border-bottom: none;
}

.config-label {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.config-label strong {
	color: var(--color-text-light);
	font-size: 14px;
	font-weight: 600;
}

.config-description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	line-height: 1.4;
}

.config-input {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ai-input-field {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
	font-size: 14px;
}

.ai-input-field:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px var(--color-primary-light);
}

.ai-input-field:disabled {
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}

.ai-input-field.readonly {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.ai-select-field {
	width: 100%;
}

@media (max-width: 768px) {
	.config-row {
		grid-template-columns: 1fr;
		gap: 12px;
	}
	
	.section-header-inline {
		flex-direction: column;
		align-items: stretch;
		gap: 12px;
	}
	
	.button-group {
		justify-content: center;
	}
}
</style>
