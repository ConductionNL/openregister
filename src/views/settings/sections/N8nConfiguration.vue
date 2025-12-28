<template>
	<SettingsSection
		id="n8n-workflows"
		name="Workflow Configuration"
		description="Configure n8n workflow automation integration"
		:loading="loading"
		loading-message="Loading n8n configuration...">
		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				n8n integration enables <strong>automated workflow management</strong> directly from OpenRegister.
				Connect to your n8n instance to create, upload, and maintain workflows that automate data processing,
				notifications, integrations, and more. Workflows can be triggered by object events, scheduled tasks,
				or manual execution.
			</p>
			<p class="toggle-status">
				<strong>Current Status:</strong>
				<span :class="n8nEnabled ? 'status-enabled' : 'status-disabled'">
					{{ n8nEnabled ? 'n8n workflow integration enabled' : 'n8n workflow integration disabled' }}
				</span>
			</p>
			<div v-if="connectionStatus && connectionStatus.message" class="connection-status" :class="connectionStatus.success ? 'status-success' : 'status-error'">
				<p><strong>Connection Status:</strong> {{ connectionStatus.message }}</p>
				<div v-if="connectionStatus.details && Object.keys(connectionStatus.details).length > 0" class="connection-details">
					<details>
						<summary>Connection Details</summary>
						<pre>{{ JSON.stringify(connectionStatus.details, null, 2) }}</pre>
					</details>
				</div>
			</div>
		</div>

		<!-- Enable n8n Toggle -->
		<div class="option-section">
			<NcCheckboxRadioSwitch
				v-model="n8nEnabled"
				:disabled="saving"
				type="switch"
				@update:checked="onToggleN8n">
				{{ n8nEnabled ? t('openregister', 'n8n integration enabled') : t('openregister', 'n8n integration disabled') }}
			</NcCheckboxRadioSwitch>
			<p class="option-description">
				{{ t('openregister', 'Enable or disable n8n workflow integration. Configure connection settings below.') }}
				<span v-if="saving" class="saving-indicator">
					<NcLoadingIcon :size="14" /> {{ t('openregister', 'Saving...') }}
				</span>
			</p>
		</div>

		<!-- Connection Configuration -->
		<SettingsCard
			v-if="n8nEnabled"
			title="n8n Connection Settings"
			icon="🔌"
			:collapsible="false">
			<template #icon>
				<Connection :size="20" />
			</template>

			<div class="connection-config">
				<!-- n8n Base URL -->
				<div class="config-field-group">
					<label for="n8n-url">n8n Base URL</label>
					<NcTextField
						id="n8n-url"
						v-model="n8nUrl"
						placeholder="http://master-n8n-1:5678"
						@update:value="updateN8nUrl">
						<template #trailing-button-icon>
							<Web :size="20" />
						</template>
					</NcTextField>
					<p class="field-hint">
						<InformationOutline :size="16" /> The base URL of your n8n instance (e.g., http://master-n8n-1:5678)
					</p>
				</div>

				<!-- API Key -->
				<div class="config-field-group">
					<label for="n8n-api-key">n8n API Key</label>
					<div class="input-row">
						<NcPasswordField
							id="n8n-api-key"
							v-model="n8nApiKey"
							placeholder="n8n_api_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
							autocomplete="off"
							@update:value="updateN8nApiKey">
							<template #trailing-button-icon>
								<Key :size="20" />
							</template>
						</NcPasswordField>
					</div>
					<p class="field-hint">
						<LockOutline :size="16" /> Your n8n API key for authentication (found in n8n Settings → API)
					</p>
				</div>

				<!-- Project Name -->
				<div class="config-field-group">
					<label for="n8n-project">Project Name</label>
					<NcTextField
						id="n8n-project"
						v-model="n8nProject"
						placeholder="openregister"
						@update:value="updateN8nProject">
						<template #trailing-button-icon>
							<FolderOutline :size="20" />
						</template>
					</NcTextField>
					<p class="field-hint">
						<InformationOutline :size="16" /> Project name in n8n for organizing workflows (will be created if it does not exist)
					</p>
				</div>

				<!-- Action Buttons -->
				<div class="action-buttons">
					<NcButton
						type="primary"
						:disabled="saving || !hasChanges"
						@click="saveConfiguration">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<ContentSave v-else :size="20" />
						</template>
						{{ t('openregister', 'Save Configuration') }}
					</NcButton>

					<NcButton
						type="secondary"
						:disabled="testingConnection || !n8nUrl || !n8nApiKey"
						@click="testConnection">
						<template #icon>
							<NcLoadingIcon v-if="testingConnection" :size="20" />
							<TestTube v-else :size="20" />
						</template>
						{{ t('openregister', 'Test Connection') }}
					</NcButton>

					<NcButton
						v-if="connectionStatus && connectionStatus.success"
						type="primary"
						:disabled="initializing"
						@click="initializeN8n">
						<template #icon>
							<NcLoadingIcon v-if="initializing" :size="20" />
							<RocketLaunch v-else :size="20" />
						</template>
						{{ t('openregister', 'Initialize Project') }}
					</NcButton>

					<span v-if="!hasChanges && !testResult" class="saved-indicator">
						<CheckCircle :size="20" /> {{ t('openregister', 'Configuration saved') }}
					</span>
				</div>

				<!-- Test Result -->
				<div v-if="testResult" class="test-result" :class="testResult.success ? 'test-success' : 'test-error'">
					<div class="test-result-header">
						<CheckCircle v-if="testResult.success" :size="20" />
						<AlertCircle v-else :size="20" />
						<strong>{{ testResult.message }}</strong>
					</div>
					<div v-if="testResult.details" class="test-result-details">
						<p v-if="testResult.details.version">
							<strong>n8n Version:</strong> {{ testResult.details.version }}
						</p>
						<p v-if="testResult.details.user">
							<strong>Connected as:</strong> {{ testResult.details.user }}
						</p>
					</div>
				</div>

				<!-- Initialization Result -->
				<div v-if="initResult" class="init-result" :class="initResult.success ? 'test-success' : 'test-error'">
					<div class="test-result-header">
						<CheckCircle v-if="initResult.success" :size="20" />
						<AlertCircle v-else :size="20" />
						<strong>{{ initResult.message }}</strong>
					</div>
					<div v-if="initResult.details" class="test-result-details">
						<p v-if="initResult.details.project">
							<strong>Project:</strong> {{ initResult.details.project }}
						</p>
						<p v-if="initResult.details.projectId">
							<strong>Project ID:</strong> {{ initResult.details.projectId }}
						</p>
						<p v-if="initResult.details.workflows !== undefined">
							<strong>Workflows:</strong> {{ initResult.details.workflows }} workflow(s) ready
						</p>
					</div>
				</div>
			</div>
		</SettingsCard>

		<!-- Workflow Management -->
		<SettingsCard
			v-if="n8nEnabled && connectionStatus && connectionStatus.success && initResult && initResult.success"
			title="Workflow Management"
			icon="⚙️"
			:collapsible="true"
			:default-collapsed="false">
			<template #icon>
				<Cog :size="20" />
			</template>

			<div class="workflow-management">
				<p class="section-intro">
					{{ t('openregister', 'Manage n8n workflows for OpenRegister automation. Workflows will be stored in the configured project.') }}
				</p>

				<div class="workflow-actions">
					<NcButton
						type="primary"
						:disabled="loadingWorkflows"
						@click="loadWorkflows">
						<template #icon>
							<NcLoadingIcon v-if="loadingWorkflows" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ t('openregister', 'Refresh Workflows') }}
					</NcButton>

					<NcButton
						type="secondary"
						@click="openN8nEditor">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('openregister', 'Open n8n Editor') }}
					</NcButton>
				</div>

				<!-- Workflow List -->
				<div v-if="workflows && workflows.length > 0" class="workflow-list">
					<h5>{{ t('openregister', 'Available Workflows') }}</h5>
					<div v-for="workflow in workflows" :key="workflow.id" class="workflow-item">
						<div class="workflow-info">
							<strong>{{ workflow.name }}</strong>
							<span :class="workflow.active ? 'workflow-active' : 'workflow-inactive'">
								{{ workflow.active ? t('openregister', 'Active') : t('openregister', 'Inactive') }}
							</span>
						</div>
						<p v-if="workflow.tags && workflow.tags.length > 0" class="workflow-tags">
							<Tag :size="16" />
							{{ workflow.tags.join(', ') }}
						</p>
					</div>
				</div>
				<div v-else-if="!loadingWorkflows" class="no-workflows">
					<p>{{ t('openregister', 'No workflows found in this project. Create workflows in the n8n editor.') }}</p>
				</div>
			</div>
		</SettingsCard>
	</SettingsSection>
