<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { dashboardStore, searchTrailStore, registerStore, schemaStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnDashboardPage
			:title="t('openregister', 'Dashboard')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="isLoading && !hasData"
			:empty-label="t('openregister', 'No data available')"
			:show-refresh="false"
			@layout-change="onLayoutChange">
			<!-- Header actions -->
			<template #actions>
				<NcButton :disabled="refreshing"
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
				<div class="kpi-card">
					<div class="kpi-icon">
						<CubeOutline :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-label">{{ t('openregister', 'Objects') }}</span>
						<span class="kpi-value">{{ totalObjects.toLocaleString() }}</span>
						<span v-if="totalRegisters > 0" class="kpi-sub">{{ t('openregister', 'across {count} registers', { count: totalRegisters.toLocaleString() }) }}</span>
					</div>
				</div>
			</template>

			<!-- Registers KPI -->
			<template #widget-count-registers>
				<div class="kpi-card">
					<div class="kpi-icon">
						<DatabaseOutline :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-label">{{ t('openregister', 'Registers') }}</span>
						<span class="kpi-value">{{ totalRegisters.toLocaleString() }}</span>
					</div>
				</div>
			</template>

			<!-- Schemas KPI -->
			<template #widget-count-schemas>
				<div class="kpi-card">
					<div class="kpi-icon">
						<FileTreeOutline :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-label">{{ t('openregister', 'Schemas') }}</span>
						<span class="kpi-value">{{ totalSchemas.toLocaleString() }}</span>
					</div>
				</div>
			</template>

			<!-- Searches KPI (range-driven) -->
			<template #widget-count-searches>
				<div class="kpi-card">
					<div class="kpi-icon">
						<Magnify :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-label">{{ t('openregister', 'Searches') }}</span>
						<span class="kpi-value">{{ totalSearches.toLocaleString() }}</span>
						<span v-if="totalSearches > 0" class="kpi-sub">{{ t('openregister', '{pct}% success rate', { pct: (searchTrailStore.statistics.successRate * 100).toFixed(0) }) }}</span>
					</div>
					<div class="kpi-chip">
						<NcActions :force-menu="true" container="body" :menu-title="t('openregister', 'Change date range')">
							<template #icon>
								<span class="kpi-chip-label">{{ rangeText }}</span>
							</template>
							<NcActionButton v-for="preset in rangePresets"
								:key="preset.id"
								:close-after-click="true"
								@click="pickRange(preset.id)">
								<template v-if="kpiRange.preset === preset.id" #icon>
									<CalendarRange :size="16" />
								</template>
								{{ t('openregister', preset.label) }}
							</NcActionButton>
						</NcActions>
					</div>
				</div>
			</template>

			<!-- Events KPI (range-driven) -->
			<template #widget-count-events>
				<div class="kpi-card">
					<div class="kpi-icon">
						<History :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-label">{{ t('openregister', 'Events') }}</span>
						<span class="kpi-value">{{ totalEventsInRange.toLocaleString() }}</span>
					</div>
					<div class="kpi-chip">
						<NcActions :force-menu="true" container="body" :menu-title="t('openregister', 'Change date range')">
							<template #icon>
								<span class="kpi-chip-label">{{ rangeText }}</span>
							</template>
							<NcActionButton v-for="preset in rangePresets"
								:key="preset.id"
								:close-after-click="true"
								@click="pickRange(preset.id)">
								<template v-if="kpiRange.preset === preset.id" #icon>
									<CalendarRange :size="16" />
								</template>
								{{ t('openregister', preset.label) }}
							</NcActionButton>
						</NcActions>
					</div>
				</div>
			</template>

			<!-- Popular Search Terms widget -->
			<template #widget-popular-terms>
				<div v-if="searchTrailStore.popularTerms.length === 0" class="widget-empty">
					{{ t('openregister', 'No search terms data available') }}
				</div>
				<CnDataTable v-else
					:columns="popularTermsColumns"
					:rows="searchTrailStore.popularTerms"
					borderless
					@row-click="onPopularTermClick" />
			</template>

			<!-- Objects by Register widget -->
			<template #widget-objects-by-register>
				<div v-if="registerData.length === 0" class="widget-empty">
					{{ t('openregister', 'No register data available') }}
				</div>
				<CnDataTable v-else
					:columns="objectsByColumns(t('openregister', 'Register'))"
					:rows="registerData"
					borderless
					@row-click="onRegisterRowClick" />
			</template>

			<!-- Objects by Schema widget -->
			<template #widget-objects-by-schema>
				<div v-if="schemaData.length === 0" class="widget-empty">
					{{ t('openregister', 'No schema data available') }}
				</div>
				<CnDataTable v-else
					:columns="objectsByColumns(t('openregister', 'Schema'))"
					:rows="schemaData"
					borderless
					@row-click="onSchemaRowClick" />
			</template>

			<!-- Objects Distribution chart widget -->
			<template #widget-objects-chart>
				<div v-if="registerData.length > 0" class="chart-widget-content">
					<apexchart
						type="pie"
						height="260"
						:options="objectsChartOptions"
						:series="registerData.map(r => r.count)" />
				</div>
				<div v-else class="widget-empty">
					{{ t('openregister', 'No data available for chart') }}
				</div>
			</template>
		</CnDashboardPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcLoadingIcon, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnDashboardPage, CnDataTable } from '@conduction/nextcloud-vue'
