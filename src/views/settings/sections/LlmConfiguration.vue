<template>
	<SettingsSection
		id="section-llm"
		:name="t('openregister', 'LLM Configuration')"
		description="Configure Large Language Model settings for AI features"
		:loading="loadingStats"
		:loadingMessage="t('openregister', 'Loading LLM configuration…')">
		<template #actions>
			<!-- LLM Actions Menu -->
			<NcActions
				:aria-label="t('openregister', 'LLM actions menu')"
				:menuName="t('openregister', 'Actions')">
				<template #icon>
					<DotsVertical :size="20" />
				</template>

				<!-- LLM Configuration -->
				<NcActionButton @click="showLLMConfigDialog = true">
					<template #icon>
						<Robot :size="20" />
					</template>
					{{ t('openregister', 'LLM Configuration') }}
				</NcActionButton>

				<!-- File Management -->
				<NcActionButton @click="showFileManagementDialog = true">
					<template #icon>
						<FileDocument :size="20" />
					</template>
					{{ t('openregister', 'File Management') }}
				</NcActionButton>

				<!-- Object Management -->
				<NcActionButton @click="showObjectManagementDialog = true">
					<template #icon>
						<CubeOutline :size="20" />
					</template>
					{{ t('openregister', 'Object Management') }}
				</NcActionButton>

				<!-- Separator -->
				<NcActionSeparator />

				<!-- Vectorize All Objects -->
				<NcActionButton
					:disabled="!llmSettings.enabled"
					@click="showVectorizeObjectsDialog">
					<template #icon>
						<VectorSquare :size="20" />
					</template>
					{{ t('openregister', 'Vectorize All Objects') }}
				</NcActionButton>

				<!-- Vectorize All Files -->
				<NcActionButton
					:disabled="!llmSettings.enabled"
					@click="showVectorizeFilesDialog">
					<template #icon>
						<FileVectorOutline :size="20" />
					</template>
					{{ t('openregister', 'Vectorize All Files') }}
				</NcActionButton>
			</NcActions>
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Large Language Models (LLMs) power AI features including semantic
				search, text generation, document summarization, and content
				classification. When enabled, OpenRegister will automatically
				vectorize objects and files for semantic search capabilities and
				provide AI-powered content understanding. Choose from providers like
				OpenAI, Fireworks AI, Ollama (local), or Azure OpenAI.
			</p>
			<p class="main-description info-note">
				<strong>📝 Note:</strong> File vectorization requires text extraction
				to be enabled. Large files are split into smaller
				<strong>chunks</strong> (text portions), which are then converted
				into <strong>embeddings</strong> (vector representations) for
				semantic search. Without text extraction, files cannot be processed
				into chunks and embeddings.
			</p>
			<p class="toggle-status">
				<strong>Current Status:</strong>
				<span
					:class="
						llmSettings.enabled ? 'status-enabled' : 'status-disabled'
					">
					{{
						llmSettings.enabled
							? 'LLM features enabled'
							: 'LLM features disabled'
					}}
				</span>
			</p>
		</div>

		<!-- Enable LLM Toggle -->
		<div class="option-section">
			<NcCheckboxRadioSwitch
				v-model="llmSettings.enabled"
				:disabled="saving"
				type="switch"
				@update:modelValue="onLlmEnabledChange">
				{{
					llmSettings.enabled
						? t('openregister', 'LLM features enabled')
						: t('openregister', 'LLM features disabled')
				}}
			</NcCheckboxRadioSwitch>
			<p class="option-description">
				{{
					t(
						'openregister',
						'Enable or disable LLM features. Configure providers and models using the LLM configuration button above.',
					)
				}}
				<span v-if="saving" class="saving-indicator">
					<NcLoadingIcon :size="14" /> {{ t('openregister', 'Saving…') }}
				</span>
			</p>
		</div>

		<!-- Provider Configuration Info -->
		<div class="provider-info-grid">
			<div class="provider-info-card">
				<h5>Embedding Provider</h5>
				<p v-if="providerConfig.embeddingProvider" class="provider-name">
					{{ getProviderDisplayName(providerConfig.embeddingProvider) }}
				</p>
				<p v-else class="not-configured">Not configured</p>
				<p v-if="providerConfig.embeddingModel" class="model-info">
					{{ providerConfig.embeddingModel }}
				</p>
			</div>

			<div class="provider-info-card">
				<h5>Chat Provider (RAG)</h5>
				<p v-if="providerConfig.chatProvider" class="provider-name">
					{{ getProviderDisplayName(providerConfig.chatProvider) }}
				</p>
				<p v-else class="not-configured">Not configured</p>
				<p v-if="providerConfig.chatModel" class="model-info">
					{{ providerConfig.chatModel }}
				</p>
			</div>

			<div
				class="provider-info-card"
				:class="{ 'warning-card': !databaseInfo.vectorSupport }">
				<div class="card-header">
					<h5>Vector Storage</h5>
					<NcButton
						variant="tertiary"
						:disabled="refreshingDatabase"
						:aria-label="t('openregister', 'Refresh database info')"
						@click="refreshDatabaseInfo">
						<template #icon>
							<NcLoadingIcon v-if="refreshingDatabase" :size="16" />
							<Refresh v-else :size="16" />
						</template>
					</NcButton>
				</div>
				<p class="provider-name">
					{{ databaseInfo.type }}
				</p>
				<p v-if="databaseInfo.version !== 'Unknown'" class="model-info">
					{{ databaseInfo.version }}
				</p>
				<p
					v-if="databaseInfo.recommendedPlugin"
					class="plugin-info"
					:class="{
						'warning-text': !databaseInfo.vectorSupport,
						'success-text': databaseInfo.vectorSupport,
					}">
					{{ databaseInfo.recommendedPlugin }}
				</p>
				<p
					v-if="databaseInfo.performanceNote"
					class="performance-note"
					:title="databaseInfo.performanceNote">
					{{ databaseInfo.performanceNote }}
				</p>
				<!-- PostgreSQL Extensions -->
				<div
					v-if="
						databaseInfo.extensions && databaseInfo.extensions.length > 0
					"
					class="extensions-section">
					<details>
						<summary>
							{{ t('openregister', 'Extensions') }} ({{
								databaseInfo.extensions.length
							}})
						</summary>
						<ul class="extensions-list">
							<li
								v-for="ext in databaseInfo.extensions"
								:key="ext.name"
								class="extension-item">
								<span class="ext-name">{{ ext.name }}</span>
								<span class="ext-version">v{{ ext.version }}</span>
							</li>
						</ul>
					</details>
				</div>
				<!-- Hybrid search readiness (hybrid-document-search) -->
				<div v-if="databaseInfo.hybridSearch" class="extensions-section">
					<details>
						<summary>
							{{ t('openregister', 'Hybrid search readiness') }}
						</summary>
						<ul class="extensions-list">
							<li class="extension-item">
								<span class="ext-name">{{
									t('openregister', 'pgvector ANN sidecar')
								}}</span>
								<span class="ext-version">
									{{
										databaseInfo.hybridSearch.annSidecarTable
											? '✓'
												+ (databaseInfo.hybridSearch
													.embeddingVectorDimension
													? ' ('
														+ databaseInfo.hybridSearch
															.embeddingVectorDimension
														+ 'd)'
													: '')
											: '✗'
									}}
								</span>
							</li>
							<li class="extension-item">
								<span class="ext-name">{{
									t('openregister', 'HNSW index')
								}}</span>
								<span class="ext-version">{{
									databaseInfo.hybridSearch.hnswIndex ? '✓' : '✗'
								}}</span>
							</li>
							<li class="extension-item">
								<span class="ext-name">{{
									t('openregister', 'Keyword GIN index')
								}}</span>
								<span class="ext-version">{{
									databaseInfo.hybridSearch.textSearchGinIndex
										? '✓'
										: '✗'
								}}</span>
							</li>
							<li class="extension-item">
								<span class="ext-name">{{
									t('openregister', 'Chunks vectorized')
								}}</span>
								<span class="ext-version">
									{{
										databaseInfo.hybridSearch.chunks.vectorized
									}}/{{ databaseInfo.hybridSearch.chunks.total }}
								</span>
							</li>
							<li class="extension-item">
								<span class="ext-name">{{
									t(
										'openregister',
										'Vectors on pgvector fast path',
									)
								}}</span>
								<span class="ext-version">
									{{
										databaseInfo.hybridSearch.vectors
											.pgvectorPopulated
									}}/{{ databaseInfo.hybridSearch.vectors.total }}
								</span>
							</li>
						</ul>
					</details>
				</div>
				<p v-if="databaseInfo.lastUpdated" class="last-updated">
					{{ t('openregister', 'Updated:') }}
					{{ formatDate(databaseInfo.lastUpdated) }}
				</p>
			</div>
		</div>

		<!-- Chat Statistics -->
		<div class="stats-grid">
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(chatStats.totalAgents) }}
				</div>
				<div class="stat-label">Agents</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(chatStats.totalConversations) }}
				</div>
				<div class="stat-label">Conversations</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(chatStats.totalMessages) }}
				</div>
				<div class="stat-label">Messages</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(vectorStats.totalVectors) }}
				</div>
				<div class="stat-label">Total Vectors</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(vectorStats.objectVectors) }}
				</div>
				<div class="stat-label">Object Embeddings</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(vectorStats.fileVectors) }}
				</div>
				<div class="stat-label">File Embeddings</div>
			</div>
		</div>

		<!-- LLM Dashboard (when enabled) -->
		<div v-if="llmSettings.enabled" class="llm-management-section">
			<!-- Loading State -->
			<div v-if="loadingStats" class="loading-section">
				<NcLoadingIcon :size="32" />
				<p>Loading LLM statistics...</p>
			</div>

			<!-- Error State -->
			<div v-else-if="llmError" class="error-section">
				<p class="error-message">❌ {{ llmErrorMessage }}</p>
				<NcButton variant="primary" @click="retryConnection">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Retry Connection
				</NcButton>
			</div>

			<!-- Connection Success -->
			<div v-else class="dashboard-section">
				<div class="connection-success">
					<span class="success-icon">✅</span>
					<span>{{ llmConnectionStatus }}</span>
					<NcButton variant="secondary" @click="retryConnection">
						<template #icon>
							<Refresh :size="16" />
						</template>
						Test Connection
					</NcButton>
				</div>
			</div>
		</div>

		<!-- LLM Configuration Modal -->
		<LLMConfigModal
			:show="showLLMConfigDialog"
			@closing="onLLMConfigClosed"
			@embeddingsCleared="loadAllStats" />

		<!-- File Management Modal -->
		<FileManagementModal
			:show="showFileManagementDialog"
			@closing="showFileManagementDialog = false" />

		<!-- Object Management Modal -->
		<ObjectManagementModal
			:show="showObjectManagementDialog"
			@closing="showObjectManagementDialog = false" />

		<!-- Object Vectorization Modal -->
		<ObjectVectorizationModal
			:show="showObjectVectorizationModal"
			@closing="showObjectVectorizationModal = false"
			@completed="loadAllStats" />

		<!-- File Vectorization Modal -->
		<FileVectorizationModal
			:show="showFileVectorizationModal"
			:extractionStats="settingsStore.extractionStats"
			:vectorStats="settingsStore.vectorStats"
			@closing="showFileVectorizationModal = false"
			@completed="loadAllStats" />
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActions,
	NcActionSeparator,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { mapStores } from 'pinia'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileVectorOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import VectorSquare from 'vue-material-design-icons/VectorSquare.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import FileManagementModal from '../../../modals/settings/FileManagementModal.vue'
