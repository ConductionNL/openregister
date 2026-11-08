<template>
	<div>
		<SettingsSection
			name="System Statistics"
			description="Overview of your Open Register data and potential issues"
			:loading="loadingStats"
			:loadingMessage="t('openregister', 'Loading statistics...')">
			<template #actions>
				<NcButton
					variant="secondary"
					:disabled="loading || saving || rebasing || loadingStats"
					@click="loadStats">
					<template #icon>
						<NcLoadingIcon v-if="loadingStats" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					Refresh
				</NcButton>
			</template>

			<div class="stats-content">
				<div class="stats-grid">
					<div
						v-for="table in statsTables"
						:key="table.className"
						class="stats-card"
						:class="table.className">
						<h4>{{ table.title }}</h4>
						<div class="stats-table-container">
							<table class="stats-table">
								<thead>
									<tr>
										<th
											v-for="header in table.headers"
											:key="header"
											scope="col"
											class="stats-table-header">
											{{ header }}
										</th>
									</tr>
								</thead>
								<tbody>
									<tr
										v-for="row in table.rows"
										:key="row.label"
										class="stats-table-row">
										<td class="stats-table-label">
											{{ row.label }}
										</td>
										<td
											class="stats-table-value"
											:class="{ danger: row.danger }">
											{{ row.count }}
										</td>
										<td class="stats-table-value">
											<template v-if="row.clear">
												<NcButton
													v-if="row.count > 0"
													variant="error"
													size="small"
													:disabled="
														loading
														|| saving
														|| rebasing
														|| row.clearing
													"
													@click="row.clear">
													<template #icon>
														<NcLoadingIcon
															v-if="row.clearing"
															:size="16" />
														<Delete v-else :size="16" />
													</template>
													Clear All
												</NcButton>
												<span v-else>-</span>
											</template>
											<template v-else>
												{{ row.size }}
											</template>
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
							Your system has objects or logs that require attention.
							You can fix these issues by running a rebase operation
							which will recalculate deletion times and assign default
							owners/organizations to unassigned objects.
						</p>
						<div class="rebase-actions">
							<NcButton
								variant="error"
								:disabled="loading || saving || rebasing"
								@click="settingsStore.showRebaseDialog">
								<template #icon>
									<NcLoadingIcon v-if="rebasing" :size="20" />
									<Refresh v-else :size="20" />
								</template>
								{{
									rebasing
										? 'Rebasing...'
										: 'Rebase All Objects and Logs'
								}}
							</NcButton>
						</div>
					</div>
				</div>

				<!-- Mass Validate Action -->
				<div class="mass-validate-section">
					<div class="mass-validate-info">
						<h4>🔄 Mass Validate Objects</h4>
						<p class="mass-validate-description">
							Re-save all objects in the system to trigger business
							logic validation and processing. This ensures all objects
							are properly processed according to current rules and
							schemas.
						</p>
						<div class="mass-validate-actions">
							<NcButton
								variant="primary"
								:disabled="
									loading || saving || rebasing || massValidating
								"
								@click="openMassValidateModal">
								<template #icon>
									<NcLoadingIcon
										v-if="massValidating"
										:size="20" />
									<CheckCircle v-else :size="20" />
								</template>
								{{
									massValidating
										? 'Validating...'
										: 'Mass Validate Objects'
								}}
							</NcButton>
						</div>
					</div>
				</div>
			</div>
		</SettingsSection>

		<!-- Rebase Confirmation Dialog -->
		<RebaseConfirmationDialog
			v-if="showRebaseConfirmation"
			:open="showRebaseConfirmation"
			:rebasing="rebasing"
			@closing="settingsStore.hideRebaseDialog"
			@confirm="settingsStore.confirmRebase" />

		<!-- Mass Validate Modal -->
		<MassValidateModal
			:show="showMassValidateModal"
			:objectStats="objectStats"
			:massValidating="massValidating"
			:completed="massValidateCompleted"
			:results="massValidateResults"
			:config="massValidateConfig"
			:memoryPrediction="memoryPrediction"
			:memoryPredictionLoading="memoryPredictionLoading"
			@close="closeMassValidateModal"
			@startValidate="handleStartMassValidate"
			@retry="handleRetryMassValidate"
			@reset="handleResetMassValidate" />

		<!-- Clear Audit Trails Confirmation Dialog -->
		<ClearAuditTrailsDialog
			v-if="showClearAuditTrailsConfirmation"
			:open="showClearAuditTrailsConfirmation"
			:clearing="clearingAuditTrails"
			:totalAuditTrails="stats.totals.totalAuditTrails"
			@closing="hideClearAuditTrailsDialog"
			@confirm="clearAllAuditTrails" />

		<!-- Clear Search Trails Confirmation Dialog -->
		<ClearSearchTrailsDialog
			v-if="showClearSearchTrailsConfirmation"
			:open="showClearSearchTrailsConfirmation"
			:clearing="clearingSearchTrails"
			:totalSearchTrails="stats.totals.totalSearchTrails"
			@closing="hideClearSearchTrailsDialog"
			@confirm="clearAllSearchTrails" />

		<!-- Clear Blob Objects Confirmation Dialog -->
		<ClearBlobObjectsDialog
			v-if="showClearBlobObjectsConfirmation"
			:open="showClearBlobObjectsConfirmation"
			:clearing="clearingBlobObjects"
			:totalBlobObjects="stats.totals.totalBlobObjects"
			@closing="hideClearBlobObjectsDialog"
			@confirm="clearAllBlobObjects" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import ClearAuditTrailsDialog from '../../../dialogs/settings/ClearAuditTrailsDialog.vue'
