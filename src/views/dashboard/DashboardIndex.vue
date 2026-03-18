<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { dashboardStore, searchTrailStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnDashboardPage
			:title="t('openregister', 'Dashboard')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="isLoading && !hasData"
			:empty-label="t('openregister', 'No data available')"
			@layout-change="onLayoutChange">
			<!-- Header actions -->
			<template #header-actions>
				<NcButton :disabled="refreshing"
					:aria-label="t('openregister', 'Refresh dashboard')"
					@click="refreshDashboard">
					<template #icon>
						<NcLoadingIcon v-if="refreshing" :size="20" />
						<Refresh v-else :size="20" />
					</template>
				</NcButton>
			</template>

			<!-- Total Searches KPI -->
			<template #widget-count-searches>
				<div class="kpi-card">
					<div class="kpi-icon">
						<Magnify :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ searchTrailStore.statistics.total.toLocaleString() }}</span>
						<span class="kpi-label">{{ t('openregister', 'Total Searches') }}</span>
					</div>
				</div>
			</template>

			<!-- Success Rate KPI -->
			<template #widget-count-success-rate>
				<div class="kpi-card">
					<div class="kpi-icon kpi-icon--success">
						<CheckCircle :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ (searchTrailStore.statistics.successRate * 100).toFixed(1) }}%</span>
						<span class="kpi-label">{{ t('openregister', 'Success Rate') }}</span>
					</div>
				</div>
			</template>

			<!-- Avg Execution Time KPI -->
			<template #widget-count-avg-time>
				<div class="kpi-card">
					<div class="kpi-icon">
						<TimerOutline :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ searchTrailStore.statistics.averageExecutionTime.toFixed(0) }}ms</span>
						<span class="kpi-label">{{ t('openregister', 'Avg Response Time') }}</span>
					</div>
				</div>
			</template>

			<!-- Unique Terms KPI -->
			<template #widget-count-unique-terms>
				<div class="kpi-card">
					<div class="kpi-icon">
						<TagMultiple :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ searchTrailStore.statistics.uniqueSearchTerms.toLocaleString() }}</span>
						<span class="kpi-label">{{ t('openregister', 'Unique Terms') }}</span>
					</div>
				</div>
			</template>

			<!-- Popular Search Terms widget -->
			<template #widget-popular-terms>
				<div class="list-widget-content">
					<div v-if="searchTrailStore.popularTerms.length === 0" class="widget-empty">
						{{ t('openregister', 'No search terms data available') }}
					</div>
					<table v-else class="stats-table">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Search Term') }}</th>
								<th class="count-header">
									{{ t('openregister', 'Count') }}
								</th>
								<th class="count-header">
									{{ t('openregister', 'Effectiveness') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="term in searchTrailStore.popularTerms" :key="term.term">
								<td class="term-cell">
									{{ term.term }}
								</td>
								<td class="count-cell">
									{{ term.count }}
								</td>
								<td class="count-cell">
									<span :class="['effectiveness-badge', term.effectiveness]">
										{{ term.effectiveness }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>

			<!-- Objects by Register widget -->
			<template #widget-objects-by-register>
				<div class="list-widget-content">
					<div v-if="registerData.length === 0" class="widget-empty">
						{{ t('openregister', 'No register data available') }}
					</div>
					<table v-else class="stats-table">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Register') }}</th>
								<th class="count-header">
									{{ t('openregister', 'Objects') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(item, index) in registerData" :key="index">
								<td>{{ item.label }}</td>
								<td class="count-cell">
									{{ item.count.toLocaleString() }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>

			<!-- Objects by Schema widget -->
			<template #widget-objects-by-schema>
				<div class="list-widget-content">
					<div v-if="schemaData.length === 0" class="widget-empty">
						{{ t('openregister', 'No schema data available') }}
					</div>
					<table v-else class="stats-table">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Schema') }}</th>
								<th class="count-header">
									{{ t('openregister', 'Objects') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(item, index) in schemaData" :key="index">
								<td>{{ item.label }}</td>
								<td class="count-cell">
									{{ item.count.toLocaleString() }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>

			<!-- Objects Distribution chart widget -->
			<!-- TODO: CnChartWidget does not exist yet in @conduction/nextcloud-vue.
			     Was this widget intentionally added? If so, please create CnChartWidget
			     in the nextcloud-vue library before re-enabling this block. -->
			<!-- <template #widget-objects-chart>
				<CnChartWidget
					v-if="registerData.length > 0"
					type="pie"
					:series="registerData.map(r => r.count)"
					:labels="registerData.map(r => r.label)"
					:height="280"
					:legend="true"
					:toolbar="false" />
				<div v-else class="widget-empty">
					{{ t('openregister', 'No data available for chart') }}
				</div>
			</template> -->
		</CnDashboardPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnDashboardPage } from '@conduction/nextcloud-vue'
// TODO: CnChartWidget does not exist yet in @conduction/nextcloud-vue. Was this intentionally added?
// If so, please create CnChartWidget in the nextcloud-vue library before re-enabling this import.
// import { CnChartWidget } from '@conduction/nextcloud-vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import TimerOutline from 'vue-material-design-icons/TimerOutline.vue'
import TagMultiple from 'vue-material-design-icons/TagMultiple.vue'

const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'count-searches', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-success-rate', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-avg-time', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'count-unique-terms', gridX: 9, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 5, widgetId: 'popular-terms', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 6, widgetId: 'objects-by-register', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 7, widgetId: 'objects-by-schema', gridX: 0, gridY: 6, gridWidth: 6, gridHeight: 4 },
	{ id: 8, widgetId: 'objects-chart', gridX: 6, gridY: 6, gridWidth: 6, gridHeight: 4 },
]

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		CnDashboardPage,
		// CnChartWidget, // TODO: commented out — CnChartWidget does not exist yet in @conduction/nextcloud-vue
		Refresh,
		Magnify,
		CheckCircle,
		TimerOutline,
		TagMultiple,
	},
	data() {
		return {
			refreshing: false,
			dashboardLayout: [...DEFAULT_LAYOUT],
		}
	},
	computed: {
		isLoading() {
			return dashboardStore.loading || searchTrailStore.statisticsLoading
		},
		hasData() {
			return searchTrailStore.statistics.total > 0
				|| this.registerData.length > 0
		},
		registerData() {
			const chartData = dashboardStore.chartData.objectsByRegister
			if (!chartData?.labels || !chartData?.series) return []
			return chartData.labels.map((label, i) => ({
				label,
				count: chartData.series[i] || 0,
			}))
		},
		schemaData() {
			const chartData = dashboardStore.chartData.objectsBySchema
			if (!chartData?.labels || !chartData?.series) return []
			return chartData.labels.map((label, i) => ({
				label,
				count: chartData.series[i] || 0,
			}))
		},
		widgetDefs() {
			return [
				{ id: 'count-searches', title: t('openregister', 'Total Searches'), type: 'custom' },
				{ id: 'count-success-rate', title: t('openregister', 'Success Rate'), type: 'custom' },
				{ id: 'count-avg-time', title: t('openregister', 'Avg Response Time'), type: 'custom' },
				{ id: 'count-unique-terms', title: t('openregister', 'Unique Terms'), type: 'custom' },
				{ id: 'popular-terms', title: t('openregister', 'Popular Search Terms'), type: 'custom' },
				{ id: 'objects-by-register', title: t('openregister', 'Objects by Register'), type: 'custom' },
				{ id: 'objects-by-schema', title: t('openregister', 'Objects by Schema'), type: 'custom' },
				{ id: 'objects-chart', title: t('openregister', 'Objects Distribution'), type: 'custom' },
			]
		},
	},
	async mounted() {
		dashboardStore.preload()
		dashboardStore.fetchAllChartData()
		try {
			await this.loadSearchTrailData()
		} catch (error) {
			console.warn('Search trail data not available:', error)
			this.setEmptySearchTrailData()
		}
	},
	methods: {
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
		async loadSearchTrailData() {
			try {
				await searchTrailStore.fetchStatistics()
				await searchTrailStore.fetchPopularTerms()
			} catch (error) {
				console.error('Error loading search trail data:', error)
				this.setEmptySearchTrailData()
			}
		},
		async refreshDashboard() {
			this.refreshing = true
			try {
				await dashboardStore.preload()
				await dashboardStore.fetchAllChartData()
				await this.loadSearchTrailData()
			} catch (error) {
				console.error('Error refreshing dashboard:', error)
			} finally {
				this.refreshing = false
			}
		},
		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},
	},
}
</script>

<style scoped>
/* KPI Cards */
.kpi-card {
	display: flex;
	align-items: center;
	gap: 12px;
}

.kpi-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.kpi-icon--success {
	background: rgba(70, 186, 97, 0.1);
	color: var(--color-success);
}

.kpi-content {
	display: flex;
	flex-direction: column;
}

.kpi-value {
	font-size: 24px;
	font-weight: 700;
	line-height: 1.2;
}

.kpi-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* List widgets */
.list-widget-content {
	overflow: auto;
}

.stats-table {
	width: 100%;
	border-collapse: collapse;
}

.stats-table th {
	text-align: left;
	padding: 10px 12px;
	font-weight: 600;
	border-bottom: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.count-header {
	text-align: right !important;
}

.stats-table td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.stats-table tbody tr:last-child td {
	border-bottom: none;
}

.stats-table tbody tr:hover {
	background: var(--color-background-hover);
}

.term-cell {
	font-weight: 500;
}

.count-cell {
	font-weight: 600;
	text-align: right;
}

.effectiveness-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.effectiveness-badge.high {
	background: rgba(70, 186, 97, 0.15);
	color: var(--color-success);
}

.effectiveness-badge.low {
	background: rgba(224, 36, 36, 0.15);
	color: var(--color-error);
}

/* Empty state */
.widget-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}
</style>