import FileVectorizationModal from '../../../modals/settings/FileVectorizationModal.vue'
import LLMConfigModal from '../../../modals/settings/LLMConfigModal.vue'
import ObjectManagementModal from '../../../modals/settings/ObjectManagementModal.vue'
import ObjectVectorizationModal from '../../../modals/settings/ObjectVectorizationModal.vue'
import { freshState } from '../../../services/initialState.js'
import { useSettingsStore } from '../../../store/settings.js'

/**
 * Where each provider keeps its embedding model: [config key, model key].
 */
const EMBEDDING_MODELS = new Map([
	['openai', ['openaiConfig', 'model']],
	['fireworks', ['fireworksConfig', 'embeddingModel']],
	['ollama', ['ollamaConfig', 'model']],
])

/**
 * Where each provider keeps its chat model: [config key, model key].
 */
const CHAT_MODELS = new Map([
	['openai', ['openaiConfig', 'chatModel']],
	['fireworks', ['fireworksConfig', 'chatModel']],
	['ollama', ['ollamaConfig', 'chatModel']],
])

// Vector stats fields, each with how to read it from the stats object.
const VECTOR_COUNTS = [
	['totalVectors', (stats) => stats.total_vectors || 0],
	['objectVectors', (stats) => typedVectors(stats, 'object')],
	['fileVectors', (stats) => typedVectors(stats, 'file')],
	['storageMB', (stats) => stats.storage?.total_mb?.toFixed(1) || '0.0'],
]

