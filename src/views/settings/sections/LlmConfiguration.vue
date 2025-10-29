<template>
	<SettingsSection 
		name="LLM Configuration"
		description="Configure Large Language Model settings for AI features"
		:loading="loading"
		loading-message="Loading LLM configuration...">
		<template #actions>
			<!-- LLM Actions Menu -->
			<NcActions
				:aria-label="t('openregister', 'LLM actions menu')"
				:menu-name="t('openregister', 'Actions')">
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
			</NcActions>
		</template>

			<!-- Section Description -->
			<div class="section-description-full">
				<p class="main-description">
					Large Language Models (LLMs) power AI features including semantic search, text generation, document summarization,
					and content classification. When enabled, OpenRegister will automatically vectorize objects and files for semantic
					search capabilities and provide AI-powered content understanding. Choose from providers like OpenAI, Fireworks AI,
					Ollama (local), or Azure OpenAI.
				</p>
				<p class="main-description info-note">
					<strong>📝 Note:</strong> File vectorization requires text extraction to be enabled. Large files are split into smaller 
					<strong>chunks</strong> (text portions), which are then converted into <strong>embeddings</strong> (vector representations) 
					for semantic search. Without text extraction, files cannot be processed into chunks and embeddings.
				</p>
				<p class="toggle-status">
					<strong>Current Status:</strong>
					<span :class="llmSettings.enabled ? 'status-enabled' : 'status-disabled'">
						{{ llmSettings.enabled ? 'LLM features enabled' : 'LLM features disabled' }}
					</span>
				</p>
			</div>

			<!-- Enable LLM Toggle -->
			<div class="option-section">
				<NcCheckboxRadioSwitch
					v-model="llmSettings.enabled"
					:disabled="saving"
					type="switch"
					@update:checked="onLlmEnabledChange">
					{{ llmSettings.enabled ? t('openregister', 'LLM features enabled') : t('openregister', 'LLM features disabled') }}
				</NcCheckboxRadioSwitch>
				<p class="option-description">
					{{ t('openregister', 'Enable or disable LLM features. Configure providers and models using the LLM Configuration button above.') }}
					<span v-if="saving" class="saving-indicator">
						<NcLoadingIcon :size="14" /> {{ t('openregister', 'Saving...') }}
					</span>
				</p>
			</div>
		</div>

		<!-- LLM Dashboard -->
		<div v-if="llmSettings.enabled" class="llm-management-section">
			<!-- Loading State -->
			<div v-if="loadingStats" class="loading-section">
				<NcLoadingIcon :size="32" />
				<p>Loading LLM statistics...</p>
			</div>

			<!-- Error State -->
			<div v-else-if="llmError" class="error-section">
				<p class="error-message">
					❌ {{ llmErrorMessage }}
				</p>
				<NcButton type="primary" @click="loadVectorStats">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Retry Connection
				</NcButton>
			</div>

			<!-- Stats Display -->
			<div v-else class="dashboard-section">
				<!-- Main LLM Statistics Grid -->
				<div class="dashboard-stats-grid">
					<div class="stat-card">
						<h5>Connection Status</h5>
						<p :class="connectionStatusClass">
							{{ llmConnectionStatus }}
						</p>
					</div>

					<div class="stat-card">
						<h5>Total Objects</h5>
						<p>{{ formatNumber(objectStats.totalObjects) }}</p>
					</div>

					<div class="stat-card">
						<h5>Object Embeddings</h5>
						<p>{{ formatNumber(vectorStats.objectVectors) }}</p>
					</div>

					<div class="stat-card">
						<h5>Total Files</h5>
						<p>{{ formatNumber(fileStats.totalFiles) }}</p>
					</div>

					<div class="stat-card">
						<h5>File Embeddings</h5>
						<p>{{ formatNumber(vectorStats.fileVectors) }}</p>
					</div>

					<div class="stat-card">
						<h5>Total Chunks</h5>
						<p>{{ formatNumber(fileStats.totalChunks) }}</p>
					</div>
				</div>
			</div>
		</div>

		<!-- LLM Configuration Modal -->
		<LLMConfigModal
			:show="showLLMConfigDialog"
			@closing="showLLMConfigDialog = false" />

		<!-- File Management Modal -->
		<FileManagementModal
			:show="showFileManagementDialog"
			@closing="showFileManagementDialog = false" />

		<!-- Object Management Modal -->
		<ObjectManagementModal
			:show="showObjectManagementDialog"
			@closing="showObjectManagementDialog = false" />
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'

import {
	NcSettingsSection,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcActions,
	NcActionButton,
	NcButton,
} from '@nextcloud/vue'

