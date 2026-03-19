<script setup>
import { objectStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'

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
						<template #option="{ label }">
							{{ label }}
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
						<template #option="{ label }">
							{{ label }}
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

		<NcAppSidebarTab id="stats-tab" :name="t('openregister', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<CnStatsPanel :sections="auditStatsSections">
				<template #item-icon-actionDistribution="{ item }">
					<Pencil v-if="item.key === 'update'" :size="32" />
					<Plus v-else-if="item.key === 'create'" :size="32" />
					<Delete v-else-if="item.key === 'delete'" :size="32" />
					<Eye v-else :size="32" />
				</template>
			</CnStatsPanel>
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
	NcDateTimePickerNative,
	NcTextField,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { CnStatsPanel } from '@conduction/nextcloud-vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'

export default {
	name: 'AuditTrailSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcButton,
		NcDateTimePickerNative,
		NcTextField,
		NcCheckboxRadioSwitch,
		CnStatsPanel,
		FilterOutline,
		ChartLine,
		Plus,
		Pencil,
		Delete,
		Eye,
		FilterOffOutline,
	},
	data() {
		return {
			actionOptions: [
				{
					label: t('openregister', 'Create'),
					value: 'create',
				},
				{
					label: t('openregister', 'Read'),
					value: 'read',
				},
				{
					label: t('openregister', 'Update'),
					value: 'update',
				},
				{
					label: t('openregister', 'Delete'),
					value: 'delete',
				},
			],

			activeTab: 'filters-tab',
			selectedActions: [],
			selectedUsers: [],
			dateFrom: null,
			dateTo: null,
			objectFilter: '',
			showOnlyWithChanges: false,
			filteredCount: 0,
			totalAuditTrails: 0,
			createCount: 0,
			updateCount: 0,
			deleteCount: 0,
			readCount: 0,
			actionDistribution: [],
			topObjects: [],
			filterTimeout: null,
		}
	},
	computed: {
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
					.filter(schema => registerStore.registerItem.schemas.some(registerSchema => registerSchema.id === schema.id))
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
		globalAuditTrailResults() {
			return objectStore.globalAuditTrails.results
		},
		userOptions() {
			if (!objectStore.globalAuditTrails.results || !objectStore.globalAuditTrails.results.length) {
				return []
			}
			// Get unique users from audit trail list
			const users = [...new Set(objectStore.globalAuditTrails.results.map(trail => trail.userName || trail.user).filter(Boolean))]
			return users.map(user => ({
				label: user,
				value: user,
			}))
		},
		auditStatsSections() {
			return [
				{
					type: 'stats',
					id: 'total',
					title: t('openregister', 'Audit Trail Statistics'),
					layout: 'stack',
					items: [{
						title: t('openregister', 'Total Audit Trails'),
						count: this.totalAuditTrails,
						countLabel: t('openregister', 'entries'),
						variant: 'primary',
						icon: TextBoxOutline,
					}],
				},
				{
					type: 'stats',
					id: 'operations',
					layout: 'grid',
					columns: 2,
					items: [
						{
							title: t('openregister', 'Create'),
							count: this.createCount,
							countLabel: t('openregister', 'operations'),
							variant: 'success',
							icon: Plus,
						},
						{
							title: t('openregister', 'Update'),
							count: this.updateCount,
							countLabel: t('openregister', 'operations'),
							variant: 'warning',
							icon: Pencil,
						},
						{
							title: t('openregister', 'Delete'),
							count: this.deleteCount,
							countLabel: t('openregister', 'operations'),
							variant: 'error',
							icon: Delete,
						},
						{
							title: t('openregister', 'Read'),
							count: this.readCount,
							countLabel: t('openregister', 'operations'),
							icon: Eye,
						},
					],
				},
				{
					type: 'list',
					id: 'actionDistribution',
					title: t('openregister', 'Action Distribution'),
					items: this.actionDistribution.map(a => ({
						key: a.action,
						name: a.action,
						subname: t('openregister', '{count} entries', { count: a.count }),
					})),
				},
				{
					type: 'list',
					id: 'topObjects',
					title: t('openregister', 'Most Active Objects'),
					items: this.topObjects.map(obj => ({
						key: obj.name,
						name: obj.name,
						subname: t('openregister', '{count} entries', { count: obj.count }),
						icon: CogOutline,
					})),
				},
			]
		},
	},
	watch: {
		// React to query param changes as single source of truth (only on /audit-trails)
		'$route.query': {
			handler() {
				if (this.$route.path !== '/audit-trails') return
				this.applyQueryParamsFromRoute()
			},
			deep: true,
		},
		globalAuditTrailResults() {
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

		// Load initial audit trail data, then derive stats from it
		this.loadAuditTrailData()

		// Listen for filtered count updates
		this.$root.$on('audit-trail-filtered-count', (count) => {
			this.filteredCount = count
		})

		// Initialize from query params after lists potentially load
		this.applyQueryParamsFromRoute()
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
				await objectStore.refreshGlobalAuditTrails()
				this.updateFilteredCount()
				this.loadStatistics()
				this.loadActionDistribution()
				this.loadTopObjects()
			} catch (error) {
				// Handle error silently
			}
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
		 * Apply filters and sync them to the URL query (single source of truth)
		 * @return {void}
		 */
		applyFilters() {
			this.updateRouteQueryFromState()
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
			this.filteredCount = objectStore.globalAuditTrails.results.length
			this.totalAuditTrails = objectStore.globalAuditTrails.total || objectStore.globalAuditTrails.results.length
		},
		/**
		 * Load statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				const stats = await objectStore.fetchAuditTrailStatistics()
				this.totalAuditTrails = stats.total || 0
				this.createCount = stats.create || 0
				this.updateCount = stats.update || 0
				this.deleteCount = stats.delete || 0
				this.readCount = stats.read || 0
			} catch (error) {
				// Handle error silently
			}
		},
		/**
		 * Load action distribution for stats
		 */
		loadActionDistribution() {
			const results = objectStore.globalAuditTrails.results || []
			const total = results.length
			const actions = ['create', 'update', 'delete', 'read']

			this.actionDistribution = actions
				.map(action => {
					const count = results.filter(item => item.action === action).length
					return {
						action,
						count,
						percentage: total > 0 ? Math.round((count / total) * 100) : 0,
					}
				})
				.filter(item => item.count > 0)
		},
		/**
		 * Load top objects for stats
		 */
		loadTopObjects() {
			const results = objectStore.globalAuditTrails.results || []
			const objectCounts = {}

			results.forEach(item => {
				if (item.object) {
					objectCounts[item.object] = (objectCounts[item.object] || 0) + 1
				}
			})

			this.topObjects = Object.entries(objectCounts)
				.map(([objectId, count]) => ({
					name: `Object ${objectId}`,
					count,
				}))
				.sort((a, b) => b.count - a.count)
				.slice(0, 10)
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
		/**
		 * Build URL query object from current sidebar state
		 * @return {object}
		 */
		buildQueryFromState() {
			const query = {}
			if (registerStore.registerItem && registerStore.registerItem.id) {
				query.register = String(registerStore.registerItem.id)
			}
			if (schemaStore.schemaItem && schemaStore.schemaItem.id) {
				query.schema = String(schemaStore.schemaItem.id)
			}
			if (Array.isArray(this.selectedActions) && this.selectedActions.length > 0) {
				query.action = this.selectedActions.map(a => a.value).join(',')
			}
			if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) {
				query.user = this.selectedUsers.map(u => u.value).join(',')
			}
			// JS dates are awful, so we first check if its a valid date and then get the ISO string.
			if (this.dateFrom) query.dateFrom = new Date(this.dateFrom).getDate() ? new Date(this.dateFrom).toISOString() : null
			if (this.dateTo) query.dateTo = new Date(this.dateTo).getDate() ? new Date(this.dateTo).toISOString() : null
			if (this.objectFilter) query.object = this.objectFilter
			if (this.showOnlyWithChanges) query.onlyWithChanges = '1'
			return query
		},
		/**
		 * Compare two shallow query objects (keys and stringified values)
		 * @param {object} a - First query object to compare
		 * @param {object} b - Second query object to compare
		 * @return {boolean}
		 */
		queriesEqual(a, b) {
			const ka = Object.keys(a || {}).sort()
			const kb = Object.keys(b || {}).sort()
			if (ka.length !== kb.length) return false
			for (let i = 0; i < ka.length; i++) {
				if (ka[i] !== kb[i]) return false
				if (String(a[ka[i]]) !== String(b[kb[i]])) return false
			}
			return true
		},
		/**
		 * Write current state to the router query (only on /audit-trails)
		 * @return {void}
		 */
		updateRouteQueryFromState() {
			if (this.$route.path !== '/audit-trails') return
			const nextQuery = this.buildQueryFromState()
			if (this.queriesEqual(nextQuery, this.$route.query || {})) return
			this.$router.replace({
				path: this.$route.path,
				query: nextQuery,
			})
		},
		/**
		 * Apply URL query params to component/store state and refresh list
		 * @return {void}
		 */
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/audit-trails') return
			const { register, schema, action, user, dateFrom, dateTo, object, onlyWithChanges } = this.$route.query || {}

			// Set simple fields
			// JS dates are awful, so we first check if its a valid date and then create the date. (dateFrom is a ISO string)
			this.dateFrom = dateFrom && new Date(dateFrom).getDate() ? new Date(dateFrom) : null
			this.dateTo = dateTo && new Date(dateTo).getDate() ? new Date(dateTo) : null
			this.objectFilter = object ? String(object) : ''
			this.showOnlyWithChanges = !!(onlyWithChanges)

			// Actions
			if (typeof action === 'string' && action.length > 0) {
				const values = action.split(',').map(s => s.trim()).filter(Boolean)
				const mapByValue = Object.fromEntries(this.actionOptions.map(o => [o.value, o]))
				this.selectedActions = values.map(v => mapByValue[v] || { value: v, label: v })
			} else {
				this.selectedActions = []
			}

			// Users
			if (typeof user === 'string' && user.length > 0) {
				const values = user.split(',').map(s => s.trim()).filter(Boolean)
				this.selectedUsers = values.map(u => ({ value: u, label: u }))
			} else {
				this.selectedUsers = []
			}

			// Registers and schemas depend on lists being loaded
			const applyRegister = () => {
				if (!register) return true
				if (!registerStore.registerList.length) return false
				const reg = registerStore.registerList.find(r => String(r.id) === String(register))
				if (reg) registerStore.setRegisterItem(reg)
				return true
			}
			const applySchema = () => {
				if (!schema) return true
				if (!schemaStore.schemaList.length) return false
				const sch = schemaStore.schemaList.find(s => String(s.id) === String(schema))
				if (sch) schemaStore.setSchemaItem(sch)
				return true
			}

			const tryApply = (attempt = 0) => {
				const regOk = applyRegister()
				const schOk = applySchema()
				if (regOk && schOk) {
					// Once state is set from URL, apply to store and refresh
					this.applyFiltersToStore()
					return
				}
				if (attempt < 10) {
					setTimeout(() => tryApply(attempt + 1), 200)
				}
			}
			tryApply()
		},
		/**
		 * Build filters from state and push to store, then refresh list
		 * @return {void}
		 */
		applyFiltersToStore() {
			const filters = {}
			if (Array.isArray(this.selectedActions) && this.selectedActions.length > 0) {
				const actions = this.selectedActions.slice()
				if (actions.length > 0) {
					filters.action = actions.map(a => a.value).join(',')
				}
			}
			if (registerStore.registerItem) {
				filters.register = registerStore.registerItem.id.toString()
			}
			if (schemaStore.schemaItem) {
				filters.schema = schemaStore.schemaItem.id.toString()
			}
			if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) {
				const users = this.selectedUsers.slice()
				if (users.length > 0) {
					filters.user = users.map(u => u.value).join(',')
				}
			}
			if (this.dateFrom) filters.dateFrom = this.dateFrom
			if (this.dateTo) filters.dateTo = this.dateTo
			if (this.objectFilter) filters.object = this.objectFilter
			if (this.showOnlyWithChanges) filters.onlyWithChanges = true

			objectStore.setAuditTrailFilters(filters)
			objectStore.refreshGlobalAuditTrails()
			this.$root.$emit('audit-trail-filters-changed', filters)
		},
		/**
		 * Clear all filters and sync URL
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
			objectStore.clearAuditTrailFilters()
			objectStore.refreshGlobalAuditTrails()

			// Reflect in URL
			this.updateRouteQueryFromState()
		},
	},
}
</script>

<style scoped>
.section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}
}

.sectionTitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.statsStack {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 8px;
}

.filterSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child {
	border-bottom: none;
}

.filterSection h3 {
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

.filter-hint {
	margin: 8px 16px;
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

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>