/**
 * File stats fields and the response keys they come from.
 */
const FILE_COUNTS = {
	totalFiles: 'total_files',
	filesExtracted: 'files_extracted',
	vectorizedFiles: 'vectorized_files',
	pendingFiles: 'pending_files',
	totalChunks: 'total_chunks',
}

/**
 * Chat stats fields and the response keys they come from.
 */
const CHAT_COUNTS = {
	totalAgents: 'total_agents',
	totalConversations: 'total_conversations',
	totalMessages: 'total_messages',
}

/**
 * Object stats fields and the response keys they come from.
 */
const OBJECT_COUNTS = {
	totalObjects: 'total_objects',
	vectorizedObjects: 'vectorized_objects',
	pendingObjects: 'pending_objects',
	progressPercentage: 'percentage_complete',
}

/**
 * A provider's model name from the settings, or null.
 *
 * @param {object} settings The LLM settings
 * @param {Array<string>} field The config key and the model key
 * @return {string|null} The model name
 */
function providerModel(settings, [configKey, modelKey]) {
	return settings[configKey]?.[modelKey] || null
}

/**
 * The number of vectors of one type, by type first, then the flat count.
 *
 * @param {object} stats The vector stats
 * @param {string} type The vector type: object or file
 * @return {number} The count
 */
