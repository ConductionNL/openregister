<script setup>
import { auditTrailStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'

</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Audit Trail Management')"
		:subtitle="t('openregister', 'Filter and manage audit trail entries')"
		:subname="t('openregister', 'Export, view, or delete audit trails')"
		:open="navigationStore.sidebarState.auditTrail"
		@update:open="(e) => navigationStore.setSidebarState('auditTrail', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openregister', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Audit Trails') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
					<NcSelect
						id="registerSelect"
						v-bind="registerOptions"
						:model-value="selectedRegisterValue"
						:placeholder="t('openregister', 'All registers')"
						:input-label="t('openregister', 'Register')"
						:clearable="true"
						@update:model-value="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect
						id="schemaSelect"
						v-bind="schemaOptions"
						:model-value="selectedSchemaValue"
						:placeholder="t('openregister', 'All schemas')"
						:input-label="t('openregister', 'Schema')"
						:disabled="!registerStore.registerItem"
						:clearable="true"
						@update:model-value="handleSchemaChange" />
				</div>
				<div class="filterGroup">
					<label for="actionSelect">{{ t('openregister', 'Actions') }}</label>
					<NcSelect
						id="actionSelect"
						v-model="selectedActions"
						:options="actionOptions"
						:placeholder="t('openregister', 'All actions')"
						:input-label="t('openregister', 'Actions')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label for="userSelect">{{ t('openregister', 'Users') }}</label>
					<NcSelect
						id="userSelect"
						v-model="selectedUsers"
						:options="userOptions"
						:placeholder="t('openregister', 'All users')"
						:input-label="t('openregister', 'Users')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Date Range') }}</label>
					<NcDateTimePickerNative
						v-model="dateFrom"
						:label="t('openregister', 'From date')"
						type="datetime-local"
						@input="applyFilters" />
					<NcDateTimePickerNative
						v-model="dateTo"
						:label="t('openregister', 'To date')"
						type="datetime-local"
						@input="applyFilters" />
				</div>
				<div class="filterGroup">
					<label for="objectFilter">{{ t('openregister', 'Object ID') }}</label>
					<NcTextField
						id="objectFilter"
						v-model="objectFilter"
						:label="t('openregister', 'Filter by object ID')"
						:placeholder="t('openregister', 'Enter object ID')"
						@update:value="handleObjectFilterChange" />
				</div>
				<div class="filterGroup">
					<NcCheckboxRadioSwitch
						v-model="showOnlyWithChanges"
						@update:checked="applyFilters">
						{{ t('openregister', 'Show only entries with changes') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<div class="actionGroup">
				<NcButton @click="clearFilters">
					<template #icon>
						<FilterOffOutline :size="20" />
					</template>
					{{ t('openregister', 'Clear Filters') }}
				</NcButton>
			</div>

			<NcNoteCard type="info" class="filter-hint">
				{{ t('openregister', 'Use filters to narrow down audit trail entries by register, schema, action type, user, date range, or object ID.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="export-tab" :name="t('openregister', 'Export & Actions')" :order="2">
			<template #icon>
				<Download :size="20" />
			</template>

			<!-- Export Section -->
			<div class="exportSection">
				<h3>{{ t('openregister', 'Export Audit Trails') }}</h3>
				<div class="exportGroup">
					<label for="formatSelect">{{ t('openregister', 'Export Format') }}</label>
					<NcSelect
						id="formatSelect"
						v-model="exportFormat"
						:options="exportFormatOptions"
						:placeholder="t('openregister', 'Select format')"
						:input-label="t('openregister', 'Export Format')"
						:clearable="false">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="exportGroup">
					<NcCheckboxRadioSwitch v-model="includeChanges">
						{{ t('openregister', 'Include change details') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="exportGroup">
					<NcCheckboxRadioSwitch v-model="includeMetadata">
						{{ t('openregister', 'Include metadata') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<div class="actionGroup">
				<NcButton
					type="primary"
					:disabled="filteredCount === 0"
					@click="exportFilteredAuditTrails">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('openregister', 'Export Filtered ({count})', { count: filteredCount }) }}
				</NcButton>
			</div>

			<!-- Bulk Actions Section -->
			<div class="bulkActionsSection">
				<h3>{{ t('openregister', 'Bulk Actions') }}</h3>
				<div class="actionGroup">
					<NcButton
						type="error"
						:disabled="filteredCount === 0"
						@click="clearFilteredAuditTrails">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Clear Filtered Entries ({count})', { count: filteredCount }) }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard type="warning" class="export-hint">
				{{ t('openregister', 'Exports include all filtered entries. Clearing entries will permanently delete them from the audit trail.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openregister', 'Statistics')" :order="3">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Audit Trail Statistics') }}</h3>
				<div class="statCard">
					<div class="statNumber">
						{{ totalAuditTrails }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Total Audit Trails') }}
					</div>
				</div>
				<div class="statCard">
					<div class="statNumber">
						{{ createCount }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Create Operations') }}
					</div>
				</div>
				<div class="statCard">
					<div class="statNumber">
						{{ updateCount }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Update Operations') }}
					</div>
				</div>
				<div class="statCard">
					<div class="statNumber">
						{{ deleteCount }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Delete Operations') }}
					</div>
				</div>
			</div>

			<!-- Action Distribution -->
			<div class="actionDistribution">
				<h4>{{ t('openregister', 'Action Distribution') }}</h4>
				<NcListItem v-for="(action, index) in actionDistribution"
					:key="index"
					:name="action.action"
					:bold="false">
					<template #icon>
						<Pencil v-if="action.action === 'update'" :size="32" />
						<Plus v-else-if="action.action === 'create'" :size="32" />
						<Delete v-else-if="action.action === 'delete'" :size="32" />
						<Eye v-else :size="32" />
					</template>
					<template #subname>
						{{ t('openregister', '{count} entries', { count: action.count }) }}
					</template>
				</NcListItem>
			</div>

			<!-- Top Objects -->
			<div class="topObjects">
				<h4>{{ t('openregister', 'Most Active Objects') }}</h4>
				<NcListItem v-for="(object, index) in topObjects"
					:key="index"
					:name="object.name"
					:bold="false">
					<template #icon>
						<CogOutline :size="32" />
					</template>
					<template #subname>
						{{ t('openregister', '{count} entries', { count: object.count }) }}
					</template>
				</NcListItem>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcSelect,
	NcNoteCard,
	NcButton,
	NcListItem,
	NcDateTimePickerNative,
	NcTextField,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'

export default {
	name: 'AuditTrailSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcButton,
		NcListItem,
		NcDateTimePickerNative,
		NcTextField,
		NcCheckboxRadioSwitch,
		FilterOutline,
		Download,
		ChartLine,
		Delete,
		CogOutline,
		Plus,
		Pencil,
		Eye,
		FilterOffOutline,
	},
	data() {
		return {
			activeTab: 'filters-tab',
			selectedActions: [],
			selectedUsers: [],
			dateFrom: null,
			dateTo: null,
			objectFilter: '',
			showOnlyWithChanges: false,
			exportFormat: { label: 'CSV', value: 'csv' },
			includeChanges: true,
			includeMetadata: false,
			filteredCount: 0,
			totalAuditTrails: 0,
			createCount: 0,
			updateCount: 0,
			deleteCount: 0,
			actionDistribution: [],
			topObjects: [],
			filterTimeout: null,
		}
	},
	computed: {
		actionOptions() {
			// Return all possible CRUD actions instead of just what's in current data
			return [
				{
					label: this.t('openregister', 'Create'),
					value: 'create',
				},
				{
					label: this.t('openregister', 'Read'),
					value: 'read',
				},
				{
					label: this.t('openregister', 'Update'),
					value: 'update',
				},
				{
					label: this.t('openregister', 'Delete'),
					value: 'delete',
				},
			]
		},
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					value: register.id,
					label: register.title,
					title: register.title,
					register,
				})),
				reduce: option => option.register,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.register && option.register.title) || option.label || ''
				},
			}
		},
		schemaOptions() {
			if (!registerStore.registerItem) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => registerStore.registerItem.schemas.includes(schema.id))
					.map(schema => ({
						value: schema.id,
						label: schema.title,
						title: schema.title,
						schema,
					})),
				reduce: option => option.schema,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.schema && option.schema.title) || option.label || ''
				},
			}
		},
		selectedRegisterValue() {
			if (!registerStore.registerItem) return null
			const register = registerStore.registerItem
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
		selectedSchemaValue() {
			if (!schemaStore.schemaItem) return null
			const schema = schemaStore.schemaItem
			return {
				value: schema.id,
				label: schema.title,
				title: schema.title,
				schema,
			}
		},
		userOptions() {
			if (!auditTrailStore.auditTrailList || !auditTrailStore.auditTrailList.length) {
				return []
			}
			// Get unique users from audit trail list
			const users = [...new Set(auditTrailStore.auditTrailList.map(trail => trail.userName || trail.user).filter(Boolean))]
			return users.map(user => ({
				label: user,
				value: user,
			}))
		},
		exportFormatOptions() {
			return [
				{ label: 'CSV', value: 'csv' },
				{ label: 'JSON', value: 'json' },
				{ label: 'XML', value: 'xml' },
				{ label: 'Plain Text', value: 'txt' },
			]
		},
	},
	watch: {
		'auditTrailStore.auditTrailList'() {
			this.updateFilteredCount()
			this.loadStatistics()
			this.loadActionDistribution()
			this.loadTopObjects()
		},
		// Watch for changes in the global stores
		'registerStore.registerItem'() {
			// Schema should be cleared when register changes, this happens in the change handler
			this.applyFilters()
		},
		'schemaStore.schemaItem'() {
			this.applyFilters()
		},
	},
	mounted() {
		// Load required data
		if (!registerStore.registerList.length) {
			registerStore.refreshRegisterList()
		}

		if (!schemaStore.schemaList.length) {
			schemaStore.refreshSchemaList()
		}

		// Load initial audit trail data and ensure it's refreshed
		this.loadAuditTrailData()

		this.loadStatistics()
		this.loadActionDistribution()
		this.loadTopObjects()

		// Listen for filtered count updates
		this.$root.$on('audit-trail-filtered-count', (count) => {
			this.filteredCount = count
		})

		// Watch store changes and update count
		this.updateFilteredCount()
	},
	beforeDestroy() {
		this.$root.$off('audit-trail-filtered-count')
	},
	methods: {
		/**
		 * Load audit trail data and update filtered count
		 * @return {Promise<void>}
		 */
		async loadAuditTrailData() {
			try {
				await auditTrailStore.refreshAuditTrailList()
				this.updateFilteredCount()
			} catch (error) {
				// Handle error silently
			}
		},
		/**
		 * Clear all filters
		 * @return {void}
		 */
		clearAllFilters() {
			// Clear component state
			this.selectedActions = []
			this.selectedUsers = []
			this.dateFrom = null
			this.dateTo = null
			this.objectFilter = ''
			this.showOnlyWithChanges = false

			// Clear global stores
			registerStore.setRegisterItem(null)
			schemaStore.setSchemaItem(null)

			// Clear store filters
			auditTrailStore.setAuditTrailFilters({})

			// Refresh without applying filters through applyFilters (which might re-add them)
			auditTrailStore.refreshAuditTrailList()
		},
		/**
		 * Clear filters (alias for clearAllFilters for template compatibility)
		 * @return {void}
		 */
		clearFilters() {
			this.clearAllFilters()
		},
		/**
		 * Handle object filter change with debouncing
		 * @param {string} value - The filter value
		 * @return {void}
		 */
		handleObjectFilterChange(value) {
			this.objectFilter = value
			this.debouncedApplyFilters()
		},
		/**
		 * Apply filters and emit to parent components
		 * @return {void}
		 */
		applyFilters() {
			const filters = {}

			// Build action filter - ensure we have a real array, not just the Observer
			if (Array.isArray(this.selectedActions) && this.selectedActions.length > 0) {
				// Convert to plain array to avoid Observer issues
				const actions = this.selectedActions.slice()
				if (actions.length > 0) {
					filters.action = actions.map(a => a.value).join(',')
				}
			}

			// Build register filter
			if (registerStore.registerItem) {
				filters.register = registerStore.registerItem.id.toString()
			}

			// Build schema filter
			if (schemaStore.schemaItem) {
				filters.schema = schemaStore.schemaItem.id.toString()
			}

			// Build user filter - ensure we have a real array, not just the Observer
			if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) {
				// Convert to plain array to avoid Observer issues
				const users = this.selectedUsers.slice()
				if (users.length > 0) {
					filters.user = users.map(u => u.value).join(',')
				}
			}

			// Date filters
			if (this.dateFrom) {
				filters.dateFrom = this.dateFrom
			}
			if (this.dateTo) {
				filters.dateTo = this.dateTo
			}

			// Object filter
			if (this.objectFilter) {
				filters.object = this.objectFilter
			}

			// Changes filter
			if (this.showOnlyWithChanges) {
				filters.onlyWithChanges = true
			}

			// Set filters in store and refresh data
			auditTrailStore.setAuditTrailFilters(filters)
			auditTrailStore.refreshAuditTrailList()

			// Also emit for legacy compatibility
			this.$root.$emit('audit-trail-filters-changed', filters)
		},
		/**
		 * Debounced version of applyFilters for text input
		 * @return {void}
		 */
		debouncedApplyFilters() {
			clearTimeout(this.filterTimeout)
			this.filterTimeout = setTimeout(() => {
				this.applyFilters()
			}, 500)
		},
		/**
		 * Update changes filter
		 * @param {boolean} value - Whether to show only entries with changes
		 * @return {void}
		 */
		updateChangesFilter(value) {
			this.showOnlyWithChanges = value
			this.applyFilters()
		},
		/**
		 * Update filtered count from store
		 * @return {void}
		 */
		updateFilteredCount() {
			this.filteredCount = auditTrailStore.auditTrailList.length
			this.totalAuditTrails = auditTrailStore.auditTrailPagination.total || auditTrailStore.auditTrailList.length
		},
		/**
		 * Export audit trails with current filters
		 * @return {Promise<void>}
		 */
		async exportFilteredAuditTrails() {
			try {
				// Build query parameters for export
				const params = new URLSearchParams()

				// Add current filters
				const filters = auditTrailStore.auditTrailFilters
				Object.entries(filters).forEach(([key, value]) => {
					if (value !== null && value !== undefined && value !== '') {
						if (Array.isArray(value)) {
							value.forEach(v => params.append(key, v))
						} else {
							params.append(key, value)
						}
					}
				})

				// Add format and options
				params.append('format', this.exportFormat.value)
				params.append('includeChanges', this.includeChanges.toString())
				params.append('includeMetadata', this.includeMetadata.toString())

				// Create download link
				const url = `/index.php/apps/openregister/api/audit-trails/export?${params.toString()}`

				// Handle the download
				const response = await fetch(url, {
					method: 'GET',
					headers: {
						requesttoken: OC.requestToken,
					},
				})

				if (response.ok) {
					const result = await response.blob()

					// Create download
					const link = document.createElement('a')
					link.href = URL.createObjectURL(result)
					link.download = `audit-trails-${new Date().toISOString().split('T')[0]}.${this.exportFormat.value}`
					document.body.appendChild(link)
					link.click()
					document.body.removeChild(link)
					URL.revokeObjectURL(link.href)

					navigationStore.setNotification({
						type: 'success',
						message: this.t('openregister', 'Audit trails exported successfully'),
					})
				} else {
					throw new Error('Export failed')
				}
			} catch (error) {
				navigationStore.setNotification({
					type: 'error',
					message: this.t('openregister', 'Failed to export audit trails'),
				})
			}
		},
		/**
		 * Clear filtered audit trails
		 * @return {void}
		 */
		clearFilteredAuditTrails() {
			navigationStore.setDialog({
				type: 'clearAuditTrails',
				data: {
					filters: auditTrailStore.auditTrailFilters,
					count: this.filteredCount,
				},
			})
		},
		/**
		 * Refresh audit trails
		 * @return {Promise<void>}
		 */
		async refreshAuditTrails() {
			try {
				await auditTrailStore.refreshAuditTrailList()
				this.updateFilteredCount()
				navigationStore.setNotification({
					type: 'success',
					message: this.t('openregister', 'Audit trails refreshed'),
				})
			} catch (error) {
				navigationStore.setNotification({
					type: 'error',
					message: this.t('openregister', 'Failed to refresh audit trails'),
				})
			}
		},
		/**
		 * Load statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				const stats = await auditTrailStore.getStatistics()
				this.totalAuditTrails = stats.total || 0
				this.createCount = stats.create || 0
				this.updateCount = stats.update || 0
				this.deleteCount = stats.delete || 0
			} catch (error) {
				// Handle error silently
			}
		},
		/**
		 * Load action distribution for stats
		 * @return {Promise<void>}
		 */
		async loadActionDistribution() {
			try {
				const actionData = await auditTrailStore.getActionDistribution()
				this.actionDistribution = actionData.map(action => ({
					action: action.action || action.name,
					count: action.count || 0,
					percentage: action.percentage || 0,
				}))
			} catch (error) {
				// Handle error silently
			}
		},
		/**
		 * Load top objects for stats
		 * @return {Promise<void>}
		 */
		async loadTopObjects() {
			try {
				const objectData = await auditTrailStore.getTopObjects()
				this.topObjects = objectData.map(object => ({
					name: object.objectId || object.name || `Object ${object.id}`,
					count: object.count || 0,
				}))
			} catch (error) {
				// Handle error silently
			}
		},
		/**
		 * Handle register change
		 * @param {object} register - Selected register
		 * @return {void}
		 */
		handleRegisterChange(register) {
			registerStore.setRegisterItem(register)
			schemaStore.setSchemaItem(null) // Clear schema when register changes
			this.applyFilters()
		},
		/**
		 * Handle schema change
		 * @param {object} schema - Selected schema
		 * @return {void}
		 */
		handleSchemaChange(schema) {
			schemaStore.setSchemaItem(schema)
			this.applyFilters()
		},
	},
}
</script>

<style scoped>
.filterSection,
.exportSection,
.actionsSection,
.statsSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child,
.exportSection:last-child,
.actionsSection:last-child,
.statsSection:last-child {
	border-bottom: none;
}

.filterSection h3,
.exportSection h3,
.actionsSection h3,
.statsSection h3 {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 16px;
	margin-bottom: 16px;
}

.filterGroup label {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.actionGroup {
	padding: 0 16px;
	margin-bottom: 12px;
}

.actionOption {
	display: flex;
	align-items: center;
	gap: 8px;
}

.actionOption.action-create {
	color: var(--color-success);
}

.actionOption.action-update {
	color: var(--color-warning);
}

.actionOption.action-delete {
	color: var(--color-error);
}

.actionOption.action-read {
	color: var(--color-info);
}

.filter-hint,
.export-hint {
	margin: 8px 16px;
}

.statsSection {
	padding: 16px;
}

.statCard {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 12px;
	text-align: center;
}

.statCard.create {
	border-left: 4px solid var(--color-success);
}

.statCard.update {
	border-left: 4px solid var(--color-warning);
}

.statCard.delete {
	border-left: 4px solid var(--color-error);
}

.statNumber {
	font-size: 2rem;
	font-weight: bold;
	color: var(--color-primary);
	margin-bottom: 4px;
}

.statLabel {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.actionDistribution,
.topObjects {
	margin-top: 20px;
}

.actionDistribution h4,
.topObjects h4 {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.actionBar {
	margin-bottom: 12px;
}

.actionLabel {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
	font-size: 0.9rem;
}

.actionLabel .action-create {
	color: var(--color-success);
	font-weight: bold;
}

.actionLabel .action-update {
	color: var(--color-warning);
	font-weight: bold;
}

.actionLabel .action-delete {
	color: var(--color-error);
	font-weight: bold;
}

.actionLabel .action-read {
	color: var(--color-info);
	font-weight: bold;
}

.actionProgress {
	background: var(--color-background-darker);
	border-radius: 4px;
	height: 8px;
	overflow: hidden;
}

.actionProgressBar {
	height: 100%;
	transition: width 0.3s ease;
}

.actionProgressBar.action-create {
	background: var(--color-success);
}

.actionProgressBar.action-update {
	background: var(--color-warning);
}

.actionProgressBar.action-delete {
	background: var(--color-error);
}

.actionProgressBar.action-read {
	background: var(--color-info);
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>
