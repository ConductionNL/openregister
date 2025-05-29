<script setup>
import { navigationStore, auditTrailStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Audit Trail Management')"
		:subtitle="t('openregister', 'Filter and analyze audit trails')"
		:subname="t('openregister', 'Advanced audit trail filtering and export')"
		:open="navigationStore.sidebarState.auditTrails"
		@update:open="(e) => navigationStore.setSidebarState('auditTrails', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openregister', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Advanced Filters') }}</h3>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Action Type') }}</label>
					<NcSelect
						v-model="selectedActions"
						:options="actionOptions"
						:placeholder="t('openregister', 'All actions')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							<span class="actionOption" :class="`action-${option.value}`">
								<Plus v-if="option.value === 'create'" :size="16" />
								<Pencil v-else-if="option.value === 'update'" :size="16" />
								<Delete v-else-if="option.value === 'delete'" :size="16" />
								<Eye v-else-if="option.value === 'read'" :size="16" />
								{{ option.label }}
							</span>
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Register') }}</label>
					<NcSelect
						v-model="selectedRegisters"
						:options="registerOptions"
						:placeholder="t('openregister', 'All registers')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Schema') }}</label>
					<NcSelect
						v-model="selectedSchemas"
						:options="schemaOptions"
						:placeholder="t('openregister', 'All schemas')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'User') }}</label>
					<NcSelect
						v-model="selectedUsers"
						:options="userOptions"
						:placeholder="t('openregister', 'All users')"
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
					<label>{{ t('openregister', 'Object ID') }}</label>
					<NcTextField
						v-model="objectFilter"
						:label="t('openregister', 'Search by object ID')"
						:placeholder="t('openregister', 'Enter object ID...')"
						@update:modelValue="debouncedApplyFilters" />
				</div>
				<div class="filterGroup">
					<NcCheckboxRadioSwitch
						:checked="showOnlyWithChanges"
						@update:checked="updateChangesFilter">
						{{ t('openregister', 'Show only entries with changes') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<NcNoteCard type="info" class="filter-hint">
				{{ t('openregister', 'Use filters to narrow down audit trail entries by action, register, schema, time period, or object.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="export-tab" :name="t('openregister', 'Export & Actions')" :order="2">
			<template #icon>
				<Download :size="20" />
			</template>

			<!-- Export Section -->
			<div class="exportSection">
				<h3>{{ t('openregister', 'Export Options') }}</h3>
				<div class="actionGroup">
					<label>{{ t('openregister', 'Export Format') }}</label>
					<NcSelect
						v-model="exportFormat"
						:options="exportFormatOptions"
						:placeholder="t('openregister', 'Select format')"
						:clearable="false">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="actionGroup">
					<NcCheckboxRadioSwitch
						:checked="includeChanges"
						@update:checked="(value) => includeChanges = value">
						{{ t('openregister', 'Include change data') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="actionGroup">
					<NcCheckboxRadioSwitch
						:checked="includeMetadata"
						@update:checked="(value) => includeMetadata = value">
						{{ t('openregister', 'Include metadata') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="actionGroup">
					<NcButton
						type="primary"
						:disabled="filteredCount === 0"
						@click="exportAuditTrails">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openregister', 'Export Filtered Audit Trails ({count})', { count: filteredCount }) }}
					</NcButton>
				</div>
			</div>

			<!-- Actions Section -->
			<div class="actionsSection">
				<h3>{{ t('openregister', 'Audit Trail Actions') }}</h3>
				<div class="actionGroup">
					<NcButton
						:disabled="filteredCount === 0"
						@click="clearFilteredAuditTrails">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Clear Filtered Entries') }}
					</NcButton>
				</div>
				<div class="actionGroup">
					<NcButton
						@click="refreshAuditTrails">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Refresh Audit Trails') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard type="warning" class="export-hint">
				{{ t('openregister', 'Large exports may take some time. Consider using date filters for better performance.') }}
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
						{{ t('openregister', 'Total Audit Trail Entries') }}
					</div>
				</div>
				<div class="statCard create">
					<div class="statNumber">
						{{ createCount }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Creates (24h)') }}
					</div>
				</div>
				<div class="statCard update">
					<div class="statNumber">
						{{ updateCount }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Updates (24h)') }}
					</div>
				</div>
				<div class="statCard delete">
					<div class="statNumber">
						{{ deleteCount }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Deletes (24h)') }}
					</div>
				</div>
			</div>

			<!-- Action Distribution -->
			<div class="actionDistribution">
				<h4>{{ t('openregister', 'Action Distribution (24h)') }}</h4>
				<div v-for="action in actionDistribution" :key="action.name" class="actionBar">
					<div class="actionLabel">
						<span :class="`action-${action.name}`">{{ action.name.toUpperCase() }}</span>
						<span class="actionCount">{{ action.count }}</span>
					</div>
					<div class="actionProgress">
						<div
							class="actionProgressBar"
							:class="`action-${action.name}`"
							:style="{ width: `${action.percentage}%` }" />
					</div>
				</div>
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
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

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
		Refresh,
		CogOutline,
		Plus,
		Pencil,
		Eye,
	},
	data() {
		return {
			activeTab: 'filters-tab',
			selectedActions: [],
			selectedRegisters: [],
			selectedSchemas: [],
			selectedUsers: [],
			dateFrom: '',
			dateTo: '',
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
			actionOptions: [
				{ label: this.t('openregister', 'Create'), value: 'create' },
				{ label: this.t('openregister', 'Update'), value: 'update' },
				{ label: this.t('openregister', 'Delete'), value: 'delete' },
				{ label: this.t('openregister', 'Read'), value: 'read' },
			],
			registerOptions: [
				{ label: this.t('openregister', 'Register 1'), value: '1' },
				{ label: this.t('openregister', 'Register 2'), value: '2' },
			],
			schemaOptions: [
				{ label: this.t('openregister', 'Schema 1'), value: '1' },
				{ label: this.t('openregister', 'Schema 2'), value: '2' },
			],
			userOptions: [
				{ label: this.t('openregister', 'Admin'), value: 'admin' },
				{ label: this.t('openregister', 'User1'), value: 'user1' },
				{ label: this.t('openregister', 'User2'), value: 'user2' },
			],
			exportFormatOptions: [
				{ label: 'CSV', value: 'csv' },
				{ label: 'JSON', value: 'json' },
				{ label: 'XML', value: 'xml' },
				{ label: 'Plain Text', value: 'txt' },
			],
		}
	},
	watch: {
		'auditTrailStore.auditTrailList'() {
			this.updateFilteredCount()
		},
	},
	mounted() {
		this.loadStatistics()
		this.loadActionDistribution()
		this.loadTopObjects()

		// Listen for filtered count updates
		this.$root.$on('audit-trail-filtered-count', (count) => {
			this.filteredCount = count
		})

		// Watch store changes
		this.updateFilteredCount()
	},
	beforeDestroy() {
		this.$root.$off('audit-trail-filtered-count')
	},
	methods: {
		/**
		 * Apply filters and emit to parent components
		 * @return {void}
		 */
		applyFilters() {
			const filters = {
				actions: this.selectedActions.map(a => a.value),
				registers: this.selectedRegisters.map(r => r.value),
				schemas: this.selectedSchemas.map(s => s.value),
				users: this.selectedUsers.map(u => u.value),
				dateFrom: this.dateFrom || null,
				dateTo: this.dateTo || null,
				object: this.objectFilter || null,
				onlyWithChanges: this.showOnlyWithChanges,
			}
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
			this.filteredCount = auditTrailStore.auditTrailCount
		},
		/**
		 * Export audit trails with current filters
		 * @return {void}
		 */
		exportAuditTrails() {
			const exportOptions = {
				format: this.exportFormat.value,
				includeChanges: this.includeChanges,
				includeMetadata: this.includeMetadata,
			}
			this.$root.$emit('audit-trail-export', exportOptions)
		},
		/**
		 * Clear filtered audit trails
		 * @return {void}
		 */
		clearFilteredAuditTrails() {
			this.$root.$emit('audit-trail-clear-filtered')
		},
		/**
		 * Refresh audit trails
		 * @return {void}
		 */
		refreshAuditTrails() {
			this.$root.$emit('audit-trail-refresh')
		},
		/**
		 * Load audit trail statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/audit-trails/statistics')
				// const stats = await response.json()

				// Mock data for now
				this.totalAuditTrails = 1247
				this.createCount = 123
				this.updateCount = 567
				this.deleteCount = 23
			} catch (error) {
				console.error('Error loading statistics:', error)
			}
		},
		/**
		 * Load action distribution data
		 * @return {Promise<void>}
		 */
		async loadActionDistribution() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/audit-trails/distribution')
				// const distribution = await response.json()

				// Mock data for now
				const data = [
					{ name: 'create', count: 123 },
					{ name: 'update', count: 567 },
					{ name: 'read', count: 456 },
					{ name: 'delete', count: 23 },
				]

				const total = data.reduce((sum, item) => sum + item.count, 0)
				this.actionDistribution = data.map(item => ({
					...item,
					percentage: (item.count / total) * 100,
				}))
			} catch (error) {
				console.error('Error loading action distribution:', error)
			}
		},
		/**
		 * Load top active objects
		 * @return {Promise<void>}
		 */
		async loadTopObjects() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/audit-trails/top-objects')
				// this.topObjects = await response.json()

				// Mock data for now
				this.topObjects = [
					{ name: 'Object 123', count: 42 },
					{ name: 'Object 456', count: 34 },
					{ name: 'Object 789', count: 23 },
					{ name: 'Object 012', count: 19 },
				]
			} catch (error) {
				console.error('Error loading top objects:', error)
			}
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