function typedVectors(stats, type) {
	return stats.by_type?.[type] || stats[`${type}_vectors`] || 0
}

/**
 * Copy the vector counts into the shown stats, one field at a time.
 *
 * @param {object} target The shown vector stats
 * @param {object} stats The vector stats from the API
 * @return {void}
 */
function assignVectorCounts(target, stats) {
	for (const [key, read] of VECTOR_COUNTS) {
		target[key] = read(stats)
	}
}

/**
 * Named counts read from a response object, 0 where one is missing.
 *
 * @param {object} source The response object
 * @param {object} fields Field name to response key
 * @return {object} The counts
 */
function countsOf(source, fields) {
	return Object.fromEntries(
		Object.entries(fields).map(([field, key]) => [field, source[key] || 0]),
	)
}

/**
 * The vector notes of a database, as the API reports them.
 *
 * @param {object} db The database info
 * @return {object} Vector support, recommended plugin and performance note
 */
function plainVectorInfo(db) {
	return {
		vectorSupport: db.vectorSupport,
		recommendedPlugin: db.recommendedPlugin,
		performanceNote: db.performanceNote,
	}
}

/**
 * The vector notes of a database whose own vector operations are in use.
 *
 * @param {object} db The database info
 * @return {object} Vector support, recommended plugin and performance note
 */
function nativeVectorInfo(db) {
	return {
		vectorSupport: true,
		recommendedPlugin: db.recommendedPlugin + ' (active ✓)',
		performanceNote: `✅ Using ${db.type} with native vector operations for fast similarity search.`,
	}
}

/**
 * The database details to show.
 *
 * @param {object} db The database info
 * @param {object} vector The vector notes to show with it
 * @return {object} The details
 */
function databaseInfoOf(db, vector) {
	return {
		type: db.type || 'Unknown',
		version: db.version || 'Unknown',
		vectorSupport: vector.vectorSupport,
		recommendedPlugin: vector.recommendedPlugin || null,
		performanceNote: vector.performanceNote,
		...databaseExtrasOf(db),
	}
}

/**
 * The extensions, hybrid search and update time of a database, with defaults.
 *
 * @param {object} db The database info
 * @return {object} The extras
 */
function databaseExtrasOf(db) {
	return {
		extensions: db.extensions || [],
		hybridSearch: db.hybridSearch || null,
		lastUpdated: db.lastUpdated || null,
	}
}

