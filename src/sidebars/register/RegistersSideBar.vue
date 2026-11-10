<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { dashboardStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Registers')"
		:subtitle="t('openregister', 'Register Overview')"
		:subname="t('openregister', 'Statistics and Metrics')"
		:open="navigationStore.sidebarState.registers"
		@update:open="
			(e) => {
				navigationStore.setSidebarState('registers', e)
			}
		">
		<NcAppSidebarTab
			id="overview-tab"
			:name="t('openregister', 'Overview')"
			:order="1">
			<template #icon>
				<ChartBar :size="20" />
			</template>

			<StatisticsScopeFilter comboboxLabels />

			<!-- System Totals Section -->
			<SidebarStatsSection
				:title="t('openregister', 'Register Totals')"
				:loading="dashboardStore.loading">
				<div v-if="systemTotals" class="statsStack">
					<CnStatsBlock
						:title="t('openregister', 'Registers')"
						:count="filteredRegisters.length"
						:countLabel="
							n(
								'openregister',
								'register',
								'registers',
								filteredRegisters.length,
							)
						"
						:icon="DatabaseOutline"
						variant="primary"
						horizontal
						showZeroCount />
					<CnStatsBlock
						:title="t('openregister', 'Schemas')"
						:count="totalSchemas"
						:countLabel="
							n('openregister', 'schema', 'schemas', totalSchemas)
						"
						:icon="TableIcon"
						variant="primary"
						horizontal
						showZeroCount />
					<CnStatsBlock
						:title="t('openregister', 'Objects')"
						:count="systemTotals.stats?.objects?.total || 0"
						:countLabel="
							n(
								'openregister',
								'object',
								'objects',
								systemTotals.stats?.objects?.total || 0,
							)
						"
						:icon="PackageVariantClosed"
						variant="primary"
						horizontal
						showZeroCount
						:breakdown="objectsBreakdown(systemTotals)" />
					<CnStatsBlock
						:title="t('openregister', 'Logs')"
						:count="systemTotals.stats?.logs?.total || 0"
						:countLabel="
							n(
								'openregister',
								'log',
								'logs',
								systemTotals.stats?.logs?.total || 0,
							)
						"
						:icon="TextBoxOutline"
						horizontal
						showZeroCount
						:breakdown="sizeBreakdown(systemTotals.stats?.logs?.size)" />
					<CnStatsBlock
						:title="t('openregister', 'Files')"
						:count="systemTotals.stats?.files?.total || 0"
						:countLabel="
							n(
								'openregister',
								'file',
								'files',
								systemTotals.stats?.files?.total || 0,
							)
						"
						:icon="FileDocumentOutline"
						horizontal
						showZeroCount
						:breakdown="
							sizeBreakdown(systemTotals.stats?.files?.size)
						" />
				</div>
			</SidebarStatsSection>

			<!-- Orphaned Items Section -->
			<SidebarStatsSection
				:title="t('openregister', 'Orphaned Items')"
				:loading="dashboardStore.loading">
				<div v-if="orphanedItems" class="statsStack">
					<CnStatsBlock
						:title="t('openregister', 'Objects')"
						:count="orphanedItems.stats?.objects?.total || 0"
						:countLabel="
							n(
								'openregister',
								'object',
								'objects',
								orphanedItems.stats?.objects?.total || 0,
							)
						"
						:icon="PackageVariantClosed"
						variant="warning"
						horizontal
						showZeroCount
						:breakdown="objectsBreakdown(orphanedItems)" />
					<CnStatsBlock
						:title="t('openregister', 'Logs')"
						:count="orphanedItems.stats?.logs?.total || 0"
						:countLabel="
							n(
								'openregister',
								'log',
								'logs',
								orphanedItems.stats?.logs?.total || 0,
							)
						"
						:icon="TextBoxOutline"
						variant="warning"
						horizontal
						showZeroCount
						:breakdown="
							sizeBreakdown(orphanedItems.stats?.logs?.size)
						" />
					<CnStatsBlock
						:title="t('openregister', 'Files')"
						:count="orphanedItems.stats?.files?.total || 0"
						:countLabel="
							n(
								'openregister',
								'file',
								'files',
								orphanedItems.stats?.files?.total || 0,
							)
						"
						:icon="FileDocumentOutline"
						variant="warning"
						horizontal
						showZeroCount
						:breakdown="
							sizeBreakdown(orphanedItems.stats?.files?.size)
						" />
				</div>
			</SidebarStatsSection>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import { NcAppSidebar, NcAppSidebarTab } from '@nextcloud/vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import SidebarStatsSection from '../SidebarStatsSection.vue'
import StatisticsScopeFilter from '../StatisticsScopeFilter.vue'
import formatBytes from '../../services/formatBytes.js'
import { dashboardScope } from '../dashboardScope.js'
// Ensure data is loaded
dashboardStore.preload()

const BREAKDOWN_COUNTS = ['invalid', 'deleted', 'locked']

/**
 * Add the object counts that are not zero to a stats breakdown.
 *
 * @param {object} breakdown The breakdown being built
 * @param {object} stats The object statistics
 */
function addCounts(breakdown, stats) {
	for (const key of BREAKDOWN_COUNTS) {
		const count = stats[key]
		if (count) breakdown[key] = count
	}
}

export default {
	name: 'RegistersSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		CnStatsBlock,
		ChartBar,
		SidebarStatsSection,
		StatisticsScopeFilter,
	},

	mixins: [dashboardScope],

	data() {
		return {
			activeTab: 'overview-tab',
			selectedRegisterId: null,
			selectedSchemaId: null,
			dateRange: {
				from: null,
				till: null,
			},

			ignoreNextPageWatch: false,
			searchQuery: '',
			// Icon components for CnStatsBlock
			DatabaseOutline,
			TableIcon,
			PackageVariantClosed,
			TextBoxOutline,
			FileDocumentOutline,
		}
	},

	computed: {
		/**
		 * System totals stats from the dashboard store.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null}
		 */
		systemTotals() {
			return dashboardStore.getSystemTotals
		},

		/**
		 * Orphaned-items stats from the dashboard store.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null}
		 */
		orphanedItems() {
			return dashboardStore.getOrphanedItems
		},


	},

	methods: {


		/**
		 * Push the selected date range into the dashboard store for stats scoping.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {void}
		 */
		onDateRangeChange() {
			dashboardStore.setDateRange(this.dateRange.from, this.dateRange.till)
		},

		/**
		 * @spec exclude Presentation-only formatter; builds the objects stats breakdown descriptor for a stats source.
		 * @param {object} source - Stats-bearing record
		 * @return {object|null} Breakdown descriptor or null
		 */
		objectsBreakdown(source) {
			const stats = source?.stats?.objects
			if (!stats) return null
			const breakdown = {}
			if (stats.size) breakdown.size = formatBytes(stats.size)
			addCounts(breakdown, stats)
			return Object.keys(breakdown).length > 0 ? breakdown : null
		},

		/**
		 * @spec exclude Presentation-only formatter; renders a byte size as a stats breakdown descriptor.
		 * @param {number} size - Byte size
		 * @return {object|null} Breakdown descriptor or null
		 */
		sizeBreakdown(size) {
			if (!size) return null
			return { size: formatBytes(size) }
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/sidebars.scss' as *;

@include stats-stack;
</style>
