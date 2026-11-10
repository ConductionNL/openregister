<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	deletedStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Deleted Items Management')"
		:subtitle="t('openregister', 'Filter and manage soft deleted items')"
		:subname="t('openregister', 'Restore or permanently delete items')"
		:open="navigationStore.sidebarState.deleted"
		@update:open="(e) => navigationStore.setSidebarState('deleted', e)">
		<ScopeFiltersTab
			:title="t('openregister', 'Filter Deleted Items')"
			:hint="
				t(
					'openregister',
					'Use filters to narrow down deleted items by register, schema, deletion date, or user who deleted them.',
				)
			"
			@registerChange="handleRegisterChange"
			@schemaChange="handleSchemaChange">
			<SidebarFilterSelect
				id="deletedBySelect"
				v-model="selectedDeletedBy"
				:options="userOptions"
				:placeholder="t('openregister', 'Any user')"
				:label="t('openregister', 'Deleted By')"
				@update:modelValue="applyFilters" />
			<div class="filterGroup">
				<label>{{ t('openregister', 'Deletion Date Range') }}</label>
				<NcDateTimePickerNative
					v-model="dateFrom"
					:label="t('openregister', 'From date')"
					type="date"
					@update:modelValue="applyFilters" />
				<NcDateTimePickerNative
					v-model="dateTo"
					:label="t('openregister', 'To date')"
					type="date"
					@update:modelValue="applyFilters" />
			</div>
		</ScopeFiltersTab>

		<StatisticsSidebarTab>
			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Deletion Statistics') }}</h3>

				<div v-if="deletedStore.statisticsLoading" class="loading-stats">
					<NcLoadingIcon :size="32" />
					<p>{{ t('openregister', 'Loading statistics...') }}</p>
				</div>

				<div v-else>
					<SidebarStatCard
						:value="deletedStore.statistics.totalDeleted"
						:label="t('openregister', 'Total Deleted Items')" />
					<SidebarStatCard
						:value="deletedStore.statistics.deletedToday"
						:label="t('openregister', 'Deleted Today')" />
					<SidebarStatCard
						:value="deletedStore.statistics.deletedThisWeek"
						:label="t('openregister', 'Deleted This Week')" />
					<SidebarStatCard
						:value="deletedStore.statistics.oldestDays"
						:label="t('openregister', 'Oldest Item (days)')" />
				</div>
			</div>

			<!-- Top Deleters -->
			<div class="topDeleters">
				<h4>{{ t('openregister', 'Top Deleters') }}</h4>

				<div v-if="deletedStore.topDeletersLoading" class="loading-stats">
					<NcLoadingIcon :size="24" />
				</div>

				<div
					v-else-if="deletedStore.topDeleters.length === 0"
					class="no-data">
					<p>{{ t('openregister', 'No deletion data available') }}</p>
				</div>

				<CountListItem
					v-for="(deleter, index) in deletedStore.topDeleters"
					v-else
					:key="index"
					:name="deleter.user"
					:subname="deletionsLabel(deleter.count)">
					<template #icon>
						<AccountCircle :size="32" />
					</template>
				</CountListItem>
			</div>
		</StatisticsSidebarTab>
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcDateTimePickerNative,
	NcLoadingIcon,
} from '@nextcloud/vue'
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import CountListItem from '../../components/shared/CountListItem.vue'
import SidebarStatCard from '../../components/shared/SidebarStatCard.vue'
import StatisticsSidebarTab from '../../components/shared/StatisticsSidebarTab.vue'
import ScopeFiltersTab from '../ScopeFiltersTab.vue'
import SidebarFilterSelect from '../SidebarFilterSelect.vue'
import eventBus from '../../eventBus.js'
import { addDateRange, addScope, routeDate } from '../../services/routeQuery.js'
import { applyRouteScope } from '../../services/routeScope.js'
import { routeBoundFilters, selectedScope } from '../routeBoundFilters.js'

/**
 * The ids of the selected register and schema, null when none is selected.
 *
 * @return {{register: (string|number|null), schema: (string|number|null)}} The ids
 */
function selectedScopeIds() {
	return {
		register: registerStore.registerItem?.id || null,
		schema: schemaStore.schemaItem?.id || null,
	}
}