/**
 * LLM configuration settings component for managing AI/LLM integration.
 * Allows users to configure LLM providers, models, and AI features.
 */
/**
 * The state every instance of this component starts from.
 */
const INITIAL_STATE = {
	llmSettings: {
		enabled: false,
		provider: null,
		apiEndpoint: '',
		apiKey: '',
		model: null,
		temperature: 0.7,
		maxTokens: 2000,
	},

	saving: false,
	loadingStats: false,
	llmError: false,
	llmErrorMessage: '',
	llmConnectionStatus: 'Unknown',
	showLLMConfigDialog: false,
	showFileManagementDialog: false,
	showObjectManagementDialog: false,
	showObjectVectorizationModal: false,
	showFileVectorizationModal: false,
	providerConfig: {
		embeddingProvider: null,
		embeddingModel: null,
		chatProvider: null,
		chatModel: null,
	},

	databaseInfo: {
		type: 'Unknown',
		version: 'Unknown',
		vectorSupport: false,
		recommendedPlugin: null,
		performanceNote: null,
		extensions: [],
		hybridSearch: null,
		lastUpdated: null,
	},

	refreshingDatabase: false,
	chatStats: {
		totalAgents: 0,
		totalConversations: 0,
		totalMessages: 0,
	},

	vectorStats: {
		totalVectors: 0,
		objectVectors: 0,
		fileVectors: 0,
		storageMB: '0.0',
	},

	fileStats: {
		totalFiles: 0,
		vectorizedFiles: 0,
		filesExtracted: 0,
		pendingFiles: 0,
		totalChunks: 0,
	},

	objectStats: {
		totalObjects: 0,
		vectorizedObjects: 0,
		pendingObjects: 0,
		progressPercentage: 0,
	},
}

