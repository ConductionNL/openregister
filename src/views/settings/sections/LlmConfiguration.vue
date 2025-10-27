<template>
	<NcSettingsSection name="LLM Configuration"
		description="Configure Large Language Model settings for AI features">
		<div v-if="!settingsStore.loadingLlmSettings" class="llm-settings">
			<!-- Provider Selection -->
			<div class="settings-card">
				<h4>🤖 LLM Provider</h4>
				<div class="settings-group">
					<div class="setting-item">
						<label for="llm-provider">Select Provider</label>
						<NcSelect v-model="llmSettings.provider"
							input-id="llm-provider"
							:options="providers"
							@input="onProviderChange">
							<template #option="{ label, description, icon }">
								<div class="option-item">
									<span class="option-icon">{{ icon }}</span>
									<div class="option-content">
										<span class="option-label">{{ label }}</span>
										<span class="option-description">{{ description }}</span>
									</div>
								</div>
							</template>
						</NcSelect>
					</div>

					<div v-if="llmSettings.provider?.id !== 'none'" class="setting-item">
						<NcCheckboxRadioSwitch :checked.sync="llmSettings.enabled"
							type="switch"
							@update:checked="saveSettings">
							Enable LLM Features
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							Enable AI-powered features like text generation, summarization, and semantic search
						</p>
					</div>
				</div>
			</div>

			<!-- API Configuration -->
			<div v-if="llmSettings.provider?.id !== 'none'" class="settings-card">
				<h4>🔑 API Configuration</h4>
				<div class="settings-group">
					<div class="setting-item">
						<label for="api-endpoint">API Endpoint</label>
						<input id="api-endpoint"
							v-model="llmSettings.apiEndpoint"
							type="url"
							:placeholder="getDefaultEndpoint()"
							@change="saveSettings">
						<p class="setting-description">
							Leave empty to use default endpoint for selected provider
						</p>
					</div>

					<div class="setting-item">
						<label for="api-key">API Key</label>
						<div class="api-key-input">
							<input id="api-key"
								v-model="llmSettings.apiKey"
								:type="showApiKey ? 'text' : 'password'"
								placeholder="Enter your API key"
								@change="saveSettings">
							<NcButton type="tertiary"
								@click="showApiKey = !showApiKey">
								<template #icon>
									<EyeIcon v-if="!showApiKey" :size="20" />
									<EyeOffIcon v-else :size="20" />
								</template>
							</NcButton>
						</div>
						<p class="setting-description">
							Your API key is stored securely and never sent to the client
						</p>
					</div>

					<div class="setting-item">
						<NcButton type="primary"
							:disabled="!llmSettings.apiKey || testingConnection"
							@click="testConnection">
							<template #icon>
								<NcLoadingIcon v-if="testingConnection" :size="20" />
								<CheckIcon v-else :size="20" />
							</template>
							Test Connection
						</NcButton>
						<span v-if="connectionStatus" 
							class="connection-status"
							:class="connectionStatus.success ? 'success' : 'error'">
							{{ connectionStatus.message }}
						</span>
					</div>
				</div>
			</div>

			<!-- Model Configuration -->
			<div v-if="llmSettings.enabled" class="settings-card">
				<h4>⚙️ Model Configuration</h4>
				<div class="settings-group">
					<div class="setting-item">
						<label for="model-name">Model</label>
						<NcSelect v-model="llmSettings.model"
							input-id="model-name"
							:options="getModelsForProvider()"
							@input="saveSettings" />
						<p class="setting-description">
							Select the LLM model to use for AI features
						</p>
					</div>

					<div class="setting-item">
						<label for="temperature">Temperature: {{ llmSettings.temperature }}</label>
						<input id="temperature"
							v-model.number="llmSettings.temperature"
							type="range"
							min="0"
							max="2"
							step="0.1"
							@change="saveSettings">
						<p class="setting-description">
							Controls randomness (0 = deterministic, 2 = very creative)
						</p>
					</div>

					<div class="setting-item">
						<label for="max-tokens">Max Tokens</label>
						<input id="max-tokens"
							v-model.number="llmSettings.maxTokens"
							type="number"
							min="100"
							max="32000"
							step="100"
							@change="saveSettings">
						<p class="setting-description">
							Maximum length of generated responses
						</p>
					</div>
				</div>
			</div>

			<!-- AI Features -->
			<div v-if="llmSettings.enabled" class="settings-card">
				<h4>✨ AI Features</h4>
				<div class="settings-group">
					<div class="features-grid">
						<NcCheckboxRadioSwitch v-for="feature in aiFeatures"
							:key="feature.id"
							:checked.sync="feature.enabled"
							type="checkbox"
							@update:checked="saveSettings">
							<span class="feature-label">
								{{ feature.icon }} {{ feature.label }}
							</span>
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</div>

			<!-- Usage & Limits -->
			<div v-if="llmSettings.enabled && usageStats" class="settings-card">
				<h4>📊 Usage & Limits</h4>
				<div class="usage-stats">
					<div class="usage-item">
						<span class="usage-label">Requests Today:</span>
						<span class="usage-value">{{ usageStats.requestsToday }} / {{ usageStats.dailyLimit }}</span>
					</div>
					<div class="usage-item">
						<span class="usage-label">Tokens Used:</span>
						<span class="usage-value">{{ formatNumber(usageStats.tokensUsed) }}</span>
					</div>
					<div class="usage-item">
						<span class="usage-label">Estimated Cost:</span>
						<span class="usage-value">${{ usageStats.estimatedCost.toFixed(2) }}</span>
					</div>
				</div>
			</div>

			<!-- Save Status -->
			<div v-if="saveMessage" class="save-message" :class="saveMessageType">
				{{ saveMessage }}
			</div>
		</div>

		<NcLoadingIcon v-else
			class="loading-icon"
			:size="64"
			appearance="dark" />
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'

