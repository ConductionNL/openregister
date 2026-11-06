<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	dashboardStore,
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
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

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Statistics') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{
						t('openregister', 'Register')
					}}</label>
					<NcSelect
						v-bind="registerOptions"
						id="registerSelect"
						:modelValue="selectedRegisterValue"
						:loading="registerLoading"
						:disabled="registerLoading"
						:aria-label-combobox="t('openregister', 'Select a register')"
						:placeholder="t('openregister', 'Select a register')"
						@update:modelValue="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{
						t('openregister', 'Schema')
					}}</label>
					<NcSelect
						v-bind="schemaOptions"
						id="schemaSelect"
						:modelValue="selectedSchemaValue"
						:loading="schemaLoading"
						:disabled="!registerStore.registerItem || schemaLoading"
						:aria-label-combobox="t('openregister', 'Select a schema')"
						:placeholder="t('openregister', 'Select a schema')"
						@update:modelValue="handleSchemaChange" />
				</div>
			</div>

			<!-- System Totals Section -->
			<div class="section">
				<h3 class="sectionTitle">
					{{ t('openregister', 'Register Totals') }}
				</h3>
				<div v-if="dashboardStore.loading" class="loadingContainer">
					<NcLoadingIcon :size="20" />
					<span>{{ t('openregister', 'Loading statistics...') }}</span>
				</div>
				<div v-else-if="systemTotals" class="statsStack">
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
			</div>

			<!-- Orphaned Items Section -->
			<div class="section">
				<h3 class="sectionTitle">
					{{ t('openregister', 'Orphaned Items') }}
				</h3>
				<div v-if="dashboardStore.loading" class="loadingContainer">
					<NcLoadingIcon :size="20" />
					<span>{{ t('openregister', 'Loading statistics...') }}</span>
				</div>
				<div v-else-if="orphanedItems" class="statsStack">
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
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcLoadingIcon,
	NcSelect,
} from '@nextcloud/vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import formatBytes from '../../services/formatBytes.js'
import { scopePickers } from '../../services/scopePickers.js'
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
		NcLoadingIcon,
		NcSelect,
		CnStatsBlock,
		ChartBar,
	},

	mixins: [scopePickers],

	data() {
		return {
			activeTab: 'overview-tab',
			selectedRegisterId: null,
			selectedSchemaId: null,
			dateRange: {
				from: null,
				till: null,
			},

			registerLoading: false,
			schemaLoading: false,
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

		/**
		 * Registers excluding the synthetic System Totals / Orphaned Items rows.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Array}
		 */
		filteredRegisters() {
			return dashboardStore.registers.filter(
				(register) =>
					register.title !== 'System Totals'
					&& register.title !== 'Orphaned Items',
			)
		},

		/**
		 * Total schema count across the filtered registers.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {number}
		 */
		totalSchemas() {
			return this.filteredRegisters.reduce((total, register) => {
				return total + (register.schemas?.length || 0)
			}, 0)
		},
	},

	methods: {
		/**
		 * Apply a register selection and reset dependent schema state (cascade).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-5
		 * @param {object|null} option - Selected register (or null to clear)
		 * @return {void}
		 */
		handleRegisterChange(option) {
			registerStore.setRegisterItem(option)
			schemaStore.setSchemaItem(null)
		},

		/**
		 * Apply a schema selection, initialise properties, and refresh the object list.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-5
		 * @param {object|null} option - Selected schema (or null to clear)
		 * @return {void}
		 */
		handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},

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

@include sidebar-section;

@include section-title;

@include sidebar-loading;

@include stats-stack;

@include filter-section-block;
</style>