export default {
	name: 'DeletedSideBar',
	components: {
		CountListItem,
		ScopeFiltersTab,
		SidebarFilterSelect,
		NcAppSidebar,
		NcDateTimePickerNative,
		NcLoadingIcon,
		AccountCircle,
		SidebarStatCard,
		StatisticsSidebarTab,
	},

	mixins: [routeBoundFilters('/deleted')],

	data() {
		return {
			activeTab: 'filters-tab',
			selectedDeletedBy: null,
			dateFrom: null,
			dateTo: null,
			filteredCount: 0,
		}
	},

	computed: {
		/**
		 * Derive the "deleted by" user filter options from the deleted-items list.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Array} User filter options
		 */
		userOptions() {
			// Get unique users from deleted items or provide default options
			const users = new Set()
			deletedStore.deletedList.forEach((item) => {
				const deletedBy = item['@self']?.deleted?.deletedBy
				if (deletedBy) {
					users.add(deletedBy)
				}
			})

			const userOptions = Array.from(users).map((user) => ({
				label: user,
				value: user,
			}))

			// Add some common default users if no data
			if (userOptions.length === 0) {
				return [{ label: this.t('openregister', 'Admin'), value: 'admin' }]
			}

			return userOptions
		},
	},

	watch: {},

	/**
	 * @spec exclude Lifecycle plumbing; loads lists/statistics and seeds state from the route, delegating to already-annotated methods.
	 */
	async mounted() {
		// Load required data
		if (!registerStore.registerList.length) {
			await registerStore.refreshRegisterList()
		}

		if (!schemaStore.schemaList.length) {
			await schemaStore.refreshSchemaList()
		}

		// Load statistics and top deleters
		await this.loadStatistics()
		await this.loadTopDeleters()

		// Initialize from current URL query params
		this.applyQueryParamsFromRoute()

		// Listen for filtered count updates
		eventBus.on('deleted-filtered-count', (count) => {
			this.filteredCount = count
		})
	},

	beforeUnmount() {
		eventBus.off('deleted-filtered-count')
	},

	methods: {
		/**
		 * "{count} deletions", translated, for a row of a top list.
		 *
		 * @param {number} count How often the row occurs
		 * @spec exclude Presentational count label.
		 * @return {string} The label
		 */
		deletionsLabel(count) {
			return t('openregister', '{count} deletions', { count })
		},

		/**
		 * Apply filters and emit to parent components.
		 *
		 * Delegates to {@link updateRouteQueryFromState} which serialises the current
		 * sidebar state into the `/deleted` route query (with path + equality guards).
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-files-sidebar-tabs/tasks.md#task-3
		 */
		applyFilters() {
			this.updateRouteQueryFromState()
		},

		/**
		 * Load deletion statistics
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				await deletedStore.fetchStatistics()
			} catch (error) {
				console.error('Error loading statistics:', error)
			}
		},

		/**
		 * Load top deleters statistics
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Promise<void>}
		 */
		async loadTopDeleters() {
			try {
				await deletedStore.fetchTopDeleters()
			} catch (error) {
				console.error('Error loading top deleters:', error)
			}
		},

		/**
		 * Build URL query object from current sidebar state
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {object}
		 */
		buildQueryFromState() {
			const query = {}
			addScope(query, selectedScope())
			if (this.selectedDeletedBy && this.selectedDeletedBy.value) {
				query.deletedBy = String(this.selectedDeletedBy.value)
			}
			addDateRange(query, this)
			return query
		},

		/**
		 * Apply URL query params to component/store state and emit filters
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/deleted') return
			const query = this.$route.query || {}
			this.dateFrom = routeDate(query.dateFrom)
			this.dateTo = routeDate(query.dateTo)
			const deletedBy = query.deletedBy
			this.selectedDeletedBy =
				typeof deletedBy === 'string' && deletedBy.length > 0
					? { value: deletedBy, label: deletedBy }
					: null
			// Once state is set from URL, emit filters for legacy compatibility
			applyRouteScope(query, () => this.applyFiltersToStore())
		},

		/**
		 * Build filters from state and emit to parent (legacy compatibility)
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		applyFiltersToStore() {
			const filters = {
				...selectedScopeIds(),
				deletedBy: this.selectedDeletedBy?.value || null,
				dateFrom: this.dateFrom || null,
				dateTo: this.dateTo || null,
			}
			eventBus.emit('deleted-filters-changed', filters)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/sidebars.scss' as *;

@include divided-sections(filterSection, statsSection);

@include filter-group-rules;

@include filter-hint-rules;

.loading-stats {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

@include stat-card;

.topDeleters {
	margin-top: 20px;
}

.topDeleters h4 {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.no-data {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	padding: 20px;
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>
