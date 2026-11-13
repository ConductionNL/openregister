<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	dashboardStore,
	objectStore,
	registerStore,
	schemaStore,
	searchTrailStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnDashboardPage
			:title="t('openregister', 'Dashboard')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="isLoading && !hasData"
			:emptyLabel="t('openregister', 'No data available')"
			:showRefresh="false"
			@layoutChange="onLayoutChange">
			<!-- Header actions -->
			<template #actions>
				<NcButton
					:disabled="refreshing"
					:aria-label="t('openregister', 'Refresh dashboard')"
					@click="refreshDashboard">
					<template #icon>
						<NcLoadingIcon v-if="refreshing" :size="20" />
						<Refresh v-else :size="20" />
					</template>
				</NcButton>
			</template>

			<!-- Objects KPI -->
			<template #widget-count-objects>
				<DashboardKpiCard
					:label="t('openregister', 'Objects')"
					:value="totalObjects"
					:sub="objectsSub">
					<template #icon>
						<CubeOutline :size="24" />
					</template>
				</DashboardKpiCard>
			</template>

			<!-- Registers KPI -->
			<template #widget-count-registers>
				<DashboardKpiCard
					:label="t('openregister', 'Registers')"
					:value="totalRegisters">
					<template #icon>
						<DatabaseOutline :size="24" />
					</template>
				</DashboardKpiCard>
			</template>

			<!-- Schemas KPI -->
			<template #widget-count-schemas>
				<DashboardKpiCard
					:label="t('openregister', 'Schemas')"
					:value="totalSchemas">
					<template #icon>
						<FileTreeOutline :size="24" />
					</template>
				</DashboardKpiCard>
			</template>

			<!-- Searches KPI (range-driven) -->
			<template #widget-count-searches>
				<DashboardKpiCard
					:label="t('openregister', 'Searches')"
					:value="totalSearches"
					:sub="searchesSub">
					<template #icon>
						<Magnify :size="24" />
					</template>
					<template #chip>
						<KpiRangeChip :preset="kpiRange.preset" @pick="pickRange" />
					</template>
				</DashboardKpiCard>
			</template>

			<!-- Events KPI (range-driven) -->
			<template #widget-count-events>
				<DashboardKpiCard
					:label="t('openregister', 'Events')"
					:value="totalEventsInRange">
					<template #icon>
						<History :size="24" />
					</template>
					<template #chip>
						<KpiRangeChip :preset="kpiRange.preset" @pick="pickRange" />
					</template>
				</DashboardKpiCard>
			</template>

			<!-- Popular Search Terms widget -->
			<template #widget-popular-terms>
				<div
					v-if="searchTrailStore.popularTerms.length === 0"
					class="widget-empty">
					{{ t('openregister', 'No search terms data available') }}
				</div>
				<CnDataTable
					v-else
					:columns="popularTermsColumns"
					:rows="searchTrailStore.popularTerms"
					borderless
					@rowClick="onPopularTermClick" />
			</template>

			<!-- Objects by Register widget -->
			<template #widget-objects-by-register>
				<div v-if="registerData.length === 0" class="widget-empty">
					{{ t('openregister', 'No register data available') }}
				</div>
				<CnDataTable
					v-else
					:columns="objectsByColumns(t('openregister', 'Register'))"
					:rows="registerData"
					borderless
					@rowClick="onRegisterRowClick" />
			</template>

			<!-- Objects by Schema widget -->
			<template #widget-objects-by-schema>
				<div v-if="schemaData.length === 0" class="widget-empty">
					{{ t('openregister', 'No schema data available') }}
				</div>
				<CnDataTable
					v-else
					:columns="objectsByColumns(t('openregister', 'Schema'))"
					:rows="schemaData"
					borderless
					@rowClick="onSchemaRowClick" />
			</template>

			<!-- Objects Distribution chart widget -->
			<template #widget-objects-chart>
				<div v-if="registerData.length > 0" class="chart-widget-content">
					<Apexchart
						type="pie"
						height="260"
						:options="objectsChartOptions"
						:series="registerData.map((r) => r.count)" />
				</div>
				<div v-else class="widget-empty">
					{{ t('openregister', 'No data available for chart') }}
				</div>
			</template>
		</CnDashboardPage>
	</NcAppContent>