import VueApexCharts from 'vue3-apexcharts'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'

const DEFAULT_LAYOUT = [
	// Row 1 — structural totals (point-in-time).
	{ id: 1, widgetId: 'count-objects', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-registers', gridX: 4, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-schemas', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	// Row 2 — activity over the selected date range (range-driven, carry a pill).
	{ id: 4, widgetId: 'count-searches', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 2, showTitle: false },
	{ id: 5, widgetId: 'count-events', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 2, showTitle: false },
	{ id: 6, widgetId: 'popular-terms', gridX: 0, gridY: 4, gridWidth: 6, gridHeight: 4, flush: true },
	{ id: 7, widgetId: 'objects-by-register', gridX: 6, gridY: 4, gridWidth: 6, gridHeight: 4, flush: true },
	{ id: 8, widgetId: 'objects-by-schema', gridX: 0, gridY: 8, gridWidth: 6, gridHeight: 4, flush: true },
	{ id: 9, widgetId: 'objects-chart', gridX: 6, gridY: 8, gridWidth: 6, gridHeight: 4 },
]

/**
 * Date-range presets for the range-driven KPI cards (Searches, Events).
 * `days: null` means "all time" (no date filter). Mirrors the shared
 * finance-dashboard range pill used across the fleet.
 */
const RANGE_PRESETS = [
	{ id: 'all', label: 'All time', days: null },
	{ id: '7d', label: 'Last 7 days', days: 7 },
	{ id: '30d', label: 'Last 30 days', days: 30 },
	{ id: '90d', label: 'Last 3 months', days: 90 },
	{ id: '12m', label: 'Last 12 months', days: 365 },
]

/** localStorage key persisting the chosen KPI range across reloads. */
const RANGE_PERSIST_KEY = 'openregister:dashboard:kpi-range'

/**
 * Trailing debounce for live-event-driven widget refetches (ms). A bulk
 * import flushes a burst of or-collection events; the dashboard endpoints
 * are aggregates, so one refetch after the burst settles is enough. The
 * dashboard store's own `chartLoading`/`statisticsLoading` guards DROP
 * concurrent fetches (they do not queue a trailing one), so without this
 * debounce the final state after a burst would be missed.
 */
const LIVE_REFETCH_DEBOUNCE_MS = 750

/**
 * Resolve a preset id to an ISO date window. Returns { from, till } as
 * 'YYYY-MM-DD' strings, or { from: null, till: null } for "all time".
 *
 * @param {string} presetId The preset id (all, 7d, 30d, 90d, 12m).
 * @return {{from: (string|null), till: (string|null)}} The resolved window.
 */
function resolvePresetWindow(presetId) {
	const preset = RANGE_PRESETS.find(p => p.id === presetId) || RANGE_PRESETS[0]
	if (preset.days === null) return { from: null, till: null }
	const iso = (d) => d.toISOString().slice(0, 10)
	const till = new Date()
	const from = new Date()
	from.setDate(from.getDate() - preset.days)
	return { from: iso(from), till: iso(till) }
}

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		CnDashboardPage,
		CnDataTable,
		apexchart: VueApexCharts,
		NcActions,
		NcActionButton,
		Refresh,
		Magnify,
		CubeOutline,
		DatabaseOutline,
		FileTreeOutline,
		History,
		CalendarRange,
	},
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
			return (registerId && schemaId) ? `${registerId}-${schemaId}` : ''
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
			return searchTrailStore.statistics.total > 0
				|| this.registerData.length > 0
				|| this.totalObjects > 0
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
		/** @return {Array<object>} The date-range presets for the KPI pill. */
		rangePresets() {
			return RANGE_PRESETS
		},
		/** @return {string} Compact label for the active KPI range. */
		rangeText() {
			const preset = RANGE_PRESETS.find(p => p.id === this.kpiRange.preset) || RANGE_PRESETS[0]
			return t('openregister', preset.label)
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
			return series.reduce((sum, s) => sum + (Array.isArray(s.data) ? s.data.reduce((a, b) => a + (b || 0), 0) : 0), 0)
		},
		/**
		 * Objects-by-register chart rows mapped from store chart data.
		 *
		 * @spec exclude UI plumbing — derived chart view state
		 * @return {Array<object>}
		 */
		registerData() {
			const chartData = dashboardStore.chartData.objectsByRegister
			if (!chartData?.labels || !chartData?.series) return []
			return chartData.labels.map((label, i) => ({
				label,
				count: chartData.series[i] || 0,
			}))
		},
		/**
		 * Objects-by-schema chart rows mapped from store chart data.
		 *
		 * @spec exclude UI plumbing — derived chart view state
		 * @return {Array<object>}
		 */
		schemaData() {
			const chartData = dashboardStore.chartData.objectsBySchema
			if (!chartData?.labels || !chartData?.series) return []
			return chartData.labels.map((label, i) => ({
				label,
				count: chartData.series[i] || 0,
			}))
		},
		/**
		 * ApexCharts options for the objects-by-register distribution pie.
		 *
		 * @spec exclude UI plumbing — chart config derived from register chart data
		 * @return {object}
		 */
		objectsChartOptions() {
			return {
				chart: { type: 'pie' },
				labels: this.registerData.map(r => r.label),
				legend: { position: 'bottom' },
				responsive: [{
					breakpoint: 480,
					options: {
						chart: { width: 200 },
						legend: { position: 'bottom' },
					},
				}],
			}
		},
		/**
		 * Columns for the Popular Search Terms table.
		 *
		 * @spec exclude UI plumbing — static column definitions for display
		 * @return {Array<object>}
		 */
		popularTermsColumns() {
			return [
				{ key: 'term', label: t('openregister', 'Search Term'), sortable: true },
				{ key: 'count', label: t('openregister', 'Count'), sortable: true, align: 'right' },
				{ key: 'effectiveness', label: t('openregister', 'Effectiveness') },
			]
		},
		/**
		 * Widget definitions for the dashboard grid.
		 *
		 * @spec exclude UI plumbing — static widget list for display
		 * @return {Array<object>}
		 */
		widgetDefs() {
			return [
				{ id: 'count-objects', title: t('openregister', 'Objects'), type: 'custom' },
				{ id: 'count-registers', title: t('openregister', 'Registers'), type: 'custom' },
				{ id: 'count-schemas', title: t('openregister', 'Schemas'), type: 'custom' },
				{ id: 'count-searches', title: t('openregister', 'Searches'), type: 'custom' },
				{ id: 'count-events', title: t('openregister', 'Events'), type: 'custom' },
				{ id: 'popular-terms', title: t('openregister', 'Popular Search Terms'), type: 'custom', flush: true },
				{ id: 'objects-by-register', title: t('openregister', 'Objects by Register'), type: 'custom', flush: true },
				{ id: 'objects-by-schema', title: t('openregister', 'Objects by Schema'), type: 'custom', flush: true },
				{ id: 'objects-chart', title: t('openregister', 'Objects Distribution'), type: 'custom' },
			]
		},
	},
	watch: {
		/**
		 * Re-scope the live collection subscription when the user changes the
		 * dashboard's register/schema filter in the sidebar.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		liveCollectionType() {
			this.syncLiveSubscription()
		},
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
	 * @spec exclude UI plumbing — view-mount data fetch for display only
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
	/**
	 * Lifecycle hook: release the live collection subscription and cancel any
	 * pending debounced refetch on unmount.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 * @return {void}
	 */
	beforeUnmount() {
		this.releaseLiveSubscription()
	},
	methods: {
		/**
		 * Subscribe to live updates for the register+schema collection the
		 * dashboard is scoped to via the sidebar filter
		 * (dashboard-live-updates). Reuses the package object store's
		 * liveUpdatesPlugin — notify_push when available, visibility-gated
		 * polling otherwise. Events are refetch hints only: the plugin
		 * refreshes the object collection cache (kept warm by the sidebar's
		 * own object search) and this view's liveLastEventAt watcher
		 * debounce-refetches the dashboard aggregates. Idempotent per type;
		 * releases the previous subscription when the scope changes. No-op
		 * while the dashboard is unscoped — the or-collection event dialect
		 * has no wildcard, so "all registers" cannot subscribe.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {Promise<void>}
		 */
		async syncLiveSubscription() {
			const type = this.liveCollectionType
			if (typeof objectStore.subscribe !== 'function') {
				return
			}
			if (!type) {
				this.releaseLiveSubscription()
				return
			}
			if (this.liveHandle && this.liveType === type) {
				return
			}
			if (this.livePendingType === type) {
				// A subscribe for this exact scope is already in flight —
				// re-subscribing here would leak the first handle.
				return
			}
			this.releaseLiveSubscription()
			try {
				// Subscription is independent of the sidebar's object-list
				// refresh timing: register the type ourselves when it is not
				// known to the package store yet.
				if (!objectStore.objectTypes.includes(type)) {
					const registerId = registerStore.registerItem?.id
					const schemaId = schemaStore.schemaItem?.id
					if (!registerId || !schemaId) {
						return
					}
					objectStore.registerObjectType(type, schemaId, registerId)
				}
				const epoch = this.liveEpoch
				this.livePendingType = type
				this.liveType = type
				const handle = await objectStore.subscribe(type)
				if (this.livePendingType === type) {
					this.livePendingType = ''
				}
				if (this.liveEpoch !== epoch) {
					// released while awaiting (scope change / destroy) — drop
					// the now-stale subscription instead of leaking it.
					objectStore.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
			} catch (e) {
				if (this.livePendingType === type) {
					this.livePendingType = ''
				}
				this.liveHandle = null
				this.liveType = ''
				console.warn('[DashboardIndex] live subscription failed:', e?.message ?? e)
			}
		},
		/**
		 * Release the current live collection subscription, if any, cancel a
		 * pending debounced refetch, and invalidate any in-flight subscribe
		 * (its resolution unsubscribes itself via the epoch check).
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {void}
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingType = ''
			clearTimeout(this.liveRefetchTimer)
			this.liveRefetchTimer = null
			if (this.liveHandle && typeof objectStore.unsubscribe === 'function') {
				objectStore.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
			this.liveType = ''
		},
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
				console.warn('[DashboardIndex] live refetch failed:', e?.message ?? e)
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
		 * @spec exclude UI plumbing — delegates to the search-trail store fetch
		 * @return {Promise<void>}
		 */
		async loadSearchTrailData() {
			try {
				await searchTrailStore.fetchStatistics({ from: this.kpiRange.from, to: this.kpiRange.till })
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
		 * @spec exclude UI plumbing — local range state restore
		 * @return {void}
		 */
		loadPersistedRange() {
			try {
				const raw = localStorage.getItem(RANGE_PERSIST_KEY)
				if (!raw) return
				const stored = JSON.parse(raw)
				if (stored && RANGE_PRESETS.some(p => p.id === stored.preset)) {
					const win = resolvePresetWindow(stored.preset)
					this.kpiRange = { from: win.from, till: win.till, preset: stored.preset }
				}
			} catch (e) {
				// Non-fatal (private window / quota / corrupt value) — keep default.
			}
		},
		/**
		 * Apply a date-range preset to the range-driven KPI cards (Searches,
		 * Events): resolve its window, persist it, and refetch both metrics.
		 *
		 * @param {string} presetId The chosen preset id.
		 * @spec exclude UI plumbing — range selection drives KPI refetch
		 * @return {void}
		 */
		pickRange(presetId) {
			const win = resolvePresetWindow(presetId)
			this.kpiRange = { from: win.from, till: win.till, preset: presetId }
			try {
				localStorage.setItem(RANGE_PERSIST_KEY, JSON.stringify({ preset: presetId }))
			} catch (e) {
				// Non-fatal — in-memory range still applied.
			}
			// Events: refetch the range-scoped audit-trail-action chart.
			dashboardStore.setDateRange(win.from, win.till)
			// Searches: refetch the range-scoped statistics.
			this.loadSearchTrailData()
		},
		/**
		 * Reload all dashboard and search-trail data.
		 *
		 * @spec exclude UI plumbing — refresh button delegates to store fetches
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
		 * @spec exclude UI plumbing — derived column definitions for display
		 * @return {Array<object>}
		 */
		objectsByColumns(label) {
			return [
				{ key: 'label', label, sortable: true },
				{ key: 'count', label: t('openregister', 'Objects'), sortable: true, align: 'right' },
			]
		},
		/**
		 * Navigate to the register behind a clicked "Objects by Register" row.
		 *
		 * @param {object} row The clicked row ({ label, count }).
		 * @spec exclude UI plumbing — row-click navigation
		 * @return {void}
		 */
		onRegisterRowClick(row) {
			const match = (registerStore.registerList || []).find(r => r.title === row.label || r.name === row.label)
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
			const match = (schemaStore.schemaList || []).find(s => s.title === row.label || s.name === row.label)
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
			this.$router.push({ path: '/search-trails', query: { search: row.term } })
		},
	},
}
</script>

<style scoped>
/* KPI Cards — bordered card treatment matching the shared finance KPI style:
 * icon circle (left) + content column (label, big value, optional sub). */
.kpi-card {
	position: relative;
	display: flex;
	align-items: center;
	gap: 16px;
	box-sizing: border-box;
	height: 100%;
	padding: 16px 20px;
	background-color: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius-large, 10px);
}

/* Date-range pill on the range-driven KPI cards (Searches, Events). */
.kpi-chip {
	position: absolute;
	top: 10px;
	right: 10px;
}

.kpi-chip-label {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-background-hover, #f5f5f5);
	color: var(--color-text-maxcontrast, #767676);
	font-size: 11px;
	font-weight: 500;
	white-space: nowrap;
	cursor: pointer;
}

.kpi-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: 48px;
	height: 48px;
	border-radius: 50%;
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element);
}

.kpi-content {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.kpi-label {
	font-size: 13px;
	line-height: 1.3;
	color: var(--color-text-maxcontrast, #767676);
}

.kpi-value {
	font-size: 26px;
	font-weight: 700;
	line-height: 1.2;
	color: var(--color-primary-element, #0082c9);
}

.kpi-sub {
	font-size: 12px;
	line-height: 1.3;
	color: var(--color-text-maxcontrast, #767676);
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

<!--
  Unscoped, namespaced override: NcActions renders its trigger as a
  fixed-size icon button (overflow: hidden), which clips the wide date
  pill. Confined to .kpi-chip, this lets the trigger shrink-wrap the pill.
-->
<style>
.kpi-chip .button-vue,
.kpi-chip .action-item__menutoggle {
	width: auto !important;
	min-width: 0 !important;
	height: auto !important;
	min-height: 0 !important;
	padding: 0 !important;
	overflow: visible !important;
}

.kpi-chip .button-vue__wrapper,
.kpi-chip .button-vue__icon {
	width: auto !important;
	min-width: 0 !important;
	height: auto !important;
}
</style>