</template>

<script>
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import SettingsCard from '../../../components/shared/SettingsCard.vue'
import { NcPasswordField, NcTextField, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Connection from 'vue-material-design-icons/Connection.vue'
import Web from 'vue-material-design-icons/Web.vue'
import Key from 'vue-material-design-icons/Key.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import RocketLaunch from 'vue-material-design-icons/RocketLaunch.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

/**
 * n8n Workflow Configuration Component
 *
 * Manages n8n workflow automation integration for OpenRegister.
 *
 * @category Component
 * @package  OCA\OpenRegister\View\Settings
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license  EUPL-1.2
 *
 * @link https://www.openregister.nl
 */
export default {
	name: 'N8nConfiguration',

	components: {
		SettingsSection,
		SettingsCard,
		NcPasswordField,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Connection,
		Web,
		Key,
		FolderOutline,
		ContentSave,
		CheckCircle,
		LockOutline,
		InformationOutline,
		TestTube,
		AlertCircle,
		RocketLaunch,
		Cog,
		Refresh,
		OpenInNew,
		Tag,
	},

	data() {
		return {
			loading: false,
			saving: false,
			testingConnection: false,
			initializing: false,
			loadingWorkflows: false,
			n8nEnabled: false,
			n8nUrl: '',
			n8nApiKey: '',
			n8nProject: 'openregister',
			originalConfig: {},
			connectionStatus: null,
			testResult: null,
			initResult: null,
			workflows: [],
		}
	},

	computed: {
		/**
		 * Check if configuration has unsaved changes.
		 *
		 * @return {boolean} True if there are unsaved changes.
		 */
		hasChanges() {
			return (
				this.n8nEnabled !== this.originalConfig.enabled
				|| this.n8nUrl !== this.originalConfig.url
				|| this.n8nApiKey !== this.originalConfig.apiKey
				|| this.n8nProject !== this.originalConfig.project
			)
		},
	},

	async mounted() {
		await this.loadConfiguration()
	},

	methods: {
		/**
		 * Load n8n configuration from backend.
		 *
		 * @return {Promise<void>}
		 */
		async loadConfiguration() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/n8n'))
				const config = response.data
				
				this.n8nEnabled = config.enabled || false
				this.n8nUrl = config.url || ''
				this.n8nApiKey = config.apiKey || ''
				this.n8nProject = config.project || 'openregister'
				
				// Store original config for change detection.
				this.originalConfig = {
					enabled: this.n8nEnabled,
					url: this.n8nUrl,
					apiKey: this.n8nApiKey,
					project: this.n8nProject,
				}

				// Load connection status if enabled.
				if (this.n8nEnabled && this.n8nUrl && this.n8nApiKey) {
					this.connectionStatus = config.connectionStatus || null
				}
			} catch (error) {
				console.error('Failed to load n8n configuration:', error)
				// Do not show error on initial load.
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update n8n URL value.
		 *
		 * @param {string} value New URL value.
		 * @return {void}
		 */
		updateN8nUrl(value) {
			this.n8nUrl = value
		},

		/**
		 * Update n8n API key value.
		 *
		 * @param {string} value New API key value.
		 * @return {void}
		 */
		updateN8nApiKey(value) {
			this.n8nApiKey = value
		},

		/**
		 * Update n8n project value.
		 *
		 * @param {string} value New project value.
		 * @return {void}
		 */
		updateN8nProject(value) {
			this.n8nProject = value
		},

		/**
		 * Handle toggle of n8n integration.
		 *
		 * @param {boolean} checked New enabled state.
		 * @return {Promise<void>}
		 */
		async onToggleN8n(checked) {
			this.n8nEnabled = checked
			if (!checked) {
				// If disabling, save immediately.
				await this.saveConfiguration()
			}
		},

		/**
		 * Save n8n configuration to backend.
		 *
		 * @return {Promise<void>}
		 */
		async saveConfiguration() {
			this.saving = true
			this.testResult = null
			this.initResult = null
			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/settings/n8n'), {
					enabled: this.n8nEnabled,
					url: this.n8nUrl,
					apiKey: this.n8nApiKey,
					project: this.n8nProject,
				})

				// Update original config.
				this.originalConfig = {
					enabled: this.n8nEnabled,
					url: this.n8nUrl,
					apiKey: this.n8nApiKey,
					project: this.n8nProject,
				}

				showSuccess(this.t('openregister', 'n8n configuration saved successfully'))
			} catch (error) {
				console.error('Failed to save n8n configuration:', error)
				showError(this.t('openregister', 'Failed to save n8n configuration'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Test n8n connection.
		 *
		 * @return {Promise<void>}
		 */
		async testConnection() {
			this.testingConnection = true
			this.testResult = null
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/n8n/test'),
					{
						url: this.n8nUrl,
						apiKey: this.n8nApiKey,
					},
				)

				this.testResult = {
					success: true,
					message: response.data.message || this.t('openregister', 'Connection successful'),
					details: response.data.details || {},
				}

				this.connectionStatus = this.testResult

				showSuccess(this.t('openregister', 'n8n connection test successful'))
			} catch (error) {
				const message = error.response?.data?.message || error.message || this.t('openregister', 'Connection failed')
				this.testResult = {
					success: false,
					message,
				}
				this.connectionStatus = this.testResult
				showError(this.t('openregister', 'n8n connection test failed: {message}', { message }))
			} finally {
				this.testingConnection = false
			}
		},

		/**
		 * Initialize n8n project and user.
		 *
		 * @return {Promise<void>}
		 */
		async initializeN8n() {
			this.initializing = true
			this.initResult = null
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/n8n/initialize'),
					{
						project: this.n8nProject,
					},
				)

				this.initResult = {
					success: true,
					message: response.data.message || this.t('openregister', 'Project initialized successfully'),
					details: response.data.details || {},
				}

				showSuccess(this.t('openregister', 'n8n project initialized successfully'))
				
				// Load workflows after initialization.
				await this.loadWorkflows()
			} catch (error) {
				const message = error.response?.data?.message || error.message || this.t('openregister', 'Initialization failed')
				this.initResult = {
					success: false,
					message,
				}
				showError(this.t('openregister', 'n8n initialization failed: {message}', { message }))
			} finally {
				this.initializing = false
			}
		},

		/**
		 * Load workflows from n8n.
		 *
		 * @return {Promise<void>}
		 */
		async loadWorkflows() {
			this.loadingWorkflows = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/n8n/workflows'))
				this.workflows = response.data.workflows || []
			} catch (error) {
				console.error('Failed to load workflows:', error)
				showError(this.t('openregister', 'Failed to load workflows'))
			} finally {
				this.loadingWorkflows = false
			}
		},

		/**
		 * Open n8n editor in new tab.
		 *
		 * @return {void}
		 */
		openN8nEditor() {
			if (this.n8nUrl) {
				window.open(this.n8nUrl, '_blank')
			}
		},
	},
}
</script>

