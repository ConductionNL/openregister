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
											Objects orphaned from registers
										</td>
										<td class="stats-table-value" :class="{ 'danger': stats.warnings.objectsOrphanedFromRegisters > 0 }">
											{{ stats.warnings.objectsOrphanedFromRegisters }}
										</td>
										<td class="stats-table-value">
											<NcButton
												v-if="stats.warnings.objectsOrphanedFromRegisters > 0"
												type="error"
												:disabled="deletingOrphanedRegisters"
												@click="deleteOrphanedFromRegisters">
												<template #icon>
													<NcLoadingIcon v-if="deletingOrphanedRegisters" :size="16" />
													<Delete v-else :size="16" />
												</template>
												Delete
											</NcButton>
											<span v-else>-</span>
										</td>
									</tr>
									<tr class="stats-table-row">
										<td class="stats-table-label">
											Objects orphaned from schemas
										</td>
										<td class="stats-table-value" :class="{ 'danger': stats.warnings.objectsOrphanedFromSchemas > 0 }">
											{{ stats.warnings.objectsOrphanedFromSchemas }}
										</td>
										<td class="stats-table-value">
											<NcButton
												v-if="stats.warnings.objectsOrphanedFromSchemas > 0"
												type="error"
												:disabled="deletingOrphanedSchemas"
												@click="deleteOrphanedFromSchemas">
												<template #icon>
													<NcLoadingIcon v-if="deletingOrphanedSchemas" :size="16" />
													<Delete v-else :size="16" />
												</template>
												Delete
											</NcButton>
											<span v-else>-</span>
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

				<!-- Mass Validate Action -->
				<div class="mass-validate-section">
					<div class="mass-validate-info">
						<h4>🔄 Mass Validate Objects</h4>
						<p class="mass-validate-description">
							Re-save all objects in the system to trigger business logic validation and processing. 
							This ensures all objects are properly processed according to current rules and schemas.
						</p>
						<div class="mass-validate-actions">
							<NcButton
								type="primary"
								:disabled="loading || saving || rebasing || massValidating"
								@click="openMassValidateModal">
								<template #icon>
									<NcLoadingIcon v-if="massValidating" :size="20" />
									<CheckCircle v-else :size="20" />
								</template>
								{{ massValidating ? 'Validating...' : 'Mass Validate Objects' }}
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

		<!-- Mass Validate Modal -->
		<MassValidateModal 
			:show="showMassValidateModal"
			:object-stats="objectStats"
			:mass-validating="massValidating"
			:completed="massValidateCompleted"
			:results="massValidateResults"
			:config="massValidateConfig"
			:memory-prediction="memoryPrediction"
			:memory-prediction-loading="memoryPredictionLoading"
			@close="closeMassValidateModal"
			@start-validate="handleStartMassValidate"
			@retry="handleRetryMassValidate"
			@reset="handleResetMassValidate"
		/>
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import { NcSettingsSection, NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { MassValidateModal } from '../../../modals/settings'

export default {
	name: 'StatisticsOverview',
	
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		Refresh,
		CheckCircle,
		Delete,
		MassValidateModal,
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

		massValidating() {
			return this.settingsStore.massValidating
		},

		showMassValidateConfirmation() {
			return this.settingsStore.showMassValidateConfirmation
		},

		massValidateResults() {
			return this.settingsStore.massValidateResults
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

	data() {
		return {
			showMassValidateModal: false,
			massValidateCompleted: false,
			objectStats: {
				loading: false,
				totalObjects: 0,
			},
			massValidateConfig: {
				mode: 'serial',
				maxObjects: 0,
				batchSize: 1000,
				collectErrors: false,
			},
			memoryPrediction: {
				prediction_safe: true,
				formatted: {
					total_predicted: 'Unknown',
					available: 'Unknown'
				}
			},
			memoryPredictionLoading: false,
			deletingOrphanedRegisters: false,
			deletingOrphanedSchemas: false,
		}
	},

	methods: {
		loadStats() {
			this.settingsStore.loadStats()
		},

		async openMassValidateModal() {
			// Open modal immediately for better UX
			this.showMassValidateModal = true
			
			// Load object stats and memory prediction in background
			this.loadObjectStats()
			this.loadMemoryPrediction(0) // Default to all objects
		},

		closeMassValidateModal() {
			this.showMassValidateModal = false
			// Reset state when modal is closed
			this.massValidateCompleted = false
		},

		async handleStartMassValidate(config) {
			this.massValidateCompleted = false
			this.massValidateConfig = { ...config }
			
			try {
				await this.settingsStore.massValidate(config)
				this.massValidateCompleted = true
			} catch (error) {
				console.error('Mass validate failed:', error)
				this.massValidateCompleted = true
			}
		},

		async handleRetryMassValidate() {
			await this.handleStartMassValidate(this.massValidateConfig)
		},

		handleResetMassValidate() {
			this.massValidateCompleted = false
			// Reset to default configuration
			this.massValidateConfig = {
				mode: 'serial',
				maxObjects: 0,
				batchSize: 1000,
				collectErrors: false,
			}
		},

		async loadObjectStats() {
			this.objectStats.loading = true
			
			try {
				// Get the total objects from the store stats
				const totalObjects = this.settingsStore.stats?.totals?.totalObjects || 0
				this.objectStats.totalObjects = totalObjects
			} catch (error) {
				console.error('Failed to load object stats:', error)
				this.objectStats.totalObjects = 0
			} finally {
				this.objectStats.loading = false
			}
		},

		async loadMemoryPrediction(maxObjects = 0) {
			this.memoryPredictionLoading = true
			try {
				const prediction = await this.settingsStore.loadMassValidateMemoryPrediction(maxObjects)
				this.memoryPrediction = prediction
			} catch (error) {
				console.warn('Failed to load memory prediction:', error)
				// Keep default prediction data
			} finally {
				this.memoryPredictionLoading = false
			}
		},

		async deleteOrphanedFromRegisters() {
			this.deletingOrphanedRegisters = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/settings/orphaned/registers', {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						'requesttoken': OC.requestToken
					}
				})

				const result = await response.json()

				if (result.success) {
					this.$toast.success(`Successfully deleted ${result.deleted_count} objects orphaned from registers`)
					// Reload stats to update the counts
					this.settingsStore.loadStats()
				} else {
					this.$toast.error(result.message || 'Failed to delete orphaned objects')
				}
			} catch (error) {
				console.error('Error deleting orphaned objects from registers:', error)
				this.$toast.error('Failed to delete orphaned objects from registers')
			} finally {
				this.deletingOrphanedRegisters = false
			}
		},

		async deleteOrphanedFromSchemas() {
			this.deletingOrphanedSchemas = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/settings/orphaned/schemas', {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						'requesttoken': OC.requestToken
					}
				})

				const result = await response.json()

				if (result.success) {
					this.$toast.success(`Successfully deleted ${result.deleted_count} objects orphaned from schemas`)
					// Reload stats to update the counts
					this.settingsStore.loadStats()
				} else {
					this.$toast.error(result.message || 'Failed to delete orphaned objects')
				}
			} catch (error) {
				console.error('Error deleting orphaned objects from schemas:', error)
				this.$toast.error('Failed to delete orphaned objects from schemas')
			} finally {
				this.deletingOrphanedSchemas = false
			}
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

.mass-validate-section {
	margin-top: 24px;
	padding: 20px;
	background: rgba(var(--color-primary), 0.1);
	border: 1px solid var(--color-primary);
	border-radius: var(--border-radius-large);
}

.mass-validate-info h4 {
	margin: 0 0 12px 0;
	color: var(--color-primary);
	font-size: 16px;
}

.mass-validate-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 16px 0;
}

.mass-validate-actions {
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