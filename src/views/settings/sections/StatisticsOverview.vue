<template>
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
										<td class="stats-table-value" :class="{ 'danger': stats.warnings.expiredAuditTrails > 0 }">
											{{ stats.warnings.expiredAuditTrails }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Expired search trails
										</td>
										<td class="stats-table-value" :class="{ 'danger': stats.warnings.expiredSearchTrails > 0 }">
											{{ stats.warnings.expiredSearchTrails }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Expired objects
										</td>
										<td class="stats-table-value" :class="{ 'danger': stats.warnings.expiredObjects > 0 }">
											{{ stats.warnings.expiredObjects }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<!-- General Stats -->
					<div class="stats-card general-stats">
						<h4>📊 System Overview</h4>
						<div class="stats-table-container">
							<table class="stats-table">
								<thead>
									<tr>
										<th class="stats-table-header">
											Resource
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
											Objects
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalObjects }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Configurations
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalConfigurations }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Audit Trails
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalAuditTrails }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Search Trails
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalSearchTrails }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Deleted Objects
										</td>
										<td class="stats-table-value">
											{{ stats.totals.deletedObjects }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Organisations
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalOrganisations }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Registers
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalRegisters }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Schemas
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalSchemas }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Sources
										</td>
										<td class="stats-table-value">
											{{ stats.totals.totalSources }}
										</td>
										<td class="stats-table-value">
											-
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Rebase Action -->
				<div v-if="hasWarnings" class="rebase-section">
					<div class="rebase-warning">
						<h4>🔧 Data Maintenance Required</h4>
						<p class="rebase-description">
							Your system has objects or logs that require attention. You can fix these issues by running a rebase operation
							which will recalculate deletion times and assign default owners/organizations to unassigned objects.
						</p>
						<div class="rebase-actions">
							<NcButton
								type="error"
								:disabled="loading || saving || rebasing"
								@click="settingsStore.showRebaseDialog">
								<template #icon>
									<NcLoadingIcon v-if="rebasing" :size="20" />
									<Refresh v-else :size="20" />
								</template>
								{{ rebasing ? 'Rebasing...' : 'Rebase All Objects and Logs' }}
							</NcButton>
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

		<!-- Rebase Confirmation Dialog -->
		<NcDialog v-if="showRebaseConfirmation"
			:open="showRebaseConfirmation"
			name="Confirm Rebase Operation"
			@closing="settingsStore.hideRebaseDialog">
			<div class="rebase-dialog-content">
				<h3>⚠️ Confirm Rebase Operation</h3>
				<p>
					This operation will recalculate deletion times for all objects and logs based on current retention settings.
					It will also assign default owners and organizations to objects that don't have them assigned.
				</p>
				<p><strong>This operation may take some time to complete.</strong></p>
				
				<div class="dialog-actions">
					<NcButton @click="settingsStore.hideRebaseDialog">
						Cancel
					</NcButton>
					<NcButton type="error"
						:disabled="rebasing"
						@click="settingsStore.confirmRebase">
						<template #icon>
							<NcLoadingIcon v-if="rebasing" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ rebasing ? 'Rebasing...' : 'Confirm Rebase' }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import { NcSettingsSection, NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'StatisticsOverview',
	
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		Refresh,
	},

	computed: {
		...mapStores(useSettingsStore),
		
		stats() {
			return this.settingsStore.stats
		},
		
		loadingStats() {
			return this.settingsStore.loadingStats
		},
		
		loading() {
			return this.settingsStore.loading
		},
		
		saving() {
			return this.settingsStore.saving
		},
		
		rebasing() {
			return this.settingsStore.rebasing
		},
		
		showRebaseConfirmation() {
			return this.settingsStore.showRebaseConfirmation
		},
		
		/**
		 * Check if there are any warnings that require attention
		 *
		 * @return {boolean} True if there are warnings
		 */
		hasWarnings() {
			return this.settingsStore.hasWarnings
		},
	},

	methods: {
		loadStats() {
			this.settingsStore.loadStats()
		},
	},
}
</script>

<style scoped>
.stats-section {
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

.stats-content {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
	gap: 20px;
	margin-bottom: 24px;
}

.stats-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.stats-card h4 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
	font-size: 16px;
	font-weight: 600;
}

.warning-stats {
	border-left: 4px solid var(--color-warning);
}

.general-stats {
	border-left: 4px solid var(--color-primary);
}

.stats-table-container {
	overflow-x: auto;
}

.stats-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.stats-table-header {
	text-align: left;
	padding: 12px 8px;
	border-bottom: 2px solid var(--color-border);
	background: var(--color-background-hover);
	font-weight: 600;
	color: var(--color-text-light);
}

.stats-table-row {
	border-bottom: 1px solid var(--color-border-dark);
}

.stats-table-row:hover {
	background: var(--color-background-hover);
}

.stats-table-label {
	padding: 12px 8px;
	color: var(--color-text-light);
	font-weight: 500;
}

.stats-table-value {
	padding: 12px 8px;
	color: var(--color-text-maxcontrast);
	text-align: right;
	font-family: monospace;
	font-size: 13px;
}

.stats-table-value.danger {
	color: var(--color-error);
	font-weight: 600;
}

.rebase-section {
	margin-top: 24px;
	padding: 20px;
	background: rgba(var(--color-warning), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-large);
}

.rebase-warning h4 {
	margin: 0 0 12px 0;
	color: var(--color-warning);
	font-size: 16px;
}

.rebase-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 16px 0;
}

.rebase-actions {
	display: flex;
	gap: 12px;
}

.loading-icon {
	margin: 40px auto;
	display: block;
}

@media (max-width: 768px) {
	.stats-grid {
		grid-template-columns: 1fr;
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

.rebase-dialog-content {
	padding: 20px;
}

.rebase-dialog-content h3 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
}

.rebase-dialog-content p {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 12px 0;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}
</style>