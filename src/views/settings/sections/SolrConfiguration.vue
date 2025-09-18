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
							<p class="config-description">SOLR server port number (optional, defaults to 8983)</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="solrOptions.port"
								type="number"
								:disabled="loading || saving"
								placeholder="8983 (default)"
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
							<strong>Zookeeper Port</strong>
							<p class="config-description">Zookeeper port number (optional, defaults to 2181)</p>
						</label>
						<div class="config-input">
							<input
								v-model.number="solrOptions.zookeeperPort"
								type="number"
								:disabled="loading || saving"
								placeholder="2181 (default)"
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
					<!-- Overall Status Header -->
					<div class="results-header">
						<div class="status-badge" :class="testResults.success ? 'success' : 'error'">
							<span class="status-icon">{{ testResults.success ? '✅' : '❌' }}</span>
							<div class="status-text">
								<h3>{{ testResults.success ? 'Connection Test Successful!' : 'Connection Test Failed' }}</h3>
								<p>{{ testResults.message }}</p>
							</div>
						</div>
					</div>

					<!-- Component Results Grid -->
					<div v-if="testResults.components" class="components-grid">
						<!-- Zookeeper Component -->
						<div v-if="testResults.components.zookeeper" class="component-card zookeeper">
							<div class="component-header">
								<div class="component-icon">
									<span class="icon-bg">🔗</span>
									<span class="status-indicator" :class="testResults.components.zookeeper.success ? 'success' : 'error'">
										{{ testResults.components.zookeeper.success ? '✅' : '❌' }}
									</span>
								</div>
								<div class="component-info">
									<h4>Zookeeper Coordination</h4>
									<p class="component-status">{{ testResults.components.zookeeper.message }}</p>
								</div>
							</div>
							<div v-if="testResults.components.zookeeper.details" class="component-metrics">
								<div class="metric">
									<span class="metric-label">Hosts</span>
									<span class="metric-value">{{ testResults.components.zookeeper.details.zookeeper_hosts }}</span>
								</div>
								<div class="metric">
									<span class="metric-label">Method</span>
									<span class="metric-value">{{ testResults.components.zookeeper.details.test_method }}</span>
								</div>
								<div v-if="testResults.components.zookeeper.details.successful_hosts?.length" class="metric success">
									<span class="metric-label">✅ Connected</span>
									<span class="metric-value">{{ testResults.components.zookeeper.details.successful_hosts.length }} host(s)</span>
								</div>
								<div v-if="testResults.components.zookeeper.details.failed_hosts?.length" class="metric error">
									<span class="metric-label">❌ Failed</span>
									<span class="metric-value">{{ testResults.components.zookeeper.details.failed_hosts.length }} host(s)</span>
								</div>
							</div>
						</div>

						<!-- SOLR Component -->
						<div v-if="testResults.components.solr" class="component-card solr">
							<div class="component-header">
								<div class="component-icon">
									<span class="icon-bg">🔍</span>
									<span class="status-indicator" :class="testResults.components.solr.success ? 'success' : 'error'">
										{{ testResults.components.solr.success ? '✅' : '❌' }}
									</span>
								</div>
								<div class="component-info">
									<h4>SOLR Search Engine</h4>
									<p class="component-status">{{ testResults.components.solr.message }}</p>
								</div>
							</div>
							<div v-if="testResults.components.solr.details" class="component-metrics">
								<div class="metric highlight">
									<span class="metric-label">⚡ Response Time</span>
									<span class="metric-value">{{ testResults.components.solr.details.response_time_ms }}ms</span>
								</div>
								<div class="metric">
									<span class="metric-label">📄 Documents</span>
									<span class="metric-value">{{ testResults.components.solr.details.num_found?.toLocaleString() || 'N/A' }}</span>
								</div>
								<div class="metric">
									<span class="metric-label">🔗 ZK Connected</span>
									<span class="metric-value">{{ testResults.components.solr.details.zk_connected ? 'Yes' : 'No' }}</span>
								</div>
								<div class="metric">
									<span class="metric-label">☁️ Cloud Mode</span>
									<span class="metric-value">{{ testResults.components.solr.details.use_cloud ? 'Enabled' : 'Disabled' }}</span>
								</div>
								<div class="metric technical">
									<span class="metric-label">🌐 Query URL</span>
									<span class="metric-value technical-url">{{ testResults.components.solr.details.url }}</span>
								</div>
							</div>
						</div>

						<!-- Collection Component -->
						<div v-if="testResults.components.collection" class="component-card collection">
							<div class="component-header">
								<div class="component-icon">
									<span class="icon-bg">📚</span>
									<span class="status-indicator" :class="testResults.components.collection.success ? 'success' : 'error'">
										{{ testResults.components.collection.success ? '✅' : '❌' }}
									</span>
								</div>
								<div class="component-info">
									<h4>Collection Management</h4>
									<p class="component-status">{{ testResults.components.collection.message }}</p>
								</div>
							</div>
							<div v-if="testResults.components.collection.details" class="component-metrics">
								<div class="metric highlight">
									<span class="metric-label">📁 Collection Name</span>
									<span class="metric-value">{{ testResults.components.collection.details.collection_name || testResults.components.collection.details.collection }}</span>
								</div>
								<div class="metric">
									<span class="metric-label">🏷️ Type</span>
									<span class="metric-value">{{ testResults.components.collection.details.collection_type || 'base' }}</span>
								</div>
								<div v-if="testResults.components.collection.details.tenant_id" class="metric">
									<span class="metric-label">🏢 Tenant ID</span>
									<span class="metric-value">{{ testResults.components.collection.details.tenant_id }}</span>
								</div>
								<div class="metric">
									<span class="metric-label">🗂️ Shards</span>
									<span class="metric-value">{{ testResults.components.collection.details.shards || 'Unknown' }}</span>
								</div>
								<div class="metric">
									<span class="metric-label">📊 Status</span>
									<span class="metric-value">{{ testResults.components.collection.details.status || 'Active' }}</span>
								</div>
								<div v-if="testResults.components.collection.details.available_collections" class="metric">
									<span class="metric-label">📋 Available Collections</span>
									<span class="metric-value">{{ testResults.components.collection.details.available_collections.join(', ') || 'None' }}</span>
								</div>
							</div>
						</div>

						<!-- Query Component -->
						<div v-if="testResults.components.query" class="component-card query">
							<div class="component-header">
								<div class="component-icon">
									<span class="icon-bg">🔍</span>
									<span class="status-indicator" :class="testResults.components.query.success ? 'success' : 'error'">
										{{ testResults.components.query.success ? '✅' : '❌' }}
									</span>
								</div>
								<div class="component-info">
									<h4>Collection Query Test</h4>
									<p class="component-status">{{ testResults.components.query.message }}</p>
								</div>
							</div>
							<div v-if="testResults.components.query.details" class="component-metrics">
								<div class="metric">
									<span class="metric-label">📁 Collection</span>
									<span class="metric-value">{{ testResults.components.query.details.collection_name }}</span>
								</div>
								<div class="metric">
									<span class="metric-label">🏷️ Type</span>
									<span class="metric-value">{{ testResults.components.query.details.collection_type || 'base' }}</span>
								</div>
								<div v-if="testResults.components.query.details.tenant_id" class="metric">
									<span class="metric-label">🏢 Tenant ID</span>
									<span class="metric-value">{{ testResults.components.query.details.tenant_id }}</span>
								</div>
								<div class="metric highlight">
									<span class="metric-label">⚡ Response Time</span>
									<span class="metric-value">{{ testResults.components.query.details.response_time_ms }}ms</span>
								</div>
								<div class="metric">
									<span class="metric-label">📄 Documents</span>
									<span class="metric-value">{{ testResults.components.query.details.total_docs?.toLocaleString() || 'N/A' }}</span>
								</div>
								<div class="metric technical">
									<span class="metric-label">🌐 Query URL</span>
									<span class="metric-value technical-url">{{ testResults.components.query.details.query_url }}</span>
								</div>
							</div>
						</div>
					</div>

					<!-- Summary Stats -->
					<div v-if="testResults.success" class="test-summary">
						<div class="summary-card">
							<h5>🎯 Test Summary</h5>
							<div class="summary-stats">
								<div class="stat">
									<span class="stat-number">{{ Object.keys(testResults.components || {}).length }}</span>
									<span class="stat-label">Components Tested</span>
								</div>
								<div class="stat">
									<span class="stat-number">{{ Object.values(testResults.components || {}).filter(c => c.success).length }}</span>
									<span class="stat-label">Passed</span>
								</div>
								<div class="stat">
									<span class="stat-number">{{ testResults.components?.solr?.details?.response_time_ms || 'N/A' }}ms</span>
									<span class="stat-label">Avg Response</span>
								</div>
							</div>
						</div>
					</div>

					<!-- Kubernetes Services Discovery -->
					<div class="kubernetes-services">
						<div class="services-card">
							<h5>🌐 Kubernetes Services Discovery</h5>
							<p class="services-description">
								Common Kubernetes service patterns for SOLR and Zookeeper in your cluster:
							</p>
							<div class="service-suggestions">
								<div class="service-group">
									<h6>🔍 SOLR Services</h6>
									<div class="service-examples">
										<code>con-solr-solrcloud-common.solr.svc.cluster.local</code>
										<code>solr-headless.solr.svc.cluster.local</code>
										<code>solr-service.default.svc.cluster.local</code>
									</div>
								</div>
								<div class="service-group">
									<h6>🔗 Zookeeper Services</h6>
									<div class="service-examples">
										<code>con-zookeeper-solrcloud-common.zookeeper.svc.cluster.local</code>
										<code>zookeeper-headless.zookeeper.svc.cluster.local</code>
										<code>zookeeper-service.default.svc.cluster.local</code>
									</div>
								</div>
								<div class="service-group">
									<h6>💡 Service Discovery Tips</h6>
									<div class="service-tips">
										<p>• Use <code>kubectl get services -n &lt;namespace&gt;</code> to list services</p>
										<p>• Format: <code>&lt;service-name&gt;.&lt;namespace&gt;.svc.cluster.local</code></p>
										<p>• Default namespace services: <code>&lt;service-name&gt;.default.svc.cluster.local</code></p>
									</div>
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
					<!-- Overall Status Header -->
					<div class="results-header">
						<div class="status-badge" :class="setupResults.success ? 'success' : 'error'">
							<span class="status-icon">{{ setupResults.success ? '✅' : '❌' }}</span>
							<div class="status-text">
								<h3>{{ setupResults.success ? 'SOLR Setup Completed!' : 'SOLR Setup Failed' }}</h3>
								<p>{{ setupResults.message }}</p>
								<div v-if="setupResults.timestamp" class="timestamp">
									<span>⏱️ {{ setupResults.timestamp }}</span>
								</div>
							</div>
						</div>
					</div>

					<!-- Progress Overview -->
					<div v-if="setupResults.progress" class="progress-overview">
						<h4>📊 Setup Progress</h4>
						<div class="progress-summary">
							<div class="progress-bar-container">
								<div class="progress-bar">
									<div class="progress-fill" 
										:style="{ width: (setupResults.progress.completed_steps / setupResults.progress.total_steps) * 100 + '%' }"
										:class="setupResults.success ? 'success' : 'error'">
									</div>
								</div>
								<div class="progress-text">
									{{ setupResults.progress.completed_steps }} / {{ setupResults.progress.total_steps }} steps completed
								</div>
							</div>
							<div v-if="!setupResults.success && setupResults.progress.failed_at_step" class="failure-info">
								<span class="failure-badge">❌ Failed at Step {{ setupResults.progress.failed_at_step }}</span>
								<span class="failure-step">{{ setupResults.progress.failed_step_name }}</span>
							</div>
						</div>
					</div>

					<!-- Detailed Error Information (shown only on failure) -->
					<div v-if="!setupResults.success && setupResults.error_details" class="error-details-section">
						<h4>🔍 Error Details</h4>
						<div class="error-card">
							<div class="error-primary">
								<h5>{{ setupResults.error_details.primary_error }}</h5>
								<div class="error-meta">
									<span class="error-type">{{ setupResults.error_details.error_type }}</span>
									<span class="error-operation">{{ setupResults.error_details.operation }}</span>
								</div>
							</div>
							
							<div v-if="setupResults.error_details.configuration_used" class="configuration-used">
								<h6>Configuration Used:</h6>
								<div class="config-grid">
									<div v-for="(value, key) in setupResults.error_details.configuration_used" :key="key" class="config-item">
										<span class="config-key">{{ key }}:</span>
										<span class="config-value">{{ value }}</span>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Setup Steps Timeline -->
					<div v-if="setupResults.steps" class="setup-steps">
						<h4>🔧 Setup Process</h4>
						<div class="steps-timeline">
							<div v-for="step in setupResults.steps" :key="step.step_number" 
								class="step-item" :class="step.status">
								<div class="step-indicator">
									<span class="step-number">{{ step.step_number }}</span>
									<span class="step-status" :class="step.status">
										{{ step.status === 'completed' ? '✅' : step.status === 'failed' ? '❌' : '⏳' }}
									</span>
								</div>
								<div class="step-content">
									<h5>{{ step.step_name }}</h5>
									<p class="step-description">{{ step.description }}</p>
									<div v-if="step.timestamp" class="step-timestamp">
										⏱️ {{ step.timestamp }}
									</div>
									<div v-if="step.details && Object.keys(step.details).length > 0" class="step-details">
										<div v-for="(value, key) in step.details" :key="key" class="step-detail">
											<span class="detail-label">{{ formatDetailLabel(key) }}:</span>
											<span class="detail-value">{{ formatDetailValue(value) }}</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Troubleshooting Steps (shown only on failure) -->
					<div v-if="!setupResults.success && setupResults.troubleshooting_steps" class="troubleshooting-section">
						<h4>🛠️ Troubleshooting Steps</h4>
						<div class="troubleshooting-list">
							<div v-for="(step, index) in setupResults.troubleshooting_steps" :key="index" class="troubleshooting-item">
								<span class="troubleshooting-number">{{ index + 1 }}</span>
								<span class="troubleshooting-text">{{ step }}</span>
							</div>
						</div>
					</div>

					<!-- Infrastructure Overview -->
					<div v-if="setupResults.infrastructure" class="infrastructure-overview">
						<h4>🏗️ Infrastructure Created</h4>
						<div class="infrastructure-grid">
							<div class="infra-card configsets">
								<div class="infra-header">
									<span class="infra-icon">⚙️</span>
									<h5>ConfigSets</h5>
								</div>
								<div class="infra-content">
									<div class="infra-stats">
										<div class="infra-stat created">
											<span class="stat-number">{{ setupResults.infrastructure.configsets_created?.length || 0 }}</span>
											<span class="stat-label">Created</span>
										</div>
										<div v-if="setupResults.infrastructure.configsets_skipped?.length" class="infra-stat skipped">
											<span class="stat-number">{{ setupResults.infrastructure.configsets_skipped?.length || 0 }}</span>
											<span class="stat-label">Skipped</span>
										</div>
									</div>
									<div v-if="setupResults.infrastructure.configsets_created?.length" class="infra-list created-list">
										<span class="list-header">Created:</span>
										<span v-for="configset in setupResults.infrastructure.configsets_created" :key="configset" class="list-item created-item">
											{{ configset }}
										</span>
									</div>
									<div v-if="setupResults.infrastructure.configsets_skipped?.length" class="infra-list skipped-list">
										<span class="list-header">Skipped (already existed):</span>
										<span v-for="configset in setupResults.infrastructure.configsets_skipped" :key="configset" class="list-item skipped-item">
											{{ configset }}
										</span>
									</div>
								</div>
							</div>

							<div class="infra-card collections">
								<div class="infra-header">
									<span class="infra-icon">📚</span>
									<h5>Collections</h5>
								</div>
								<div class="infra-content">
									<div class="infra-stat">
										<span class="stat-number">{{ setupResults.infrastructure.collections_created?.length || 0 }}</span>
										<span class="stat-label">Created</span>
									</div>
									<div v-if="setupResults.infrastructure.collections_created" class="infra-list">
										<span v-for="collection in setupResults.infrastructure.collections_created" :key="collection" class="list-item">
											{{ collection }}
										</span>
									</div>
								</div>
							</div>

							<div class="infra-card schema">
								<div class="infra-header">
									<span class="infra-icon">🔍</span>
									<h5>Schema Fields</h5>
								</div>
								<div class="infra-content">
									<div class="infra-stat">
										<span class="stat-number">{{ setupResults.infrastructure.schema_fields || 0 }}</span>
										<span class="stat-label">Fields</span>
									</div>
									<div class="infra-features">
										<span v-if="setupResults.infrastructure.multi_tenant_ready" class="feature-badge success">🏠 Multi-Tenant</span>
										<span v-if="setupResults.infrastructure.cloud_mode" class="feature-badge success">☁️ Cloud Mode</span>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Next Steps -->
					<div v-if="setupResults.next_steps" class="next-steps">
						<h4>🚀 What's Next?</h4>
						<div class="next-steps-list">
							<div v-for="(step, index) in setupResults.next_steps" :key="index" class="next-step-item">
								<span class="next-step-icon">{{ index + 1 }}</span>
								<span class="next-step-text">{{ step }}</span>
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

		async testSolrConnection() {
			await this.settingsStore.testSolrConnection()
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

/* Enhanced Test Dialog Styles */
.test-dialog {
	padding: 24px;
	max-width: 900px;
}

.test-loading {
	text-align: center;
	padding: 40px 20px;
}

.loading-spinner {
	margin-bottom: 20px;
}

.loading-description {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.test-results {
	max-height: 700px;
	overflow-y: auto;
}

/* Enhanced Results Header */
.results-header {
	margin-bottom: 24px;
}

.status-badge {
	display: flex;
	align-items: center;
	padding: 20px 24px;
	border-radius: 12px;
	border: 2px solid;
	background: linear-gradient(135deg, var(--color-background-hover) 0%, var(--color-background-dark) 100%);
}

.status-badge.success {
	border-color: var(--color-success);
	background: linear-gradient(135deg, rgba(46, 125, 50, 0.1) 0%, rgba(76, 175, 80, 0.05) 100%);
}

.status-badge.error {
	border-color: var(--color-error);
	background: linear-gradient(135deg, rgba(211, 47, 47, 0.1) 0%, rgba(244, 67, 54, 0.05) 100%);
}

.status-icon {
	font-size: 32px;
	margin-right: 16px;
}

.status-text h3 {
	margin: 0 0 4px 0;
	color: var(--color-main-text);
	font-size: 18px;
	font-weight: 600;
}

.status-text p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

/* Enhanced Components Grid */
.components-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 20px;
	margin-bottom: 24px;
}

.component-card {
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 20px;
	background: var(--color-background-hover);
	transition: all 0.2s ease;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.component-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.component-card.zookeeper {
	border-left: 4px solid #FF9800;
}

.component-card.solr {
	border-left: 4px solid #2196F3;
}

.component-card.collection {
	border-left: 4px solid #4CAF50;
}

.component-card.query {
	border-left: 4px solid #9C27B0;
}

.component-header {
	display: flex;
	align-items: flex-start;
	margin-bottom: 16px;
}

.component-icon {
	position: relative;
	margin-right: 12px;
	flex-shrink: 0;
}

.icon-bg {
	font-size: 24px;
	display: inline-block;
	padding: 8px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.status-indicator {
	position: absolute;
	top: -4px;
	right: -4px;
	font-size: 14px;
	background: var(--color-background-hover);
	border-radius: 50%;
	padding: 2px;
	border: 2px solid var(--color-background-hover);
}

.component-info h4 {
	margin: 0 0 4px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.component-status {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* Enhanced Metrics */
.component-metrics {
	display: grid;
	gap: 8px;
}

.metric {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	font-size: 13px;
}

.metric.highlight {
	background: linear-gradient(135deg, rgba(33, 150, 243, 0.1) 0%, rgba(33, 150, 243, 0.05) 100%);
	border: 1px solid rgba(33, 150, 243, 0.2);
}

.metric.success {
	background: linear-gradient(135deg, rgba(76, 175, 80, 0.1) 0%, rgba(76, 175, 80, 0.05) 100%);
	border: 1px solid rgba(76, 175, 80, 0.2);
}

.metric.error {
	background: linear-gradient(135deg, rgba(244, 67, 54, 0.1) 0%, rgba(244, 67, 54, 0.05) 100%);
	border: 1px solid rgba(244, 67, 54, 0.2);
}

.metric.technical {
	background: var(--color-background-darker);
}

.metric-label {
	font-weight: 500;
	color: var(--color-main-text);
	flex-shrink: 0;
}

.metric-value {
	color: var(--color-text-maxcontrast);
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
	font-size: 12px;
	text-align: right;
	margin-left: 12px;
}

.technical-url {
	font-size: 11px;
	word-break: break-all;
	opacity: 0.8;
}

/* Test Summary */
.test-summary {
	margin-top: 24px;
}

.summary-card {
	background: linear-gradient(135deg, var(--color-primary-light) 0%, var(--color-primary) 100%);
	color: white;
	padding: 20px;
	border-radius: 12px;
	text-align: center;
}

.summary-card h5 {
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
}

.summary-stats {
	display: flex;
	justify-content: space-around;
	gap: 16px;
}

.stat {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.stat-number {
	font-size: 24px;
	font-weight: bold;
	margin-bottom: 4px;
}

.stat-label {
	font-size: 12px;
	opacity: 0.9;
	font-weight: 500;
}

/* Kubernetes Services Discovery Styles */
.kubernetes-services {
	margin-top: 24px;
}

.services-card {
	background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
	border: 1px solid #dee2e6;
	border-radius: 12px;
	padding: 20px;
}

.services-card h5 {
	margin: 0 0 12px 0;
	color: #495057;
	font-size: 16px;
	font-weight: 600;
}

.services-description {
	margin: 0 0 16px 0;
	color: #6c757d;
	font-size: 14px;
	line-height: 1.5;
}

.service-suggestions {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.service-group h6 {
	margin: 0 0 8px 0;
	color: #495057;
	font-size: 14px;
	font-weight: 600;
}

.service-examples {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.service-examples code {
	background: #f8f9fa;
	border: 1px solid #dee2e6;
	border-radius: 4px;
	padding: 6px 8px;
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
	font-size: 12px;
	color: #495057;
	cursor: pointer;
	transition: all 0.2s ease;
}

.service-examples code:hover {
	background: #e9ecef;
	border-color: #ced4da;
	transform: translateX(2px);
}

.service-tips {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.service-tips p {
	margin: 0;
	font-size: 13px;
	color: #6c757d;
	line-height: 1.4;
}

.service-tips code {
	background: #f8f9fa;
	border: 1px solid #dee2e6;
	border-radius: 3px;
	padding: 2px 4px;
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
	font-size: 11px;
	color: #495057;
}

/* Enhanced Setup Dialog Styles */
.setup-dialog {
	padding: 24px;
	max-width: 900px;
}

.setup-loading {
	text-align: center;
	padding: 40px 20px;
}

.setup-results {
	max-height: 700px;
	overflow-y: auto;
}

/* Progress Overview */
.progress-overview {
	margin: 24px 0;
	background: var(--color-background-hover);
	border-radius: 12px;
	padding: 20px;
	border: 1px solid var(--color-border);
}

.progress-overview h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.progress-summary {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.progress-bar-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.progress-bar {
	width: 100%;
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	transition: width 0.3s ease;
	border-radius: 4px;
}

.progress-fill.success {
	background: linear-gradient(90deg, var(--color-success-light) 0%, var(--color-success) 100%);
}

.progress-fill.error {
	background: linear-gradient(90deg, var(--color-error-light) 0%, var(--color-error) 100%);
}

.progress-text {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.failure-info {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px;
	background: rgba(var(--color-error), 0.1);
	border: 1px solid var(--color-error);
	border-radius: 8px;
}

.failure-badge {
	background: var(--color-error);
	color: white;
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: 500;
}

.failure-step {
	color: var(--color-error-text);
	font-weight: 500;
}

/* Error Details Section */
.error-details-section {
	margin: 24px 0;
}

.error-details-section h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.error-card {
	background: rgba(var(--color-error), 0.05);
	border: 1px solid var(--color-error);
	border-radius: 12px;
	padding: 20px;
}

.error-primary h5 {
	margin: 0 0 12px 0;
	color: var(--color-error-text);
	font-size: 16px;
	font-weight: 600;
}

.error-meta {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}

.error-type,
.error-operation {
	background: var(--color-background-dark);
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

.configuration-used h6 {
	margin: 0 0 12px 0;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
}

.config-grid {
	display: grid;
	gap: 8px;
}

.config-item {
	display: flex;
	justify-content: space-between;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	font-size: 13px;
}

.config-key {
	font-weight: 500;
	color: var(--color-main-text);
}

.config-value {
	color: var(--color-text-maxcontrast);
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

/* Troubleshooting Section */
.troubleshooting-section {
	margin: 24px 0;
	background: var(--color-background-hover);
	border-radius: 12px;
	padding: 20px;
	border: 1px solid var(--color-border);
}

.troubleshooting-section h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.troubleshooting-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.troubleshooting-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: 8px;
	border-left: 4px solid var(--color-warning);
}

.troubleshooting-number {
	width: 24px;
	height: 24px;
	background: var(--color-warning);
	color: white;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	font-size: 12px;
	flex-shrink: 0;
}

.troubleshooting-text {
	flex: 1;
	color: var(--color-text-light);
	font-size: 14px;
	line-height: 1.4;
}

/* Enhanced Step Items */
.step-item.failed {
	border-left-color: var(--color-error);
	background: rgba(var(--color-error), 0.05);
}

.step-item.completed {
	border-left-color: var(--color-success);
}

.step-timestamp {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

.timestamp {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 8px;
	font-size: 12px;
	opacity: 0.8;
}

.mode-badge {
	background: rgba(255, 255, 255, 0.2);
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
}

/* Setup Steps Timeline */
.setup-steps {
	margin: 24px 0;
}

.setup-steps h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.steps-timeline {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.step-item {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: 12px;
	border-left: 4px solid var(--color-success);
}

.step-indicator {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.step-number {
	width: 32px;
	height: 32px;
	background: var(--color-primary);
	color: white;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	font-size: 14px;
}

.step-status {
	font-size: 16px;
}

.step-content {
	flex: 1;
}

.step-content h5 {
	margin: 0 0 4px 0;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
}

.step-description {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.step-details {
	display: grid;
	gap: 6px;
}

.step-detail {
	display: flex;
	justify-content: space-between;
	padding: 6px 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	font-size: 12px;
}

.step-detail .detail-label {
	font-weight: 500;
	color: var(--color-main-text);
}

.step-detail .detail-value {
	color: var(--color-text-maxcontrast);
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

/* Infrastructure Overview */
.infrastructure-overview {
	margin: 24px 0;
}

.infrastructure-overview h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.infrastructure-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
}

.infra-card {
	background: var(--color-background-hover);
	border-radius: 12px;
	padding: 16px;
	border: 1px solid var(--color-border);
	transition: transform 0.2s ease;
}

.infra-card:hover {
	transform: translateY(-2px);
}

.infra-card.configsets {
	border-left: 4px solid #FF9800;
}

.infra-card.collections {
	border-left: 4px solid #4CAF50;
}

.infra-card.schema {
	border-left: 4px solid #2196F3;
}

.infra-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.infra-icon {
	font-size: 18px;
}

.infra-header h5 {
	margin: 0;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
}

.infra-content {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.infra-stat {
	display: flex;
	align-items: baseline;
	gap: 8px;
}

.infra-stat .stat-number {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-primary);
}

.infra-stat .stat-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.infra-list {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.list-item {
	background: var(--color-background-dark);
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

.infra-features {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.feature-badge {
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
}

.feature-badge.success {
	background: rgba(76, 175, 80, 0.1);
	color: var(--color-success);
	border: 1px solid rgba(76, 175, 80, 0.2);
}

/* Next Steps */
.next-steps {
	margin: 24px 0;
}

.next-steps h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.next-steps-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.next-step-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: linear-gradient(135deg, var(--color-primary-light) 0%, var(--color-primary) 100%);
	color: white;
	border-radius: 8px;
	font-size: 14px;
}

.next-step-icon {
	width: 24px;
	height: 24px;
	background: rgba(255, 255, 255, 0.2);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	font-size: 12px;
	flex-shrink: 0;
}

.next-step-text {
	flex: 1;
}
</style>