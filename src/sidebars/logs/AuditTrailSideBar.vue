<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	auditTrailStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
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
		<ScopeFiltersTab
			:title="t('openregister', 'Filter Audit Trails')"
			:hint="
				t(
					'openregister',
					'Use filters to narrow down audit trail entries by register, schema, action type, user, date range, or object ID.',
				)
			"
			clearable
			@registerChange="handleRegisterChange"
			@schemaChange="handleSchemaChange"
			@clear="clearFilters">
			<SidebarFilterSelect
				id="actionSelect"
				v-model="selectedActions"
				:options="actionOptions"
				:placeholder="t('openregister', 'All actions')"
				:label="t('openregister', 'Actions')"
				multiple
				@update:modelValue="applyFilters" />
			<TrailUserDateFields
				v-model:users="selectedUsers"
				v-model:dateFrom="dateFrom"
				v-model:dateTo="dateTo"
				:userOptions="userOptions"
				@change="applyFilters" />
			<div class="filterGroup">
				<label for="objectFilter">{{
					t('openregister', 'Object ID')
				}}</label>
				<NcTextField
					id="objectFilter"
					v-model="objectFilter"
					:label="t('openregister', 'Filter by object ID')"
					:placeholder="t('openregister', 'Enter object ID')"
					@update:modelValue="handleObjectFilterChange" />
			</div>
			<div class="filterGroup">
				<NcCheckboxRadioSwitch
					v-model="showOnlyWithChanges"
					@update:modelValue="applyFilters">
					{{ t('openregister', 'Show only entries with changes') }}
				</NcCheckboxRadioSwitch>
			</div>
		</ScopeFiltersTab>

		<StatisticsSidebarTab>
			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Audit Trail Statistics') }}</h3>
				<SidebarStatCard
					:value="totalAuditTrails"
					:label="t('openregister', 'Total Audit Trails')" />
				<SidebarStatCard
					:value="createCount"
					:label="t('openregister', 'Create Operations')" />
				<SidebarStatCard
					:value="updateCount"
					:label="t('openregister', 'Update Operations')" />
				<SidebarStatCard
					:value="deleteCount"
					:label="t('openregister', 'Delete Operations')" />
			</div>

			<!-- Action Distribution -->
			<div class="actionDistribution">
				<h4>{{ t('openregister', 'Action Distribution') }}</h4>
				<CountListItem
					v-for="(action, index) in actionDistribution"
					:key="index"
					:name="action.action"
					:subname="entriesLabel(action.count)">
					<template #icon>
						<Pencil v-if="action.action === 'update'" :size="32" />
						<Plus v-else-if="action.action === 'create'" :size="32" />
						<Delete v-else-if="action.action === 'delete'" :size="32" />
						<Eye v-else :size="32" />
					</template>
				</CountListItem>
			</div>

			<!-- Top Objects -->
			<div class="topObjects">
				<h4>{{ t('openregister', 'Most Active Objects') }}</h4>
				<CountListItem
					v-for="(object, index) in topObjects"
					:key="index"
					:name="object.name"
					:subname="entriesLabel(object.count)">
					<template #icon>
						<CogOutline :size="32" />
					</template>
				</CountListItem>
			</div>
		</StatisticsSidebarTab>
	</NcAppSidebar>
</template>

<script>
/**
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
import {
	NcAppSidebar,
	NcCheckboxRadioSwitch,
	NcTextField,
} from '@nextcloud/vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { trailSidebar } from '../../composables/trailSidebar.js'
import eventBus from '../../eventBus.js'
import {
	addDateRange,
	addScope,
	csvValues,
	routeDate,
} from '../../services/routeQuery.js'
import { applyRouteScope } from '../../services/routeScope.js'
import {
	addJoinedValues,
	addPresent,
	addScopeIds,
} from '../../services/trailFilters.js'
import { routeBoundFilters, selectedScope } from '../routeBoundFilters.js'

/**
 * The user options a comma-separated route parameter names.
 *
 * @param {string|undefined} user The users
 * @return {Array<object>} The selected users
 */
function usersFromRoute(user) {
	if (typeof user !== 'string' || user.length === 0) {
		return []
	}
	return csvValues(user).map((u) => ({ value: u, label: u }))
}

const STATISTIC_FIELDS = [
	['totalAuditTrails', 'total'],
	['createCount', 'create'],
	['updateCount', 'update'],
	['deleteCount', 'delete'],
]

/**
 * The known option for an action value, or a new one for an unknown action.
 *
 * @param {Map<string, object>} byValue The known options by value
 * @param {string} value The action
 * @return {object} The option
 */
function actionOption(byValue, value) {
	return byValue.get(value) || { value, label: value }
}