import {
	NcSettingsSection,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcButton,
} from '@nextcloud/vue'

import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EyeOffIcon from 'vue-material-design-icons/EyeOff.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

/**
 * @class LlmConfiguration
 * @module Components
 * @package Settings
 * 
 * LLM configuration settings component for managing AI/LLM integration.
 * Allows users to configure LLM providers, models, and AI features.
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
 */
export default {
	name: 'LlmConfiguration',

	components: {
		NcSettingsSection,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcButton,
		EyeIcon,
		EyeOffIcon,
		CheckIcon,
	},

	data() {
		return {
			llmSettings: {
				enabled: false,
				provider: null,
				apiEndpoint: '',
				apiKey: '',
				model: null,
				temperature: 0.7,
				maxTokens: 2000,
			},
			providers: [
				{
					id: 'none',
					label: 'None',
					description: 'Disable LLM features',
					icon: '🚫',
				},
				{
					id: 'openai',
					label: 'OpenAI',
					description: 'GPT-4, GPT-3.5 Turbo, etc.',
					icon: '🤖',
				},
				{
					id: 'anthropic',
					label: 'Anthropic',
					description: 'Claude 3 Opus, Sonnet, Haiku',
					icon: '🧠',
				},
				{
					id: 'ollama',
					label: 'Ollama (Local)',
					description: 'Run models locally',
					icon: '🏠',
				},
				{
					id: 'azure',
					label: 'Azure OpenAI',
					description: 'Microsoft Azure OpenAI Service',
					icon: '☁️',
				},
			],
			aiFeatures: [
				{ id: 'text_generation', label: 'Text Generation', icon: '✍️', enabled: true },
				{ id: 'summarization', label: 'Document Summarization', icon: '📋', enabled: true },
				{ id: 'semantic_search', label: 'Semantic Search', icon: '🔍', enabled: true },
				{ id: 'embedding', label: 'Text Embeddings', icon: '🧮', enabled: true },
				{ id: 'translation', label: 'Translation', icon: '🌍', enabled: false },
				{ id: 'classification', label: 'Content Classification', icon: '🏷️', enabled: false },
			],
			usageStats: null,
			showApiKey: false,
			testingConnection: false,
			connectionStatus: null,
			saveMessage: '',
			saveMessageType: 'success',
		}
	},

	computed: {
		...mapStores(useSettingsStore),
	},

	async mounted() {
		await this.loadSettings()
		await this.loadUsageStats()
	},

	methods: {
		/**
		 * Load LLM configuration settings
		 */
		async loadSettings() {
			try {
				const settings = await this.settingsStore.getLlmSettings()
				if (settings) {
					this.llmSettings = { ...this.llmSettings, ...settings }
					// Find provider object from ID
					this.llmSettings.provider = this.providers.find(p => p.id === settings.providerId) || this.providers[0]
				}
			} catch (error) {
				console.error('Failed to load LLM settings:', error)
			}
		},

		/**
		 * Save LLM configuration settings
		 */
		async saveSettings() {
			try {
				await this.settingsStore.saveLlmSettings({
					...this.llmSettings,
					providerId: this.llmSettings.provider?.id,
					enabledFeatures: this.aiFeatures
						.filter(f => f.enabled)
						.map(f => f.id),
				})
				
				this.showSaveMessage('Settings saved successfully', 'success')
			} catch (error) {
				console.error('Failed to save LLM settings:', error)
				this.showSaveMessage('Failed to save settings', 'error')
			}
		},

		/**
		 * Handle provider change
		 */
		onProviderChange(provider) {
			this.llmSettings.provider = provider
			this.llmSettings.apiEndpoint = ''
			this.connectionStatus = null
			this.saveSettings()
		},

		/**
		 * Get default endpoint for provider
		 */
		getDefaultEndpoint() {
			switch (this.llmSettings.provider?.id) {
			case 'openai':
				return 'https://api.openai.com/v1'
			case 'anthropic':
				return 'https://api.anthropic.com/v1'
			case 'ollama':
				return 'http://localhost:11434'
			case 'azure':
				return 'https://<resource>.openai.azure.com'
			default:
				return ''
			}
		},

		/**
		 * Get available models for selected provider
		 */
		getModelsForProvider() {
			const models = {
				openai: [
					{ id: 'gpt-4-turbo', label: 'GPT-4 Turbo' },
					{ id: 'gpt-4', label: 'GPT-4' },
					{ id: 'gpt-3.5-turbo', label: 'GPT-3.5 Turbo' },
				],
				anthropic: [
					{ id: 'claude-3-opus', label: 'Claude 3 Opus' },
					{ id: 'claude-3-sonnet', label: 'Claude 3 Sonnet' },
					{ id: 'claude-3-haiku', label: 'Claude 3 Haiku' },
				],
				ollama: [
					{ id: 'llama2', label: 'Llama 2' },
					{ id: 'mistral', label: 'Mistral' },
					{ id: 'codellama', label: 'Code Llama' },
				],
				azure: [
					{ id: 'gpt-4', label: 'GPT-4' },
					{ id: 'gpt-35-turbo', label: 'GPT-3.5 Turbo' },
				],
			}
			return models[this.llmSettings.provider?.id] || []
		},

		/**
		 * Test API connection
		 */
		async testConnection() {
			this.testingConnection = true
			this.connectionStatus = null
			
			try {
				const result = await this.settingsStore.testLlmConnection({
					provider: this.llmSettings.provider.id,
					apiEndpoint: this.llmSettings.apiEndpoint || this.getDefaultEndpoint(),
					apiKey: this.llmSettings.apiKey,
				})
				
				this.connectionStatus = {
					success: result.success,
					message: result.success ? '✓ Connection successful!' : '✗ Connection failed: ' + result.error,
				}
			} catch (error) {
				this.connectionStatus = {
					success: false,
					message: '✗ Connection failed: ' + error.message,
				}
			} finally {
				this.testingConnection = false
			}
		},

		/**
		 * Load usage statistics
		 */
		async loadUsageStats() {
			if (!this.llmSettings.enabled) return
			
			try {
				this.usageStats = await this.settingsStore.getLlmUsageStats()
			} catch (error) {
				console.error('Failed to load usage stats:', error)
			}
		},

		/**
		 * Format number with thousands separator
		 */
		formatNumber(num) {
			return new Intl.NumberFormat().format(num)
		},

		/**
		 * Show save message
		 */
		showSaveMessage(message, type = 'success') {
			this.saveMessage = message
			this.saveMessageType = type
			setTimeout(() => {
				this.saveMessage = ''
			}, 3000)
		},
	},
}
</script>