export default {
	name: 'LlmConfiguration',

	components: {
		SettingsSection,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcActions,
		NcActionButton,
		NcActionSeparator,
		NcButton,
		DotsVertical,
		Robot,
		FileDocument,
		CubeOutline,
		Refresh,
		VectorSquare,
		FileVectorOutline,
		LLMConfigModal,
		FileManagementModal,
		ObjectManagementModal,
		ObjectVectorizationModal,
		FileVectorizationModal,
	},

	data() {
		return freshState(INITIAL_STATE)
	},

	computed: {
		...mapStores(useSettingsStore),

		/**
		 * Get connection status CSS class
		 *
		 * @spec exclude UI plumbing — derived status-styling helper
		 * @return {string}
		 */
		connectionStatusClass() {
			if (
				this.llmConnectionStatus === 'Connected'
				|| this.llmConnectionStatus.includes('✓')
			) {
				return 'status-connected'
			}
			if (
				this.llmConnectionStatus === 'Disconnected'
				|| this.llmConnectionStatus.includes('✗')
			) {
				return 'status-disconnected'
			}
			return 'status-unknown'
		},
	},

	/**
	 * Lifecycle hook: load LLM settings and all statistics on mount.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch for display only
	 * @return {Promise<void>}
	 */
	async mounted() {
		await this.loadSettings()
		await this.loadAllStats()
	},

	methods: {
		/**
		 * Load LLM configuration settings
		 *
		 * @spec exclude UI plumbing — delegates to the settings store
		 * @return {Promise<void>}
		 */
		async loadSettings() {
			try {
				const settings = await this.settingsStore.getLlmSettings()
				if (settings) {
					this.llmSettings = { ...this.llmSettings, ...settings }
					this.applyProviderSettings(settings)
				}
			} catch (error) {
				console.error('Failed to load LLM settings:', error)
			}
		},

		/**
		 * Take the embedding and chat providers, and each one's model, from the settings.
		 *
		 * @param {object} settings The LLM settings
		 * @spec exclude UI plumbing — copies loaded settings into the form state
		 * @return {void}
		 */
		applyProviderSettings(settings) {
			const config = this.providerConfig
			config.embeddingProvider = settings.embeddingProvider || null
			config.chatProvider = settings.chatProvider || null
			const embedding = EMBEDDING_MODELS.get(settings.embeddingProvider)
			if (embedding) {
				config.embeddingModel = providerModel(settings, embedding)
			}
			const chat = CHAT_MODELS.get(settings.chatProvider)
			if (chat) {
				config.chatModel = providerModel(settings, chat)
			}
		},

		/**
		 * Get display name for provider.
		 *
		 * @param {string} providerId - The ID of the provider.
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {string} The display name for the provider.
		 */
		getProviderDisplayName(providerId) {
			const providerNames = {
				openai: 'OpenAI',
				fireworks: 'Fireworks AI',
				ollama: 'Ollama',
			}
			return providerNames[providerId] || providerId
		},

		/**
		 * Load all statistics (chat, vector, etc.)
		 *
		 * @spec exclude UI plumbing — fans out to other store-backed loaders
		 * @return {Promise<void>}
		 */
		async loadAllStats() {
			await Promise.all([
				this.loadChatStats(),
				this.loadVectorStats(),
				this.loadDatabaseInfo(),
				this.settingsStore.getExtractionStats(),
			])
		},

		/**
		 * Load chat and agent statistics
		 *
		 * @spec exclude UI plumbing — delegates to the settings store
		 * @return {Promise<void>}
		 */
		async loadChatStats() {
			try {
				const response = await this.settingsStore.getChatStats()
				if (response) {
					Object.assign(this.chatStats, countsOf(response, CHAT_COUNTS))
				}
			} catch (error) {
				console.error('Failed to load chat stats:', error)
			}
		},

		/**
		 * Refresh database information (force re-query)
		 *
		 * @spec exclude UI plumbing — delegates to the database refresh API
		 * @return {Promise<void>}
		 */
		async refreshDatabaseInfo() {
			this.refreshingDatabase = true
			try {
				await axios.post(
					generateUrl('/apps/openregister/api/settings/database/refresh'),
				)
				await this.loadDatabaseInfo()
				showSuccess(this.t('openregister', 'Database information refreshed'))
			} catch (error) {
				this.databaseRefreshFailed(error)
			} finally {
				this.refreshingDatabase = false
			}
		},

		/**
		 * Report a failed database refresh.
		 *
		 * @param {Error} error The failure
		 * @spec exclude UI plumbing — reports a failed database refresh
		 * @return {void}
		 */
		databaseRefreshFailed(error) {
			console.error('Failed to refresh database info:', error)
			showError(
				this.t('openregister', 'Failed to refresh database information'),
			)
		},

		/**
		 * Format date for display
		 *
		 * @param {string} dateString - ISO date string
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {string} Formatted date
		 */
		formatDate(dateString) {
			if (!dateString) return ''
			const date = new Date(dateString)
			return date.toLocaleString()
		},

		/**
		 * Load database information
		 *
		 * @spec exclude UI plumbing — fetches database info for display
		 * @return {Promise<void>}
		 */
		async loadDatabaseInfo() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/settings/database'),
				)
				if (response.data && response.data.success) {
					await this.applyDatabaseInfo(response.data.database)
				}
			} catch (error) {
				console.error('Failed to load database info:', error)
				// Don't throw error, just use defaults
			}
		},

		/**
		 * Show the database's details, with the vector notes of the backend in use.
		 *
		 * @param {object} db The database info from the API
		 * @spec exclude UI plumbing — maps database info for display
		 * @return {Promise<void>}
		 */
		async applyDatabaseInfo(db) {
			const plain = plainVectorInfo(db)
			const backend = await this.vectorBackend()
			this.databaseInfo = databaseInfoOf(
				db,
				backend === 'database' && db.vectorSupport
					? nativeVectorInfo(db)
					: plain,
			)
		},

		/**
		 * The configured vector backend, or undefined when the settings cannot be read.
		 *
		 * @spec exclude UI plumbing — reads one LLM setting for display
		 * @return {Promise<string|undefined>}
		 */
		async vectorBackend() {
			try {
				const llmResponse = await axios.get(
					generateUrl('/apps/openregister/api/settings/llm'),
				)
				return llmResponse.data.vectorConfig?.backend
			} catch {
				return undefined
			}
		},

		/**
		 * Retry connection - tests LLM connectivity
		 *
		 * @spec exclude UI plumbing — reloads stats and updates status display
		 * @return {Promise<void>}
		 */
		async retryConnection() {
			this.loadingStats = true
			this.llmError = false

			try {
				// Reload all statistics and test connection
				await this.loadAllStats()
				this.llmConnectionStatus = 'Connected ✓'
			} catch (error) {
				this.llmError = true
				this.llmErrorMessage =
					error.message || 'Failed to connect to LLM service'
				this.llmConnectionStatus = 'Disconnected ✗'
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Handle LLM Config Modal closing - reload stats to show updated backend
		 *
		 * @spec exclude UI plumbing — modal-close callback reloads stats
		 * @return {void}
		 */
		onLLMConfigClosed() {
			this.showLLMConfigDialog = false
			// Reload all stats to reflect the new vector backend configuration
			this.loadAllStats()
		},

		/**
		 * Save LLM configuration settings
		 *
		 * @spec exclude UI plumbing — save action delegates to the settings store
		 * @return {Promise<void>}
		 */
		async saveSettings() {
			this.saving = true
			try {
				await this.settingsStore.saveLlmSettings(this.llmSettings)
			} catch (error) {
				console.error('Failed to save LLM settings:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Handle LLM enabled toggle change
		 *
		 * @spec exclude UI plumbing — toggle delegates to the settings store
		 * @return {Promise<void>}
		 */
		async onLlmEnabledChange() {
			// Only send the enabled field via PATCH
			await this.settingsStore.patchLlmSettings({
				enabled: this.llmSettings.enabled,
			})
			if (this.llmSettings.enabled) {
				await this.loadVectorStats()
			}
		},

		/**
		 * Load vector statistics
		 *
		 * @spec exclude UI plumbing — fetches vector stats for display
		 * @return {Promise<void>}
		 */
		async loadVectorStats() {
			if (!this.llmSettings.enabled) return

			this.beginVectorStatsLoad()
			try {
				const response = await this.settingsStore.getVectorStats()
				if (response) {
					this.applyVectorStats(response)
				}
			} catch (error) {
				this.vectorStatsFailed(error)
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Mark the vector stats as loading and clear the last LLM error.
		 *
		 * @spec exclude UI plumbing — resets the vector stats state
		 * @return {void}
		 */
		beginVectorStatsLoad() {
			this.loadingStats = true
			this.llmError = false
			this.llmErrorMessage = ''
		},

		/**
		 * Show the LLM service as unreachable after a failed stats request.
		 *
		 * @param {Error} error The failure
		 * @spec exclude UI plumbing — reports failed vector stats
		 * @return {void}
		 */
		vectorStatsFailed(error) {
			console.error('Failed to load vector stats:', error)
			this.llmError = true
			this.llmErrorMessage =
				error.message || 'Failed to connect to LLM service'
			this.llmConnectionStatus = 'Disconnected'
		},

		/**
		 * Show the vector, file and object counts of a vector stats response.
		 *
		 * @param {object} response The vector stats response
		 * @spec exclude UI plumbing — maps vector stats for display
		 * @return {void}
		 */
		applyVectorStats(response) {
			// The counts sit in a 'stats' object, or at the top level.
			const stats = response.stats || response
			this.llmConnectionStatus = response.success
				? 'Connected'
				: 'Disconnected'
			assignVectorCounts(this.vectorStats, stats)
			this.applyFileAndObjectCounts(response)
		},

		/**
		 * Show the file and object counts a vector stats response carries.
		 *
		 * @param {object} response The vector stats response
		 * @spec exclude UI plumbing — maps vector stats for display
		 * @return {void}
		 */
		applyFileAndObjectCounts(response) {
			if (response.files) {
				Object.assign(this.fileStats, countsOf(response.files, FILE_COUNTS))
			}
			if (response.objects) {
				Object.assign(
					this.objectStats,
					countsOf(response.objects, OBJECT_COUNTS),
				)
			}
		},

		/**
		 * Format number with thousands separator
		 *
		 * @param {number} num - The number to format
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {string}
		 */
		formatNumber(num) {
			return new Intl.NumberFormat().format(num)
		},

		/**
		 * Show object vectorization modal
		 *
		 * @spec exclude UI plumbing — modal visibility toggle
		 * @return {void}
		 */
		showVectorizeObjectsDialog() {
			this.showObjectVectorizationModal = true
		},

		/**
		 * Show dialog to vectorize all files
		 *
		 * @spec exclude UI plumbing — modal visibility toggle
		 * @return {void}
		 */
		showVectorizeFilesDialog() {
			this.showFileVectorizationModal = true
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/common.scss' as *;
@use '../../../styles/views.scss' as *;

/* SettingsSection handles all action button positioning and spacing */

@include section-description-full-rules-2;

.main-description.info-note strong {
	color: var(--color-primary-element);
}

.toggle-status {
	margin: 0;
	font-size: 14px;
	line-height: 1.6;
}

.toggle-status strong {
	color: var(--color-text-light);
}

.status-enabled {
	color: var(--color-success);
	font-weight: 600;
}

.status-disabled {
	color: var(--color-text-maxcontrast);
}

.option-section {
	margin-bottom: 24px;
}

.option-description {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 8px 0 0 0;
	line-height: 1.4;
}

.saving-indicator {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-left: 8px;
	color: var(--color-primary-element);
}

.llm-management-section {
	margin-top: 32px;
}

.loading-section,
.error-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	gap: 16px;
}

@include loading-section-p;

.dashboard-section {
	margin-top: 20px;
}

.error-section {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.error-message {
	color: var(--color-error);
	font-size: 16px;
	font-weight: 500;
	margin: 0;
}

/* Provider Info Cards */
.provider-info-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
	margin-bottom: 24px;
}

.provider-info-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.provider-info-card.warning-card {
	border-color: var(--color-warning);
	background: var(--color-warning-hover, rgba(255, 180, 0, 0.1));
}

.provider-info-card h5 {
	margin: 0 0 8px 0;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.provider-info-card .provider-name {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.provider-info-card .not-configured {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.provider-info-card .model-info {
	margin: 8px 0 0 0;
	font-size: 12px;
	color: var(--color-text-lighter);
	font-family: monospace;
}

.provider-info-card .plugin-info {
	margin: 8px 0 0 0;
	font-size: 12px;
	font-weight: 500;
}

.provider-info-card .plugin-info.warning-text {
	color: var(--color-warning);
}

.provider-info-card .plugin-info.success-text {
	color: var(--color-success);
}

.provider-info-card .performance-note {
	margin: 8px 0 0 0;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	line-height: 1.3;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	cursor: help;
}

.provider-info-card .card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.provider-info-card .card-header h5 {
	margin: 0;
}

.provider-info-card .extensions-section {
	margin-top: 12px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.provider-info-card .extensions-section summary {
	cursor: pointer;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.provider-info-card .extensions-list {
	margin: 8px 0 0 0;
	padding: 0;
	list-style: none;
	max-height: 150px;
	overflow-y: auto;
}

.provider-info-card .extension-item {
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
	font-size: 11px;
	border-bottom: 1px solid var(--color-border-dark);
}

.provider-info-card .extension-item:last-child {
	border-bottom: none;
}

.provider-info-card .ext-name {
	color: var(--color-main-text);
	font-weight: 500;
}

.provider-info-card .ext-version {
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 10px;
}

.provider-info-card .last-updated {
	margin: 8px 0 0 0;
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Statistics Grid (matching File Configuration tiles) */
.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.stat-tile {
	background: var(--color-background-hover);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
	transition: all 0.2s ease;
}

.stat-tile:hover {
	border-color: var(--color-primary-element);
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.stat-value {
	font-size: 32px;
	font-weight: 700;
	color: var(--color-primary-element);
	line-height: 1;
	margin-bottom: 8px;
}

.stat-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

/* Connection Success */
.connection-success {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	background: var(--color-success-background, #d4edda);
	border: 1px solid var(--color-success, #28a745);
	border-radius: var(--border-radius-large);
	color: var(--color-success-text, #155724);
	font-weight: 500;
}

.success-icon {
	font-size: 20px;
}

@media (max-width: 768px) {
	.provider-info-grid {
		grid-template-columns: 1fr;
	}
}

/* Tablet breakpoint - 2 columns for medium screens */
@media (min-width: 641px) and (max-width: 1024px) {
	.provider-info-grid {
		grid-template-columns: repeat(2, 1fr);
	}

	.stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (prefers-reduced-motion: reduce) {
	.stat-tile {
		transition: none;
	}
}
</style>