import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import LLMConfigModal from '../../../modals/settings/LLMConfigModal.vue'
import FileManagementModal from '../../../modals/settings/FileManagementModal.vue'
import ObjectManagementModal from '../../../modals/settings/ObjectManagementModal.vue'

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
		NcActions,
		NcActionButton,
		NcButton,
		DotsVertical,
		Robot,
		FileDocument,
		CubeOutline,
		Refresh,
		LLMConfigModal,
		FileManagementModal,
		ObjectManagementModal,
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
			saving: false,
			loadingStats: false,
			llmError: false,
			llmErrorMessage: '',
			llmConnectionStatus: 'Unknown',
			showLLMConfigDialog: false,
			showFileManagementDialog: false,
			showObjectManagementDialog: false,
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
	},

	computed: {
		...mapStores(useSettingsStore),

		/**
		 * Get connection status CSS class
		 */
		connectionStatusClass() {
			if (this.llmConnectionStatus === 'Connected' || this.llmConnectionStatus.includes('✓')) {
				return 'status-connected'
			}
			if (this.llmConnectionStatus === 'Disconnected' || this.llmConnectionStatus.includes('✗')) {
				return 'status-disconnected'
			}
			return 'status-unknown'
		},
	},

	async mounted() {
		await this.loadSettings()
		await this.loadVectorStats()
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
				}
			} catch (error) {
				console.error('Failed to load LLM settings:', error)
			}
		},

		/**
		 * Save LLM configuration settings
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
		 */
		async onLlmEnabledChange() {
			await this.saveSettings()
			if (this.llmSettings.enabled) {
				await this.loadVectorStats()
			}
		},

		/**
		 * Load vector statistics
		 */
		async loadVectorStats() {
			if (!this.llmSettings.enabled) return
			
			this.loadingStats = true
			this.llmError = false
			this.llmErrorMessage = ''
			
			try {
				const response = await this.settingsStore.getVectorStats()
				if (response) {
					// Update connection status
					this.llmConnectionStatus = response.connection_status || 'Connected'
					
					// Update vector stats
					this.vectorStats.totalVectors = response.total_vectors || 0
					this.vectorStats.objectVectors = response.by_type?.object || 0
					this.vectorStats.fileVectors = response.by_type?.file || 0
					this.vectorStats.storageMB = response.storage?.total_mb?.toFixed(1) || '0.0'

					// Update file stats
					if (response.files) {
						this.fileStats.totalFiles = response.files.total_files || 0
						this.fileStats.filesExtracted = response.files.files_extracted || 0
						this.fileStats.vectorizedFiles = response.files.vectorized_files || 0
						this.fileStats.pendingFiles = response.files.pending_files || 0
						this.fileStats.totalChunks = response.files.total_chunks || 0
					}

					// Update object stats
					if (response.objects) {
						this.objectStats.totalObjects = response.objects.total_objects || 0
						this.objectStats.vectorizedObjects = response.objects.vectorized_objects || 0
						this.objectStats.pendingObjects = response.objects.pending_objects || 0
						this.objectStats.progressPercentage = response.objects.percentage_complete || 0
					}
				}
			} catch (error) {
				console.error('Failed to load vector stats:', error)
				this.llmError = true
				this.llmErrorMessage = error.message || 'Failed to connect to LLM service'
				this.llmConnectionStatus = 'Disconnected'
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Format number with thousands separator
		 */
		formatNumber(num) {
			return new Intl.NumberFormat().format(num)
		},
	},
}
</script>

<style scoped>
/* OpenConnector pattern: Actions positioned with relative positioning and negative margins */
/* Adjusted for NcSettingsSection's internal H2 structure */
.section-header-inline {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	position: relative;
	top: -30px; /* Pull up to align with NcSettingsSection's H2 */
	margin-bottom: -25px; /* Compensate for pull-up */
	z-index: 10;
}

.button-group {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.section-description-full {
	margin-top: 25px; /* Compensate for section-header-inline's negative margin */
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.main-description {
	color: var(--color-text-light);
	font-size: 14px;
	line-height: 1.6;
	margin: 0 0 16px 0;
}

.main-description.info-note {
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-primary-element);
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-top: 16px;
}

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

.loading-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	gap: 16px;
}

.loading-section p {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.dashboard-section {
	margin-top: 20px;
}

.section-title {
	color: var(--color-text-light);
	font-size: 16px;
	font-weight: 600;
	margin: 24px 0 16px 0;
}

.section-title:first-child {
	margin-top: 0;
}

.dashboard-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.stat-card {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
	transition: all 0.2s ease;
}

.stat-card:hover {
	border-color: var(--color-primary-element);
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.stat-card h5 {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 500;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin: 0 0 12px 0;
}

.stat-card p {
	color: var(--color-primary-element);
	font-size: 32px;
	font-weight: 700;
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.stat-card p.status-connected {
	color: var(--color-success);
}

.stat-card p.status-disconnected {
	color: var(--color-error);
}

.stat-card p.status-unknown {
	color: var(--color-text-maxcontrast);
}

.error-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	gap: 16px;
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

@media (max-width: 768px) {
	.section-header-inline {
		position: static;
		margin-bottom: 1rem;
		flex-direction: column;
		align-items: stretch;
	}

	.button-group {
		justify-content: center;
	}

	.dashboard-stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}
</style>
