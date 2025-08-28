<template>
	<div>
		<NcSettingsSection
			name="Open Register"
			description="A central place for managing your Open Register"
			doc-url="https://docs.openregister.nl" />

		<NcSettingsSection
			name="Version Information"
			description="Current application version information">
			<div v-if="!loadingVersionInfo" class="version-info">
				<div class="version-details">
					<div class="version-item">
						<strong>Application:</strong> {{ versionInfo.appName }} v{{ versionInfo.appVersion }}
					</div>
					<div class="version-item">
						<strong>License:</strong> EUPL-1.2
					</div>
					<div class="version-item">
						<strong>Author:</strong> Conduction B.V.
					</div>
					<div class="version-item">
						<strong>Website:</strong>
						<a href="https://github.com/ConductionNL/OpenRegister" target="_blank" rel="noopener noreferrer">
							https://github.com/ConductionNL/OpenRegister
						</a>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection name="System Statistics"
			description="Overview of your Open Register data and potential issues">
			<div v-if="!loadingStats" class="stats-section">
				<!-- Save and Rebase Buttons -->
				<div class="section-header-inline">
					<span />
					<div class="button-group">
						<NcButton
							type="secondary"
							:disabled="loading || saving || rebasing || loadingStats"
							@click="loadStats">
							<template #icon>
								<NcLoadingIcon v-if="loadingStats" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Refresh
						</NcButton>
					</div>
				</div>

				<div class="stats-content">
					<div class="stats-grid">
						<!-- Warning Stats -->
						<div class="stats-card warning-stats">
							<h4>⚠️ Items Requiring Attention</h4>
							<div class="stats-table-container">
								<table class="stats-table">
									<thead>
										<tr>
											<th class="stats-table-header">
												Issue
											</th>
											<th class="stats-table-header">
												Count
											</th>
											<th class="stats-table-header">
												Size
											</th>
										</tr>
									</thead>
									<tbody>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Objects without owner
											</td>
											<td class="stats-table-value" :class="{ 'danger': stats.warnings.objectsWithoutOwner > 0 }">
												{{ stats.warnings.objectsWithoutOwner }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Objects without organisation
											</td>
											<td class="stats-table-value" :class="{ 'danger': stats.warnings.objectsWithoutOrganisation > 0 }">
												{{ stats.warnings.objectsWithoutOrganisation }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Audit trails without expiry
											</td>
											<td class="stats-table-value" :class="{ 'danger': stats.warnings.auditTrailsWithoutExpiry > 0 }">
												{{ stats.warnings.auditTrailsWithoutExpiry }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Search trails without expiry
											</td>
											<td class="stats-table-value" :class="{ 'danger': stats.warnings.searchTrailsWithoutExpiry > 0 }">
												{{ stats.warnings.searchTrailsWithoutExpiry }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired audit trails
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredAuditTrails || 0) > 0 }">
												{{ stats.warnings.expiredAuditTrails || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredAuditTrailsSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredAuditTrailsSize || 0) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired search trails
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredSearchTrails || 0) > 0 }">
												{{ stats.warnings.expiredSearchTrails || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredSearchTrailsSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredSearchTrailsSize || 0) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired objects
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredObjects || 0) > 0 }">
												{{ stats.warnings.expiredObjects || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredObjectsSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredObjectsSize || 0) }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<!-- Total Stats -->
						<div class="stats-card total-stats">
							<h4>📊 System Totals</h4>
							<div class="stats-table-container">
								<table class="stats-table">
									<thead>
										<tr>
											<th class="stats-table-header">
												Category
											</th>
											<th class="stats-table-header">
												Total
											</th>
											<th class="stats-table-header">
												Size
											</th>
										</tr>
									</thead>
									<tbody>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Objects
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalObjects.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalObjectsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Deleted Objects
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.deletedObjects.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.deletedObjectsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Audit Trails
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalAuditTrails.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalAuditTrailsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Search Trails
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSearchTrails.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalSearchTrailsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Configurations
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalConfigurations.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Data Access Profiles
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalDataAccessProfiles.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Organisations
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalOrganisations.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Registers
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalRegisters.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Schemas
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSchemas.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Sources
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSources.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>

					<div class="stats-footer">
						<p class="stats-updated">
							Last updated: {{ formatDate(stats.lastUpdated) }}
						</p>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection name="Role Based Access Control (RBAC)">
			<template #description>
				Configure access permissions and user groups
			</template>

			<div v-if="!loading" class="rbac-options">
				<!-- Save and Rebase Buttons -->
				<div class="section-header-inline">
					<span />
					<div class="button-group">
						<NcButton
							type="error"
							:disabled="loading || saving || rebasing"
							@click="showRebaseDialog">
							<template #icon>
								<NcLoadingIcon v-if="rebasing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Rebase
						</NcButton>
						<NcButton
							type="primary"
							:disabled="loading || saving || rebasing"
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
						Role Based Access Control (RBAC) allows you to control who can access and modify different parts of your Open Register.
						When enabled, users are assigned to specific Nextcloud groups that determine their permissions for registers, schemas, and objects.
						Note: This system uses Nextcloud's built-in group functionality rather than separate roles.
					</p>
					<p class="toggle-status">
						<strong>Current Status:</strong>
						<span :class="rbacOptions.enableRBAC ? 'status-enabled' : 'status-disabled'">
							{{ rbacOptions.enableRBAC ? 'Role Based Access Control enabled' : 'Role Based Access Control disabled' }}
						</span>
					</p>
					<p class="impact-description">
						<strong>{{ rbacOptions.enableRBAC ? 'Disabling' : 'Enabling' }} RBAC will:</strong><br>
						<span v-if="!rbacOptions.enableRBAC">
							• Provide fine-grained access control over registers and schemas<br>
							• Allow you to assign users to specific Nextcloud groups (Viewer, Editor, Admin)<br>
							• Enable secure multi-user environments with proper permission boundaries<br>
							• Require group assignment for new users accessing the system
						</span>
						<span v-else>
							• Remove all group-based restrictions and permissions<br>
							• Grant all users full access to all registers and schemas<br>
							• Simplify user management but reduce security controls<br>
							• Allow unrestricted access to sensitive data and configurations
						</span>
					</p>
				</div>
				<!-- Enable RBAC Toggle -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="rbacOptions.enableRBAC"
						:disabled="saving"
						type="switch">
						{{ rbacOptions.enableRBAC ? 'Role Based Access Control enabled' : 'Role Based Access Control disabled' }}
					</NcCheckboxRadioSwitch>

					<!-- Admin Override -->
					<div v-if="rbacOptions.enableRBAC">
						<NcCheckboxRadioSwitch
							:checked.sync="rbacOptions.adminOverride"
							:disabled="saving"
							type="switch">
							{{ rbacOptions.adminOverride ? 'Admin override enabled' : 'Admin override disabled' }}
						</NcCheckboxRadioSwitch>
						<p class="option-description">
							Allow administrators to bypass all RBAC restrictions
						</p>

						<h4>Default User Groups</h4>
						<p class="option-description">
							Configure which Nextcloud groups different types of users are assigned to by default
						</p>

						<div class="groups-table">
							<div class="groups-row">
								<div class="group-label">
									<strong>Anonymous Users</strong>
									<p class="user-type-description">
										Unidentified, non-logged-in users who access public content without authentication
									</p>
								</div>
								<div class="group-select">
									<NcSelect
										v-model="rbacOptions.anonymousGroup"
										:options="groupOptions"
										input-label="Anonymous Group"
										:disabled="loading || saving" />
								</div>
							</div>

							<div class="groups-row">
								<div class="group-label">
									<strong>Default New Users</strong>
									<p class="user-type-description">
										Authenticated users who have logged in but haven't been assigned to specific groups yet
									</p>
								</div>
								<div class="group-select">
									<NcSelect
										v-model="rbacOptions.defaultNewUserGroup"
										:options="groupOptions"
										input-label="New User Group"
										:disabled="loading || saving" />
								</div>
							</div>

							<div class="groups-row">
								<div class="group-label">
									<strong>Default Object Owner</strong>
									<p class="user-type-description">
										Default user assigned as owner when creating new objects without explicit ownership
									</p>
								</div>
								<div class="group-select">
									<NcSelect
										v-model="rbacOptions.defaultObjectOwner"
										:options="userOptions"
										input-label="Default Owner"
										:disabled="loading || saving" />
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection name="Multitenancy">
			<template #description>
				Configure multi-organization support and tenant isolation
			</template>

			<div v-if="!loading" class="multitenancy-options">
				<!-- Save and Rebase Buttons -->
				<div class="section-header-inline">
					<span />
					<div class="button-group">
						<NcButton
							type="error"
							:disabled="loading || saving || rebasing"
							@click="showRebaseDialog">
							<template #icon>
								<NcLoadingIcon v-if="rebasing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Rebase
						</NcButton>
						<NcButton
							type="primary"
							:disabled="loading || saving || rebasing"
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
						Multitenancy enables multiple organizations to use the same Open Register instance while keeping their data completely separate.
						Each tenant (organization) has isolated access to their own registers, schemas, and objects, ensuring data privacy and security.
					</p>
					<p class="toggle-status">
						<strong>Current Status:</strong>
						<span :class="multitenancyOptions.enableMultitenancy ? 'status-enabled' : 'status-disabled'">
							{{ multitenancyOptions.enableMultitenancy ? 'Multitenancy enabled' : 'Multitenancy disabled' }}
						</span>
					</p>
					<p class="impact-description">
						<strong>{{ multitenancyOptions.enableMultitenancy ? 'Disabling' : 'Enabling' }} Multitenancy will:</strong><br>
						<span v-if="!multitenancyOptions.enableMultitenancy">
							• Enable multiple organizations to share the same system instance<br>
							• Provide complete data isolation between different tenants<br>
							• Allow centralized management while maintaining security boundaries<br>
							• Reduce infrastructure costs by sharing resources across organizations
						</span>
						<span v-else>
							• Merge all tenant data into a single shared environment<br>
							• Remove data isolation between organizations<br>
							• Simplify the system to single-tenant mode<br>
							• May expose sensitive data to unauthorized users
						</span>
					</p>
				</div>
				<!-- Enable Multitenancy Toggle -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="multitenancyOptions.enableMultitenancy"
						:disabled="saving"
						type="switch">
						{{ multitenancyOptions.enableMultitenancy ? 'Multitenancy enabled' : 'Multitenancy disabled' }}
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Default Tenants -->
				<div v-if="multitenancyOptions.enableMultitenancy">
					<h4>Default Tenants</h4>
					<p class="option-description">
						Configure default tenant assignments for users and objects
					</p>

					<div class="groups-table">
						<div class="groups-row">
							<div class="group-label">
								<strong>Default User Tenant</strong>
								<p class="user-type-description">
									The tenant assigned to users who are not part of any specific organization
								</p>
							</div>
							<div class="group-select">
								<NcSelect
									v-model="multitenancyOptions.defaultUserTenant"
									:options="tenantOptions"
									input-label="Default User Tenant"
									:disabled="loading || saving" />
							</div>
						</div>

						<div class="groups-row">
							<div class="group-label">
								<strong>Default Object Tenant</strong>
								<p class="user-type-description">
									The tenant assigned to objects when no specific organization is specified
								</p>
							</div>
							<div class="group-select">
								<NcSelect
									v-model="multitenancyOptions.defaultObjectTenant"
									:options="tenantOptions"
									input-label="Default Object Tenant"
									:disabled="loading || saving" />
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection name="Retention">
			<template #description>
				Configure data and log retention policies
			</template>

			<div v-if="!loading" class="retention-options">
				<!-- Save Button -->
				<div class="section-header-inline">
					<span />
					<div class="button-group">
						<NcButton
							type="error"
							:disabled="loading || saving || rebasing"
							@click="showRebaseDialog">
							<template #icon>
								<NcLoadingIcon v-if="rebasing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Rebase
						</NcButton>
						<NcButton
							type="primary"
							:disabled="loading || saving || rebasing"
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
						Configure retention policies for objects and audit logs. Object retention controls when inactive objects are archived and permanently deleted.
						Log retention manages how long audit trails for different CRUD operations are kept for compliance and debugging.
						<strong>Note:</strong> Setting retention to 0 means data is kept forever (not advisable for production).
					</p>
					<p class="toggle-status" :class="retentionStatusClass">
						<span :class="retentionStatusTextClass">{{ retentionStatusMessage }}</span>
					</p>
					<p class="impact-description warning-box">
						<strong>⚠️ Important:</strong> Changes to retention policies only apply to objects that are "touched" (created, updated, or accessed) after the retention policy was changed.
						Existing objects will retain their previous retention schedules until they are modified.
					</p>
				</div>

				<!-- Consolidated Retention Settings -->
				<div class="option-section">
					<h4>Data & Log Retention Policies</h4>
					<p class="option-description">
						Configure retention periods for objects and audit logs (in milliseconds). Object retention controls lifecycle management, while log retention manages audit trail storage by action type.
					</p>

					<div class="retention-table">
						<div class="retention-row">
							<div class="retention-label">
								<strong>Soft Delete After Inactivity</strong>
								<p class="retention-description">
									Time since last CRUD action before object is soft-deleted
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.objectArchiveRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="31536000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.objectArchiveRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Permanent Delete After Soft Delete</strong>
								<p class="retention-description">
									Time from soft-delete to permanent deletion
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.objectDeleteRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="63072000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.objectDeleteRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Search Trail Retention</strong>
								<p class="retention-description">
									Retention period for search query audit trails and analytics
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.searchTrailRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="2592000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.searchTrailRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Create Action Logs</strong>
								<p class="retention-description">
									Retention period for object creation audit logs
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.createLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="2592000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.createLogRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Read Action Logs</strong>
								<p class="retention-description">
									Retention period for object access/view audit logs
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.readLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="86400000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.readLogRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Update Action Logs</strong>
								<p class="retention-description">
									Retention period for object modification audit logs
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.updateLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="604800000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.updateLogRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Delete Action Logs</strong>
								<p class="retention-description">
									Retention period for object deletion audit logs
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.deleteLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="2592000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.deleteLogRetention) }}
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<!-- Rebase Confirmation Dialog -->
		<NcDialog
			v-if="showRebaseConfirmation"
			name="Confirm Rebase"
			:can-close="!rebasing"
			@closing="hideRebaseDialog">
			<div class="rebase-dialog">
				<div class="rebase-warning">
					<h3>⚠️ Rebase All Objects and Logs</h3>
					<p class="warning-text">
						This action will recalculate deletion times for all objects and logs based on your current retention settings.
						Additionally, objects without assigned owners or organizations will be assigned defaults based on your current configuration.
					</p>
					<p class="impact-text">
						<strong>This operation:</strong><br>
						• Will update deletion timestamps for all existing objects and logs<br>
						• Cannot be undone once started<br>
						• May take some time to complete depending on data volume<br>
						• Will assign default owners/organizations to unassigned objects
					</p>
				</div>
				<div class="dialog-actions">
					<NcButton
						:disabled="rebasing"
						@click="hideRebaseDialog">
						Cancel
					</NcButton>
					<NcButton
						type="error"
						:disabled="rebasing"
						@click="performRebase">
						<template #icon>
							<NcLoadingIcon v-if="rebasing" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ rebasing ? 'Rebasing...' : 'Confirm Rebase' }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { defineComponent } from 'vue'
