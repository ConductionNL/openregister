<template>
	<NcSettingsSection name="SOLR Search Configuration"
		description="Configure Apache SOLR search engine for advanced search capabilities">
		<div class="solr-options">
			<!-- Save and Test Buttons -->
			<div class="section-header-inline">
				<span />
				<div class="button-group">
					<NcButton
						type="primary"
						:disabled="loading || saving || testingConnection || warmingUpSolr || settingUpSolr"
						@click="setupSolr">
						<template #icon>
							<NcLoadingIcon v-if="settingUpSolr" :size="20" />
							<Settings v-else :size="20" />
						</template>
						{{ settingUpSolr ? 'Setting up...' : 'Setup SOLR' }}
					</NcButton>
					<NcButton
						type="secondary"
						:disabled="loading || saving || testingConnection || warmingUpSolr || settingUpSolr"
						@click="testSolrConnection">
						<template #icon>
							<NcLoadingIcon v-if="testingConnection" :size="20" />
							<TestTube v-else :size="20" />
						</template>
						Test Connection
					</NcButton>
					<NcButton
						type="primary"
						:disabled="loading || saving || testingConnection || settingUpSolr"
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
					Apache SOLR provides advanced search capabilities including full-text search, faceted search, filtering, and sorting.
					When enabled, OpenRegister will index objects to SOLR for faster and more sophisticated search operations.
					This is recommended for production environments with large datasets.
				</p>
				<p class="toggle-status">
					<strong>Current Status:</strong>
					<span :class="solrOptions.enabled ? 'status-enabled' : 'status-disabled'">
						{{ solrOptions.enabled ? 'SOLR search enabled' : 'SOLR search disabled' }}
					</span>
				</p>
				<div v-if="solrConnectionStatus && solrConnectionStatus.message" class="connection-status" :class="solrConnectionStatus.success ? 'status-success' : 'status-error'">
					<p><strong>Connection Status:</strong> {{ solrConnectionStatus.message }}</p>
					<div v-if="solrConnectionStatus.details && Object.keys(solrConnectionStatus.details).length > 0" class="connection-details">
						<details>
							<summary>Connection Details</summary>
							<pre>{{ JSON.stringify(solrConnectionStatus.details, null, 2) }}</pre>
						</details>
					</div>
				</div>
			</div>

			<!-- Enable SOLR Toggle -->
			<div class="option-section">
				<NcCheckboxRadioSwitch
					:checked.sync="solrOptions.enabled"
					:disabled="saving"
					type="switch">
					{{ solrOptions.enabled ? 'SOLR search enabled' : 'SOLR search disabled' }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- SOLR Configuration -->
			<div v-if="solrOptions.enabled" class="solr-configuration">
				<h4>SOLR Server Configuration</h4>
				<p class="option-description">
					Configure connection settings for your SOLR server. Make sure SOLR is running and accessible before enabling.
				</p>

				<div class="solr-config-grid">
					<div class="config-row">
						<label class="config-label">
							<strong>Host</strong>
							<p class="config-description">SOLR server hostname or IP address</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.host"
								type="text"
								:disabled="loading || saving"
								placeholder="localhost"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Port</strong>
							<p class="config-description">SOLR server port number</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="solrOptions.port"
								type="number"
								:disabled="loading || saving"
								placeholder="8983"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Scheme</strong>
							<p class="config-description">Connection protocol</p>
						</label>
						<div class="config-input">
							<NcSelect
								v-model="solrOptions.scheme"
								:options="schemeOptions"
								input-label="Scheme"
								:disabled="loading || saving" />
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Path</strong>
							<p class="config-description">SOLR base path (usually /solr)</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.path"
								type="text"
								:disabled="loading || saving"
								placeholder="/solr"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Core</strong>
							<p class="config-description">SOLR core name for OpenRegister data</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.core"
								type="text"
								:disabled="loading || saving"
								placeholder="openregister"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Username</strong>
							<p class="config-description">Username for SOLR authentication (optional)</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.username"
								type="text"
								:disabled="loading || saving"
								placeholder=""
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Password</strong>
							<p class="config-description">Password for SOLR authentication (optional)</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.password"
								type="password"
								:disabled="loading || saving"
								placeholder=""
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Timeout (seconds)</strong>
							<p class="config-description">Connection timeout in seconds</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="solrOptions.timeout"
								type="number"
								:disabled="loading || saving"
								placeholder="30"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Zookeeper Hosts</strong>
							<p class="config-description">Zookeeper connection string for SolrCloud</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.zookeeperHosts"
								type="text"
								:disabled="loading || saving"
								placeholder="zookeeper:2181"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Collection</strong>
							<p class="config-description">SolrCloud collection name</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.collection"
								type="text"
								:disabled="loading || saving"
								placeholder="openregister"
								class="solr-input-field">
						</div>
					</div>

					<div class="config-row">
						<label class="config-label">
							<strong>Tenant ID</strong>
							<p class="config-description">Unique identifier for multi-tenant isolation (auto-generated if empty)</p>
						</label>
						<div class="config-input">
							<input
								v-model="solrOptions.tenantId"
								type="text"
								:disabled="loading || saving"
								placeholder="Auto-generated from Nextcloud instance"
								class="solr-input-field">
						</div>
					</div>
				</div>

				<h4>Advanced Options</h4>
				<div class="advanced-options">
					<NcCheckboxRadioSwitch
						:checked.sync="solrOptions.useCloud"
						:disabled="saving"
						type="switch">
						{{ solrOptions.useCloud ? 'SolrCloud mode enabled' : 'Standalone SOLR mode' }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						Use SolrCloud with Zookeeper for distributed search
					</p>

					<NcCheckboxRadioSwitch
						:checked.sync="solrOptions.autoCommit"
						:disabled="saving"
						type="switch">
						{{ solrOptions.autoCommit ? 'Auto-commit enabled' : 'Auto-commit disabled' }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						Automatically commit changes to SOLR index
					</p>

					<div class="config-row">
						<label class="config-label">
							<strong>Commit Within (ms)</strong>
							<p class="config-description">Maximum time to wait before committing changes</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="solrOptions.commitWithin"
								type="number"
								:disabled="loading || saving"
								placeholder="1000"
								class="solr-input-field">
						</div>
					</div>

					<NcCheckboxRadioSwitch
						:checked.sync="solrOptions.enableLogging"
						:disabled="saving"
						type="switch">
						{{ solrOptions.enableLogging ? 'SOLR logging enabled' : 'SOLR logging disabled' }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						Enable detailed logging for SOLR operations (recommended for debugging)
					</p>
				</div>
			</div>
		</div>

		<!-- Test Connection Results Dialog -->
		<NcDialog
			v-if="showTestDialog"
			name="SOLR Connection Test Results"
			:can-close="!testingConnection"
			@closing="hideTestDialog"
			:size="'large'">
			<div class="test-dialog">
				<div v-if="testingConnection" class="test-loading">
					<div class="loading-spinner">
						<NcLoadingIcon :size="40" />
					</div>
					<h4>Testing SOLR Connection...</h4>
					<p class="loading-description">
						Please wait while we test the connection to your SOLR server. This may take a few seconds.
					</p>
				</div>

				<div v-else-if="testResults" class="test-results">
					<div class="results-header">
						<div :class="testResults.success ? 'success-icon' : 'error-icon'">
							{{ testResults.success ? '✅' : '❌' }}
						</div>
						<h4>{{ testResults.success ? 'Connection Test Successful!' : 'Connection Test Failed' }}</h4>
						<p class="results-description">{{ testResults.message }}</p>
					</div>

					<div v-if="testResults.components" class="components-results">
						<h5>Component Test Results</h5>
						<div class="component-grid">
							<div
								v-for="(component, name) in testResults.components"
								:key="name"
								class="component-card"
								:class="component.success ? 'success' : 'error'">
								<div class="component-header">
									<span class="component-icon">{{ component.success ? '✅' : '❌' }}</span>
									<h6>{{ formatComponentName(name) }}</h6>
								</div>
								<p class="component-message">{{ component.message }}</p>
								
								<div v-if="component.details" class="component-details">
									<details>
										<summary>View Details</summary>
										<div class="details-content">
											<div v-for="(value, key) in component.details" :key="key" class="detail-item">
												<span class="detail-label">{{ formatDetailLabel(key) }}:</span>
												<span class="detail-value">{{ formatDetailValue(value) }}</span>
											</div>
										</div>
									</details>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="dialog-actions">
					<NcButton
						:disabled="testingConnection"
						@click="hideTestDialog">
						Close
					</NcButton>
					<NcButton
						v-if="!testingConnection && testResults && !testResults.success"
						type="primary"
						@click="retryTest">
						<template #icon>
							<TestTube :size="20" />
						</template>
						Test Again
					</NcButton>
				</div>
			</div>
		</NcDialog>

		<!-- Setup SOLR Results Dialog -->
		<NcDialog
			v-if="showSetupDialog"
			name="SOLR Setup Results"
			:can-close="!settingUpSolr"
			@closing="hideSetupDialog"
			:size="'large'">
			<div class="setup-dialog">
				<div v-if="settingUpSolr" class="setup-loading">
					<div class="loading-spinner">
						<NcLoadingIcon :size="40" />
					</div>
					<h4>Setting up SOLR...</h4>
					<p class="loading-description">
						Please wait while we configure SOLR for OpenRegister. This may take a few moments.
					</p>
				</div>

				<div v-else-if="setupResults" class="setup-results">
					<div class="results-header">
						<div :class="setupResults.success ? 'success-icon' : 'error-icon'">
							{{ setupResults.success ? '✅' : '❌' }}
						</div>
						<h4>{{ setupResults.success ? 'SOLR Setup Completed!' : 'SOLR Setup Failed' }}</h4>
						<p class="results-description">{{ setupResults.message }}</p>
					</div>

					<div v-if="setupResults.details" class="setup-details">
						<h5>Setup Details</h5>
						<div class="details-content">
							<div v-for="(value, key) in setupResults.details" :key="key" class="detail-item">
								<span class="detail-label">{{ formatDetailLabel(key) }}:</span>
								<span class="detail-value">{{ formatDetailValue(value) }}</span>
							</div>
						</div>
					</div>
				</div>

				<div class="dialog-actions">
					<NcButton
						:disabled="settingUpSolr"
						@click="hideSetupDialog">
						Close
					</NcButton>
					<NcButton
						v-if="!settingUpSolr && setupResults && !setupResults.success"
						type="primary"
						@click="retrySetup">
						<template #icon>
							<Settings :size="20" />
						</template>
						Setup Again
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import { NcSettingsSection, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect, NcDialog } from '@nextcloud/vue'
import Settings from 'vue-material-design-icons/ApplicationSettings.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'SolrConfiguration',
	
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcDialog,
		Settings,
		TestTube,
		Save,
		Refresh,
	},

	computed: {
		...mapStores(useSettingsStore),

		solrOptions: {
			get() {
				return this.settingsStore.solrOptions
			},
			set(value) {
				this.settingsStore.solrOptions = value
			}
		},

		solrConnectionStatus() {
			return this.settingsStore.solrConnectionStatus
		},

		loading() {
			return this.settingsStore.loading
		},

		saving() {
			return this.settingsStore.saving
		},

		testingConnection() {
			return this.settingsStore.testingConnection
		},

		warmingUpSolr() {
			return this.settingsStore.warmingUpSolr
		},

		settingUpSolr() {
			return this.settingsStore.settingUpSolr
		},

		schemeOptions() {
			return this.settingsStore.schemeOptions
		},

		showTestDialog() {
			return this.settingsStore.showTestDialog
		},

		showSetupDialog() {
			return this.settingsStore.showSetupDialog
		},

		testResults() {
			return this.settingsStore.testResults
		},

		setupResults() {
			return this.settingsStore.setupResults
		},
	},

	methods: {
		async setupSolr() {
			await this.settingsStore.setupSolr()
		},

		async testSolrConnection(testConfig = null) {
			await this.settingsStore.testSolrConnection(testConfig)
		},

		async saveSettings() {
			await this.settingsStore.updateSolrSettings(this.solrOptions)
		},

		hideTestDialog() {
			this.settingsStore.hideTestDialog()
		},

		retryTest() {
			this.settingsStore.retryTest()
		},

		hideSetupDialog() {
			this.settingsStore.hideSetupDialog()
		},

		retrySetup() {
			this.settingsStore.retrySetup()
		},

		formatComponentName(name) {
			return name.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase())
		},

		formatDetailLabel(key) {
			return key.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase())
		},

		formatDetailValue(value) {
			if (typeof value === 'object') {
				return JSON.stringify(value, null, 2)
			}
			return String(value)
		},
	},
}
</script>