export default {
	name: 'AuditTrailSideBar',
	components: {
		NcAppSidebar,
		NcTextField,
		NcCheckboxRadioSwitch,
		CogOutline,
		Delete,
		Plus,
		Pencil,
		Eye,
	},

	mixins: [
		routeBoundFilters('/audit-trails'),
		trailSidebar(() => auditTrailStore.auditTrailList),
	],

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
			objectFilter: '',
			showOnlyWithChanges: false,
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
		// A <script setup> binding is not on `this`, and a watch path reads `this`.
		auditTrailStore: () => auditTrailStore,
	},

	watch: {
		'auditTrailStore.auditTrailList': function () {
			this.updateFilteredCount()
			this.loadStatistics()
			this.loadActionDistribution()
			this.loadTopObjects()
		},
	},

	/**
	 * @spec exclude Lifecycle plumbing; fire-and-forget load of lists/audit-trail data and route-seed, delegating to already-annotated methods.
	 */
	mounted() {
		// Load required data
		this.refreshEmptyScopeLists()

		this.loadStatistics()
		this.loadActionDistribution()
		this.loadTopObjects()

		// Listen for filtered count updates
		this.listenFilteredCount('audit-trail-filtered-count')

		// Watch store changes and update count
		this.updateFilteredCount()

		// Initialize from query params after lists potentially load
		this.applyQueryParamsFromRoute()
	},

	beforeUnmount() {
		eventBus.off('audit-trail-filtered-count')
	},

	methods: {
		/**
		 * "{count} entries", translated, for a row of a top list.
		 *
		 * @param {number} count How often the row occurs
		 * @spec exclude Presentational count label.
		 * @return {string} The label
		 */
		entriesLabel(count) {
			return t('openregister', '{count} entries', { count })
		},


		/**
		 * Handle object filter change with debouncing
		 *
		 * @param {string} value - The filter value
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		handleObjectFilterChange(value) {
			this.objectFilter = value
			this.debouncedApplyFilters()
		},

		/**
		 * Apply filters and sync them to the URL query (single source of truth)
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		applyFilters() {
			this.updateRouteQueryFromState()
		},


		/**
		 * Update changes filter
		 *
		 * @param {boolean} value - Whether to show only entries with changes
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		updateChangesFilter(value) {
			this.showOnlyWithChanges = value
			this.applyFilters()
		},

		/**
		 * Update filtered count from store
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		updateFilteredCount() {
			this.filteredCount = auditTrailStore.auditTrailList.length
			this.totalAuditTrails =
				auditTrailStore.auditTrailPagination.total
				|| auditTrailStore.auditTrailList.length
		},

		/**
		 * Load statistics
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async loadStatistics() {
			try {
				const stats = await auditTrailStore.getStatistics()
				for (const [field, key] of STATISTIC_FIELDS) {
					this[field] = stats[key] || 0
				}
			} catch {
				// Handle error silently
			}
		},

		/**
		 * Load action distribution for stats
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async loadActionDistribution() {
			try {
				const actionData = await auditTrailStore.getActionDistribution()
				this.actionDistribution = actionData.map((action) => ({
					action: action.action || action.name,
					count: action.count || 0,
					percentage: action.percentage || 0,
				}))
			} catch {
				// Handle error silently
			}
		},

		/**
		 * Load top objects for stats
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async loadTopObjects() {
			try {
				const objectData = await auditTrailStore.getTopObjects()
				this.topObjects = objectData.map((object) => ({
					name: object.objectId || object.name || `Object ${object.id}`,
					count: object.count || 0,
				}))
			} catch {
				// Handle error silently
			}
		},

		/**
		 * Build URL query object from current sidebar state
		 *
		 * @return {object}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		buildQueryFromState() {
			const query = {}
			addScope(query, selectedScope())
			addJoinedValues(query, ['action', this.selectedActions])
			addJoinedValues(query, ['user', this.selectedUsers])
			addDateRange(query, this)
			if (this.objectFilter) query.object = this.objectFilter
			if (this.showOnlyWithChanges) query.onlyWithChanges = '1'
			return query
		},

		/**
		 * Apply URL query params to component/store state and refresh list
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/audit-trails') return
			const query = this.$route.query || {}
			// JS dates are awful, so we first check if its a valid date and then create the date. (dateFrom is a ISO string)
			this.dateFrom = routeDate(query.dateFrom)
			this.dateTo = routeDate(query.dateTo)
			this.objectFilter = query.object ? String(query.object) : ''
			this.showOnlyWithChanges = !!query.onlyWithChanges
			this.selectedActions = this.actionsFromRoute(query.action)
			this.selectedUsers = usersFromRoute(query.user)
			// Once state is set from URL, apply to store and refresh
			applyRouteScope(query, () => this.applyFiltersToStore())
		},

		/**
		 * The action options a route parameter names; unknown actions get an
		 * option of their own.
		 *
		 * @param {string|undefined} action The comma-separated actions
		 * @return {Array<object>} The selected actions
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		actionsFromRoute(action) {
			if (typeof action !== 'string' || action.length === 0) {
				return []
			}
			const byValue = new Map(this.actionOptions.map((o) => [o.value, o]))
			return csvValues(action).map((v) => actionOption(byValue, v))
		},

		/**
		 * Build filters from state and, when they changed, push them to the store and refresh the list
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		applyFiltersToStore() {
			const filters = {}
			addJoinedValues(filters, ['action', this.selectedActions])
			addScopeIds(filters, selectedScope())
			addJoinedValues(filters, ['user', this.selectedUsers])
			addPresent(filters, [
				['dateFrom', this.dateFrom],
				['dateTo', this.dateTo],
				['object', this.objectFilter],
			])
			if (this.showOnlyWithChanges) filters.onlyWithChanges = true

			// The index loads the list on mount; only a change of filters needs another load.
			if (
				JSON.stringify(filters)
				=== JSON.stringify(auditTrailStore.auditTrailFilters)
			)
				return

			auditTrailStore.setAuditTrailFilters(filters)
			auditTrailStore.refreshAuditTrailList()
			eventBus.emit('audit-trail-filters-changed', filters)
		},

		/**
		 * Clear all filters and sync URL
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
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
			auditTrailStore.refreshAuditTrailList()

			// Reflect in URL
			this.updateRouteQueryFromState()
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/sidebars.scss' as *;

@include divided-sections(filterSection, statsSection);

@include filter-group-rules;

@include action-group;

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

@include filter-hint-rules;

@include stat-card;

.statCard.create {
	border-left: 4px solid var(--color-success);
}

.statCard.update {
	border-left: 4px solid var(--color-warning);
}

.statCard.delete {
	border-left: 4px solid var(--color-error);
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

@media (prefers-reduced-motion: reduce) {
	.actionProgressBar {
		transition: none;
	}
}
</style>