import {
	NcSettingsSection,
	NcSelect,
	NcButton,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcDialog,
} from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

/**
 * @class Settings
 * @module Components
 * @package
 * @author Claude AI
 * @copyright 2024 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/OpenRegister/openregister
 *
 * Settings component for the Open Register that allows users to configure
 * version information, RBAC, and multitenancy options.
 */
export default defineComponent({
	name: 'Settings',
	components: {
		NcSettingsSection,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcDialog,
		Save,
		Refresh,
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			loading: true,
			saving: false,
			rebasing: false,
			showRebaseConfirmation: false,
			loadingVersionInfo: true,
			loadingStats: true,
			stats: {
				warnings: {
					objectsWithoutOwner: 0,
					objectsWithoutOrganisation: 0,
					auditTrailsWithoutExpiry: 0,
					searchTrailsWithoutExpiry: 0,
					expiredAuditTrails: 0,
					expiredSearchTrails: 0,
					expiredObjects: 0,
				},
				totals: {
					totalObjects: 0,
					totalAuditTrails: 0,
					totalSearchTrails: 0,
					totalConfigurations: 0,
					totalDataAccessProfiles: 0,
					totalOrganisations: 0,
					totalRegisters: 0,
					totalSchemas: 0,
					totalSources: 0,
					deletedObjects: 0,
				},
				sizes: {
					totalObjectsSize: 0,
					totalAuditTrailsSize: 0,
					totalSearchTrailsSize: 0,
					deletedObjectsSize: 0,
					expiredAuditTrailsSize: 0,
					expiredSearchTrailsSize: 0,
					expiredObjectsSize: 0,
				},
				lastUpdated: new Date(),
			},
			versionInfo: {
				appName: 'Open Register',
				appVersion: '0.2.3',
			},
			rbacOptions: {
				enableRBAC: false,
				anonymousGroup: null,
				defaultNewUserGroup: null,
				defaultObjectOwner: null,
				adminOverride: true,
			},
			multitenancyOptions: {
				enableMultitenancy: false,
				defaultUserTenant: null,
				defaultObjectTenant: null,
			},
			retentionOptions: {
				objectArchiveRetention: 31536000000, // 1 year default
				objectDeleteRetention: 63072000000, // 2 years default
				searchTrailRetention: 2592000000, // 1 month default
				createLogRetention: 2592000000, // 1 month default
				readLogRetention: 86400000, // 24 hours default
				updateLogRetention: 604800000, // 1 week default
				deleteLogRetention: 2592000000, // 1 month default
			},
			availableGroups: {},
			availableTenants: {},
			availableUsers: {},
		}
	},

	computed: {
		/**
		 * Available group options for RBAC
		 *
		 * @return {Array<object>} Array of Nextcloud group options
		 */
		groupOptions() {
			return Object.entries(this.availableGroups).map(([value, label]) => ({
				label,
				value,
			}))
		},

		/**
		 * Available tenant options
		 *
		 * @return {Array<object>} Array of tenant options
		 */
		tenantOptions() {
			return Object.entries(this.availableTenants).map(([value, label]) => ({
				label,
				value,
			}))
		},

		/**
		 * Available user options
		 *
		 * @return {Array<object>} Array of user options
		 */
		userOptions() {
			return Object.entries(this.availableUsers).map(([value, label]) => ({
				label,
				value,
			}))
		},

		/**
		 * Retention status message based on zero values
		 *
		 * @return {string} Status message
		 */
		retentionStatusMessage() {
			const zeroRetentions = []

			if (this.retentionOptions.objectArchiveRetention === 0) {
				zeroRetentions.push('Soft Delete')
			}
			if (this.retentionOptions.objectDeleteRetention === 0) {
				zeroRetentions.push('Permanent Delete')
			}
			if (this.retentionOptions.searchTrailRetention === 0) {
				zeroRetentions.push('Search Trail')
			}
			if (this.retentionOptions.createLogRetention === 0) {
				zeroRetentions.push('Create Logs')
			}
			if (this.retentionOptions.readLogRetention === 0) {
				zeroRetentions.push('Read Logs')
			}
			if (this.retentionOptions.updateLogRetention === 0) {
				zeroRetentions.push('Update Logs')
			}
			if (this.retentionOptions.deleteLogRetention === 0) {
				zeroRetentions.push('Delete Logs')
			}

			if (zeroRetentions.length === 0) {
				return 'All retention policies configured'
			} else if (zeroRetentions.length === 7) {
				return '⚠️ All retention policies set to forever (not advisable)'
			} else {
				return `⚠️ ${zeroRetentions.join(', ')} set to forever (not advisable)`
			}
		},

		/**
		 * CSS class for retention status
		 *
		 * @return {string} CSS class
		 */
		retentionStatusClass() {
			const zeroCount = [
				this.retentionOptions.objectArchiveRetention,
				this.retentionOptions.objectDeleteRetention,
				this.retentionOptions.searchTrailRetention,
				this.retentionOptions.createLogRetention,
				this.retentionOptions.readLogRetention,
				this.retentionOptions.updateLogRetention,
				this.retentionOptions.deleteLogRetention,
			].filter(val => val === 0).length

			return zeroCount > 0 ? 'status-warning' : 'status-success'
		},

		/**
		 * CSS class for retention status text
		 *
		 * @return {string} CSS class
		 */
		retentionStatusTextClass() {
			const zeroCount = [
				this.retentionOptions.objectArchiveRetention,
				this.retentionOptions.objectDeleteRetention,
				this.retentionOptions.searchTrailRetention,
				this.retentionOptions.createLogRetention,
				this.retentionOptions.readLogRetention,
				this.retentionOptions.updateLogRetention,
				this.retentionOptions.deleteLogRetention,
			].filter(val => val === 0).length

			return zeroCount > 0 ? 'status-warning-text' : 'status-enabled'
		},
	},

	watch: {
		/**
		 * Auto-save when RBAC is enabled/disabled
		 */
		'rbacOptions.enableRBAC'() {
			if (!this.loading) {
				this.saveSettings()
			}
		},

		/**
		 * Auto-save when Multitenancy is enabled/disabled
		 */
		'multitenancyOptions.enableMultitenancy'() {
			if (!this.loading) {
				this.saveSettings()
			}
		},

		/**
		 * Auto-save when Admin Override is changed
		 */
		'rbacOptions.adminOverride'() {
			if (!this.loading) {
				this.saveSettings()
			}
		},
	},

	/**
	 * Lifecycle hook that loads settings when component is created
	 */
	async created() {
		await this.loadSettings()
		await this.loadStats()
	},

	methods: {
		/**
		 * Loads all settings from the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadSettings() {
			this.loading = true
			this.loadingVersionInfo = true

			try {
				const response = await fetch('/index.php/apps/openregister/api/settings')
				const data = await response.json()

				if (data.error) {
					console.error('Failed to load settings:', data.error)
					return
				}

				// Version information
				this.versionInfo = data.version

				// Available options
				this.availableGroups = data.availableGroups
				this.availableTenants = data.availableTenants
				this.availableUsers = data.availableUsers

				// RBAC settings
				this.rbacOptions = {
					enableRBAC: data.rbac.enabled,
					anonymousGroup: this.findOptionByValue(this.groupOptions, data.rbac.anonymousGroup),
					defaultNewUserGroup: this.findOptionByValue(this.groupOptions, data.rbac.defaultNewUserGroup),
					defaultObjectOwner: this.findOptionByValue(this.userOptions, data.rbac.defaultObjectOwner),
					adminOverride: data.rbac.adminOverride,
				}

				// Multitenancy settings
				this.multitenancyOptions = {
					enableMultitenancy: data.multitenancy.enabled,
					defaultUserTenant: this.findOptionByValue(this.tenantOptions, data.multitenancy.defaultUserTenant),
					defaultObjectTenant: this.findOptionByValue(this.tenantOptions, data.multitenancy.defaultObjectTenant),
				}

				// Retention settings
				if (data.retention) {
					this.retentionOptions = {
						objectArchiveRetention: data.retention.objectArchiveRetention || 31536000000,
						objectDeleteRetention: data.retention.objectDeleteRetention || 63072000000,
						searchTrailRetention: data.retention.searchTrailRetention || 2592000000,
						createLogRetention: data.retention.createLogRetention || 2592000000,
						readLogRetention: data.retention.readLogRetention || 86400000,
						updateLogRetention: data.retention.updateLogRetention || 604800000,
						deleteLogRetention: data.retention.deleteLogRetention || 2592000000,
					}
				}

			} catch (error) {
				console.error('Failed to load settings:', error)
			} finally {
				this.loading = false
				this.loadingVersionInfo = false
			}
		},

		/**
		 * Helper function to find option object by value
		 *
		 * @param {Array} options Array of option objects
		 * @param {string} value Value to find
		 * @return {object|null} Found option or null
		 */
		findOptionByValue(options, value) {
			return options.find(option => option.value === value) || null
		},

		/**
		 * Format retention period in milliseconds to human-readable format
		 *
		 * @param {number} ms Milliseconds
		 * @return {string} Formatted time period
		 */
		formatRetentionPeriod(ms) {
			if (!ms || ms === 0) {
				return 'Forever (not advisable)'
			}

			const units = [
				{ name: 'year', ms: 365 * 24 * 60 * 60 * 1000, plural: 'years' },
				{ name: 'month', ms: 30 * 24 * 60 * 60 * 1000, plural: 'months' },
				{ name: 'week', ms: 7 * 24 * 60 * 60 * 1000, plural: 'weeks' },
				{ name: 'day', ms: 24 * 60 * 60 * 1000, plural: 'days' },
				{ name: 'hour', ms: 60 * 60 * 1000, plural: 'hours' },
				{ name: 'minute', ms: 60 * 1000, plural: 'minutes' },
			]

			const parts = []
			let remaining = ms

			for (const unit of units) {
				const count = Math.floor(remaining / unit.ms)
				if (count > 0) {
					parts.push(`${count} ${count === 1 ? unit.name : unit.plural}`)
					remaining -= count * unit.ms
				}
			}

			if (parts.length === 0) {
				return 'Less than 1 minute'
			}

			// Show up to 2 most significant units
			return parts.slice(0, 2).join(', ')
		},

		/**
		 * Saves all settings to the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveSettings() {
			this.saving = true

			try {
				const settingsData = {
					rbac: {
						enabled: this.rbacOptions.enableRBAC,
						anonymousGroup: this.rbacOptions.anonymousGroup?.value || 'public',
						defaultNewUserGroup: this.rbacOptions.defaultNewUserGroup?.value || 'viewer',
						defaultObjectOwner: this.rbacOptions.defaultObjectOwner?.value || '',
						adminOverride: this.rbacOptions.adminOverride,
					},
					multitenancy: {
						enabled: this.multitenancyOptions.enableMultitenancy,
						defaultUserTenant: this.multitenancyOptions.defaultUserTenant?.value || '',
						defaultObjectTenant: this.multitenancyOptions.defaultObjectTenant?.value || '',
					},
					retention: {
						objectArchiveRetention: this.retentionOptions.objectArchiveRetention,
						objectDeleteRetention: this.retentionOptions.objectDeleteRetention,
						searchTrailRetention: this.retentionOptions.searchTrailRetention,
						createLogRetention: this.retentionOptions.createLogRetention,
						readLogRetention: this.retentionOptions.readLogRetention,
						updateLogRetention: this.retentionOptions.updateLogRetention,
						deleteLogRetention: this.retentionOptions.deleteLogRetention,
					},
				}

				const response = await fetch('/index.php/apps/openregister/api/settings', {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(settingsData),
				})

				const result = await response.json()

				if (result.error) {
					console.error('Failed to save settings:', result.error)
					return
				}

				// Update local state with server response
				this.availableGroups = result.availableGroups
				this.availableTenants = result.availableTenants
				this.availableUsers = result.availableUsers

				// Update RBAC settings
				this.rbacOptions = {
					enableRBAC: result.rbac.enabled,
					anonymousGroup: this.findOptionByValue(this.groupOptions, result.rbac.anonymousGroup),
					defaultNewUserGroup: this.findOptionByValue(this.groupOptions, result.rbac.defaultNewUserGroup),
					defaultObjectOwner: this.findOptionByValue(this.userOptions, result.rbac.defaultObjectOwner),
					adminOverride: result.rbac.adminOverride,
				}

				// Update Multitenancy settings
				this.multitenancyOptions = {
					enableMultitenancy: result.multitenancy.enabled,
					defaultUserTenant: this.findOptionByValue(this.tenantOptions, result.multitenancy.defaultUserTenant),
					defaultObjectTenant: this.findOptionByValue(this.tenantOptions, result.multitenancy.defaultObjectTenant),
				}

				// Update Retention settings
				if (result.retention) {
					this.retentionOptions = {
						objectArchiveRetention: result.retention.objectArchiveRetention,
						objectDeleteRetention: result.retention.objectDeleteRetention,
						searchTrailRetention: result.retention.searchTrailRetention,
						createLogRetention: result.retention.createLogRetention,
						readLogRetention: result.retention.readLogRetention,
						updateLogRetention: result.retention.updateLogRetention,
						deleteLogRetention: result.retention.deleteLogRetention,
					}
				}

			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Saves RBAC options (alias for saveSettings)
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveRBACOptions() {
			await this.saveSettings()
		},

		/**
		 * Saves multitenancy options (alias for saveSettings)
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveMultitenancyOptions() {
			await this.saveSettings()
		},

		/**
		 * Shows the rebase confirmation dialog
		 *
		 * @return {void}
		 */
		showRebaseDialog() {
			this.showRebaseConfirmation = true
		},

		/**
		 * Hides the rebase confirmation dialog
		 *
		 * @return {void}
		 */
		hideRebaseDialog() {
			this.showRebaseConfirmation = false
		},

		/**
		 * Performs the rebase operation
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async performRebase() {
			this.rebasing = true

			try {
				const response = await fetch('/index.php/apps/openregister/api/settings/rebase', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const result = await response.json()

				if (result.error) {
					console.error('Failed to rebase:', result.error)
					// You could add a toast notification here
					return
				}

				// Hide the dialog and show success
				this.hideRebaseDialog()
				// You could add a success toast notification here

			} catch (error) {
				console.error('Failed to rebase:', error)
				// You could add an error toast notification here
			} finally {
				this.rebasing = false
			}
		},

		/**
		 * Load statistics from the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadStats() {
			this.loadingStats = true

			try {
				const response = await fetch('/index.php/apps/openregister/api/settings/stats')
				const data = await response.json()

				if (data.error) {
					console.error('Failed to load stats:', data.error)
					return
				}

				this.stats = {
					warnings: data.warnings || {},
					totals: data.totals || {},
					sizes: data.sizes || {},
					lastUpdated: new Date(data.lastUpdated || Date.now()),
				}

			} catch (error) {
				console.error('Failed to load stats:', error)
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Format a date for display
		 *
		 * @param {Date|string} date Date to format
		 * @return {string} Formatted date string
		 */
		formatDate(date) {
			if (!date) return 'Unknown'

			try {
				// Handle both string dates and date objects with nested date property
				let dateValue = date
				if (typeof date === 'object' && date.date) {
					dateValue = date.date
				}

				const dateObj = dateValue instanceof Date ? dateValue : new Date(dateValue)
				if (isNaN(dateObj.getTime())) {
					return 'Invalid Date'
				}
				return dateObj.toLocaleString()
			} catch (error) {
				console.error('Error formatting date:', error, date)
				return 'Invalid Date'
			}
		},

		/**
		 * Format bytes to human readable format
		 *
		 * @param {number} bytes Number of bytes
		 * @return {string} Formatted byte string
		 */
		formatBytes(bytes) {
			if (!bytes || bytes === 0) return '0 Bytes'

			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))

			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},
	},
})
</script>

