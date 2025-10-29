<template>
	<NcSettingsSection name="Search Configuration"
		description="Configure Apache SOLR search engine for advanced search capabilities">
		<div class="solr-options">
			<!-- Actions Bar -->
			<div class="section-header-inline">
				<span />
				<div class="button-group">
					<!-- Refresh Stats Button -->
					<NcButton
						v-if="solrOptions.enabled"
						type="secondary"
						:disabled="loadingStats"
						@click="loadSolrStats">
						<template #icon>
							<NcLoadingIcon v-if="loadingStats" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ t('openregister', 'Refresh Stats') }}
					</NcButton>

					<!-- All SOLR Actions Menu -->
					<NcActions
						:aria-label="t('openregister', 'SOLR actions menu')"
						:menu-name="t('openregister', 'Actions')">
						<template #icon>
							<DotsVertical :size="20" />
						</template>

						<!-- Connection Settings -->
						<NcActionButton @click="showConnectionDialog = true">
							<template #icon>
								<Connection :size="20" />
							</template>
							{{ t('openregister', 'Connection Settings') }}
						</NcActionButton>

						<!-- ConfigSet Management -->
						<NcActionButton @click="showConfigSetDialog = true">
							<template #icon>
								<Cog :size="20" />
							</template>
							{{ t('openregister', 'ConfigSet Management') }}
						</NcActionButton>

						<!-- Collection Management -->
						<NcActionButton @click="showCollectionDialog = true">
							<template #icon>
								<DatabaseCog :size="20" />
							</template>
						{{ t('openregister', 'Collection Management') }}
					</NcActionButton>

					<!-- File Processing -->
						<NcActionButton
							v-if="solrOptions.enabled"
							@click="openFileWarmup">
							<template #icon>
								<Fire :size="20" />
							</template>
							{{ t('openregister', 'File Warmup') }}
						</NcActionButton>

						<NcActionButton
							v-if="solrOptions.enabled"
							@click="openWarmupModal">
							<template #icon>
								<Fire :size="20" />
							</template>
							{{ t('openregister', 'Object Warmup') }}
						</NcActionButton>

						<!-- Diagnostics -->
						<NcActionButton
							:disabled="loading || saving || warmingUpSolr || loadingFields"
							@click="inspectFields">
							<template #icon>
								<NcLoadingIcon v-if="loadingFields" :size="20" />
								<ViewList v-else :size="20" />
							</template>
							{{ t('openregister', 'Inspect Fields') }}
						</NcActionButton>
						<NcActionButton
							v-if="solrOptions.enabled"
							@click="openInspectModal">
							<template #icon>
								<FileSearchOutline :size="20" />
							</template>
							{{ t('openregister', 'Inspect Index') }}
						</NcActionButton>
						<NcActionButton
							v-if="solrOptions.enabled"
							@click="openFacetConfigModal">
							<template #icon>
								<Tune :size="20" />
							</template>
							{{ t('openregister', 'Configure Facets') }}
						</NcActionButton>
					</NcActions>
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
					v-model="solrEnabled"
					:disabled="saving"
					type="switch">
					{{ solrEnabled ? t('openregister', 'SOLR search enabled') : t('openregister', 'SOLR search disabled') }}
				</NcCheckboxRadioSwitch>
				<p class="option-description">
					{{ t('openregister', 'Enable or disable SOLR search integration. Configure connection settings using the Connection Settings button above.') }}
					<span v-if="saving" class="saving-indicator">
						<NcLoadingIcon :size="14" /> {{ t('openregister', 'Saving...') }}
					</span>
				</p>
			</div>
		</div>

		<!-- SOLR Management Dashboard -->
		<div v-if="solrEnabled" class="solr-management-section">
			<!-- Loading State -->
			<div v-if="loadingStats" class="loading-section">
				<NcLoadingIcon :size="32" />
				<p>Loading SOLR statistics...</p>
			</div>

			<!-- Error State -->
			<div v-else-if="solrError" class="error-section">
				<p class="error-message">
					❌ {{ solrErrorMessage }}
				</p>
				<NcButton type="primary" @click="loadSolrStats">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Retry Connection
				</NcButton>
			</div>

			<!-- Success State -->
			<div v-else-if="solrStats && solrStats.available" class="dashboard-section">
				<!-- Dashboard Stats -->
				<div class="dashboard-stats-grid">
					<div class="stat-card">
						<h5>Connection Status</h5>
						<p :class="connectionStatusClass">
							{{ solrStats.overview?.connection_status || 'Unknown' }}
						</p>
					</div>

					<div class="stat-card">
						<h5>Total Objects</h5>
						<p>{{ formatNumber(solrStats.total_count || 0) }}</p>
					</div>

					<div class="stat-card">
						<h5>Published Objects</h5>
						<p>{{ formatNumber(solrStats.published_count || 0) }}</p>
					</div>

					<div class="stat-card">
						<h5>Indexed Objects</h5>
						<p>{{ formatNumber(solrStats.document_count || 0) }}</p>
					</div>

					<div class="stat-card">
						<h5>Total Files</h5>
						<p>{{ formatNumber(solrStats.total_files || 0) }}</p>
					</div>

					<div class="stat-card">
						<h5>Indexed Files</h5>
						<p>{{ formatNumber(solrStats.indexed_files || 0) }}</p>
					</div>
				</div>
			</div>

			<!-- Default State (no data) -->
			<div v-else class="no-data-section">
				<p>No SOLR data available</p>
				<NcButton type="primary" @click="loadSolrStats">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Load Stats
				</NcButton>
			</div>
		</div>

		<!-- Setup SOLR Results Dialog -->
		<NcDialog
			v-if="showSetupDialog"
			name="SOLR Setup Results"
			:can-close="!settingUpSolr"
			:size="'large'"
			@closing="hideSetupDialog">
			<div class="setup-dialog">
				<!-- Confirmation State -->
				<div v-if="!settingUpSolr && !setupResults" class="setup-confirmation">
					<div class="confirmation-icon">
						🚀
					</div>
					<h4>SOLR Setup</h4>
					<p class="confirmation-description">
						This will configure your SOLR search engine for OpenRegister. The setup process will:
					</p>
					<div class="setup-preview-steps">
						<ul>
							<li>🔗 <strong>Test connectivity</strong> to your SOLR cluster</li>
							<li>📦 <strong>Create configuration sets</strong> with your search schema</li>
							<li>⏱️ <strong>Sync configurations</strong> across all cluster nodes</li>
							<li>🗂️ <strong>Create search collections</strong> for your data</li>
							<li>🔧 <strong>Configure field mappings</strong> and search rules</li>
						</ul>
					</div>
					<div class="timing-warning">
						<strong>⏳ Expected Duration:</strong> 1-3 minutes<br>
						<small>In distributed SOLR environments, configurations need time to propagate across multiple server nodes via ZooKeeper coordination.</small>
					</div>
					<div class="confirmation-actions">
						<NcButton type="secondary" @click="hideSetupDialog">
							Cancel
						</NcButton>
						<NcButton type="primary" @click="startSolrSetup">
							<template #icon>
								<PlayIcon :size="16" />
							</template>
							Start Setup
						</NcButton>
					</div>
				</div>

				<div v-else-if="settingUpSolr" class="setup-loading">
					<div class="loading-spinner">
						<NcLoadingIcon :size="40" />
					</div>
					<h4>Setting up SOLR...</h4>
					<div class="game-loading-content">
						<div class="educational-tips">
							<div v-for="(tip, index) in visibleTips"
								:key="index"
								class="tip-item"
								:class="{ 'tip-fade-in': tip.visible }">
								{{ tip.text }}
							</div>
						</div>
						<div class="loading-progress-text">
							{{ currentLoadingMessage }}
						</div>
					</div>
				</div>

				<div v-else-if="setupResults" class="setup-results">
					<!-- Overall Status Header -->
					<div class="results-header">
						<div class="status-badge" :class="setupResults.success ? 'success' : 'error'">
							<span class="status-icon">{{ setupResults.success ? '✅' : '❌' }}</span>
							<div class="status-text">
								<h3>{{ setupResults.success ? 'SOLR Setup Completed!' : 'SOLR Setup Failed' }}</h3>
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
										:class="setupResults.success ? 'success' : 'error'" />
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

					<!-- ConfigSet Propagation Error (Special handling) -->
					<div v-if="!setupResults.success && isConfigSetPropagationError" class="propagation-error-section">
						<h4>⏱️ ConfigSet Propagation Delay</h4>
						<div class="propagation-explanation">
							<p><strong>What happened?</strong></p>
							<p>The configuration was successfully created but is still propagating across the SOLR cluster nodes. This is normal in distributed SOLR environments.</p>

							<div class="propagation-details">
								<p><strong>📊 Retry Information:</strong></p>
								<ul v-if="setupResults.error_details?.solr_response">
									<li>🔄 <strong>Attempts made:</strong> {{ setupResults.error_details.solr_response.attempts || 'Unknown' }}</li>
									<li>⏱️ <strong>Total time:</strong> {{ setupResults.error_details.solr_response.total_elapsed_seconds || 'Unknown' }} seconds</li>
									<li>🕐 <strong>Started at:</strong> {{ formatTime(setupResults.error_details.solr_response.attempt_timestamps?.[0]) }}</li>
									<li>🕐 <strong>Last attempt:</strong> {{ formatTime(setupResults.error_details.solr_response.attempt_timestamps?.slice(-1)[0]) }}</li>
								</ul>
							</div>

							<div class="propagation-solution">
								<p><strong>🛠️ What to do next:</strong></p>
								<ol>
									<li><strong>Wait 2-5 minutes</strong> for the configuration to fully propagate</li>
									<li><strong>Click "Setup Again"</strong> to retry the setup process</li>
									<li>If the issue persists, contact your SOLR administrator</li>
								</ol>
							</div>

							<div v-if="setupResults.error_details?.configuration_used" class="propagation-technical">
								<details>
									<summary>🔧 Technical Details</summary>
									<div class="config-grid">
										<div v-for="(value, key) in setupResults.error_details.configuration_used" :key="key" class="config-item">
											<span class="config-key">{{ key }}:</span>
											<span class="config-value">{{ value }}</span>
										</div>
									</div>
								</details>
							</div>
						</div>
					</div>

					<!-- General Error Information (shown for other failures) -->
					<div v-else-if="!setupResults.success && setupResults.error_details" class="error-details-section">
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
							<div v-for="step in setupResults.steps"
								:key="step.step_number"
								class="step-item"
								:class="step.status">
								<div class="step-indicator">
									<span class="step-number">{{ step.step_number }}</span>
									<span class="step-status" :class="step.status">
										{{ step.status === 'completed' ? '✅' : step.status === 'failed' ? '❌' : '⏳' }}
									</span>
								</div>
								<div class="step-content">
									<h5>{{ step.step_name }}</h5>
									<p class="step-description">
										{{ step.description }}
									</p>
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

		<!-- SOLR Fields Inspection Dialog -->
		<NcDialog
			v-if="showFieldsDialog"
			name="SOLR Field Configuration"
			:can-close="!loadingFields"
			:size="'large'"
			@closing="hideFieldsDialog">
			<div class="fields-dialog">
				<div v-if="loadingFields" class="fields-loading">
					<div class="loading-spinner">
						<NcLoadingIcon :size="40" />
					</div>
					<h4>Loading SOLR Field Configuration...</h4>
					<p class="loading-description">
						Please wait while we retrieve the field configuration from your SOLR core.
					</p>
				</div>

				<div v-else-if="fieldsInfo" class="fields-results">
					<!-- Mismatch Alert -->
					<div v-if="fieldComparison && fieldComparison.total_differences > 0" class="mismatch-alert">
						<div class="alert-content">
							<span class="alert-icon">⚠️</span>
							<div class="alert-text">
								<h3>Configuration Issues Found</h3>
								<p>{{ fieldComparison.total_differences }} field configuration differences detected between schemas and SOLR.</p>
							</div>
							<button class="alert-button" @click="scrollToMismatches">
								View Issues
							</button>
						</div>
					</div>

					<div v-if="fieldsInfo.success && fieldsInfo.fields" class="fields-content">
						<!-- Fields Overview -->
						<div class="fields-overview">
							<h4>📊 Fields Overview</h4>
							<div class="overview-stats">
								<div class="stat-card">
									<div class="stat-number">
										{{ Object.keys(fieldsInfo.fields).length }}
									</div>
									<div class="stat-label">
										Total Fields
									</div>
								</div>
								<div class="stat-card">
									<div class="stat-number">
										{{ fieldsInfo.dynamic_fields ? Object.keys(fieldsInfo.dynamic_fields).length : 0 }}
									</div>
									<div class="stat-label">
										Dynamic Fields
									</div>
								</div>
								<div class="stat-card">
									<div class="stat-number">
										{{ fieldsInfo.field_types ? Object.keys(fieldsInfo.field_types).length : 0 }}
									</div>
									<div class="stat-label">
										Field Types
									</div>
								</div>
							</div>
						</div>

						<!-- Core Information -->
						<div v-if="fieldsInfo.core_info" class="core-info">
							<h4>🏗️ Core Information</h4>
							<div class="info-grid">
								<div class="info-item">
									<span class="info-label">Core Name:</span>
									<span class="info-value">{{ fieldsInfo.core_info.core_name }}</span>
								</div>
								<div class="info-item">
									<span class="info-label">Schema Name:</span>
									<span class="info-value">{{ fieldsInfo.core_info.schema_name }}</span>
								</div>
								<div class="info-item">
									<span class="info-label">Schema Version:</span>
									<span class="info-value">{{ fieldsInfo.core_info.schema_version }}</span>
								</div>
								<div class="info-item">
									<span class="info-label">Unique Key:</span>
									<span class="info-value">{{ fieldsInfo.core_info.unique_key }}</span>
								</div>
							</div>
						</div>

						<!-- Fields Table -->
						<div class="fields-table-section">
							<h4>🔍 Field Details</h4>
							<div class="fields-controls">
								<input
									v-model="fieldFilter"
									type="text"
									placeholder="Filter fields..."
									class="field-filter">
								<NcSelect
									v-model="fieldTypeFilter"
									:options="fieldTypeOptions"
									placeholder="Filter by type"
									:clearable="true"
									class="field-type-filter" />
							</div>
							<div class="fields-table-container">
								<table class="fields-table">
									<thead>
										<tr>
											<th>Field Name</th>
											<th>Type</th>
											<th>Indexed</th>
											<th>Stored</th>
											<th>Multi</th>
											<th>Req.</th>
											<th>DocVals</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(field, fieldName) in filteredFields" :key="fieldName" class="field-row">
											<td class="field-name">
												<code>{{ fieldName }}</code>
											</td>
											<td class="field-type">
												<span class="type-badge" :class="getTypeClass(field.type)">
													{{ field.type }}
												</span>
											</td>
											<td class="field-indexed">
												<span class="boolean-indicator" :class="field.indexed ? 'true' : 'false'">
													{{ field.indexed ? '✓' : '✗' }}
												</span>
											</td>
											<td class="field-stored">
												<span class="boolean-indicator" :class="field.stored ? 'true' : 'false'">
													{{ field.stored ? '✓' : '✗' }}
												</span>
											</td>
											<td class="field-multivalued">
												<span class="boolean-indicator" :class="field.multiValued ? 'true' : 'false'">
													{{ field.multiValued ? '✓' : '✗' }}
												</span>
											</td>
											<td class="field-required">
												<span class="boolean-indicator" :class="field.required ? 'true' : 'false'">
													{{ field.required ? '✓' : '✗' }}
												</span>
											</td>
											<td class="field-docvalues">
												<span class="boolean-indicator" :class="field.docValues ? 'true' : 'false'">
													{{ field.docValues ? '✓' : '✗' }}
												</span>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<!-- Dynamic Fields -->
						<div v-if="fieldsInfo.dynamic_fields && Object.keys(fieldsInfo.dynamic_fields).length > 0" class="dynamic-fields-section">
							<h4>⚡ Dynamic Field Patterns</h4>
							<div class="dynamic-fields-grid">
								<div v-for="(field, pattern) in fieldsInfo.dynamic_fields" :key="pattern" class="dynamic-field-card">
									<div class="dynamic-field-header">
										<code class="pattern-name">{{ pattern }}</code>
										<span class="type-badge" :class="getTypeClass(field.type)">{{ field.type }}</span>
									</div>
									<div class="dynamic-field-properties">
										<div class="property-row">
											<span class="property-label">Indexed:</span>
											<span class="boolean-indicator" :class="field.indexed ? 'true' : 'false'">
												{{ field.indexed ? '✓' : '✗' }}
											</span>
										</div>
										<div class="property-row">
											<span class="property-label">Stored:</span>
											<span class="boolean-indicator" :class="field.stored ? 'true' : 'false'">
												{{ field.stored ? '✓' : '✗' }}
											</span>
										</div>
										<div class="property-row">
											<span class="property-label">Multi-valued:</span>
											<span class="boolean-indicator" :class="field.multiValued ? 'true' : 'false'">
												{{ field.multiValued ? '✓' : '✗' }}
											</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Schema vs SOLR Comparison -->
					<div v-if="fieldComparison && fieldComparison.total_differences > 0" id="field-mismatches" class="comparison-section">
						<h3 class="comparison-title">
							<span class="comparison-icon">⚠️</span>
							Schema vs SOLR Differences ({{ fieldComparison.total_differences }})
						</h3>
						<p class="comparison-description">
							The following differences were detected between your OpenRegister schemas and the actual SOLR configuration:
						</p>

						<!-- Missing Fields -->
						<div v-if="fieldComparison.missing && fieldComparison.missing.length > 0" class="difference-category">
							<h4 class="category-title missing">
								Missing Fields ({{ fieldComparison.missing.length }})
							</h4>
							<p class="category-description">
								Fields defined in schemas but not present in SOLR:
							</p>
							<table class="comparison-table">
								<thead>
									<tr>
										<th>Field Name</th>
										<th>Expected Type</th>
										<th>Expected Config</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="field in fieldComparison.missing" :key="'missing-' + field.name">
										<td class="field-name">
											{{ field.name }}
										</td>
										<td>
											<span class="field-type" :class="field.config && field.config.type">{{ field.config && field.config.type || field.type }}</span>
										</td>
										<td class="config-details">
											<span v-if="field.config && field.config.multiValued" class="config-badge multi">Multi</span>
											<span v-if="field.config && field.config.indexed" class="config-badge indexed">Indexed</span>
											<span v-if="field.config && field.config.stored" class="config-badge stored">Stored</span>
											<span v-if="field.config && field.config.docValues" class="config-badge docvalues">DocValues</span>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Extra Fields -->
						<div v-if="fieldComparison.extra && fieldComparison.extra.length > 0" class="difference-category">
							<h4 class="category-title extra">
								Extra Fields ({{ fieldComparison.extra.length }})
							</h4>
							<p class="category-description">
								Fields present in SOLR but not defined in any schema:
							</p>
							<table class="comparison-table">
								<thead>
									<tr>
										<th>Field Name</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="fieldName in fieldComparison.extra" :key="'extra-' + fieldName">
										<td class="field-name">
											{{ fieldName }}
										</td>
										<td class="field-actions">
											<NcButton
												type="error"
												:disabled="deletingField === fieldName"
												:aria-label="`Delete field ${fieldName}`"
												@click="deleteField(fieldName)">
												<template #icon>
													<NcLoadingIcon v-if="deletingField === fieldName" :size="16" />
													<Delete v-else :size="16" />
												</template>
											</NcButton>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Mismatched Fields -->
						<div v-if="fieldComparison.mismatched && fieldComparison.mismatched.length > 0" class="difference-category">
							<h4 class="category-title mismatched">
								Configuration Mismatches ({{ fieldComparison.mismatched.length }})
							</h4>
							<p class="category-description">
								Fields with different configuration between schemas and SOLR:
							</p>
							<div v-for="field in fieldComparison.mismatched" :key="'mismatch-' + field.field" class="field-comparison-card">
								<div class="field-header">
									<h5 class="field-title">
										{{ field.field }}
									</h5>
									<NcButton
										type="error"
										:disabled="deletingField === field.field"
										:aria-label="`Delete field ${field.field}`"
										@click="deleteField(field.field)">
										<template #icon>
											<NcLoadingIcon v-if="deletingField === field.field" :size="16" />
											<Delete v-else :size="16" />
										</template>
										Delete
									</NcButton>
								</div>
								<table class="comparison-table">
									<thead>
										<tr>
											<th>Property</th>
											<th>Expected</th>
											<th>Actual</th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td class="property-name">
												Type
											</td>
											<td class="field-config expected-config">
												<span class="field-value expected-value">
													{{ field.expected_type }}
												</span>
											</td>
											<td class="field-config">
												<span class="field-value" :class="{ 'match': field.expected_type === field.actual_type, 'mismatch': field.expected_type !== field.actual_type }">
													{{ field.actual_type }}
												</span>
											</td>
										</tr>
										<tr>
											<td class="property-name">
												Multi
											</td>
											<td class="field-config expected-config">
												<span class="field-value expected-value">
													{{ field.expected_multiValued ? 'Yes' : 'No' }}
												</span>
											</td>
											<td class="field-config">
												<span class="field-value" :class="{ 'match': field.expected_multiValued === field.actual_multiValued, 'mismatch': field.expected_multiValued !== field.actual_multiValued }">
													{{ field.actual_multiValued ? 'Yes' : 'No' }}
												</span>
											</td>
										</tr>
										<tr>
											<td class="property-name">
												DocValues
											</td>
											<td class="field-config expected-config">
												<span class="field-value expected-value">
													{{ field.expected_docValues ? 'Yes' : 'No' }}
												</span>
											</td>
											<td class="field-config">
												<span class="field-value" :class="{ 'match': field.expected_docValues === field.actual_docValues, 'mismatch': field.expected_docValues !== field.actual_docValues }">
													{{ field.actual_docValues ? 'Yes' : 'No' }}
												</span>
											</td>
										</tr>
									</tbody>
								</table>
							</div>

							<!-- Fix Mismatches Actions -->
							<div class="fix-mismatches-section">
								<h4 class="fix-title">
									Fix Configuration Issues
								</h4>
								<p class="fix-description">
									Update SOLR field configurations to match the expected schema definitions.
								</p>
								<div class="fix-actions">
									<NcButton
										type="secondary"
										:disabled="fixingFields"
										@click="fixMismatchedFields(true)">
										<template #icon>
											<NcLoadingIcon v-if="fixingFields" :size="16" />
											<Eye v-else :size="16" />
										</template>
										Preview Changes (Dry Run)
									</NcButton>
									<NcButton
										type="primary"
										:disabled="fixingFields"
										@click="fixMismatchedFields(false)">
										<template #icon>
											<NcLoadingIcon v-if="fixingFields" :size="16" />
											<Wrench v-else :size="16" />
										</template>
										Fix Mismatched Fields
									</NcButton>
								</div>
							</div>
						</div>
					</div>

					<!-- Field Creation Actions -->
					<div v-if="fieldComparison && fieldComparison.missing && fieldComparison.missing.length > 0" class="field-actions-section">
						<h3 class="actions-title">
							<span class="actions-icon">🛠️</span>
							Field Creation Actions
						</h3>
						<p class="actions-description">
							Create the missing fields in SOLR to resolve schema differences:
						</p>
						<div class="action-buttons">
							<NcButton
								type="secondary"
								:disabled="creatingFields"
								@click="createMissingFields(true)">
								<template #icon>
									<NcLoadingIcon v-if="creatingFields" :size="20" />
									<span v-else>🔍</span>
								</template>
								Preview Changes (Dry Run)
							</NcButton>

							<NcButton
								type="primary"
								:disabled="creatingFields"
								@click="createMissingFields(false)">
								<template #icon>
									<NcLoadingIcon v-if="creatingFields" :size="20" />
									<span v-else>🚀</span>
								</template>
								Create {{ fieldComparison.missing.length }} Missing Fields
							</NcButton>
						</div>
					</div>

					<!-- Field Creation Results -->
					<div v-if="fieldCreationResult" class="field-creation-results">
						<div v-if="fieldCreationResult.success" class="success-result">
							<span class="result-icon">✅</span>
							<strong>{{ fieldCreationResult.message }}</strong>
							<div v-if="fieldCreationResult.dry_run && fieldCreationResult.would_create" class="dry-run-preview">
								<p><strong>Fields that would be created:</strong></p>
								<ul class="field-list">
									<li v-for="field in fieldCreationResult.would_create" :key="field" class="field-item">
										{{ field }}
									</li>
								</ul>
							</div>
							<div v-else-if="fieldCreationResult.created && fieldCreationResult.created.length > 0" class="created-fields">
								<p><strong>Successfully created {{ fieldCreationResult.created.length }} fields:</strong></p>
								<ul class="field-list">
									<li v-for="field in fieldCreationResult.created" :key="field" class="field-item success">
										✅ {{ field }}
									</li>
								</ul>
								<div v-if="fieldCreationResult.execution_time_ms" class="execution-time">
									<small>Completed in {{ fieldCreationResult.execution_time_ms }}ms</small>
								</div>
							</div>
						</div>
						<div v-else class="error-result">
							<span class="result-icon">❌</span>
							<strong>{{ fieldCreationResult.message }}</strong>
							<div v-if="fieldCreationResult.errors && Object.keys(fieldCreationResult.errors).length > 0" class="creation-errors">
								<p><strong>Errors encountered:</strong></p>
								<ul class="error-list">
									<li v-for="(error, field) in fieldCreationResult.errors" :key="field" class="error-item">
										<strong>{{ field }}:</strong> {{ error }}
									</li>
								</ul>
							</div>
						</div>
					</div>

					<!-- No Differences Message -->
					<div v-else-if="fieldComparison && fieldComparison.total_differences === 0" class="no-differences">
						<div class="success-message">
							<span class="success-icon">✅</span>
							<h4>Schema and SOLR in Sync</h4>
							<p>All schema fields are properly configured in SOLR. No differences detected.</p>
						</div>
					</div>

					<!-- Error Display -->
					<div v-else-if="!fieldsInfo.success" class="fields-error">
						<div class="error-card">
							<h4>❌ Failed to Load Field Configuration</h4>
							<p>{{ fieldsInfo.message || 'Unable to retrieve SOLR field configuration' }}</p>
							<div v-if="fieldsInfo.details" class="error-details">
								<details>
									<summary>Error Details</summary>
									<pre>{{ JSON.stringify(fieldsInfo.details, null, 2) }}</pre>
								</details>
							</div>
						</div>
					</div>
				</div>

				<div class="dialog-actions">
					<NcButton
						:disabled="loadingFields"
						@click="hideFieldsDialog">
						Close
					</NcButton>
					<NcButton
						v-if="!loadingFields && fieldsInfo && !fieldsInfo.success"
						type="primary"
						@click="retryLoadFields">
						<template #icon>
							<ViewList :size="20" />
						</template>
						Retry
					</NcButton>
				</div>
			</div>
		</NcDialog>

		<!-- Dashboard Modals -->
		<!-- Warmup Modal -->
		<SolrWarmupModal
			:show="showWarmupDialog"
			:object-stats="objectStats"
			:memory-prediction="memoryPrediction"
			:warming-up="warmingUp"
			:completed="warmupCompleted"
			:results="warmupResults"
			:config="warmupConfig"
			:available-schemas="availableSchemas"
			:schemas-loading="schemasLoading"
			@close="closeWarmupModal"
			@start-warmup="handleStartWarmup" />

		<!-- Clear Index Modal -->
		<ClearIndexModal
			:show="showClearDialog"
			@close="showClearDialog = false"
			@confirm="handleClearIndex" />

		<!-- Inspect Index Modal -->
		<InspectIndexModal
			:show="showInspectDialog"
			@close="showInspectDialog = false" />

		<!-- Delete Collection Modal -->
		<DeleteCollectionModal
			:show="showDeleteCollectionDialog"
			@close="closeDeleteCollectionModal"
			@deleted="handleCollectionDeleted" />

		<!-- Facet Configuration Modal -->
		<FacetConfigModal
			:show="showFacetConfigDialog"
			@close="showFacetConfigDialog = false" />

		<!-- Connection Configuration Modal -->
		<ConnectionConfigModal
			:show="showConnectionDialog"
			:config="solrOptions"
			:scheme-options="schemeOptions"
			:saving="saving"
			@close="showConnectionDialog = false"
			@save="handleConnectionSave" />

		<!-- ConfigSet Management Modal -->
		<ConfigSetManagementModal
			:show="showConfigSetDialog"
			@closing="showConfigSetDialog = false" />

		<!-- Collection Management Modal -->
		<CollectionManagementModal
			:show="showCollectionDialog"
			@closing="showCollectionDialog = false" />

		<!-- File Warmup Modal -->
		<FileWarmupModal
			:open="showFileWarmupDialog"
			@close="showFileWarmupDialog = false" />
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import { NcSettingsSection, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect, NcDialog, NcActions, NcActionButton } from '@nextcloud/vue'
import Settings from 'vue-material-design-icons/ApplicationSettings.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ViewList from 'vue-material-design-icons/ViewList.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Fire from 'vue-material-design-icons/Fire.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DatabaseRemove from 'vue-material-design-icons/DatabaseRemove.vue'
import FileSearchOutline from 'vue-material-design-icons/FileSearchOutline.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'
import Connection from 'vue-material-design-icons/Connection.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import DatabaseCog from 'vue-material-design-icons/DatabaseCog.vue'
import { SolrWarmupModal, ClearIndexModal } from '../../../modals/settings'
import InspectIndexModal from '../../../modals/settings/InspectIndexModal.vue'
import DeleteCollectionModal from '../../../modals/settings/DeleteCollectionModal.vue'
import FacetConfigModal from '../../../modals/settings/FacetConfigModal.vue'
import ConnectionConfigModal from '../../../modals/settings/ConnectionConfigModal.vue'
import ConfigSetManagementModal from '../../../modals/settings/ConfigSetManagementModal.vue'
import CollectionManagementModal from '../../../modals/settings/CollectionManagementModal.vue'
import FileWarmupModal from '../../../modals/settings/FileWarmupModal.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SolrConfiguration',

	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcDialog,
		NcActions,
		NcActionButton,
		Settings,
		TestTube,
		Save,
		Refresh,
		ViewList,
		Wrench,
		Eye,
		Fire,
		Delete,
		DatabaseRemove,
		FileSearchOutline,
		PlayIcon,
		Tune,
		Magnify,
		DotsVertical,
		Connection,
		Cog,
		DatabaseCog,
		SolrWarmupModal,
		ClearIndexModal,
		InspectIndexModal,
		DeleteCollectionModal,
		FacetConfigModal,
		ConnectionConfigModal,
		ConfigSetManagementModal,
		CollectionManagementModal,
		FileWarmupModal,
	},

	data() {
		return {
			fieldFilter: '',
			fieldTypeFilter: null,
			deletingField: null, // Track which field is being deleted
			reindexing: false, // Track reindex operation
			// Dashboard data properties
			loadingStats: false,
			solrError: false,
			solrErrorMessage: '',
			showWarmupDialog: false,
			showClearDialog: false,
			showInspectDialog: false,
			showDeleteCollectionDialog: false,
			showConnectionDialog: false,
			showConfigSetDialog: false,
			showCollectionDialog: false,
			showFileWarmupDialog: false,
			solrStats: null,
			objectStats: {
				loading: false,
				totalObjects: 0,
			},
			memoryPrediction: {
				prediction_safe: true,
				formatted: {
					total_predicted: 'Unknown',
					available: 'Unknown',
				},
			},
			warmingUp: false,
			warmupCompleted: false,
			warmupResults: null,
			warmupConfig: {
				mode: 'serial',
				maxObjects: 0,
				batchSize: 1000,
				collectErrors: false,
				selectedSchemas: [],
			},
			availableSchemas: [],
			schemasLoading: false,
			// Game-style loading
			loadingTips: [
				'🔍 SOLR is a powerful enterprise search platform built on Apache Lucene...',
				'🌐 In distributed mode, SOLR uses ZooKeeper for cluster coordination...',
				'📦 ConfigSets contain the schema and configuration files for your search index...',
				'⚡ SOLR can handle millions of documents with sub-second search response times...',
				'🔄 Replication ensures your search index is available even if nodes fail...',
				'🎯 Faceted search allows users to drill down into results by categories...',
				'📊 SOLR provides rich analytics and statistics about search performance...',
				'🛡️ Security features include authentication, authorization, and SSL encryption...',
				'🚀 Auto-scaling can dynamically add or remove nodes based on load...',
				'💡 Did you know? SOLR powers search for Netflix, Apple, and many other major sites!',
			],
			visibleTips: [],
			currentLoadingMessage: 'Initializing SOLR setup...',
			loadingInterval: null,
			tipIndex: 0,
			// Facet configuration
			showFacetConfigDialog: false,
		}
	},

	computed: {
		...mapStores(useSettingsStore),

		// Access the settings store
		settingsStore() {
			return useSettingsStore()
		},

		solrOptions: {
			get() {
				return this.settingsStore.solrOptions
			},
			set(value) {
				this.settingsStore.solrOptions = value
			},
		},

		// Computed property for SOLR enabled toggle with auto-save
		solrEnabled: {
			get() {
				const value = Boolean(this.solrOptions?.enabled)
				console.log('🔍 solrEnabled getter called, returning:', value)
				return value
			},
			async set(newValue) {
				console.log('🔄 solrEnabled setter called with:', newValue)
				// Update the store
				this.solrOptions.enabled = newValue
				console.log('💾 Saving settings...')

				// Auto-save the settings
				await this.saveSettings()
				console.log('✅ Settings saved')

				// If SOLR was just enabled, load stats
				if (newValue) {
					console.log('📊 SOLR enabled, loading stats...')
					await this.loadSolrStats()
				}
			},
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

		showFieldsDialog() {
			return this.settingsStore.showFieldsDialog
		},

		loadingFields() {
			return this.settingsStore.loadingFields
		},

		fieldsInfo() {
			return this.settingsStore.fieldsInfo
		},

		fieldComparison() {
			return this.settingsStore.fieldComparison
		},

		creatingFields() {
			return this.settingsStore.creatingFields
		},

		fixingFields() {
			return this.settingsStore.fixingFields
		},

		fieldCreationResult() {
			return this.settingsStore.fieldCreationResult
		},

		filteredFields() {
			if (!this.fieldsInfo || !this.fieldsInfo.fields) {
				return {}
			}

			let fields = this.fieldsInfo.fields

			// Apply text filter
			if (this.fieldFilter) {
				const filter = this.fieldFilter.toLowerCase()
				fields = Object.fromEntries(
					Object.entries(fields).filter(([name]) =>
						name.toLowerCase().includes(filter),
					),
				)
			}

			// Apply type filter
			if (this.fieldTypeFilter) {
				fields = Object.fromEntries(
					Object.entries(fields).filter(([, field]) =>
						field.type === this.fieldTypeFilter.value,
					),
				)
			}

			return fields
		},

		fieldTypeOptions() {
			if (!this.fieldsInfo || !this.fieldsInfo.fields) {
				return []
			}

			const types = [...new Set(Object.values(this.fieldsInfo.fields).map(field => field.type))]
			return types.map(type => ({
				value: type,
				label: type,
			})).sort((a, b) => a.label.localeCompare(b.label))
		},

		// Dashboard computed properties
		connectionStatusClass() {
			if (!this.solrStats || !this.solrStats.available) {
				return 'status-error'
			}
			if (this.solrStats.overview?.connection_status === 'Connected') {
				return 'status-success'
			}
			return 'status-warning'
		},
	},

	async mounted() {
		console.log('🔧 SolrConfiguration mounted - starting initialization')

		// Wait for settings store to load first
		try {
			await this.settingsStore.loadSettings()
			console.log('✅ Settings loaded')
			console.log('🔘 SOLR enabled value:', this.solrEnabled)
		} catch (error) {
			console.error('❌ Failed to load settings:', error)
		}

		// Load dashboard stats if SOLR is enabled
		if (this.solrEnabled) {
			console.log('📊 SOLR is enabled, loading stats...')
			try {
				// Load both SOLR stats and object stats in parallel
				await Promise.all([
					this.loadSolrStats(),
					this.loadObjectStats(),
				])
				console.log('✅ Stats loaded successfully')
			} catch (error) {
				console.error('❌ Failed to load stats:', error)
			}
		} else {
			console.log('⚠️ SOLR is not enabled, skipping stats load')
			console.log('💡 Tip: Toggle SOLR on to load stats automatically')
		}
	},

	methods: {
		scrollToMismatches() {
			const element = document.getElementById('field-mismatches')
			if (element) {
				element.scrollIntoView({ behavior: 'smooth', block: 'start' })
			}
		},

		async setupSolr() {
			// Just show the setup dialog - it will start with confirmation screen
			this.settingsStore.showSetupDialog = true
			this.settingsStore.setupResults = null
		},

		async startSolrSetup() {
			// Start the game-style loading
			this.startGameLoading()

			// Actually start the SOLR setup process
			await this.settingsStore.setupSolr()

			// Stop the game-style loading
			this.stopGameLoading()
		},

		startGameLoading() {
			this.visibleTips = []
			this.tipIndex = 0
			this.currentLoadingMessage = 'Initializing SOLR setup...'

			// Show first tip immediately
			this.showNextTip()

			// Set interval to show new tips every 3 seconds
			this.loadingInterval = setInterval(() => {
				this.showNextTip()
				this.updateLoadingMessage()
			}, 3000)
		},

		stopGameLoading() {
			if (this.loadingInterval) {
				clearInterval(this.loadingInterval)
				this.loadingInterval = null
			}
		},

		showNextTip() {
			if (this.tipIndex < this.loadingTips.length) {
				this.visibleTips.push({
					text: this.loadingTips[this.tipIndex],
					visible: true,
				})
				this.tipIndex++

				// Keep only last 3 tips visible
				if (this.visibleTips.length > 3) {
					this.visibleTips.shift()
				}
			}
		},

		updateLoadingMessage() {
			const messages = [
				'Connecting to SOLR cluster...',
				'Verifying server connectivity...',
				'Uploading configuration sets...',
				'Waiting for cluster synchronization...',
				'Creating search collections...',
				'Configuring field mappings...',
				'Optimizing search performance...',
				'Finalizing setup...',
			]

			const randomMessage = messages[Math.floor(Math.random() * messages.length)]
			this.currentLoadingMessage = randomMessage
		},

		formatTime(timestamp) {
			if (!timestamp) return 'Unknown'

			try {
				const date = new Date(timestamp)
				return date.toLocaleTimeString()
			} catch (error) {
				return timestamp
			}
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

		async inspectFields() {
			await this.settingsStore.loadSolrFields()
		},

		hideFieldsDialog() {
			this.settingsStore.hideFieldsDialog()
		},

		async createMissingFields(dryRun = false) {
			try {
				await this.settingsStore.createMissingSolrFields(dryRun)

				// Show success notification
				if (this.fieldCreationResult?.success) {
					if (dryRun) {
						showSuccess(`Dry run completed: ${this.fieldCreationResult.would_create?.length || 0} fields would be created`)
					} else {
						showSuccess(`Successfully created ${this.fieldCreationResult.created?.length || 0} SOLR fields`)
					}
				}
			} catch (error) {
				console.error('Error creating missing SOLR fields:', error)
				showError('Failed to create missing SOLR fields: ' + error.message)
			}
		},

		async fixMismatchedFields(dryRun = false) {
			try {
				// Use the dedicated fix-mismatches endpoint (automatically detects and fixes all mismatches)
				await this.settingsStore.fixMismatchedSolrFields(dryRun)

				// Show success notification
				if (this.fieldCreationResult?.success) {
					const fixedCount = this.fieldCreationResult.fixed?.length || 0
					if (dryRun) {
						showSuccess(`Dry run completed: ${fixedCount} fields would be fixed`)
					} else {
						showSuccess(`Successfully fixed ${fixedCount} SOLR field configurations`)
						// Refresh the field comparison after fixing
						await this.inspectFields()
					}
				}
			} catch (error) {
				console.error('Error fixing mismatched SOLR fields:', error)
				showError('Failed to fix mismatched SOLR fields: ' + error.message)
			}
		},

		retryLoadFields() {
			this.inspectFields()
		},

		getTypeClass(type) {
			const typeMap = {
				string: 'type-string',
				text_general: 'type-text',
				pint: 'type-integer',
				pfloat: 'type-float',
				pdate: 'type-date',
				boolean: 'type-boolean',
				plong: 'type-long',
				pdouble: 'type-double',
			}
			return typeMap[type] || 'type-unknown'
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

		// Dashboard methods
		async loadSolrStats() {
			console.log('📊 Loading SOLR stats...')
			this.loadingStats = true
			this.solrError = false
			this.solrErrorMessage = ''

			try {
				const url = generateUrl('/apps/openregister/api/solr/dashboard/stats')
				console.log('🌐 Making API request to:', url)
				const response = await axios.get(url)
				console.log('📦 API response data:', response.data)

				if (response.data && response.data.available) {
					console.log('✅ SOLR is available, storing stats')
					// Store the complete response data (includes published_count, total_count, memory_prediction)
					this.solrStats = {
						...response.data,
						overview: {
							connection_status: 'Connected',
							total_documents: response.data.document_count || 0,
						},
					}
				} else {
					console.warn('⚠️ SOLR not available:', response.data?.error)
					this.solrError = true
					this.solrErrorMessage = response.data?.error || 'SOLR not available'
					this.solrStats = null
				}
			} catch (error) {
				console.error('❌ Failed to load SOLR stats:', error)
				this.solrError = true
				this.solrErrorMessage = error.response?.data?.error || error.message || 'Failed to load SOLR statistics'
				this.solrStats = null
			} finally {
				this.loadingStats = false
				console.log('✅ Loading stats complete. Stats:', this.solrStats)
			}
		},

		formatNumber(num) {
			if (typeof num !== 'number') return num
			return num.toLocaleString()
		},

		async openWarmupModal() {
			// Load object stats before opening the modal
			await this.loadObjectStats()
			await this.loadAvailableSchemas()
			this.showWarmupDialog = true
		},

		openFileWarmup() {
			console.log('🔥 Opening File Warmup modal...')
			console.log('🔥 Current showFileWarmupDialog value:', this.showFileWarmupDialog)
			this.showFileWarmupDialog = true
			console.log('🔥 Set showFileWarmupDialog to:', this.showFileWarmupDialog)
		},

		async loadAvailableSchemas() {
			this.schemasLoading = true
			try {
				console.log('📊 Loading schemas with stats from API...')
				// Use _extend=@self.stats to get object counts in one call
				const response = await axios.get(
					generateUrl('/apps/openregister/api/schemas'),
					{ params: { _extend: '@self.stats' } },
				)
				console.log('📊 Schemas API response:', response.data)

				// Handle different response formats
				let schemaArray = []
				if (response.data && Array.isArray(response.data.results)) {
					schemaArray = response.data.results
				} else if (response.data && Array.isArray(response.data)) {
					schemaArray = response.data
				} else if (response.data && response.data.data && Array.isArray(response.data.data)) {
					schemaArray = response.data.data
				}

				console.log('📋 Schema array:', schemaArray)
				console.log('🔍 First schema structure:', schemaArray[0])

				if (schemaArray.length > 0) {
					this.availableSchemas = schemaArray
						.filter(schema => schema && (schema.id || schema.uuid))
						.map(schema => {
							const schemaId = schema.id || schema.uuid
							const objectCount = schema.stats?.objects?.total || 0

							console.log(`📊 Schema "${schema.title || schemaId}" has ${objectCount} objects`)

							return {
								id: schemaId,
								label: schema.title || schema.name || schemaId || 'Unnamed Schema',
								objectCount,
							}
						})

					console.log('✅ Available schemas loaded:', this.availableSchemas)
					console.log('📊 Total schemas after filtering:', this.availableSchemas.length)
				} else {
					console.warn('⚠️ No schemas found in response')
					this.availableSchemas = []
				}
			} catch (error) {
				console.error('❌ Failed to load available schemas:', error)
				this.availableSchemas = []
			} finally {
				this.schemasLoading = false
			}
		},

		closeWarmupModal() {
			this.showWarmupDialog = false
			// Reset warmup state when modal is closed
			this.warmingUp = false
			this.warmupCompleted = false
			this.warmupResults = null
			// Reset config to defaults
			this.warmupConfig = {
				mode: 'serial',
				maxObjects: 0,
				batchSize: 1000,
				collectErrors: false,
				selectedSchemas: [],
			}
		},

		openClearModal() {
			this.showClearDialog = true
		},

		openInspectModal() {
			this.showInspectDialog = true
		},

		openDeleteCollectionModal() {
			this.showDeleteCollectionDialog = true
		},

		closeDeleteCollectionModal() {
			this.showDeleteCollectionDialog = false
		},

		async handleCollectionDeleted(result) {
			// Close the modal
			this.closeDeleteCollectionModal()

			// Refresh SOLR stats to reflect the deletion
			await this.loadSolrStats()
		},

		async handleClearIndex() {
			try {
				const url = generateUrl('/apps/openregister/api/settings/solr/clear')
				const response = await axios.post(url)

				// Close modal and refresh stats
				this.showClearDialog = false
				await this.loadSolrStats()
			} catch (error) {
				console.error('Clear index failed:', error)
				// Keep modal open on error so user can see what happened
			}
		},

		async loadObjectStats() {
			this.objectStats.loading = true

			try {
				// Use SOLR dashboard stats to get all data needed for warmup (total count + memory prediction)
				await this.loadSolrStats()

				// Get the total objects count from SOLR stats (we now index all objects, not just published)
				const totalObjects = this.solrStats?.total_count || 0
				this.objectStats.totalObjects = totalObjects

				// Get memory prediction from SOLR stats (no separate API call needed)
				if (this.solrStats?.memory_prediction) {
					this.memoryPrediction = this.solrStats.memory_prediction
				} else {
					// Fallback to default prediction if not available
					this.memoryPrediction = {
						prediction_safe: true,
						formatted: {
							total_predicted: 'Unknown',
							available: 'Unknown',
						},
					}
				}
			} catch (error) {
				console.error('Failed to load object stats:', error)
				this.objectStats.totalObjects = 0
			} finally {
				this.objectStats.loading = false
			}
		},

		async loadMemoryPrediction(maxObjects = 0) {
			try {
				const url = generateUrl('/apps/openregister/api/settings/solr/memory-prediction')
				const response = await axios.post(url, { maxObjects })

				if (response.data && response.data.success) {
					this.memoryPrediction = response.data.prediction
				}
			} catch (error) {
				console.warn('Failed to load memory prediction:', error)
				// Keep default prediction data
			}
		},

		async handleStartWarmup(config) {
			// Store the config so it can be displayed in the modal
			this.warmupConfig = { ...config }

			// Set loading state
			this.warmingUp = true
			this.warmupCompleted = false
			this.warmupResults = null

			try {
				const url = generateUrl('/apps/openregister/api/settings/solr/warmup')

				// Convert config to the expected format
				// Extract schema IDs from the selected schema objects
				console.log('🔥 handleStartWarmup config:', config)
				console.log('🔥 config.selectedSchemas:', config.selectedSchemas)

				const selectedSchemaIds = (config.selectedSchemas || []).map(schema => {
				// Handle both object format {id: '123', label: '...'} and string/number format
					const id = typeof schema === 'object' ? parseInt(schema.id) : parseInt(schema)
					console.log('🔥 Mapping schema:', schema, '-> ID:', id)
					return id
				}).filter(id => !isNaN(id))

				console.log('🔥 selectedSchemaIds to send:', selectedSchemaIds)

				const warmupParams = {
					maxObjects: config.maxObjects || 0,
					mode: config.mode || 'serial',
					batchSize: config.batchSize || 1000,
					selectedSchemas: selectedSchemaIds,
				}

				console.log('🔥 Final warmupParams:', warmupParams)

				const response = await axios.post(url, warmupParams)

				// Set results state
				this.warmupCompleted = true
				this.warmupResults = response.data

				// Refresh stats after warmup completes
				await this.loadSolrStats()
			} catch (error) {
				console.error('Warmup failed:', error)

				// Set error state
				this.warmupCompleted = true
				this.warmupResults = {
					success: false,
					message: error.response?.data?.error || error.message || 'Warmup failed',
					error: true,
				}
			} finally {
				// Clear loading state
				this.warmingUp = false
			}
		},

		/**
		 * Delete a SOLR field
		 * @param fieldName
		 */
		async deleteField(fieldName) {
			if (!fieldName) {
				this.$toast.error('Invalid field name')
				return
			}

			// Confirm deletion
			if (!confirm(`Are you sure you want to delete the field "${fieldName}"?\n\nThis action cannot be undone and will remove the field from SOLR permanently.`)) {
				return
			}

			this.deletingField = fieldName

			try {
				const url = generateUrl(`/apps/openregister/api/solr/fields/${encodeURIComponent(fieldName)}`)
				const response = await axios.delete(url)

				if (response.data.success) {
					this.$toast.success(`Field "${fieldName}" deleted successfully`)

					// Reload field information to reflect changes
					await this.settingsStore.loadSolrFields()
				} else {
					this.$toast.error(response.data.message || `Failed to delete field "${fieldName}"`)
				}
			} catch (error) {
				console.error('Failed to delete field:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error occurred'
				this.$toast.error(`Failed to delete field "${fieldName}": ${errorMessage}`)
			} finally {
				this.deletingField = null
			}
		},

		/**
		 * Start SOLR reindex operation
		 */
		async startReindex() {
			// Confirm reindex operation
			if (!confirm('Are you sure you want to reindex all objects in SOLR?\n\nThis will:\n• Clear the current SOLR index\n• Rebuild the index with all objects using current field schema\n• Take several minutes to complete\n\nThis operation cannot be undone.')) {
				return
			}

			this.reindexing = true

			try {
				const url = generateUrl('/apps/openregister/api/solr/reindex')
				const response = await axios.post(url, {
					maxObjects: 0, // Reindex all objects
					batchSize: 1000, // Use default batch size
				})

				if (response.data.success) {
					const stats = response.data.stats || {}
					this.$toast.success(`Reindex completed successfully! Processed ${stats.processed_objects || 0} objects in ${stats.duration_seconds || 0}s`)

					// Refresh SOLR stats to show updated document count
					await this.loadSolrStats()

					// Refresh field information if the fields dialog is open
					if (this.settingsStore.showFieldsDialog) {
						await this.settingsStore.loadSolrFields()
					}
				} else {
					this.$toast.error(response.data.message || 'Reindex failed')
				}
			} catch (error) {
				console.error('Failed to reindex SOLR:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error occurred'
				this.$toast.error(`Failed to reindex SOLR: ${errorMessage}`)
			} finally {
				this.reindexing = false
			}
		},

		/**
		 * Open facet configuration modal
		 */
		openFacetConfigModal() {
			this.showFacetConfigDialog = true
		},

		/**
		 * Handle connection settings save
		 *
		 * @param {object} updatedConfig - Updated connection configuration
		 */
		async handleConnectionSave(updatedConfig) {
			// Update the local configuration
			this.solrOptions = { ...this.solrOptions, ...updatedConfig }

			// Save the settings
			await this.saveSettings()

			// Close the dialog
			this.showConnectionDialog = false
		},

	},
}

</script>

<style scoped>
/* OpenConnector pattern: Actions positioned with relative positioning and negative margins */
.section-header-inline {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	position: relative;
	top: -45px;
	margin-bottom: -40px;
	z-index: 10;
}

.button-group {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

/* NcActions styling */
.button-group :deep(.action-item) {
	display: inline-flex;
}

.button-group :deep(.action-item__menutoggle) {
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	transition: all 0.2s ease;
}

.button-group :deep(.action-item__menutoggle:hover) {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

.button-group :deep(.action-item__menutoggle--primary) {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.button-group :deep(.action-item__menutoggle--primary:hover) {
	background: var(--color-primary-element-hover);
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

.saving-indicator {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-left: 12px;
	color: var(--color-primary);
	font-weight: 500;
	font-size: 13px;
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
		position: static;
		margin-bottom: 1rem;
		flex-direction: column;
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

/* Fields Dialog Styles */
.fields-dialog {
	padding: 24px;
	max-width: 1200px;
}

.fields-loading {
	text-align: center;
	padding: 40px 20px;
}

.fields-results {
	max-height: 800px;
	overflow-y: auto;
}

/* Fields Overview */
.fields-overview {
	margin-bottom: 24px;
}

.fields-overview h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.overview-stats {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.stat-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	text-align: center;
	flex: 1;
	min-width: 120px;
}

.stat-number {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-primary);
	margin-bottom: 4px;
}

.stat-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

/* Core Information */
.core-info {
	margin-bottom: 24px;
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 16px;
	border: 1px solid var(--color-border);
}

.core-info h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
}

.info-item {
	display: flex;
	justify-content: space-between;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: 4px;
}

.info-label {
	font-weight: 500;
	color: var(--color-main-text);
}

.info-value {
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 13px;
}

/* Fields Table */
.fields-table-section {
	margin-bottom: 24px;
}

.fields-table-section h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.fields-controls {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.field-filter {
	flex: 1;
	min-width: 200px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-text-light);
}

.field-type-filter {
	min-width: 150px;
}

.fields-table-container {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
	background: var(--color-main-background);
}

.fields-table {
	width: 100%;
	border-collapse: collapse;
}

.fields-table th {
	background: var(--color-background-hover);
	padding: 12px;
	text-align: left;
	font-weight: 600;
	color: var(--color-main-text);
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.fields-table td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	font-size: 13px;
}

.field-row:hover {
	background: var(--color-background-hover);
}

.field-name code {
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 12px;
	color: var(--color-primary);
	font-family: monospace;
}

.type-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
	color: white;
}

.type-string { background: #2196F3; }
.type-text { background: #4CAF50; }
.type-integer { background: #FF9800; }
.type-float { background: #FF5722; }
.type-date { background: #9C27B0; }
.type-boolean { background: #607D8B; }
.type-long { background: #FF9800; }
.type-double { background: #FF5722; }
.type-unknown { background: #9E9E9E; }

.boolean-indicator {
	font-weight: bold;
	font-size: 14px;
}

.boolean-indicator.true {
	color: var(--color-success);
}

.boolean-indicator.false {
	color: var(--color-text-maxcontrast);
}

/* Dynamic Fields */
.dynamic-fields-section {
	margin-bottom: 24px;
}

.dynamic-fields-section h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.dynamic-fields-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 16px;
}

.dynamic-field-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	border-left: 4px solid var(--color-warning);
}

.dynamic-field-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.pattern-name {
	background: var(--color-background-dark);
	padding: 4px 8px;
	border-radius: 4px;
	font-family: monospace;
	font-size: 12px;
	color: var(--color-primary);
}

.dynamic-field-properties {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.property-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 4px 0;
}

.property-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* Fields Error */
.fields-error {
	padding: 20px;
	text-align: center;
}

.error-card {
	background: rgba(244, 67, 54, 0.1);
	border: 1px solid var(--color-error);
	border-radius: 8px;
	padding: 20px;
}

.error-card h4 {
	margin: 0 0 12px 0;
	color: var(--color-error-text);
}

.error-card p {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.error-details {
	text-align: left;
}

.error-details pre {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: 4px;
	font-size: 11px;
	overflow-x: auto;
	margin-top: 8px;
}

@media (max-width: 768px) {
	.fields-dialog {
		padding: 16px;
	}

	.overview-stats {
		flex-direction: column;
	}

	.info-grid {
		grid-template-columns: 1fr;
	}

	.fields-controls {
		flex-direction: column;
	}

	.fields-table {
		font-size: 12px;
	}

	.fields-table th,
	.fields-table td {
		padding: 8px;
	}

	.dynamic-fields-grid {
		grid-template-columns: 1fr;
	}
}

/* Mismatch Alert */
.mismatch-alert {
	margin-bottom: 24px;
	padding: 16px;
	background: rgba(255, 152, 0, 0.1);
	border: 1px solid rgba(255, 152, 0, 0.2);
	border-radius: 8px;
}

.alert-content {
	display: flex;
	align-items: center;
	gap: 16px;
}

.alert-icon {
	font-size: 24px;
	flex-shrink: 0;
}

.alert-text {
	flex: 1;
}

.alert-text h3 {
	margin: 0 0 4px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

.alert-text p {
	margin: 0;
	color: var(--color-text-light);
	font-size: 14px;
}

.alert-button {
	padding: 8px 16px;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 6px;
	font-size: 14px;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s;
}

.alert-button:hover {
	background: var(--color-primary-hover);
}

/* Schema Comparison Styling */
.comparison-section {
	margin-top: 24px;
	padding: 20px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.comparison-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 18px;
	font-weight: 600;
	color: #856404;
	margin-bottom: 8px;
}

.comparison-icon {
	font-size: 20px;
}

.comparison-description {
	color: #856404;
	margin-bottom: 20px;
}

.difference-category {
	margin-bottom: 24px;
}

.category-title {
	font-size: 16px;
	font-weight: 600;
	margin-bottom: 8px;
	display: flex;
	align-items: center;
	gap: 8px;
}

.category-title.missing {
	color: #dc3545;
}

.category-title.extra {
	color: #fd7e14;
}

.category-title.mismatched {
	color: #6f42c1;
}

.category-description {
	font-size: 14px;
	color: #6c757d;
	margin-bottom: 12px;
}

.comparison-table {
	width: 100%;
	border-collapse: collapse;
	background: white;
	border-radius: 6px;
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.comparison-table th {
	background: #f8f9fa;
	padding: 12px;
	text-align: left;
	font-weight: 600;
	border-bottom: 2px solid #dee2e6;
}

.comparison-table td {
	padding: 12px;
	border-bottom: 1px solid #dee2e6;
	vertical-align: top;
}

.comparison-table tr:hover {
	background: #f8f9fa;
}

.field-actions {
	text-align: center;
	vertical-align: middle;
	padding: 8px;
	width: 80px;
}

.field-actions .button-vue {
	min-width: auto;
}

.field-comparison-card {
	margin-bottom: 24px;
	border: 1px solid #dee2e6;
	border-radius: 8px;
	background: white;
	overflow: hidden;
}

.field-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px;
	background: #f8f9fa;
	border-bottom: 1px solid #dee2e6;
}

.field-title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: #495057;
	font-family: monospace;
}

.field-comparison-card .comparison-table {
	margin: 0;
	box-shadow: none;
	border-radius: 0;
}

.property-name {
	font-weight: 600;
	color: #495057;
}

.config-badge {
	display: inline-block;
	padding: 2px 6px;
	font-size: 11px;
	font-weight: 500;
	border-radius: 3px;
	margin-right: 4px;
	margin-bottom: 2px;
}

.config-badge.multi {
	background: #e3f2fd;
	color: #1976d2;
}

.config-badge.indexed {
	background: #e8f5e8;
	color: #2e7d32;
}

.config-badge.stored {
	background: #fff3e0;
	color: #f57c00;
}

.config-badge.docvalues {
	background: #f3e5f5;
	color: #7b1fa2;
}

.field-type.expected {
	background: #d4edda;
	color: #155724;
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 500;
}

.field-type.actual {
	background: #f8d7da;
	color: #721c24;
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 500;
}

.action-badge {
	background: #fff3cd;
	color: #856404;
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: 500;
}

.no-differences {
	margin-top: 24px;
	padding: 20px;
	text-align: center;
}

.success-message {
	background: #d4edda;
	border: 1px solid #c3e6cb;
	border-radius: 8px;
	padding: 20px;
}

.success-icon {
	font-size: 24px;
	display: block;
	margin-bottom: 8px;
}

.success-message h4 {
	color: #155724;
	margin-bottom: 8px;
}

.success-message p {
	color: #155724;
	margin: 0;
}

/* Field Actions Section */
.field-actions-section {
	margin-top: 24px;
	padding: 20px;
	background: #e3f2fd;
	border: 1px solid #2196f3;
	border-radius: 8px;
}

.actions-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 18px;
	font-weight: 600;
	color: #1976d2;
	margin-bottom: 8px;
}

.actions-icon {
	font-size: 20px;
}

.actions-description {
	color: #1976d2;
	margin-bottom: 16px;
}

.action-buttons {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

/* Field Creation Results */
.field-creation-results {
	margin-top: 16px;
	padding: 16px;
	border-radius: 6px;
}

.success-result {
	background: #d4edda;
	border: 1px solid #c3e6cb;
	color: #155724;
}

.error-result {
	background: #f8d7da;
	border: 1px solid #f5c6cb;
	color: #721c24;
}

.result-icon {
	font-size: 18px;
	margin-right: 8px;
}

.dry-run-preview,
.created-fields,
.creation-errors {
	margin-top: 12px;
}

.field-list {
	margin: 8px 0 0 20px;
	padding: 0;
}

.field-item {
	margin: 4px 0;
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
	font-size: 13px;
}

.field-item.success {
	color: #28a745;
}

.error-list {
	margin: 8px 0 0 20px;
	padding: 0;
}

.error-item {
	margin: 6px 0;
	font-size: 14px;
}

.execution-time {
	margin-top: 8px;
	font-style: italic;
	color: #666;
}

/* Field configuration display */
.field-config {
	font-size: 12px;
	line-height: 1.4;
}

.config-item {
	margin: 2px 0;
	display: flex;
	align-items: center;
	gap: 4px;
}

.config-item strong {
	min-width: 60px;
	font-size: 11px;
	color: #666;
}

.field-value {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 11px;
	font-weight: 500;
}

.field-value.match {
	background: #d4edda;
	color: #155724;
}

.field-value.mismatch {
	background: #f8d7da;
	color: #721c24;
}

.field-value.expected-value {
	background: #d4edda;
	color: #155724;
}

/* Fix mismatches section */
.fix-mismatches-section {
	margin-top: 20px;
	padding: 16px;
	background: #f8f9fa;
	border-radius: 6px;
	border-left: 4px solid #ffc107;
}

.fix-title {
	margin: 0 0 8px 0;
	font-size: 16px;
	font-weight: 600;
	color: #856404;
}

.fix-description {
	margin: 0 0 16px 0;
	color: #666;
	font-size: 14px;
	line-height: 1.4;
}

.fix-actions {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

/* Dashboard Styles */
.solr-management-section {
	margin-top: 32px;
	padding-top: 24px;
	border-top: 2px solid var(--color-border);
}

.solr-management-section h4 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
}

.loading-section {
	text-align: center;
	padding: 2rem;
}

.error-section {
	text-align: center;
	padding: 2rem;
}

.error-message {
	color: var(--color-error);
	margin-bottom: 1rem;
}

.no-data-section {
	text-align: center;
	padding: 2rem;
}

.dashboard-section {
	padding: 1rem 0;
}

.dashboard-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
}

.stat-card {
	background: var(--color-background-hover);
	padding: 1rem;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
}

.stat-card h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.stat-card p {
	margin: 0;
	font-size: 1.1rem;
	font-weight: bold;
}

.object-info {
	display: block;
	margin-top: 0.25rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	font-weight: normal;
	font-style: italic;
}

.status-success {
	color: var(--color-success);
}

.status-warning {
	color: var(--color-warning);
}

.status-error {
	color: var(--color-error);
}

/* Confirmation State */
.setup-confirmation {
	text-align: center;
	padding: 1.5rem 0;
}

.confirmation-icon {
	font-size: 3rem;
	margin-bottom: 1rem;
}

.setup-confirmation h4 {
	color: var(--color-primary);
	margin: 0 0 1rem 0;
	font-size: 1.5rem;
}

.confirmation-description {
	color: var(--color-text);
	margin: 0 0 1.5rem 0;
	line-height: 1.5;
}

.setup-preview-steps {
	margin: 1.5rem 0;
	text-align: left;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1rem;
}

.setup-preview-steps ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.setup-preview-steps li {
	margin: 0.75rem 0;
	color: var(--color-text);
	font-size: 0.95rem;
	line-height: 1.4;
}

.timing-warning {
	background-color: rgba(var(--color-warning-rgb), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin: 1.5rem 0;
	text-align: left;
	color: var(--color-text);
	font-size: 0.9rem;
	line-height: 1.5;
}

.timing-warning strong {
	color: var(--color-warning);
}

.timing-warning small {
	color: var(--color-text-light);
	font-style: italic;
}

.confirmation-actions {
	display: flex;
	gap: 1rem;
	justify-content: center;
	margin-top: 2rem;
}

/* Game-style Loading */
.game-loading-content {
	margin-top: 2rem;
	min-height: 200px;
}

.educational-tips {
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1.5rem;
	margin-bottom: 1.5rem;
	min-height: 120px;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

.tip-item {
	opacity: 0;
	transform: translateY(20px);
	transition: all 0.5s ease-in-out;
	margin: 0.5rem 0;
	color: var(--color-text);
	font-size: 0.95rem;
	line-height: 1.4;
	text-align: left;
}

.tip-item.tip-fade-in {
	opacity: 1;
	transform: translateY(0);
}

.loading-progress-text {
	text-align: center;
	color: var(--color-primary);
	font-weight: 600;
	font-size: 1rem;
	padding: 1rem;
	background-color: rgba(var(--color-primary-rgb), 0.1);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-primary);
	animation: pulse 2s infinite;
}

@keyframes pulse {
	0% {
		opacity: 0.8;
		transform: scale(1);
	}
	50% {
		opacity: 1;
		transform: scale(1.02);
	}
	100% {
		opacity: 0.8;
		transform: scale(1);
	}
}

/* ConfigSet Propagation Error Styles */
.propagation-error-section {
	margin: 1.5rem 0;
	background-color: rgba(var(--color-warning-rgb), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 1.5rem;
}

.propagation-error-section h4 {
	color: var(--color-warning);
	margin: 0 0 1rem 0;
	font-size: 1.2rem;
	font-weight: 600;
}

.propagation-explanation p {
	margin: 0.5rem 0;
	color: var(--color-text);
	line-height: 1.5;
}

.propagation-explanation strong {
	color: var(--color-warning);
	font-weight: 600;
}

.propagation-details {
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin: 1rem 0;
}

.propagation-details ul {
	margin: 0.5rem 0;
	padding-left: 1.5rem;
	list-style: none;
}

.propagation-details li {
	margin: 0.5rem 0;
	color: var(--color-text);
	font-size: 0.95rem;
	line-height: 1.4;
}

.propagation-solution {
	background-color: rgba(var(--color-success-rgb), 0.1);
	border: 1px solid var(--color-success);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin: 1rem 0;
}

.propagation-solution p {
	margin: 0.5rem 0;
	color: var(--color-text);
	font-weight: 600;
}

.propagation-solution ol {
	margin: 0.5rem 0;
	padding-left: 1.5rem;
}

.propagation-solution li {
	margin: 0.5rem 0;
	color: var(--color-text);
	line-height: 1.4;
}

.propagation-technical {
	margin: 1rem 0;
}

.propagation-technical details {
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1rem;
}

.propagation-technical summary {
	color: var(--color-primary);
	cursor: pointer;
	font-weight: 600;
	margin-bottom: 0.5rem;
}

.propagation-technical summary:hover {
	color: var(--color-primary-hover);
}

/* Facet Configuration Modal Styles */
.facet-config-dialog {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.config-header {
	margin-bottom: 30px;
}

.config-header h3 {
	margin: 0 0 10px 0;
	color: var(--color-text-dark);
}

.config-header p {
	margin: 0;
	color: var(--color-text-lighter);
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
	color: var(--color-text-dark);
	font-size: 16px;
}

.form-row {
	margin-bottom: 15px;
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.form-row label {
	font-weight: 600;
	color: var(--color-text-dark);
}

.form-input {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-text-dark);
}

.form-select {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-text-dark);
	cursor: pointer;
}

.form-select:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px rgba(var(--color-primary), 0.2);
}

.form-textarea {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-text-dark);
	resize: vertical;
	font-family: inherit;
}

.no-facets {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-lighter);
}

.facets-list {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.facet-item {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 15px;
	background: var(--color-main-background);
}

.facet-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 15px;
}

.facet-header h5 {
	margin: 0;
	color: var(--color-text-dark);
	font-family: monospace;
	background: var(--color-background-dark);
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 14px;
}

.facet-details {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 15px;
}

.facet-details .form-row:last-child {
	grid-column: 1 / -1;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	color: var(--color-text-lighter);
}

</style>
