<!-- 
/**
 * @class ConnectionConfigModal
 * @module Modals/Settings
 * @package OpenRegister
 * 
 * Modal for configuring basic SOLR connection settings.
 * This includes server details, authentication, Zookeeper settings, and advanced connection options.
 * Core and Collection management should be done through their dedicated dialogs.
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
 */
-->
<template>
	<NcDialog
		v-if="show"
		name="SOLR Connection Settings"
		:can-close="!saving"
		size="large"
		@closing="handleClose">
		<div class="connection-config-modal">
			<!-- Header -->
			<div class="modal-header">
				<h3>{{ t('openregister', 'SOLR Connection Settings') }}</h3>
				<p class="header-description">
					{{ t('openregister', 'Configure basic connection settings for your SOLR server including authentication and network options. Use the separate ConfigSet and Collection Management dialogs to manage cores and collections.') }}
				</p>
			</div>

			<!-- Server Configuration -->
			<div class="config-section">
				<h4>{{ t('openregister', 'Server Configuration') }}</h4>
				<div class="config-grid">
					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Host') }}</strong>
							<p class="config-description">{{ t('openregister', 'SOLR server hostname or IP address') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model="localConfig.host"
								type="text"
								:disabled="saving"
								placeholder="localhost"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Port') }}</strong>
							<p class="config-description">{{ t('openregister', 'SOLR server port number (optional, defaults to 8983)') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="localConfig.port"
								type="number"
								:disabled="saving"
								placeholder="8983 (default)"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Scheme') }}</strong>
							<p class="config-description">{{ t('openregister', 'Connection protocol') }}</p>
						</label>
						<div class="config-input">
							<NcSelect
								v-model="localConfig.scheme"
								:options="schemeOptions"
								:input-label="t('openregister', 'Scheme')"
								:disabled="saving" />
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Path') }}</strong>
							<p class="config-description">{{ t('openregister', 'SOLR base path (usually /solr)') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model="localConfig.path"
								type="text"
								:disabled="saving"
								placeholder="/solr"
								class="solr-input-field">
						</div>
					</div>
				</div>
			</div>

			<!-- Authentication -->
			<div class="config-section">
				<h4>{{ t('openregister', 'Authentication') }}</h4>
				<div class="config-grid">
					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Username') }}</strong>
							<p class="config-description">{{ t('openregister', 'Username for SOLR authentication (optional)') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model="localConfig.username"
								type="text"
								:disabled="saving"
								placeholder=""
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Password') }}</strong>
							<p class="config-description">{{ t('openregister', 'Password for SOLR authentication (optional)') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model="localConfig.password"
								type="password"
								:disabled="saving"
								placeholder=""
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Timeout (seconds)') }}</strong>
							<p class="config-description">{{ t('openregister', 'Connection timeout in seconds') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="localConfig.timeout"
								type="number"
								:disabled="saving"
								placeholder="30"
								class="solr-input-field">
						</div>
					</div>
				</div>
			</div>

			<!-- Zookeeper Settings -->
			<div class="config-section">
				<h4>{{ t('openregister', 'Zookeeper Settings (SolrCloud)') }}</h4>
				<div class="config-grid">
					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Zookeeper Hosts') }}</strong>
							<p class="config-description">{{ t('openregister', 'Zookeeper connection string for SolrCloud') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model="localConfig.zookeeperHosts"
								type="text"
								:disabled="saving"
								placeholder="zookeeper:2181"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Zookeeper Port') }}</strong>
							<p class="config-description">{{ t('openregister', 'Zookeeper port number (optional, defaults to 2181)') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="localConfig.zookeeperPort"
								type="number"
								:disabled="saving"
								placeholder="2181 (default)"
								class="solr-input-field">
						</div>
					</div>
				</div>
			</div>

			<!-- Advanced Options -->
			<div class="config-section">
				<h4>{{ t('openregister', 'Advanced Options') }}</h4>
				<div class="advanced-options">
					<NcCheckboxRadioSwitch
						:checked="Boolean(localConfig.useCloud)"
						@update:checked="localConfig.useCloud = $event"
						:disabled="saving"
						type="switch">
						{{ localConfig.useCloud ? t('openregister', 'SolrCloud mode enabled') : t('openregister', 'Standalone SOLR mode') }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						{{ t('openregister', 'Use SolrCloud with Zookeeper for distributed search') }}
					</p>

					<NcCheckboxRadioSwitch
						:checked="Boolean(localConfig.autoCommit)"
						@update:checked="localConfig.autoCommit = $event"
						:disabled="saving"
						type="switch">
						{{ localConfig.autoCommit ? t('openregister', 'Auto-commit enabled') : t('openregister', 'Auto-commit disabled') }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						{{ t('openregister', 'Automatically commit changes to SOLR index') }}
					</p>

					<div class="config-row">
						<label class="config-label">
							<strong>{{ t('openregister', 'Commit Within (ms)') }}</strong>
							<p class="config-description">{{ t('openregister', 'Maximum time to wait before committing changes') }}</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="localConfig.commitWithin"
								type="number"
								:disabled="saving"
								placeholder="1000"
								class="solr-input-field">
						</div>
					</div>

					<NcCheckboxRadioSwitch
						:checked="Boolean(localConfig.enableLogging)"
						@update:checked="localConfig.enableLogging = $event"
						:disabled="saving"
						type="switch">
						{{ localConfig.enableLogging ? t('openregister', 'SOLR logging enabled') : t('openregister', 'SOLR logging disabled') }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						{{ t('openregister', 'Enable detailed logging for SOLR operations (recommended for debugging)') }}
					</p>
				</div>
			</div>

			<!-- Test Connection Results -->
			<div v-if="testResults" class="test-results-section">
				<div v-if="testResults.success" class="test-success">
					<h4>✅ {{ t('openregister', 'Connection Successful!') }}</h4>
					<p>{{ testResults.message }}</p>
					<div v-if="testResults.serverInfo" class="server-info">
						<h5>{{ t('openregister', 'Server Information') }}</h5>
						<div class="info-grid">
							<div v-if="testResults.serverInfo.solr_version" class="info-item">
								<span class="info-label">{{ t('openregister', 'SOLR Version:') }}</span>
								<span class="info-value">{{ testResults.serverInfo.solr_version }}</span>
							</div>
							<div v-if="testResults.serverInfo.lucene_version" class="info-item">
								<span class="info-label">{{ t('openregister', 'Lucene Version:') }}</span>
								<span class="info-value">{{ testResults.serverInfo.lucene_version }}</span>
							</div>
							<div v-if="testResults.serverInfo.mode" class="info-item">
								<span class="info-label">{{ t('openregister', 'Mode:') }}</span>
								<span class="info-value">{{ testResults.serverInfo.mode }}</span>
							</div>
							<div v-if="testResults.serverInfo.uptime" class="info-item">
								<span class="info-label">{{ t('openregister', 'Uptime:') }}</span>
								<span class="info-value">{{ testResults.serverInfo.uptime }}</span>
							</div>
							<div v-if="testResults.serverInfo.collections_count !== undefined" class="info-item">
								<span class="info-label">{{ t('openregister', 'Collections:') }}</span>
								<span class="info-value">{{ testResults.serverInfo.collections_count }}</span>
							</div>
							<div v-if="testResults.serverInfo.response_time" class="info-item">
								<span class="info-label">{{ t('openregister', 'Response Time:') }}</span>
								<span class="info-value">{{ testResults.serverInfo.response_time }}ms</span>
							</div>
						</div>
					</div>
				</div>
				<div v-else class="test-error">
					<h4>❌ {{ t('openregister', 'Connection Failed') }}</h4>
					<p>{{ testResults.message }}</p>
					<div v-if="testResults.details" class="error-details">
						<details>
							<summary>{{ t('openregister', 'Error Details') }}</summary>
							<pre>{{ testResults.details }}</pre>
						</details>
					</div>
				</div>
			</div>

			<!-- Actions -->
			<div class="modal-actions">
				<NcButton
					type="secondary"
					:disabled="saving || testing"
					@click="handleClose">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="saving || testing"
					@click="handleTestConnection">
					<template #icon>
						<NcLoadingIcon v-if="testing" :size="20" />
						<TestTube v-else :size="20" />
					</template>
					{{ testing ? t('openregister', 'Testing...') : t('openregister', 'Test Connection') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || testing"
					@click="handleSave">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<Save v-else :size="20" />
					</template>
					{{ saving ? t('openregister', 'Saving...') : t('openregister', 'Save Connection Settings') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * ConnectionConfigModal component
 * 
 * Provides a dialog for configuring basic SOLR connection settings including
 * server details, authentication, and Zookeeper configuration.
 * Core and collection management is handled by separate dedicated dialogs.
 */
export default {
	name: 'ConnectionConfigModal',
	
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		Save,
		TestTube,
	},

	props: {
		/**
		 * Whether the modal is visible
		 */
		show: {
			type: Boolean,
			required: true,
		},
		/**
		 * Current SOLR configuration
		 */
		config: {
			type: Object,
			required: true,
		},
		/**
		 * Scheme options for dropdown
		 */
		schemeOptions: {
			type: Array,
			default: () => [
				{ value: 'http', label: 'HTTP' },
				{ value: 'https', label: 'HTTPS' },
			],
		},
		/**
		 * Whether save operation is in progress
		 */
		saving: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			localConfig: {},
			testing: false,
			testResults: null,
		}
	},

	watch: {
		/**
		 * Watch for config changes and update local copy
		 */
		config: {
			immediate: true,
			deep: true,
			handler(newConfig) {
				this.localConfig = { ...newConfig }
			},
		},
	},

	methods: {
		/**
		 * Handle modal close
		 */
		handleClose() {
			// Reset test results when closing
			this.testResults = null
			this.$emit('close')
		},

		/**
		 * Handle save button click
		 */
		handleSave() {
			this.$emit('save', this.localConfig)
		},

		/**
		 * Test connection with current settings
		 */
		async handleTestConnection() {
			this.testing = true
			this.testResults = null

			try {
				const url = generateUrl('/apps/openregister/api/settings/solr/test')
				const response = await axios.post(url, this.localConfig)

				if (response.data.success) {
					this.testResults = {
						success: true,
						message: response.data.message || 'Successfully connected to SOLR server',
						serverInfo: response.data.server_info || response.data.serverInfo || null
					}
				} else {
					this.testResults = {
						success: false,
						message: response.data.message || response.data.error || 'Failed to connect to SOLR server',
						details: response.data.details || null
					}
				}
			} catch (error) {
				console.error('Connection test failed:', error)
				this.testResults = {
					success: false,
					message: error.response?.data?.message || error.message || 'Connection test failed',
					details: error.response?.data?.details || error.toString()
				}
			} finally {
				this.testing = false
			}
		},
	},
}

</script>

<style scoped>
.connection-config-modal {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.modal-header {
	margin-bottom: 30px;
}

.modal-header h3 {
	margin: 0 0 10px 0;
	color: var(--color-text-light);
	font-size: 18px;
	font-weight: 600;
}

.header-description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	line-height: 1.5;
}

.config-section {
	margin-bottom: 30px;
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
}

.config-section h4 {
	margin: 0 0 20px 0;
	color: var(--color-text-light);
	font-size: 16px;
	font-weight: 600;
}

.config-grid {
	display: grid;
	gap: 20px;
}

.config-row {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 16px;
	align-items: start;
}

.config-label {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.config-label strong {
	color: var(--color-text-light);
	font-weight: 500;
}

.config-description {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 0;
	line-height: 1.3;
}

.config-input {
	display: flex;
	align-items: center;
	gap: 8px;
}

.solr-input-field {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
	font-size: 14px;
}

.solr-input-field:focus {
	border-color: var(--color-primary);
	outline: none;
}

.solr-input-field:disabled {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}

.advanced-options {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.option-description {
	color: var(--color-text-maxcontrast);
	margin: -8px 0 0 0;
	font-size: 14px;
	line-height: 1.4;
}

.modal-actions {
	display: flex;
	gap: 12px;
	justify-content: flex-end;
	margin-top: 30px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

/* Test Results Styling */
.test-results-section {
	margin-top: 30px;
	padding: 20px;
	border-radius: 8px;
	border: 1px solid var(--color-border);
}

.test-success {
	background: rgba(76, 175, 80, 0.1);
	padding: 16px;
	border-radius: 8px;
	border: 1px solid var(--color-success);
}

.test-success h4 {
	margin: 0 0 12px 0;
	color: var(--color-success-text);
	font-size: 16px;
	font-weight: 600;
}

.test-success p {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.test-error {
	background: rgba(244, 67, 54, 0.1);
	padding: 16px;
	border-radius: 8px;
	border: 1px solid var(--color-error);
}

.test-error h4 {
	margin: 0 0 12px 0;
	color: var(--color-error-text);
	font-size: 16px;
	font-weight: 600;
}

.test-error p {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.error-details {
	margin-top: 12px;
}

.error-details summary {
	cursor: pointer;
	color: var(--color-primary);
	font-weight: 500;
	margin-bottom: 8px;
}

.error-details pre {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: 4px;
	font-size: 12px;
	overflow-x: auto;
	margin: 8px 0 0 0;
}

.server-info {
	margin-top: 16px;
}

.server-info h5 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
	font-size: 14px;
	font-weight: 600;
}

.server-info .info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
}

.server-info .info-item {
	display: flex;
	justify-content: space-between;
	padding: 8px 12px;
	background: var(--color-background-hover);
	border-radius: 4px;
}

.server-info .info-label {
	font-weight: 500;
	color: var(--color-main-text);
}

.server-info .info-value {
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 13px;
}

@media (max-width: 768px) {
	.config-row {
		grid-template-columns: 1fr;
		gap: 8px;
	}
	
	.connection-config-modal {
		padding: 16px;
	}
	
	.server-info .info-grid {
		grid-template-columns: 1fr;
	}
	
	.modal-actions {
		flex-direction: column;
	}
}
</style>