<style scoped>
.version-info {
	max-width: none;
}

.version-details {
	margin-bottom: 2rem;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.version-item {
	margin-bottom: 0.5rem;
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.version-item:last-child {
	margin-bottom: 0;
}

.no-version {
	color: var(--color-text-lighter);
	font-style: italic;
}

.status-ok {
	color: var(--color-success);
	font-weight: bold;
}

.status-warning {
	color: var(--color-warning);
	font-weight: bold;
}

.status-error {
	color: var(--color-error);
	font-weight: bold;
}

.rbac-options,
.multitenancy-options,
.retention-options {
	max-width: none;
}

.option-section {
	margin-bottom: 1.5rem;
	padding: 1rem 0;
	border-bottom: 1px solid var(--color-border);
}

.option-section:last-child {
	border-bottom: none;
}

.option-description {
	margin-top: 0.5rem;
	color: var(--color-text-lighter);
	font-size: 0.9rem;
	line-height: 1.4;
}

.button-container {
	margin-top: 2rem;
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 2rem 0;
}

h3 {
	font-size: 13px;
}

h4 {
	margin-bottom: 0.5rem;
	font-weight: bold;
}

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

.section-subtitle {
	color: var(--color-text-lighter);
	font-size: 0.9rem;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 2rem;
	margin-bottom: 1rem;
}

.section-description {
	flex: 1;
	max-width: 70%;
}

.section-controls {
	flex-shrink: 0;
	align-self: flex-start;
}

.main-description {
	margin-bottom: 1rem;
	line-height: 1.5;
	color: var(--color-text-light);
}

.toggle-status {
	margin-bottom: 1rem;
	padding: 0.75rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.toggle-status:has(.status-enabled) {
	border-left: 4px solid var(--color-success);
}

.toggle-status:has(.status-disabled) {
	border-left: 4px solid var(--color-error);
}

.status-enabled {
	color: var(--color-success);
	font-weight: bold;
}

.status-disabled {
	color: var(--color-error);
	font-weight: bold;
}

.status-warning {
	border-left: 4px solid var(--color-warning);
}

.status-warning-text {
	color: var(--color-warning);
	font-weight: bold;
}

.status-success {
	border-left: 4px solid var(--color-success);
}

.warning-box {
	background-color: var(--color-warning-light, #fff3cd);
	border: 1px solid var(--color-warning, #ffc107);
	border-radius: var(--border-radius);
	padding: 0.75rem;
	margin-bottom: 1rem;
	color: var(--color-warning-dark, #856404);
}

.impact-description {
	margin-bottom: 0;
	padding: 0.75rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	line-height: 1.4;
	font-size: 0.9rem;
}

.impact-description strong {
	color: var(--color-text-dark);
}

@media (max-width: 768px) {
	.section-header {
		flex-direction: column;
		align-items: stretch;
		gap: 1rem;
	}

	.section-description {
		max-width: 100%;
	}

	.section-controls {
		align-self: stretch;
	}
}

.groups-table {
	margin-top: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.retention-table {
	margin-top: 1rem;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.groups-row {
	display: flex;
	align-items: flex-start;
	padding: 1rem;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.retention-row {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 1.5rem;
	align-items: center;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.groups-row:last-child {
	border-bottom: none;
}

.groups-row:nth-child(even) {
	background-color: var(--color-background-dark);
}

.group-label {
	flex: 1;
	padding-right: 1rem;
}

.group-label strong {
	display: block;
	margin-bottom: 0.5rem;
	color: var(--color-text-dark);
}

.user-type-description {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-lighter);
	line-height: 1.4;
}

.group-select {
	flex: 0 0 250px;
	min-width: 250px;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.retention-input-wrapper {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.retention-input {
	flex: 1;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.retention-input:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px var(--color-primary-light);
}

.retention-label {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.retention-label strong {
	font-size: 1rem;
	color: var(--color-text-dark);
}

.retention-description {
	margin: 0;
	color: var(--color-text-light);
	font-size: 0.9rem;
	line-height: 1.4;
}

.retention-input-field {
	width: 140px;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	text-align: right;
	background-color: var(--color-main-background);
}

.retention-input-field:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px var(--color-primary-light);
}

.retention-unit {
	color: var(--color-text-lighter);
	font-size: 0.9rem;
	font-weight: bold;
	min-width: 20px;
}

.retention-display {
	font-size: 0.8rem;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: 0.25rem 0.5rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-small);
	border-left: 3px solid var(--color-primary);
}

.retention-category-header {
	background-color: var(--color-background-dark) !important;
	border-bottom: 2px solid var(--color-border-dark) !important;
	font-weight: bold;
}

.retention-category-header .group-label strong {
	font-size: 1rem;
	color: var(--color-text-dark);
}

.category-indicator {
	padding: 0.25rem 0.75rem;
	background-color: var(--color-primary);
	color: white;
	border-radius: var(--border-radius);
	font-size: 0.8rem;
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.button-group {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.rebase-dialog {
	padding: 1.5rem;
	max-width: 700px;
	width: 100%;
}

.rebase-warning {
	margin-bottom: 1.5rem;
}

.rebase-warning h3 {
	color: var(--color-error);
	margin-bottom: 1rem;
	font-size: 1.1rem;
}

.warning-text {
	margin-bottom: 1rem;
	line-height: 1.5;
	color: var(--color-text-light);
}

.impact-text {
	padding: 1rem;
	background-color: var(--color-warning-light, #fff3cd);
	border: 1px solid var(--color-warning, #ffc107);
	border-radius: var(--border-radius);
	margin-bottom: 1rem;
	color: var(--color-warning-dark, #856404);
	line-height: 1.4;
	font-size: 0.9rem;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-top: 1rem;
}

.stats-section {
	max-width: none;
}

.stats-content {
	margin-top: 1rem;
}

.stats-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1.5rem;
	margin-bottom: 1.5rem;
}

.stats-card {
	padding: 1.5rem;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.stats-card h4 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: bold;
	color: var(--color-text-dark);
}

.stats-items {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.stats-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem 0;
	border-bottom: 1px solid var(--color-border-light);
}

.stats-item:last-child {
	border-bottom: none;
}

.stats-label {
	color: var(--color-text-light);
	font-size: 0.9rem;
}

.stats-value {
	font-weight: bold;
	font-size: 1rem;
}

.stats-value.warning {
	color: var(--color-warning);
}

.stats-table-value.danger {
	color: var(--color-error) !important;
	font-weight: 600;
}

.stats-value.total {
	color: var(--color-primary);
}

.stats-footer {
	text-align: center;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.stats-updated {
	margin: 0;
	color: var(--color-text-lighter);
	font-size: 0.8rem;
}

/* Stats Table Styles */
.stats-table-container {
	margin-top: 1rem;
	overflow-x: auto;
}

.stats-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9rem;
}

.stats-table-header {
	padding: 0.75rem 1rem;
	text-align: left;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
}

.stats-table-header:first-child {
	border-top-left-radius: var(--border-radius);
}

.stats-table-header:last-child {
	border-top-right-radius: var(--border-radius);
}

.stats-table-row {
	border-bottom: 1px solid var(--color-border-dark);
}

.stats-table-row:hover {
	background-color: var(--color-background-hover);
}

.stats-table-label {
	padding: 0.75rem 1rem;
	font-weight: 500;
	color: var(--color-text-light);
}

.stats-table-value {
	padding: 0.75rem 1rem;
	text-align: right;
	font-weight: 600;
}

.stats-table-value.total {
	color: var(--color-primary-element);
}

@media (max-width: 768px) {
	.stats-grid {
		grid-template-columns: 1fr;
		gap: 1rem;
	}
}

@media (max-width: 768px) {
	.groups-row {
		flex-direction: column;
		gap: 1rem;
	}

	.group-label {
		padding-right: 0;
	}

	.group-select {
		flex: 1;
		min-width: auto;
	}
}
</style>
