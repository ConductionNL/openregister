<template>
	<NcDialog v-if="show"
		:name="t('openregister', 'LLM Configuration')"
		size="large"
		@closing="$emit('closing')">
		<div class="llm-config-content">
			<!-- Info box -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>
					{{ t('openregister', 'Configure Large Language Model (LLM) providers for AI-powered features including semantic search, embeddings, and chat.') }}
				</p>
			</div>

			<!-- Provider Selection (Two Columns) -->
			<div class="providers-grid">
				<!-- Embedding Provider -->
				<div class="provider-column">
					<h3>{{ t('openregister', 'Embedding Provider') }}</h3>
					<p class="section-description">
						{{ t('openregister', 'For vector embeddings and semantic search') }}
					</p>

					<NcSelect
						v-model="selectedEmbeddingProvider"
						:options="embeddingProviderOptions"
						label="name"
						:placeholder="t('openregister', 'Select provider')"
						@input="handleEmbeddingProviderChange">
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
						{{ t('openregister', 'For chat and retrieval-augmented generation') }}
					</p>

					<NcSelect
						v-model="selectedChatProvider"
						:options="chatProviderOptions"
						label="name"
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
			<div v-if="selectedEmbeddingProvider && selectedEmbeddingProvider.id === 'openai'" class="config-section">
				<h3>{{ t('openregister', 'OpenAI Embedding Configuration') }}</h3>

				<div class="form-group">
					<label for="openai-api-key">{{ t('openregister', 'API Key') }}</label>
					<input
						id="openai-api-key"
						v-model="openaiConfig.apiKey"
						type="password"
						:placeholder="t('openregister', 'sk-...')"
						class="input-field">
					<small>{{ t('openregister', 'Your OpenAI API key. Get one at') }} <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></small>
				</div>

				<div class="form-group">
					<label for="openai-model">{{ t('openregister', 'Embedding Model') }}</label>
					<NcSelect
						v-model="openaiConfig.model"
						:options="openaiModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select model')"
						:label-outside="true">
						<template #option="{ name, dimensions, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small>{{ dimensions }} dimensions • {{ cost }}</small>
							</div>
						</template>
					</NcSelect>
				</div>

				<div class="form-group">
					<label for="openai-org">{{ t('openregister', 'Organization ID (Optional)') }}</label>
					<input
						id="openai-org"
						v-model="openaiConfig.organizationId"
						type="text"
						:placeholder="t('openregister', 'org-...')"
						class="input-field">
				</div>
			</div>

			<!-- Ollama Embedding Configuration -->
			<div v-if="selectedEmbeddingProvider && selectedEmbeddingProvider.id === 'ollama'" class="config-section">
				<h3>{{ t('openregister', 'Ollama Embedding Configuration') }}</h3>

				<div class="form-group">
					<label for="ollama-url">{{ t('openregister', 'Ollama URL') }}</label>
					<input
						id="ollama-url"
						v-model="ollamaConfig.url"
						type="text"
						:placeholder="t('openregister', 'http://localhost:11434')"
						class="input-field">
					<small>{{ t('openregister', 'URL where Ollama is running') }}</small>
				</div>

				<div class="form-group">
					<label for="ollama-model">{{ t('openregister', 'Model Name') }}</label>
					<NcSelect
						id="ollama-model"
						v-model="ollamaConfig.model"
						:options="ollamaModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select model')"
						:label-outside="true"
						:taggable="true"
						:create-option="(option) => ({ id: option, name: option })">
						<template #option="{ name, description }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small v-if="description">{{ description }}</small>
							</div>
						</template>
					</NcSelect>
					<small>{{ t('openregister', 'Select a model or type a custom model name') }}</small>
				</div>
			</div>

			<!-- Fireworks Embedding Configuration -->
			<div v-if="selectedEmbeddingProvider && selectedEmbeddingProvider.id === 'fireworks'" class="config-section">
				<h3>{{ t('openregister', 'Fireworks AI Embedding Configuration') }}</h3>

				<div class="form-group">
					<label for="fireworks-api-key">{{ t('openregister', 'API Key') }}</label>
					<input
						id="fireworks-api-key"
						v-model="fireworksConfig.apiKey"
						type="password"
						:placeholder="t('openregister', 'fw_...')"
						class="input-field">
					<small>{{ t('openregister', 'Your Fireworks AI API key. Get one at') }} <a href="https://fireworks.ai" target="_blank">fireworks.ai</a></small>
				</div>

				<div class="form-group">
					<label for="fireworks-embedding-model">{{ t('openregister', 'Embedding Model') }}</label>
					<NcSelect
						v-model="fireworksConfig.embeddingModel"
						:options="fireworksEmbeddingModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select model')"
						:label-outside="true">
						<template #option="{ name, dimensions, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small>{{ dimensions }} dimensions • {{ cost }}</small>
							</div>
						</template>
					</NcSelect>
				</div>

				<div class="form-group">
					<label for="fireworks-base-url">{{ t('openregister', 'Base URL (Optional)') }}</label>
					<input
						id="fireworks-base-url"
						v-model="fireworksConfig.baseUrl"
						type="text"
						:placeholder="t('openregister', 'https://api.fireworks.ai/inference/v1')"
						class="input-field">
					<small>{{ t('openregister', 'Custom API endpoint if using a different region') }}</small>
				</div>
			</div>

			<!-- Chat Provider Configuration -->
			<!-- OpenAI Chat Configuration -->
			<div v-if="selectedChatProvider && selectedChatProvider.id === 'openai'" class="config-section">
				<h3>{{ t('openregister', 'OpenAI Chat Settings') }}</h3>

				<div v-if="selectedEmbeddingProvider?.id !== 'openai'" class="form-group">
					<label for="openai-chat-api-key">{{ t('openregister', 'API Key') }}</label>
					<input
						id="openai-chat-api-key"
						v-model="openaiConfig.apiKey"
						type="password"
						:placeholder="t('openregister', 'sk-...')"
						class="input-field">
					<small>{{ t('openregister', 'Your OpenAI API key. Get one at') }} <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></small>
				</div>

				<div class="form-group">
					<label for="openai-chat-model">{{ t('openregister', 'Chat Model') }}</label>
					<NcSelect
						v-model="openaiConfig.chatModel"
						:options="openaiChatModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select chat model')"
						:label-outside="true">
						<template #option="{ name, contextWindow, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small>{{ contextWindow }} tokens • {{ cost }}/1M tokens</small>
							</div>
						</template>
					</NcSelect>
				</div>
			</div>

			<!-- Fireworks Chat Configuration -->
			<div v-if="selectedChatProvider && selectedChatProvider.id === 'fireworks'" class="config-section">
				<h3>{{ t('openregister', 'Fireworks AI Chat Settings') }}</h3>

				<div v-if="selectedEmbeddingProvider?.id !== 'fireworks'" class="form-group">
					<label for="fireworks-chat-api-key">{{ t('openregister', 'API Key') }}</label>
					<input
						id="fireworks-chat-api-key"
						v-model="fireworksConfig.apiKey"
						type="password"
						:placeholder="t('openregister', 'fw_...')"
						class="input-field">
					<small>{{ t('openregister', 'Your Fireworks AI API key. Get one at') }} <a href="https://fireworks.ai" target="_blank">fireworks.ai</a></small>
				</div>

				<div class="form-group">
					<label for="fireworks-chat-model">{{ t('openregister', 'Chat Model') }}</label>
					<NcSelect
						v-model="fireworksConfig.chatModel"
						:options="fireworksChatModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select chat model')"
						:label-outside="true">
						<template #option="{ name, contextWindow, cost }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small>{{ contextWindow }} tokens • {{ cost }}</small>
							</div>
						</template>
					</NcSelect>
				</div>
			</div>

			<!-- Ollama Chat Configuration -->
			<div v-if="selectedChatProvider && selectedChatProvider.id === 'ollama'" class="config-section">
				<h3>{{ t('openregister', 'Ollama Chat Settings') }}</h3>

				<div v-if="selectedEmbeddingProvider?.id !== 'ollama'" class="form-group">
					<label for="ollama-chat-url">{{ t('openregister', 'Ollama URL') }}</label>
					<input
						id="ollama-chat-url"
						v-model="ollamaConfig.url"
						type="text"
						:placeholder="t('openregister', 'http://localhost:11434')"
						class="input-field">
					<small>{{ t('openregister', 'URL where Ollama is running') }}</small>
				</div>

				<div class="form-group">
					<label for="ollama-chat-model">{{ t('openregister', 'Chat Model') }}</label>
					<NcSelect
						id="ollama-chat-model"
						v-model="ollamaConfig.chatModel"
						:options="ollamaModelOptions"
						label="name"
						:placeholder="t('openregister', 'Select model')"
						:label-outside="true"
						:taggable="true"
						:create-option="(option) => ({ id: option, name: option })">
						<template #option="{ name, description }">
							<div class="model-option">
								<strong>{{ name }}</strong>
								<small v-if="description">{{ description }}</small>
							</div>
						</template>
					</NcSelect>
					<small>{{ t('openregister', 'Select a model or type a custom model name') }}</small>
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
					v-if="selectedEmbeddingProvider && selectedEmbeddingProvider.id !== 'none'"
					type="secondary"
					:disabled="testingEmbedding || !canTestEmbedding"
					@click="testEmbeddingConnection">
					<template #icon>
						<NcLoadingIcon v-if="testingEmbedding" :size="20" />
						<TestTube v-else :size="20" />
					</template>
					{{ testingEmbedding ? t('openregister', 'Testing...') : t('openregister', 'Test Embedding') }}
				</NcButton>

				<!-- Test Chat Provider -->
				<NcButton
					v-if="selectedChatProvider && selectedChatProvider.id !== 'none'"
					type="secondary"
					:disabled="testingChat || !canTestChat"
					@click="testChatConnection">
					<template #icon>
						<NcLoadingIcon v-if="testingChat" :size="20" />
						<TestTube v-else :size="20" />
					</template>
					{{ testingChat ? t('openregister', 'Testing...') : t('openregister', 'Test Chat') }}
				</NcButton>

				<!-- Test Results -->
				<div v-if="embeddingTestResult" class="test-result-inline" :class="embeddingTestResult.success ? 'success' : 'error'">
					{{ embeddingTestResult.success ? '✅' : '❌' }} Embedding: {{ embeddingTestResult.message }}
				</div>
				<div v-if="chatTestResult" class="test-result-inline" :class="chatTestResult.success ? 'success' : 'error'">
					{{ chatTestResult.success ? '✅' : '❌' }} Chat: {{ chatTestResult.message }}
				</div>
			</div>

			<div class="actions-right">
				<NcButton @click="$emit('closing')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving"
					@click="saveConfiguration">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ saving ? t('openregister', 'Saving...') : t('openregister', 'Save Configuration') }}
				</NcButton>
			</div>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

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
		ContentSave,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			saving: false,
			testingEmbedding: false,
			testingChat: false,
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

			embeddingProviderOptions: [
				{ id: 'openai', name: 'OpenAI', description: 'State-of-the-art embeddings (paid)' },
				{ id: 'fireworks', name: 'Fireworks AI', description: 'Fast, optimized inference (paid)' },
				{ id: 'ollama', name: 'Ollama', description: 'Local models (free, requires Ollama)' },
			],

			ollamaModelOptions: [
				{ id: 'llama2', name: 'llama2', description: 'Meta\'s Llama 2 model' },
				{ id: 'llama3', name: 'llama3', description: 'Meta\'s Llama 3 model' },
				{ id: 'mistral', name: 'mistral', description: 'Mistral 7B model' },
				{ id: 'mixtral', name: 'mixtral', description: 'Mistral\'s Mixtral 8x7B model' },
				{ id: 'phi', name: 'phi', description: 'Microsoft\'s Phi model' },
				{ id: 'codellama', name: 'codellama', description: 'Code-specialized Llama model' },
				{ id: 'gemma', name: 'gemma', description: 'Google\'s Gemma model' },
				{ id: 'neural-chat', name: 'neural-chat', description: 'Intel\'s Neural Chat model' },
				{ id: 'starling-lm', name: 'starling-lm', description: 'Starling language model' },
				{ id: 'orca-mini', name: 'orca-mini', description: 'Microsoft\'s Orca Mini' },
			],

			chatProviderOptions: [
				{ id: 'openai', name: 'OpenAI ChatGPT', description: 'GPT-4, GPT-3.5 models' },
				{ id: 'fireworks', name: 'Fireworks AI', description: 'Fast OSS models' },
				{ id: 'ollama', name: 'Ollama', description: 'Local LLMs' },
			],

			openaiModelOptions: [
				{ id: 'text-embedding-ada-002', name: 'text-embedding-ada-002', dimensions: '1536', cost: '$0.10/1M tokens' },
				{ id: 'text-embedding-3-small', name: 'text-embedding-3-small', dimensions: '1536', cost: '$0.02/1M tokens' },
				{ id: 'text-embedding-3-large', name: 'text-embedding-3-large', dimensions: '3072', cost: '$0.13/1M tokens' },
			],

			openaiChatModelOptions: [
				{ id: 'gpt-4-turbo', name: 'GPT-4 Turbo', contextWindow: '128K', cost: '$10.00/$30.00' },
				{ id: 'gpt-4', name: 'GPT-4', contextWindow: '8K', cost: '$30.00/$60.00' },
				{ id: 'gpt-3.5-turbo', name: 'GPT-3.5 Turbo', contextWindow: '16K', cost: '$0.50/$1.50' },
			],

			fireworksEmbeddingModelOptions: [
				{ id: 'nomic-ai/nomic-embed-text-v1.5', name: 'Nomic Embed Text v1.5', dimensions: '768', cost: '$0.008/1M tokens' },
				{ id: 'WhereIsAI/UAE-Large-V1', name: 'UAE Large V1', dimensions: '1024', cost: '$0.016/1M tokens' },
				{ id: 'thenlper/gte-large', name: 'GTE Large', dimensions: '1024', cost: '$0.016/1M tokens' },
				{ id: 'thenlper/gte-base', name: 'GTE Base', dimensions: '768', cost: '$0.008/1M tokens' },
			],

			fireworksChatModelOptions: [
				{ id: 'accounts/fireworks/models/llama-v3p3-70b-instruct', name: 'Llama 3.3 70B', contextWindow: '131K', cost: '$0.9/1M' },
				{ id: 'accounts/fireworks/models/llama-v3p1-405b-instruct', name: 'Llama 3.1 405B', contextWindow: '131K', cost: '$3/1M' },
				{ id: 'accounts/fireworks/models/llama-v3p1-70b-instruct', name: 'Llama 3.1 70B', contextWindow: '131K', cost: '$0.9/1M' },
				{ id: 'accounts/fireworks/models/llama-v3p1-8b-instruct', name: 'Llama 3.1 8B', contextWindow: '131K', cost: '$0.2/1M' },
				{ id: 'accounts/fireworks/models/qwen2p5-72b-instruct', name: 'Qwen 2.5 72B', contextWindow: '32K', cost: '$0.9/1M' },
				{ id: 'accounts/fireworks/models/deepseek-r1', name: 'DeepSeek R1', contextWindow: '163K', cost: '$3/1M' },
				{ id: 'accounts/fireworks/models/mixtral-8x22b-instruct', name: 'Mixtral 8x22B', contextWindow: '64K', cost: '$1.2/1M' },
			],

			aiFeatures: [
				{ id: 'text_generation', label: 'Text Generation', icon: '✍️', enabled: true },
				{ id: 'summarization', label: 'Document Summarization', icon: '📋', enabled: true },
				{ id: 'semantic_search', label: 'Semantic Search', icon: '🔍', enabled: true },
				{ id: 'embedding', label: 'Text Embeddings', icon: '🧮', enabled: true },
				{ id: 'translation', label: 'Translation', icon: '🌍', enabled: false },
				{ id: 'classification', label: 'Content Classification', icon: '🏷️', enabled: false },
			],
		}
	},

	computed: {
		canTestEmbedding() {
			const provider = this.selectedEmbeddingProvider?.id
			if (!provider) return false

			if (provider === 'openai') {
				return !!this.openaiConfig.apiKey && !!this.openaiConfig.model
			} else if (provider === 'fireworks') {
				return !!this.fireworksConfig.apiKey && !!this.fireworksConfig.embeddingModel
			} else if (provider === 'ollama') {
				return !!this.ollamaConfig.url && !!this.ollamaConfig.model
			}
			return false
		},

		canTestChat() {
			const provider = this.selectedChatProvider?.id
			if (!provider) return false

			if (provider === 'openai') {
				return !!this.openaiConfig.apiKey && !!this.openaiConfig.chatModel
			} else if (provider === 'fireworks') {
				return !!this.fireworksConfig.apiKey && !!this.fireworksConfig.chatModel
			} else if (provider === 'ollama') {
				return !!this.ollamaConfig.url && !!this.ollamaConfig.chatModel
			}
			return false
		},
	},

	mounted() {
		this.loadConfiguration()
	},

	methods: {
		async loadConfiguration() {
			// TODO: Load saved configuration from backend
			this.loading = false
		},

		handleEmbeddingProviderChange() {
			this.embeddingTestResult = null
		},

		async testEmbeddingConnection() {
			this.testingEmbedding = true
			this.embeddingTestResult = null

			try {
				let config = {}
				const provider = this.selectedEmbeddingProvider?.id

				if (provider === 'openai') {
					config = {
						apiKey: this.openaiConfig.apiKey,
						model: this.openaiConfig.model?.id || this.openaiConfig.model,
					}
				} else if (provider === 'fireworks') {
					config = {
						apiKey: this.fireworksConfig.apiKey,
						model: this.fireworksConfig.embeddingModel?.id || this.fireworksConfig.embeddingModel,
						baseUrl: this.fireworksConfig.baseUrl,
					}
				} else if (provider === 'ollama') {
					config = {
						url: this.ollamaConfig.url,
						model: this.ollamaConfig.model?.id || this.ollamaConfig.model,
					}
				}

				await axios.post(generateUrl('/apps/openregister/api/vectors/test-embedding'), {
					provider,
					config,
					testText: 'This is a test embedding generation.',
				})

				this.embeddingTestResult = {
					success: true,
					message: 'Connected',
				}
				showSuccess(this.t('openregister', 'Embedding provider connection successful!'))
			} catch (error) {
				this.embeddingTestResult = {
					success: false,
					message: 'Failed',
				}
				showError(this.t('openregister', 'Embedding test failed: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.testingEmbedding = false
			}
		},

		async testChatConnection() {
			this.testingChat = true
			this.chatTestResult = null

			try {
				let config = {}
				const provider = this.selectedChatProvider?.id

				if (provider === 'openai') {
					config = {
						apiKey: this.openaiConfig.apiKey,
						model: this.openaiConfig.chatModel?.id || this.openaiConfig.chatModel,
					}
				} else if (provider === 'fireworks') {
					config = {
						apiKey: this.fireworksConfig.apiKey,
						model: this.fireworksConfig.chatModel?.id || this.fireworksConfig.chatModel,
						baseUrl: this.fireworksConfig.baseUrl,
					}
				} else if (provider === 'ollama') {
					config = {
						url: this.ollamaConfig.url,
						model: this.ollamaConfig.chatModel?.id || this.ollamaConfig.chatModel,
					}
				}

				await axios.post(generateUrl('/apps/openregister/api/llm/test-chat'), {
					provider,
					config,
					testMessage: 'Hello! Please respond with a brief greeting.',
				})

				this.chatTestResult = {
					success: true,
					message: 'Connected',
				}
				showSuccess(this.t('openregister', 'Chat provider connection successful!'))
			} catch (error) {
				this.chatTestResult = {
					success: false,
					message: 'Failed',
				}
				showError(this.t('openregister', 'Chat test failed: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.testingChat = false
			}
		},

		async saveConfiguration() {
			this.saving = true

			try {
				await axios.post(generateUrl('/apps/openregister/api/settings/llm'), {
					embeddingProvider: this.selectedEmbeddingProvider?.id,
					chatProvider: this.selectedChatProvider?.id,
					openaiConfig: this.openaiConfig,
					ollamaConfig: this.ollamaConfig,
					fireworksConfig: this.fireworksConfig,
					enabledFeatures: this.aiFeatures
						.filter(f => f.enabled)
						.map(f => f.id),
				})

				showSuccess(this.t('openregister', 'LLM configuration saved successfully'))
				this.$emit('closing')
			} catch (error) {
				showError(this.t('openregister', 'Failed to save configuration: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
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

.provider-column {
	h3 {
		margin: 0 0 8px 0;
		font-size: 16px;
		font-weight: 600;
	}

	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}

.config-section {
	margin-bottom: 32px;

	h3 {
		margin: 0 0 8px 0;
		font-size: 16px;
		font-weight: 600;
	}

	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
	}
}

.form-group {
	margin-bottom: 20px;

	label {
		display: block;
		margin-bottom: 8px;
		font-weight: 500;
	}

	.input-field {
		width: 100%;
		padding: 10px 12px;
		border: 1px solid var(--color-border);
		border-radius: 6px;
		font-size: 14px;
		background: var(--color-main-background);
		color: var(--color-main-text);

		&:focus {
			outline: none;
			border-color: var(--color-primary-element);
		}
	}

	small {
		display: block;
		margin-top: 6px;
		color: var(--color-text-maxcontrast);
		font-size: 12px;

		a {
			color: var(--color-primary-element);
			text-decoration: none;

			&:hover {
				text-decoration: underline;
			}
		}
	}
}

.provider-option,
.model-option {
	display: flex;
	flex-direction: column;
	gap: 4px;

	strong {
		font-size: 14px;
	}

	small {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
	}
}

.test-result {
	margin-top: 16px;
	padding: 16px;
	border-radius: 8px;

	&.success {
		background: var(--color-success);
		color: white;
	}

	&.error {
		background: var(--color-error);
		color: white;
	}

	p {
		margin: 0 0 8px 0;
	}

	pre {
		margin: 0;
		padding: 12px;
		background: rgba(0, 0, 0, 0.2);
		border-radius: 4px;
		font-size: 12px;
		overflow-x: auto;
	}
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

.slider {
	width: 100%;
	max-width: 400px;
}

/* Actions layout */
:deep(.dialog__actions) {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.actions-left {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.actions-right {
	display: flex;
	gap: 8px;
	margin-left: auto;
}

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