import ClearBlobObjectsDialog from '../../../dialogs/settings/ClearBlobObjectsDialog.vue'
import ClearSearchTrailsDialog from '../../../dialogs/settings/ClearSearchTrailsDialog.vue'
// eslint-disable-next-line n/no-unpublished-import -- false positive: this bundled dialog (identical in shape to the sibling dialogs imported below, which lint clean) is app source shipped via webpack, not an npm package.
import RebaseConfirmationDialog from '../../../dialogs/settings/RebaseConfirmationDialog.vue'
import MassValidateModal from '../../../modals/settings/MassValidateModal.vue'
import { settingsSectionState } from './settingsSectionState.js'

/**
 * The rows of the attention table: their label and warning count.
 */
const WARNING_ROWS = [
	['Objects without owner', 'objectsWithoutOwner'],
	['Objects without organisation', 'objectsWithoutOrganisation'],
	['Audit trails without expiry', 'auditTrailsWithoutExpiry'],
	['Search trails without expiry', 'searchTrailsWithoutExpiry'],
	['Expired audit trails', 'expiredAuditTrails'],
	['Expired search trails', 'expiredSearchTrails'],
	['Expired objects', 'expiredObjects'],
]

/**
 * The rows of the overview table: their label, the totals they show and,
 * for a clearable resource, the suffix of its clearing flag and dialog.
 */
const TOTAL_ROWS = [
	{ label: 'Objects', count: 'totalObjects', size: 'totalSize' },
	{
		label: '└─ Blob Storage Objects',
		count: 'totalBlobObjects',
		clear: 'BlobObjects',
	},
	{
		label: '└─ Magic Mapper Objects',
		count: 'totalMagicObjects',
		size: 'totalMagicSize',
	},
	{ label: 'Configurations', count: 'totalConfigurations' },
	{ label: 'Audit Trails', count: 'totalAuditTrails', clear: 'AuditTrails' },
	{ label: 'Search Trails', count: 'totalSearchTrails', clear: 'SearchTrails' },
	{ label: 'Webhook Logs', count: 'totalWebhookLogs' },
	{ label: 'Deleted Objects', count: 'deletedObjects' },
	{ label: 'Organisations', count: 'totalOrganisations' },
	{ label: 'Registers', count: 'totalRegisters' },
	{ label: 'Schemas', count: 'totalSchemas' },
	{ label: 'Sources', count: 'totalSources' },
]

