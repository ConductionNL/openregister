<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	dashboardStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Dashboard')"
		:subname="
			t(
				'openregister',
				'Get real-time insights into your organization\'s data health by focusing on registers, schema definitions, and object storage and usage.',
			)
		"
		:open="isSidebarOpen"
		class="dashboard-sidebar"
		@update:open="(e) => (isSidebarOpen = e)">
		<NcAppSidebarTab
			id="overview-tab"
			:name="t('openregister', 'Overview')"
			:order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

			<StatisticsScopeFilter
				:registerLoading="registerLoading"
				:schemaLoading="schemaLoading" />

			<!-- System Totals Section -->
			<SidebarStatsSection
				:title="t('openregister', 'Totals')"
				:loading="dashboardStore.loading">
				<div v-if="systemTotals" class="statsContainer">
					<StatisticsTotalsTable
						:stats="systemTotals.stats"
						:leadingRows="registerCountRows" />
				</div>
			</SidebarStatsSection>

			<!-- Orphaned Items Section -->
			<SidebarStatsSection
				:title="t('openregister', 'Orphaned Items')"
				:loading="dashboardStore.loading">
				<div v-if="orphanedItems" class="statsContainer">
					<StatisticsTotalsTable :stats="orphanedItems.stats" />
				</div>
			</SidebarStatsSection>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import SidebarStatsSection from '../SidebarStatsSection.vue'
import StatisticsScopeFilter from '../StatisticsScopeFilter.vue'
import StatisticsTotalsTable from './StatisticsTotalsTable.vue'
import { dashboardScope } from '../dashboardScope.js'
import { scopeListLoader } from '../scopeListLoader.js'

export default {
	name: 'DashboardSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		Magnify,
		StatisticsTotalsTable,
		SidebarStatsSection,
		StatisticsScopeFilter,
	},

	mixins: [scopeListLoader, dashboardScope],

	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			ignoreNextPageWatch: false,
			searchQuery: '',
			activeTab: 'overview-tab',
			searchTimeout: null,
			// Dashboard opens with the sidebar collapsed; the user can open it
			// manually via the standard NC toggle (which writes back here).
			isSidebarOpen: false,
		}
	},

	computed: {
		/**
		 * Get system totals from dashboardStore
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object | null}
		 */
		systemTotals() {
			return dashboardStore.registers.find(
				(register) => register.title === 'System Totals',
			)
		},

		/**
		 * Get orphaned items from dashboardStore
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object | null}
		 */
		orphanedItems() {
			return dashboardStore.registers.find(
				(register) => register.title === 'Orphaned Items',
			)
		},


		/**
		 * The register and schema counts that open the system totals table.
		 *
		 * @return {Array<object>} The rows
		 */
		registerCountRows() {
			return [
				{
					label: t('openregister', 'Registers'),
					count: this.filteredRegisters.length,
				},
				{ label: t('openregister', 'Schemas'), count: this.totalSchemas },
			]
		},

	},

	watch: {
		searchQuery(value) {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			this.searchTimeout = setTimeout(() => {
				objectStore.setFilters({
					_search: value || '',
				})
				if (registerStore.registerItem && schemaStore.schemaItem) {
					objectStore.refreshObjectList({
						register: registerStore.registerItem.id,
						schema: schemaStore.schemaItem.id,
					})
				}
			}, 1000)
		},

		// Watch for schema changes to initialize properties
		// Use immediate: true equivalent in mounted
		// This watcher will update properties when schema changes
		'$root.schemaStore.schemaItem': {
			handler: 'syncSchemaProperties',
			deep: true,
		},
	},

	/**
	 * @spec exclude Lifecycle plumbing; fire-and-forget load of register/schema/object lists for the dashboard filter, no domain logic.
	 */
	mounted() {
		objectStore.initializeColumnFilters()
		this.registerLoading = true
		this.schemaLoading = true

		// Only load lists if they're empty
		this.loadScopeLists()

		// Load objects if register and schema are already selected
		if (registerStore.registerItem && schemaStore.schemaItem) {
			objectStore.refreshObjectList()
		}
	},

	methods: {


		/**
		 * Re-run the object search for the current register/schema with the search term.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		handleSearch() {
			if (registerStore.registerItem && schemaStore.schemaItem) {
				objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
					search: this.searchQuery,
				})
			}
		},
	},
}
</script>

<style>
.dashboard-sidebar .app-sidebar-header__subname {
	white-space: normal !important;
}
</style>

<style lang="scss" scoped>
.statsContainer {
	padding: 0 16px;
}

.dateRangeInputs {
	display: flex;
	gap: 8px;
}
</style>