<style scoped>
.solr-options {
	margin-top: 20px;
}

.section-header-inline {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 24px;
}

.button-group {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.section-description-full {
	margin-bottom: 24px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.main-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 12px 0;
}

.toggle-status {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.status-enabled {
	color: var(--color-success);
	font-weight: 500;
}

.status-disabled {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.connection-status {
	padding: 12px;
	border-radius: var(--border-radius);
	margin-top: 12px;
}

.connection-status.status-success {
	background: rgba(var(--color-success), 0.1);
	border: 1px solid var(--color-success);
	color: var(--color-success-text);
}

.connection-status.status-error {
	background: rgba(var(--color-error), 0.1);
	border: 1px solid var(--color-error);
	color: var(--color-error-text);
}

.connection-status p {
	margin: 0;
}

.connection-details {
	margin-top: 8px;
}

.connection-details details {
	cursor: pointer;
}

.connection-details pre {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: 4px;
	font-size: 12px;
	overflow-x: auto;
	margin-top: 8px;
}

.option-section {
	margin: 24px 0;
}

.solr-configuration {
	margin-top: 24px;
}

.solr-configuration h4 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
}

.option-description {
	color: var(--color-text-maxcontrast);
	margin: 8px 0 16px 0;
	line-height: 1.4;
}

.solr-config-grid {
	display: grid;
	gap: 20px;
	margin-bottom: 24px;
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
	margin-top: 16px;
}

.advanced-options .option-description {
	margin: 4px 0 16px 0;
}

@media (max-width: 768px) {
	.config-row {
		grid-template-columns: 1fr;
		gap: 8px;
	}
	
	.section-header-inline {
		flex-direction: column;
		gap: 12px;
		align-items: stretch;
	}
	
	.button-group {
		justify-content: center;
	}
}
</style>