export default {
	name: 'StatisticsOverview',

	components: {
		SettingsSection,
		NcButton,
		NcLoadingIcon,
		Refresh,
		CheckCircle,
		Delete,
		MassValidateModal,
		RebaseConfirmationDialog,
		ClearAuditTrailsDialog,
		ClearSearchTrailsDialog,
		ClearBlobObjectsDialog,
	},

	mixins: [settingsSectionState],

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
					available: 'Unknown',
				},
			},

			memoryPredictionLoading: false,
		}
	},

	computed: {
		/**
		 * The attention and overview tables, for display.
		 *
		 * @spec exclude UI plumbing — table layout of the statistics
		 * @return {Array<object>}
		 */
		statsTables() {
			return [
				{
					className: 'warning-stats',
					title: '⚠️ Items Requiring Attention',
					headers: ['Issue', 'Count', 'Size'],
					rows: this.warningRows,
				},
				{
					className: 'general-stats',
					title: '📊 System Overview',
					headers: ['Resource', 'Count', 'Size'],
					rows: TOTAL_ROWS.map((row) => this.totalRow(row)),
				},
			]
		},

		/**
		 * The rows of the attention table, a count above zero marked as danger.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {Array<object>}
		 */
		warningRows() {
			const warnings = this.stats.warnings
			return WARNING_ROWS.map(([label, key]) => ({
				label,
				count: warnings[key],
				danger: warnings[key] > 0,
				size: '-',
			}))
		},

		/**
		 * Statistics object from the settings store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object}
		 */
		stats() {
			return this.settingsStore.stats
		},

		/**
		 * Whether statistics are loading, for spinner display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {boolean}
		 */
		loadingStats() {
			return this.settingsStore.loadingStats
		},

		/**
		 * Whether the rebase confirmation is showing, for display.
		 *
		 * @spec exclude UI plumbing — derived dialog visibility state
		 * @return {boolean}
		 */
		showRebaseConfirmation() {
			return this.settingsStore.showRebaseConfirmation
		},

		/**
		 * Whether mass validation is in progress, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {boolean}
		 */
		massValidating() {
			return this.settingsStore.massValidating
		},

		/**
		 * Whether the mass-validate confirmation is showing, for display.
		 *
		 * @spec exclude UI plumbing — derived dialog visibility state
		 * @return {boolean}
		 */
		showMassValidateConfirmation() {
			return this.settingsStore.showMassValidateConfirmation
		},

		/**
		 * Mass-validate results from the store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object}
		 */
		massValidateResults() {
			return this.settingsStore.massValidateResults
		},

		/**
		 * Whether audit trails are being cleared, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {boolean}
		 */
		clearingAuditTrails() {
			return this.settingsStore.clearingAuditTrails
		},

		/**
		 * Whether search trails are being cleared, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {boolean}
		 */
		clearingSearchTrails() {
			return this.settingsStore.clearingSearchTrails
		},

		/**
		 * Whether blob objects are being cleared, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {boolean}
		 */
		clearingBlobObjects() {
			return this.settingsStore.clearingBlobObjects
		},

		/**
		 * Whether the clear-audit-trails confirmation is showing, for display.
		 *
		 * @spec exclude UI plumbing — derived dialog visibility state
		 * @return {boolean}
		 */
		showClearAuditTrailsConfirmation() {
			return this.settingsStore.showClearAuditTrailsConfirmation
		},

		/**
		 * Whether the clear-search-trails confirmation is showing, for display.
		 *
		 * @spec exclude UI plumbing — derived dialog visibility state
		 * @return {boolean}
		 */
		showClearSearchTrailsConfirmation() {
			return this.settingsStore.showClearSearchTrailsConfirmation
		},

		/**
		 * Whether the clear-blob-objects confirmation is showing, for display.
		 *
		 * @spec exclude UI plumbing — derived dialog visibility state
		 * @return {boolean}
		 */
		showClearBlobObjectsConfirmation() {
			return this.settingsStore.showClearBlobObjectsConfirmation
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
		/**
		 * One row of the overview table: its count, its size or a dash, and
		 * for a clearable resource its clearing flag and dialog opener.
		 *
		 * @param {object} row The row from TOTAL_ROWS
		 * @param {string} row.label The label
		 * @param {string} row.count The totals key of the count
		 * @param {string} [row.size] The totals key of the size, if any
		 * @param {string} [row.clear] The suffix of the clearing flag and dialog, if any
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object} The row to display
		 */
		totalRow({ label, count, size, clear }) {
			const totals = this.stats.totals
			return {
				label,
				count: totals[count],
				size: size ? this.formatBytes(totals[size]) : '-',
				clearing: clear ? this[`clearing${clear}`] : false,
				clear: clear ? this[`showClear${clear}Dialog`] : null,
			}
		},

		/**
		 * Load statistics from the settings store.
		 *
		 * @spec exclude UI plumbing — delegates to the settings store
		 * @return {void}
		 */
		loadStats() {
			this.settingsStore.loadStats()
		},

		/**
		 * Format bytes to human-readable size.
		 *
		 * @param {number} bytes - The size in bytes
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {string} Formatted size string (e.g., '1.5 MB')
		 */
		formatBytes(bytes) {
			if (bytes === 0 || bytes === null || bytes === undefined) return '0 B'

			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))

			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},

		/**
		 * Open the mass-validate modal and load supporting data in the background.
		 *
		 * @spec exclude UI plumbing — modal open plus background data fetch
		 * @return {Promise<void>}
		 */
		async openMassValidateModal() {
			// Open modal immediately for better UX
			this.showMassValidateModal = true

			// Load object stats and memory prediction in background
			this.loadObjectStats()
			this.loadMemoryPrediction(0) // Default to all objects
		},

		/**
		 * Close the mass-validate modal and reset its state.
		 *
		 * @spec exclude UI plumbing — modal visibility toggle
		 * @return {void}
		 */
		closeMassValidateModal() {
			this.showMassValidateModal = false
			// Reset state when modal is closed
			this.massValidateCompleted = false
		},

		/**
		 * Start a mass-validate run with the given config.
		 *
		 * @param {object} config The mass-validate configuration.
		 * @spec exclude UI plumbing — action delegates to the settings store
		 * @return {Promise<void>}
		 */
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

		/**
		 * Retry the last mass-validate run.
		 *
		 * @spec exclude UI plumbing — re-invokes the start handler
		 * @return {Promise<void>}
		 */
		async handleRetryMassValidate() {
			await this.handleStartMassValidate(this.massValidateConfig)
		},

		/**
		 * Reset the mass-validate form to defaults.
		 *
		 * @spec exclude UI plumbing — resets local form state
		 * @return {void}
		 */
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

		/**
		 * Load total object count from store stats for the modal.
		 *
		 * @spec exclude UI plumbing — reads store stats for display
		 * @return {Promise<void>}
		 */
		async loadObjectStats() {
			this.objectStats.loading = true

			try {
				// Get the total objects from the store stats
				const totalObjects =
					this.settingsStore.stats?.totals?.totalObjects || 0
				this.objectStats.totalObjects = totalObjects
			} catch (error) {
				console.error('Failed to load object stats:', error)
				this.objectStats.totalObjects = 0
			} finally {
				this.objectStats.loading = false
			}
		},

		/**
		 * Load the memory-usage prediction for a mass-validate run.
		 *
		 * @param {number} maxObjects The object cap for the prediction.
		 * @spec exclude UI plumbing — delegates to the settings store
		 * @return {Promise<void>}
		 */
		async loadMemoryPrediction(maxObjects = 0) {
			this.memoryPredictionLoading = true
			try {
				const prediction =
					await this.settingsStore.loadMassValidateMemoryPrediction(
						maxObjects,
					)
				this.memoryPrediction = prediction
			} catch (error) {
				console.warn('Failed to load memory prediction:', error)
				// Keep default prediction data
			} finally {
				this.memoryPredictionLoading = false
			}
		},

		/**
		 * Show the clear-audit-trails confirmation dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle via store
		 * @return {void}
		 */
		showClearAuditTrailsDialog() {
			this.settingsStore.showClearAuditTrailsDialog()
		},

		/**
		 * Hide the clear-audit-trails confirmation dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle via store
		 * @return {void}
		 */
		hideClearAuditTrailsDialog() {
			this.settingsStore.hideClearAuditTrailsDialog()
		},

		/**
		 * Clear all audit trails and reload stats.
		 *
		 * @spec exclude UI plumbing — action delegates to the settings store
		 * @return {Promise<void>}
		 */
		async clearAllAuditTrails() {
			try {
				await this.settingsStore.clearAllAuditTrails()
				// Reload stats after clearing
				await this.loadStats()
			} catch (error) {
				console.error('Failed to clear audit trails:', error)
			}
		},

		/**
		 * Show the clear-search-trails confirmation dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle via store
		 * @return {void}
		 */
		showClearSearchTrailsDialog() {
			this.settingsStore.showClearSearchTrailsDialog()
		},

		/**
		 * Hide the clear-search-trails confirmation dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle via store
		 * @return {void}
		 */
		hideClearSearchTrailsDialog() {
			this.settingsStore.hideClearSearchTrailsDialog()
		},

		/**
		 * Clear all search trails and reload stats.
		 *
		 * @spec exclude UI plumbing — action delegates to the settings store
		 * @return {Promise<void>}
		 */
		async clearAllSearchTrails() {
			try {
				await this.settingsStore.clearAllSearchTrails()
				// Reload stats after clearing
				await this.loadStats()
			} catch (error) {
				console.error('Failed to clear search trails:', error)
			}
		},

		/**
		 * Show the clear-blob-objects confirmation dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle via store
		 * @return {void}
		 */
		showClearBlobObjectsDialog() {
			this.settingsStore.showClearBlobObjectsDialog()
		},

		/**
		 * Hide the clear-blob-objects confirmation dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle via store
		 * @return {void}
		 */
		hideClearBlobObjectsDialog() {
			this.settingsStore.hideClearBlobObjectsDialog()
		},

		/**
		 * Clear all blob objects and reload stats.
		 *
		 * @spec exclude UI plumbing — action delegates to the settings store
		 * @return {Promise<void>}
		 */
		async clearAllBlobObjects() {
			try {
				await this.settingsStore.clearAllBlobObjects()
				// Reload stats after clearing.
				await this.loadStats()
			} catch (error) {
				console.error('Failed to clear blob objects:', error)
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/views.scss' as *;

/* SettingsSection handles all action button positioning and spacing */

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

@include loading-icon;

@media (max-width: 768px) {
	.stats-grid {
		grid-template-columns: 1fr;
	}
}
</style>