<style scoped>
.llm-settings {
	margin-top: 20px;
}

.settings-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 20px;
	margin-bottom: 20px;
}

.settings-card h4 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 16px;
}

.settings-group {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.setting-item {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.setting-item label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-size: 14px;
}

.setting-item input[type="url"],
.setting-item input[type="number"] {
	max-width: 400px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
}

.setting-item input[type="range"] {
	max-width: 400px;
}

.api-key-input {
	display: flex;
	gap: 8px;
	max-width: 400px;
}

.api-key-input input {
	flex: 1;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
}

.setting-description {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
	line-height: 1.4;
}

.option-item {
	display: flex;
	align-items: center;
	gap: 12px;
}

.option-icon {
	font-size: 24px;
	flex-shrink: 0;
}

.option-content {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.option-label {
	font-weight: 500;
}

.option-description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
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

.connection-status {
	padding: 8px 12px;
	border-radius: var(--border-radius);
	font-size: 14px;
	margin-top: 8px;
	display: inline-block;
}

.connection-status.success {
	background: var(--color-success);
	color: white;
}

.connection-status.error {
	background: var(--color-error);
	color: white;
}

.usage-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.usage-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.usage-label {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
}

.usage-value {
	color: var(--color-text-light);
	font-size: 20px;
	font-weight: bold;
}

.save-message {
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-top: 16px;
	text-align: center;
	font-weight: 500;
}

.save-message.success {
	background: var(--color-success);
	color: white;
}

.save-message.error {
	background: var(--color-error);
	color: white;
}

.loading-icon {
	margin: 40px auto;
	display: block;
}

@media (max-width: 768px) {
	.features-grid {
		grid-template-columns: 1fr;
	}

	.usage-stats {
		grid-template-columns: 1fr;
	}

	.api-key-input {
		max-width: 100%;
	}

	.setting-item input[type="url"],
	.setting-item input[type="number"],
	.setting-item input[type="range"] {
		max-width: 100%;
	}
}
</style>

