<script setup>
import { navigationStore, auditTrailStore, registerStore, schemaStore, dashboardStore } from '../../store/store.js'
import DeleteAuditTrail from '../../modals/logs/DeleteAuditTrail.vue'
import AuditTrailDetails from '../../modals/logs/AuditTrailDetails.vue'
import AuditTrailChanges from '../../modals/logs/AuditTrailChanges.vue'
import ClearAuditTrails from '../../modals/logs/ClearAuditTrails.vue'
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
					<NcButton
						type="secondary"
						@click="clearAllFilters">
						<template #icon>
							<FilterOffOutline :size="20" />
						</template>
						{{ t('openregister', 'Clear All Filters') }}
					</NcButton>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Action Type') }}</label>
					<NcSelect
						v-model="selectedActions"
						:options="actionOptions"
						:placeholder="t('openregister', 'All actions')"
						:input-label="t('openregister', 'Action Type')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							<span v-if="option" class="actionOption" :class="`action-${option.value || ''}`">
								<Plus v-if="option.value === 'create'" :size="16" />
								<Pencil v-else-if="option.value === 'update'" :size="16" />
								<Delete v-else-if="option.value === 'delete'" :size="16" />
								<Eye v-else-if="option.value === 'read'" :size="16" />
								{{ option.label || option.value || '' }}
							</span>
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Register') }}</label>
					<NcSelect
						:model-value="selectedRegisterValue"
						v-bind="registerOptions"
						:placeholder="t('openregister', 'All registers')"
						:input-label="t('openregister', 'Register')"
						:clearable="true"
						@update:model-value="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Schema') }}</label>
					<NcSelect
						:model-value="selectedSchemaValue"
						v-bind="schemaOptions"
						:placeholder="t('openregister', 'All schemas')"
						:input-label="t('openregister', 'Schema')"
						:disabled="!registerStore.registerItem"
						:clearable="true"
						@update:model-value="handleSchemaChange" />
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'User') }}</label>
					<NcSelect
						v-model="selectedUsers"
						:options="userOptions"
						:placeholder="t('openregister', 'All users')"
						:input-label="t('openregister', 'User')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							<span v-if="option">{{ option.label || option.value || '' }}</span>
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
						:input-label="t('openregister', 'Export Format')"
						:clearable="false">
						<template #option="{ option }">
							<span v-if="option">{{ option.label || option.value || '' }}</span>
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
						:disabled="false"
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
						:disabled="false"
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

	<!-- Import the new modals -->
	<DeleteAuditTrail />
	<AuditTrailDetails />
	<AuditTrailChanges />
	<ClearAuditTrails />
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
		Refresh,
		CogOutline,
		Plus,
		Pencil,
		Eye,
		FilterOffOutline,
		DeleteAuditTrail,
		AuditTrailDetails,
		AuditTrailChanges,
		ClearAuditTrails,
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
			if (!auditTrailStore.uniqueUsers || !auditTrailStore.uniqueUsers.length) {
				return []
			}
			return auditTrailStore.uniqueUsers.map(user => ({
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
			console.log('Loading audit trail data...')
			try {
				await auditTrailStore.refreshAuditTrailList()
				this.updateFilteredCount()
				console.log('Audit trail data loaded. Count:', this.filteredCount)
			} catch (error) {
				console.error('Error loading audit trail data:', error)
				// Set count to 0 if loading fails
				this.filteredCount = 0
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
			auditTrailStore.setFilters({})

			// Method 2: If the store has a clearFilters method, use it
			if (typeof auditTrailStore.clearFilters === 'function') {
				auditTrailStore.clearFilters()
			}

			// Method 3: Directly set filters to empty if accessible
			if (auditTrailStore.filters) {
				auditTrailStore.filters = {}
			}

			// Refresh without applying filters through applyFilters (which might re-add them)
			auditTrailStore.refreshAuditTrailList()
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
			auditTrailStore.setFilters(filters)
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
			this.filteredCount = auditTrailStore.auditTrailCount
			this.totalAuditTrails = auditTrailStore.pagination.total || auditTrailStore.auditTrailCount
		},
		/**
		 * Export audit trails with current filters
		 * @return {Promise<void>}
		 */
		async exportAuditTrails() {
			console.log('Export button clicked')
			console.log('Export format:', this.exportFormat)
			console.log('Include changes:', this.includeChanges)
			console.log('Include metadata:', this.includeMetadata)
			console.log('Current filters:', auditTrailStore.filters)

			try {
				// Build query parameters
				const params = new URLSearchParams()
				params.append('format', this.exportFormat.value || 'csv')
				params.append('includeChanges', this.includeChanges || false)
				params.append('includeMetadata', this.includeMetadata || false)

				// Add current filters
				if (auditTrailStore.filters) {
					Object.entries(auditTrailStore.filters).forEach(([key, value]) => {
						if (value !== null && value !== undefined && value !== '') {
							params.append(key, value)
						}
					})
				}

				console.log('API URL:', `/index.php/apps/openregister/api/audit-trails/export?${params.toString()}`)

				// Make the API request
				const response = await fetch(`/index.php/apps/openregister/api/audit-trails/export?${params.toString()}`)
				console.log('Response status:', response.status)

				const result = await response.json()
				console.log('Response result:', result)

				if (result.success && result.data) {
					// Create and trigger download
					const blob = new Blob([result.data.content], { type: result.data.contentType })
					const url = window.URL.createObjectURL(blob)
					const a = document.createElement('a')
					a.href = url
					a.download = result.data.filename
					document.body.appendChild(a)
					a.click()
					window.URL.revokeObjectURL(url)
					document.body.removeChild(a)

					OC.Notification.showSuccess(this.t('openregister', 'Export completed successfully'))
				} else {
					throw new Error(result.error || 'Export failed')
				}
			} catch (error) {
				console.error('Error exporting audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Export failed: {error}', { error: error.message }))
			}
		},
		/**
		 * Clear filtered audit trails
		 * @return {void}
		 */
		clearFilteredAuditTrails() {
			console.log('Clear filtered button clicked')
			console.log('Current filters:', auditTrailStore.filters)

			// Open the clear confirmation modal instead of using browser confirm
			navigationStore.setDialog('clearAuditTrails')
		},
		/**
		 * Refresh audit trails
		 * @return {Promise<void>}
		 */
		async refreshAuditTrails() {
			console.log('Refresh button clicked')

			try {
				await this.loadAuditTrailData()
				// Update statistics as well
				this.loadStatistics()
				this.loadActionDistribution()
				this.loadTopObjects()
				OC.Notification.showSuccess(this.t('openregister', 'Audit trails refreshed successfully'))
			} catch (error) {
				console.error('Error refreshing audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Error refreshing audit trails'))
			}
		},
		/**
		 * Load audit trail statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				// Use dashboard store method instead of direct API call
				await dashboardStore.fetchAuditTrailStatistics(24)

				// Update local component state from store data
				const stats = dashboardStore.statisticsData.auditTrailStats
				if (stats) {
					this.totalAuditTrails = stats.total
					this.createCount = stats.creates
					this.updateCount = stats.updates
					this.deleteCount = stats.deletes
				}
			} catch (error) {
				console.error('Error loading statistics:', error)
				// Fallback to client-side calculation
				this.fallbackLoadStatistics()
			}
		},
		/**
		 * Fallback method for client-side statistics calculation
		 * @return {void}
		 */
		fallbackLoadStatistics() {
			// Calculate statistics from current audit trail data
			this.totalAuditTrails = auditTrailStore.pagination.total || auditTrailStore.auditTrailCount

			// Calculate counts for different actions in the last 24 hours
			const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000)
			const recentTrails = auditTrailStore.auditTrailList.filter(trail => {
				const createdDate = new Date(trail.created)
				return createdDate >= oneDayAgo
			})

			this.createCount = recentTrails.filter(trail => trail.action === 'create').length
			this.updateCount = recentTrails.filter(trail => trail.action === 'update').length
			this.deleteCount = recentTrails.filter(trail => trail.action === 'delete').length
		},
		/**
		 * Load action distribution data
		 * @return {Promise<void>}
		 */
		async loadActionDistribution() {
			try {
				// Use dashboard store method instead of direct API call
				await dashboardStore.fetchActionDistribution(24)

				// Update local component state from store data
				const distribution = dashboardStore.statisticsData.actionDistribution
				if (distribution && distribution.actions) {
					this.actionDistribution = distribution.actions
				}
			} catch (error) {
				console.error('Error loading action distribution:', error)
				// Fallback to client-side calculation
				this.fallbackLoadActionDistribution()
			}
		},
		/**
		 * Fallback method for client-side action distribution calculation
		 * @return {void}
		 */
		fallbackLoadActionDistribution() {
			// Calculate action distribution from recent audit trail data
			const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000)
			const recentTrails = auditTrailStore.auditTrailList.filter(trail => {
				const createdDate = new Date(trail.created)
				return createdDate >= oneDayAgo
			})

			// Count actions
			const actionCounts = {}
			recentTrails.forEach(trail => {
				actionCounts[trail.action] = (actionCounts[trail.action] || 0) + 1
			})

			// Convert to distribution format
			const data = Object.entries(actionCounts).map(([action, count]) => ({
				name: action,
				count,
			}))

			const total = data.reduce((sum, item) => sum + item.count, 0)
			this.actionDistribution = data.map(item => ({
				...item,
				percentage: total > 0 ? (item.count / total) * 100 : 0,
			}))
		},
		/**
		 * Load top active objects
		 * @return {Promise<void>}
		 */
		async loadTopObjects() {
			try {
				// Use dashboard store method instead of direct API call
				await dashboardStore.fetchMostActiveObjects(4, 24)

				// Update local component state from store data
				const objects = dashboardStore.statisticsData.mostActiveObjects
				if (objects && objects.objects) {
					this.topObjects = objects.objects
				}
			} catch (error) {
				console.error('Error loading top objects:', error)
				// Fallback to client-side calculation
				this.fallbackLoadTopObjects()
			}
		},
		/**
		 * Fallback method for client-side top objects calculation
		 * @return {void}
		 */
		fallbackLoadTopObjects() {
			// Calculate top objects from audit trail data
			const objectCounts = {}
			auditTrailStore.auditTrailList.forEach(trail => {
				const objectId = trail.object
				objectCounts[objectId] = (objectCounts[objectId] || 0) + 1
			})

			// Convert to top objects format and sort by count
			this.topObjects = Object.entries(objectCounts)
				.map(([objectId, count]) => ({
					name: `Object ${objectId}`,
					count,
				}))
				.sort((a, b) => b.count - a.count)
				.slice(0, 4) // Top 4 objects
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