</template>

<script>
import { CnDashboardPage, CnDataTable } from '@conduction/nextcloud-vue'
import { NcAppContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import VueApexCharts from 'vue3-apexcharts'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import DashboardKpiCard from './DashboardKpiCard.vue'
import KpiRangeChip from './KpiRangeChip.vue'
import {
	liveCollectionLifecycle,
	liveCollectionMethods,
} from '../../composables/liveCollectionSubscription.js'
import {
	chartRows,
	dashboardWidgets,
	DEFAULT_LAYOUT,
	objectColumns,
	pieChartOptions,
	termColumns,
} from './dashboardWidgets.js'
import { rangeFor, storedRange, storeRange } from './kpiRange.js'

/**
 * Trailing debounce for live-event-driven widget refetches (ms). A bulk
 * import flushes a burst of or-collection events; the dashboard endpoints
 * are aggregates, so one refetch after the burst settles is enough. The
 * dashboard store's own `chartLoading`/`statisticsLoading` guards DROP
 * concurrent fetches (they do not queue a trailing one), so without this
 * debounce the final state after a burst would be missed.
 */
const LIVE_REFETCH_DEBOUNCE_MS = 750

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		CnDashboardPage,
		CnDataTable,
		Apexchart: VueApexCharts,
		DashboardKpiCard,
		KpiRangeChip,
		Refresh,
		Magnify,
		CubeOutline,
		DatabaseOutline,
		FileTreeOutline,
		History,
	},

	// Re-scopes the live subscription when the sidebar filter changes, and
	// releases it (cancelling a pending debounced refetch) on unmount.
	mixins: [liveCollectionLifecycle],

	data() {
		return {
			refreshing: false,
			dashboardLayout: [...DEFAULT_LAYOUT],
			kpiRange: { from: null, till: null, preset: 'all' },
			// Live-updates handle for the or-collection-{register}-{schema}
			// subscription of the register+schema the dashboard is scoped to
			// via the sidebar filter (dashboard-live-updates). Managed by
			// syncLiveSubscription(); same guard set as ObjectsList.vue:
			// livePendingType marks an in-flight subscribe so a concurrent
			// same-scope call doesn't double-subscribe; liveEpoch invalidates
			// in-flight resolutions after a release (scope change / destroy).
			liveHandle: null,
			liveType: '',
			livePendingType: '',
			liveEpoch: 0,
			// Trailing-debounce timer coalescing burst events into one refetch.
			liveRefetchTimer: null,
		}
	},

	computed: {
		isLoading() {
			return dashboardStore.loading || searchTrailStore.statisticsLoading
		},

		/**
		 * @spec exclude Derived client-state — composes the object-store type slug for the dashboard's sidebar scope so the watcher below can re-scope the live subscription. Empty unless BOTH register and schema are selected: the or-collection event dialect needs both slugs, so an unscoped (or register-only) dashboard cannot subscribe.
		 */
		liveCollectionType() {
			const registerId = registerStore.registerItem?.id
			const schemaId = schemaStore.schemaItem?.id
			return registerId && schemaId ? `${registerId}-${schemaId}` : ''
		},

		/**
		 * @spec exclude Derived client-state — mirrors the object store's liveLastEventAt diagnostic so the Options-API watcher below can react to live events.
		 */
		liveLastEventAt() {
			return objectStore.liveLastEventAt
		},

		/**
		 * Whether the dashboard has any data to display.
		 *
		 * @spec exclude UI plumbing — derived view state for empty-content gating
		 * @return {boolean}
		 */
		hasData() {
			return (
				searchTrailStore.statistics.total > 0
				|| this.registerData.length > 0
				|| this.totalObjects > 0
			)
		},

		/**
		 * Total number of objects across all registers (from System Totals).
		 *
		 * @spec exclude UI plumbing — derived KPI value for display
		 * @return {number}
		 */
		totalObjects() {
			return dashboardStore.getSystemTotals?.stats?.objects?.total || 0
		},

		/**
		 * Total number of registers (excludes the System Totals / Orphaned Items pseudo-rows).
		 *
		 * @spec exclude UI plumbing — derived KPI value for display
		 * @return {number}
		 */
		totalRegisters() {
			return registerStore.registerList?.length || 0
		},

		/**
		 * Total number of schemas.
		 *
		 * @spec exclude UI plumbing — derived KPI value for display
		 * @return {number}
		 */
		totalSchemas() {
			return schemaStore.schemaList?.length || 0
		},

		/**
		 * Total number of audit-log events across all registers (from System Totals).
		 *
		 * @spec exclude UI plumbing — derived KPI value for display
		 * @return {number}
		 */
		totalEvents() {
			return dashboardStore.getSystemTotals?.stats?.logs?.total || 0
		},

		/**
		 * Total searches over the active range (the search-trail statistics
		 * are refetched with the range, so the all-time bag already reflects it).
		 *
		 * @return {number}
		 */
		totalSearches() {
			return searchTrailStore.statistics.total || 0
		},

		/**
		 * Total audit-log events over the active range. "All time" uses the
		 * System Totals log count; a bounded range sums the audit-trail-action
		 * chart series (Create/Update/Delete/Read) the store fetched for it.
		 *
		 * @return {number}
		 */
		totalEventsInRange() {
			if (this.kpiRange.preset === 'all') {
				return this.totalEvents
			}
			const series = dashboardStore.chartData.auditTrailActions?.series
			if (!Array.isArray(series)) return 0
			return series.reduce(
				(sum, s) =>
					sum
					+ (Array.isArray(s.data)
						? s.data.reduce((a, b) => a + (b || 0), 0)
						: 0),
				0,
			)
		},

		/**
		 * Objects-by-register chart rows mapped from store chart data.
		 *
		 * @spec exclude UI plumbing — derived chart view state
		 * @return {Array<object>}
		 */
		registerData() {
			return chartRows(dashboardStore.chartData.objectsByRegister)
		},

		/**
		 * Objects-by-schema chart rows mapped from store chart data.
		 *
		 * @spec exclude UI plumbing — derived chart view state
		 * @return {Array<object>}
		 */
		schemaData() {
			return chartRows(dashboardStore.chartData.objectsBySchema)
		},

		/**
		 * ApexCharts options for the objects-by-register distribution pie.
		 *
		 * @spec exclude UI plumbing — chart config derived from register chart data
		 * @return {object}
		 */
		objectsChartOptions() {
			return pieChartOptions(this.registerData.map((r) => r.label))
		},

		/**
		 * The Objects card's sub line.
		 *
		 * @spec exclude UI plumbing: derived KPI text for display
		 * @return {string}
		 */
		objectsSub() {
			if (this.totalRegisters <= 0) return ''
			return t('openregister', 'across {count} registers', {
				count: this.totalRegisters.toLocaleString(),
			})
		},

		/**
		 * The Searches card's sub line.
		 *
		 * @spec exclude UI plumbing: derived KPI text for display
		 * @return {string}
		 */
		searchesSub() {
			if (this.totalSearches <= 0) return ''
			return t('openregister', '{pct}% success rate', {
				pct: (searchTrailStore.statistics.successRate * 100).toFixed(0),
			})
		},

		/**
		 * Columns for the Popular Search Terms table.
		 *
		 * @spec exclude UI plumbing: static column definitions for display
		 * @return {Array<object>}
		 */
		popularTermsColumns() {
			return termColumns()
		},

		/**
		 * Widget definitions for the dashboard grid.
		 *
		 * @spec exclude UI plumbing: static widget list for display
		 * @return {Array<object>}
		 */
		widgetDefs() {
			return dashboardWidgets()
		},
	},

	watch: {
		/**
		 * A live event arrived on the object store (the plugin stamps
		 * liveLastEventAt on every event). While the dashboard holds a
		 * collection subscription, treat it as a refetch HINT for the
		 * object-CRUD-derived widgets — debounced, never patched from the
		 * event payload.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		liveLastEventAt() {
			if (!this.liveHandle) return
			this.scheduleLiveRefetch()
		},
	},

	/**
	 * Lifecycle hook: preload dashboard and search-trail data on mount.
	 *
	 * @spec openspec/specs/built-in-dashboards/spec.md
	 * @return {Promise<void>}
	 */
	async mounted() {
		this.syncLiveSubscription()
		this.loadPersistedRange()
		dashboardStore.preload()
		dashboardStore.fetchAllChartData()
		registerStore.refreshRegisterList()
		schemaStore.refreshSchemaList()
		// Fetch the range-driven audit-trail chart for the active range so the
		// Events KPI can sum it (no-op for "all time", which uses log totals).
		dashboardStore.setDateRange(this.kpiRange.from, this.kpiRange.till)
		try {
			await this.loadSearchTrailData()
		} catch (error) {
			console.warn('Search trail data not available:', error)
			this.setEmptySearchTrailData()
		}
	},

	methods: {
		// Live subscription of the register+schema collection the dashboard
		// is scoped to via the sidebar filter (dashboard-live-updates). The
		// plugin refreshes the object collection cache (kept warm by the
		// sidebar's own object search) and this view's liveLastEventAt watcher
		// debounce-refetches the dashboard aggregates. No-op while the
		// dashboard is unscoped: the or-collection event dialect has no
		// wildcard, so "all registers" cannot subscribe. A release also
		// cancels a pending debounced refetch.
		...liveCollectionMethods({
			tag: '[DashboardIndex]',
			currentType: (vm) => vm.liveCollectionType,
			onRelease: (vm) => {
				clearTimeout(vm.liveRefetchTimer)
				vm.liveRefetchTimer = null
			},
		}),

		/**
		 * Coalesce a burst of live collection events (e.g. a bulk import
		 * flush) into a single trailing refetch of the object-CRUD-derived
		 * dashboard data. Epoch-guarded so a timer scheduled before a release
		 * (scope change / destroy) never fires a stale refetch.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {void}
		 */
		scheduleLiveRefetch() {
			clearTimeout(this.liveRefetchTimer)
			const epoch = this.liveEpoch
			this.liveRefetchTimer = setTimeout(() => {
				this.liveRefetchTimer = null
				if (this.liveEpoch !== epoch || !this.liveHandle) return
				this.refetchLiveWidgets()
			}, LIVE_REFETCH_DEBOUNCE_MS)
		},

		/**
		 * Refetch the widgets whose data derives from object CRUD: the
		 * register totals (Objects KPI + sidebar totals), the objects-by-*
		 * charts, and the audit-trail statistics (every create/delete writes
		 * audit entries). Search-trail widgets are NOT refetched — search
		 * activity is not signalled by or-collection events. All fetches read
		 * the current register/schema scope from the stores at call time.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {Promise<void>}
		 * @spec openspec/specs/built-in-dashboards/spec.md
		 */
		async refetchLiveWidgets() {
			try {
				await Promise.all([
					dashboardStore.fetchRegisters(),
					dashboardStore.fetchAllChartData(),
					dashboardStore.fetchAllStatistics(),
				])
			} catch (e) {
				// Refetch hint failed — the widgets keep their last state and
				// the next event (or manual refresh) tries again.
				console.warn(
					'[DashboardIndex] live refetch failed:',
					e?.message ?? e,
				)
			}
		},

		/**
		 * Reset search-trail statistics to empty defaults for display.
		 *
		 * @spec exclude UI plumbing — empty-state seed for display
		 * @return {void}
		 */
		setEmptySearchTrailData() {
			searchTrailStore.setStatistics({
				total_searches: 0,
				total_results: 0,
				avg_results_per_search: 0,
				avg_response_time: 0,
				success_rate: 0,
				unique_search_terms: 0,
				unique_users: 0,
				unique_organizations: 0,
				query_complexity: { simple: 0, medium: 0, complex: 0 },
			})
			searchTrailStore.setPopularTerms({ results: [] })
			searchTrailStore.setActivity({ daily: { activity: [] } })
		},

		/**
		 * Load search-trail statistics and popular terms from the store.
		 *
		 * @spec openspec/specs/built-in-dashboards/spec.md
		 * @return {Promise<void>}
		 */
		async loadSearchTrailData() {
			try {
				await searchTrailStore.fetchStatistics({
					from: this.kpiRange.from,
					to: this.kpiRange.till,
				})
				await searchTrailStore.fetchPopularTerms()
			} catch (error) {
				console.error('Error loading search trail data:', error)
				this.setEmptySearchTrailData()
			}
		},

		/**
		 * Restore the persisted KPI date range from localStorage (falls back
		 * to "all time"). Validates the stored preset against the known set.
		 *
		 * @spec openspec/specs/built-in-dashboards/spec.md
		 * @return {void}
		 */
		loadPersistedRange() {
			const range = storedRange()
			if (range) {
				this.kpiRange = range
			}
		},

		/**
		 * Apply a date-range preset to the range-driven KPI cards (Searches,
		 * Events): resolve its window, persist it, and refetch both metrics.
		 *
		 * @param {string} presetId The chosen preset id.
		 * @spec openspec/specs/built-in-dashboards/spec.md
		 * @return {void}
		 */
		pickRange(presetId) {
			this.kpiRange = rangeFor(presetId)
			storeRange(presetId)
			// Events: refetch the range-scoped audit-trail-action chart.
			dashboardStore.setDateRange(this.kpiRange.from, this.kpiRange.till)
			// Searches: refetch the range-scoped statistics.
			this.loadSearchTrailData()
		},

		/**
		 * Reload all dashboard and search-trail data.
		 *
		 * @spec openspec/specs/built-in-dashboards/spec.md
		 * @return {Promise<void>}
		 */
		async refreshDashboard() {
			this.refreshing = true
			try {
				await dashboardStore.preload()
				await dashboardStore.fetchAllChartData()
				await registerStore.refreshRegisterList()
				await schemaStore.refreshSchemaList()
				await this.loadSearchTrailData()
			} catch (error) {
				console.error('Error refreshing dashboard:', error)
			} finally {
				this.refreshing = false
			}
		},

		/**
		 * Update the stored dashboard grid layout when the user rearranges widgets.
		 *
		 * @param {Array<object>} newLayout The new widget layout.
		 * @spec exclude UI plumbing — local layout state update
		 * @return {void}
		 */
		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},

		/**
		 * Column definitions for an "Objects by X" table (Register/Schema).
		 *
		 * @param {string} label The first column header (e.g. Register or Schema).
		 * @spec exclude UI plumbing: derived column definitions for display
		 * @return {Array<object>}
		 */
		objectsByColumns(label) {
			return objectColumns(label)
		},

		/**
		 * Navigate to the register behind a clicked "Objects by Register" row.
		 *
		 * @param {object} row The clicked row ({ label, count }).
		 * @spec exclude UI plumbing — row-click navigation
		 * @return {void}
		 */
		onRegisterRowClick(row) {
			const match = (registerStore.registerList || []).find(
				(r) => r.title === row.label || r.name === row.label,
			)
			if (match?.id) {
				this.$router.push(`/registers/${match.id}`).catch(() => {})
			}
		},

		/**
		 * Navigate to the schema behind a clicked "Objects by Schema" row.
		 *
		 * @param {object} row The clicked row ({ label, count }).
		 * @spec exclude UI plumbing — row-click navigation
		 * @return {void}
		 */
		onSchemaRowClick(row) {
			const match = (schemaStore.schemaList || []).find(
				(s) => s.title === row.label || s.name === row.label,
			)
			if (match?.id) {
				this.$router.push(`/schemas/${match.id}`).catch(() => {})
			}
		},

		/**
		 * Open the search-trails overview when a popular term is clicked.
		 *
		 * @param {object} row The clicked row (a search-trail term entry).
		 * @spec exclude UI plumbing — row-click navigation
		 * @return {void}
		 */
		onPopularTermClick(row) {
			this.$router.push({
				path: '/search-trails',
				query: { search: row.term },
			})
		},
	},
}
</script>

<style scoped>
/* Date-range pill on the range-driven KPI cards (Searches, Events). */
.kpi-chip {
	position: absolute;
	top: 10px;
	right: 10px;
}

/* Chart widget — center the pie within the widget body */
.chart-widget-content {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

/* Empty state */
.widget-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}
</style>
