<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<NcButton variant="tertiary" @click="$router.push('/reports')">
						<template #icon>
							<ChevronLeft :size="20" />
						</template>
						{{ t('openregister', 'Back') }}
					</NcButton>
					<h1 class="viewHeaderTitleIndented">
						{{ dashboard?.titel || t('openregister', 'Dashboard') }}
					</h1>
					<NcButton variant="tertiary"
						:disabled="loading"
						:aria-label="t('openregister', 'Refresh dashboard')"
						@click="refresh">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
							<Refresh v-else :size="20" />
						</template>
					</NcButton>
					<NcActions :force-name="true" :inline="0" menu-name="Export">
						<template #icon>
							<Download :size="20" />
						</template>
						<NcActionButton close-after-click @click="downloadAs('xlsx')">
							<template #icon>
								<MicrosoftExcel :size="20" />
							</template>
							{{ t('openregister', 'Excel (.xlsx)') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="downloadAs('ods')">
							<template #icon>
								<FileDocumentOutline :size="20" />
							</template>
							{{ t('openregister', 'OpenDocument (.ods)') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="downloadAs('csv')">
							<template #icon>
								<FileDelimitedOutline :size="20" />
							</template>
							{{ t('openregister', 'CSV') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="downloadAs('pdf')">
							<template #icon>
								<FilePdfBox :size="20" />
							</template>
							{{ t('openregister', 'PDF') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="openHtmlPreview">
							<template #icon>
								<Printer :size="20" />
							</template>
							{{ t('openregister', 'HTML preview') }}
						</NcActionButton>
					</NcActions>
				</div>
				<p v-if="dashboard?.beschrijving">
					{{ dashboard.beschrijving }}
				</p>
			</div>

			<NcEmptyContent
				v-if="!dashboard && !loading"
				:name="t('openregister', 'Dashboard not found')"
				:description="t('openregister', 'The requested dashboard could not be loaded. Verify the URL or pick another dashboard from the list.')">
				<template #icon>
					<AlertCircleOutline :size="64" />
				</template>
			</NcEmptyContent>

			<div v-else-if="dashboard" class="reportGrid" :style="gridStyles">
				<section
					v-for="(widget, index) in widgets"
					:key="index"
					class="reportWidget"
					:style="widgetCellStyle(widget)">
					<header class="reportWidgetHeader">
						<h3>{{ widget.title }}</h3>
						<p v-if="widget.subtitle" class="reportWidgetSubtitle">
							{{ widget.subtitle }}
						</p>
					</header>

					<div class="reportWidgetBody">
						<div v-if="widgetState(index).loading" class="widgetLoading">
							<NcLoadingIcon :size="32" />
						</div>

						<div v-else-if="widgetState(index).error" class="widgetError">
							<AlertCircleOutline :size="20" />
							<span>{{ widgetState(index).error }}</span>
						</div>

						<!-- KPI -->
						<div v-else-if="widget.type === 'kpi'" class="widgetKpi">
							<component
								:is="widgetIcon(widget)"
								v-if="widget.options?.icon"
								:size="32"
								class="widgetKpiIcon" />
							<div>
								<div class="widgetKpiValue">
									{{ formatValue(widgetState(index).data, widget) }}
								</div>
								<div v-if="widget.subtitle" class="widgetKpiLabel">
									{{ widget.subtitle }}
								</div>
							</div>
						</div>

						<!-- Chart -->
						<CnChartWidget
							v-else-if="widget.type === 'chart'"
							:type="widget.options?.chartType || 'bar'"
							:series="chartSeries(widgetState(index).data, widget)"
							:categories="chartCategories(widgetState(index).data, widget)"
							:labels="chartLabels(widgetState(index).data, widget)" />

						<!-- Table -->
						<CnDataTable
							v-else-if="widget.type === 'table'"
							:rows="tableRows(widgetState(index).data)"
							:columns="tableColumns(widget)"
							borderless />

						<!-- Sparkline (renders as a tiny line chart) -->
						<CnChartWidget
							v-else-if="widget.type === 'sparkline'"
							type="line"
							:series="[{ name: widget.title, data: sparklineData(widgetState(index).data, widget) }]"
							:categories="sparklineCategories(widgetState(index).data)"
							:hide-axes="true"
							:height="80" />

						<!-- Tile -->
						<div v-else-if="widget.type === 'tile'" class="widgetTile">
							<div class="widgetTileValue">
								{{ formatValue(widgetState(index).data, widget) }}
							</div>
							<div class="widgetTileLabel">
								{{ widget.subtitle || widget.title }}
							</div>
						</div>

						<!-- Stats -->
						<div v-else-if="widget.type === 'stats'" class="widgetStats">
							<dl>
								<div v-for="entry in statsEntries(widgetState(index).data, widget)" :key="entry.label" class="widgetStatsRow">
									<dt>{{ entry.label }}</dt>
									<dd>{{ entry.value }}</dd>
								</div>
							</dl>
						</div>

						<!-- Unknown -->
						<div v-else class="widgetUnknown">
							{{ t('openregister', 'Unknown widget type: {type}', { type: widget.type }) }}
						</div>
					</div>
				</section>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnChartWidget, CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'

import Refresh from 'vue-material-design-icons/Refresh.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import MicrosoftExcel from 'vue-material-design-icons/MicrosoftExcel.vue'
import FileDelimitedOutline from 'vue-material-design-icons/FileDelimitedOutline.vue'
import Printer from 'vue-material-design-icons/Printer.vue'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'

import { reportsStore } from '../../store/store.js'

const ICON_MAP = {
	ChartBoxOutline,
	AccountGroupOutline,
	FileDocumentOutline,
	DatabaseOutline,
}

export default {
	name: 'ReportView',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		CnChartWidget,
		CnDataTable,
		Refresh,
		ChevronLeft,
		AlertCircleOutline,
		ChartBoxOutline,
		AccountGroupOutline,
		FileDocumentOutline,
		DatabaseOutline,
		Download,
		MicrosoftExcel,
		FileDelimitedOutline,
		Printer,
		FilePdfBox,
	},

	data() {
		return {
			widgetStates: {},
		}
	},

	computed: {
		/**
		 * The active report dashboard from the store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object}
		 */
		dashboard() {
			return reportsStore.getActiveDashboard
		},
		/**
		 * Whether the reports store is loading, for spinner display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {boolean}
		 */
		loading() {
			return reportsStore.isLoading
		},
		/**
		 * Widget list for the active dashboard, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {Array<object>}
		 */
		widgets() {
			return this.dashboard?.widgets || []
		},
		/**
		 * CSS grid custom properties derived from the dashboard layout.
		 *
		 * @spec exclude UI plumbing — derived presentation styles
		 * @return {object}
		 */
		gridStyles() {
			const cols = this.dashboard?.layout?.cols ?? 4
			return {
				'--report-cols': cols,
			}
		},
	},

	watch: {
		'$route.params.id': {
			immediate: true,
			/**
			 * Load the dashboard when the route id changes.
			 *
			 * @param {string} value The new route id.
			 * @spec exclude UI plumbing — route watch handler
			 * @return {void}
			 */
			handler(value) {
				if (value) this.load(value)
			},
		},
	},

	methods: {
		/**
		 * Fetch a dashboard and its widget data for display.
		 *
		 * @param {string} identifier The dashboard identifier.
		 * @spec exclude UI plumbing — delegates to the reports store fetch
		 * @return {Promise<void>}
		 */
		async load(identifier) {
			try {
				await reportsStore.fetchDashboard(identifier)
				if (this.dashboard) {
					await this.loadWidgetData()
				}
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Fetch data for every widget in the active dashboard.
		 *
		 * @param {boolean} forceRefresh Whether to bypass cached widget data.
		 * @spec exclude UI plumbing — delegates to the reports store fetch
		 * @return {Promise<void>}
		 */
		async loadWidgetData(forceRefresh = false) {
			const widgets = this.widgets
			for (let i = 0; i < widgets.length; i++) {
				this.widgetStates = {
					...this.widgetStates,
					[i]: { loading: true, error: null, data: null },
				}
			}

			await Promise.all(widgets.map(async (widget, i) => {
				const data = await reportsStore.fetchWidgetData(widget, forceRefresh)
				const cached = reportsStore.getWidgetData(this._cacheKey(widget))
				this.widgetStates = {
					...this.widgetStates,
					[i]: {
						loading: false,
						error: cached?.error ?? null,
						data,
					},
				}
			}))
		},

		/**
		 * Reload the current dashboard and its widget data.
		 *
		 * @spec exclude UI plumbing — refresh button delegates to load()
		 * @return {Promise<void>}
		 */
		async refresh() {
			await this.load(this.$route.params.id)
		},

		/**
		 * Render and download the dashboard in the given export format.
		 *
		 * @param {string} format The export format (e.g. pdf, xlsx).
		 * @spec exclude UI plumbing — delegates to the render API and downloads
		 * @return {Promise<void>}
		 */
		async downloadAs(format) {
			const id = this.$route.params.id
			if (!id) return
			try {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/reports/${encodeURIComponent(id)}/render`),
					null,
					{ params: { format }, responseType: 'blob' },
				)
				const filename = this._extractFilename(response) || `${this.dashboard?.titel || 'dashboard'}.${format}`
				const url = URL.createObjectURL(response.data)
				const a = document.createElement('a')
				a.href = url
				a.download = filename
				a.click()
				URL.revokeObjectURL(url)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[ReportView.downloadAs]', e)
			}
		},

		/**
		 * Open the dashboard's HTML preview in a new tab.
		 *
		 * @spec exclude UI plumbing — opens a preview URL in a new tab
		 * @return {void}
		 */
		openHtmlPreview() {
			const id = this.$route.params.id
			if (!id) return
			const url = generateUrl(`/apps/openregister/api/reports/${encodeURIComponent(id)}/preview`)
			window.open(url, '_blank')
		},

		/**
		 * Extract a filename from a response's content-disposition header.
		 *
		 * @param {object} response The axios response.
		 * @spec exclude UI plumbing — private download-filename helper
		 * @return {(string|null)}
		 */
		_extractFilename(response) {
			const cd = response.headers?.['content-disposition'] || ''
			const m = cd.match(/filename="?([^";]+)"?/i)
			return m ? m[1] : null
		},

		/**
		 * Return the per-widget loading/error/data state for display.
		 *
		 * @param {number} index The widget index.
		 * @spec exclude UI plumbing — derived per-widget view state
		 * @return {object}
		 */
		widgetState(index) {
			return this.widgetStates[index] ?? { loading: false, error: null, data: null }
		},

		/**
		 * Build the cache key for a widget's data source.
		 *
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — private cache-key helper
		 * @return {string}
		 */
		_cacheKey(widget) {
			const ds = widget?.dataSource
			if (!ds) return ''
			if (ds.mode === 'aggregation') return `agg:${ds.register || ''}:${ds.schema || ''}:${ds.aggregation || ''}`
			if (ds.mode === 'graphql') return `gql:${(ds.graphqlQuery || '').slice(0, 200)}`
			if (ds.mode === 'statistics') return `stats:${ds.register || ''}:${ds.schema || ''}`
			return `unknown:${ds.mode}`
		},

		/**
		 * Compute the CSS grid span styles for a widget cell.
		 *
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — derived presentation styles
		 * @return {object}
		 */
		widgetCellStyle(widget) {
			const layout = widget.layout
			if (!layout) return {}
			const styles = {}
			if (layout.w) styles['grid-column'] = `span ${layout.w}`
			if (layout.h) styles['grid-row'] = `span ${layout.h}`
			return styles
		},

		/**
		 * Resolve the icon component for a widget.
		 *
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {object}
		 */
		widgetIcon(widget) {
			return ICON_MAP[widget.options?.icon] || ChartBoxOutline
		},

		/**
		 * Format a widget value for display per its configured format.
		 *
		 * @param {*} data The raw widget data.
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {string}
		 */
		formatValue(data, widget) {
			if (data === null || data === undefined) return '—'
			const field = widget.options?.valueField || 'count'
			const raw = (data && typeof data === 'object') ? (data[field] ?? data.count ?? data) : data
			if (raw === null || raw === undefined) return '—'

			const format = widget.options?.valueFormat || 'number'
			if (format === 'percent') {
				return `${(Number(raw) * 100).toFixed(1)}%`
			}
			if (format === 'currency') {
				return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(Number(raw))
			}
			if (format === 'duration') {
				return `${Number(raw).toFixed(0)}s`
			}
			if (format === 'date') {
				try {
					return new Date(raw).toLocaleDateString()
				} catch (e) {
					return String(raw)
				}
			}
			if (typeof raw === 'number') {
				return new Intl.NumberFormat('nl-NL').format(raw)
			}
			return String(raw)
		},

		/**
		 * Build the ApexCharts series array from widget data.
		 *
		 * @param {object} data The widget data.
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array}
		 */
		chartSeries(data, widget) {
			if (!data) return []
			// If the aggregation returned grouped data: { groups: [{ key, value }] }
			if (Array.isArray(data.groups)) {
				const valueField = widget.options?.valueField || 'value'
				return [{
					name: widget.title,
					data: data.groups.map((g) => Number(g[valueField] ?? g.value ?? 0)),
				}]
			}
			// Pie/donut wants a flat number array.
			const chartType = widget.options?.chartType || 'bar'
			if (chartType === 'pie' || chartType === 'donut') {
				if (Array.isArray(data)) return data.map((d) => Number(d.value ?? d.count ?? 0))
				return []
			}
			return []
		},

		/**
		 * Build the chart x-axis categories from widget data.
		 *
		 * @param {object} data The widget data.
		 * @param {object} _widget The widget definition (unused).
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array<string>}
		 */
		chartCategories(data, _widget) {
			if (!data || !Array.isArray(data.groups)) return []
			return data.groups.map((g) => String(g.key ?? g.label ?? ''))
		},

		/**
		 * Build the pie/donut chart labels from widget data.
		 *
		 * @param {object} data The widget data.
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array<string>}
		 */
		chartLabels(data, widget) {
			const chartType = widget.options?.chartType || 'bar'
			if ((chartType === 'pie' || chartType === 'donut') && Array.isArray(data?.groups)) {
				return data.groups.map((g) => String(g.key ?? g.label ?? ''))
			}
			return []
		},

		/**
		 * Extract table row data from a widget's payload.
		 *
		 * @param {object} data The widget data.
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array}
		 */
		tableRows(data) {
			if (!data) return []
			if (Array.isArray(data)) return data
			if (Array.isArray(data.groups)) return data.groups
			if (Array.isArray(data.results)) return data.results
			return []
		},

		/**
		 * Build the table column definitions for a widget.
		 *
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array<object>}
		 */
		tableColumns(widget) {
			const cols = widget.options?.columns
			if (Array.isArray(cols) && cols.length > 0) {
				return cols.map((c) => ({ key: c.key, label: c.label || c.key, sortable: true }))
			}
			// Default: try to infer from the first row.
			return [
				{ key: 'key', label: t('openregister', 'Key'), sortable: true },
				{ key: 'value', label: t('openregister', 'Value'), sortable: true },
			]
		},

		/**
		 * Build the sparkline value series from widget data.
		 *
		 * @param {object} data The widget data.
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array<number>}
		 */
		sparklineData(data, widget) {
			if (!data) return []
			const field = widget.options?.trendField || 'groups'
			const arr = Array.isArray(data[field]) ? data[field] : (Array.isArray(data) ? data : [])
			const valueField = widget.options?.valueField || 'value'
			return arr.map((entry) => Number(entry[valueField] ?? entry.value ?? entry.count ?? entry))
		},

		/**
		 * Build the sparkline x-axis categories from widget data.
		 *
		 * @param {object} data The widget data.
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array<string>}
		 */
		sparklineCategories(data) {
			if (!data || !Array.isArray(data.groups)) return []
			return data.groups.map((g) => String(g.key ?? ''))
		},

		/**
		 * Build the label/value entries for a stats widget.
		 *
		 * @param {object} data The widget data.
		 * @param {object} widget The widget definition.
		 * @spec exclude UI plumbing — pure presentation data mapping
		 * @return {Array<object>}
		 */
		statsEntries(data, widget) {
			if (!data) return []
			const valueField = widget.options?.valueField || 'value'
			if (Array.isArray(data.groups)) {
				return data.groups.map((g) => ({
					label: String(g.key ?? g.label ?? ''),
					value: this.formatValue(g[valueField] ?? g.value ?? g.count ?? 0, widget),
				}))
			}
			// Fall back to top-level numeric fields. Includes the
			// AggregationRunner's flat-output keys (name, metric, value).
			const candidates = ['name', 'metric', 'value', 'count', 'sum', 'avg', 'min', 'max', 'count_distinct']
			return candidates.filter((k) => k in data && data[k] !== null && data[k] !== undefined).map((k) => ({
				label: k,
				value: (typeof data[k] === 'number') ? this.formatValue(data[k], widget) : String(data[k]),
			}))
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.viewHeader {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.viewHeaderTitle {
	display: flex;
	align-items: center;
	gap: 12px;
}

.viewHeaderTitleIndented {
	margin: 0;
	flex: 1;
}

.reportGrid {
	display: grid;
	grid-template-columns: repeat(var(--report-cols, 4), 1fr);
	gap: 16px;
	align-items: start;
}

.reportWidget {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	min-height: 120px;
	display: flex;
	flex-direction: column;
}

.reportWidgetHeader h3 {
	margin: 0 0 4px 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.reportWidgetSubtitle {
	margin: 0 0 12px 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.reportWidgetBody {
	flex: 1;
	display: flex;
	align-items: center;
}

.widgetLoading,
.widgetError,
.widgetUnknown {
	width: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}

.widgetError {
	color: var(--color-error);
}

.widgetKpi {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
}

.widgetKpiIcon {
	color: var(--color-primary);
}

.widgetKpiValue {
	font-size: 32px;
	font-weight: 700;
	line-height: 1;
}

.widgetKpiLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.widgetTile {
	width: 100%;
	text-align: center;
}

.widgetTileValue {
	font-size: 28px;
	font-weight: 700;
}

.widgetTileLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.widgetStats {
	width: 100%;
}

.widgetStats dl {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.widgetStatsRow {
	display: grid;
	grid-template-columns: 1fr auto;
	gap: 12px;
}

.widgetStats dt {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: capitalize;
}

.widgetStats dd {
	margin: 0;
	font-weight: 600;
	font-family: monospace;
}
</style>