<style scoped>
/* Section Description */
.section-description-full {
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

.toggle-status {
	margin: 0;
	font-size: 14px;
}

.status-enabled {
	color: var(--color-success);
	font-weight: 600;
}

.status-disabled {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.connection-status {
	margin-top: 16px;
	padding: 12px 16px;
	border-radius: var(--border-radius);
	border-left: 4px solid;
}

.status-success {
	background: var(--color-success-light);
	border-color: var(--color-success);
}

.status-error {
	background: var(--color-error-light);
	border-color: var(--color-error);
}

.connection-details {
	margin-top: 8px;
}

.connection-details summary {
	cursor: pointer;
	font-weight: 500;
}

.connection-details pre {
	margin-top: 8px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 12px;
	overflow-x: auto;
}

/* Option Section */
.option-section {
	margin-bottom: 20px;
}

.option-description {
	margin: 8px 0 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.saving-indicator {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-primary-element);
	font-weight: 500;
}

/* Connection Configuration */
.connection-config {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.config-field-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.config-field-group label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-size: 14px;
}

.input-row {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	width: 100%;
}

.field-hint {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.action-buttons {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.saved-indicator {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-success);
	font-weight: 500;
	font-size: 14px;
}

/* Test Result */
.test-result,
.init-result {
	padding: 16px;
	border-radius: var(--border-radius);
	border-left: 4px solid;
	margin-top: 16px;
}

.test-success {
	background: var(--color-success-light);
	border-color: var(--color-success);
}

.test-error {
	background: var(--color-error-light);
	border-color: var(--color-error);
}

.test-result-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.test-result-details p {
	margin: 4px 0;
	font-size: 14px;
}

/* Workflow Management */
.workflow-management {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.section-intro {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 0 0 8px 0;
}

.workflow-actions {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.workflow-list {
	margin-top: 8px;
}

.workflow-list h5 {
	color: var(--color-text-light);
	margin: 0 0 12px 0;
	font-size: 15px;
	font-weight: 500;
}

.workflow-item {
	padding: 12px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.workflow-info {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 4px;
}

.workflow-active {
	color: var(--color-success);
	font-size: 13px;
	font-weight: 500;
}

.workflow-inactive {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 500;
}

.workflow-tags {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0 0;
}

.no-workflows {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

@media (max-width: 768px) {
	.action-buttons {
		flex-direction: column;
		align-items: stretch;
	}

	.workflow-actions {
		flex-direction: column;
	}
}
</style>

