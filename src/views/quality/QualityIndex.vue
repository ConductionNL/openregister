<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<h1>{{ t('openregister', 'Data Quality') }}</h1>
				<p>{{ t('openregister', 'Review score distribution and the lowest-quality objects for a register and schema.') }}</p>
			</div>

			<RegisterSchemaSelector />

			<NcEmptyContent
				v-if="!hasSelection"
				:name="t('openregister', 'Select a register and schema')"
				:description="t('openregister', 'Choose a register and schema above to see its quality statistics.')">
				<template #icon>
					<ChartBoxOutline :size="64" />
				</template>
			</NcEmptyContent>

			<template v-else-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<template v-else-if="isEmpty">
				<NcEmptyContent
					:name="t('openregister', 'No scored objects for this schema')"
					:description="t('openregister', 'This schema has no objects with a materialised quality score yet.')">
					<template #icon>
						<ChartBoxOutline :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<!-- KPI cards -->
				<div class="kpiRow">
					<div class="kpiCard">
						<span class="kpiLabel">{{ t('openregister', 'Average score') }}</span>
						<span class="kpiValue">{{ formattedAverage }}</span>
					</div>
					<div class="kpiCard kpiGood">
						<span class="kpiLabel">{{ t('openregister', 'Good') }}</span>
						<span class="kpiValue">{{ qualityStats.buckets?.good ?? 0 }}</span>
					</div>
					<div class="kpiCard kpiFair">
						<span class="kpiLabel">{{ t('openregister', 'Fair') }}</span>
						<span class="kpiValue">{{ qualityStats.buckets?.fair ?? 0 }}</span>
					</div>
					<div class="kpiCard kpiPoor">
						<span class="kpiLabel">{{ t('openregister', 'Poor') }}</span>
						<span class="kpiValue">{{ qualityStats.buckets?.poor ?? 0 }}</span>
					</div>
				</div>

				<!-- Histogram -->
				<section class="histogramSection">
					<h2>{{ t('openregister', 'Score histogram') }}</h2>
					<CnChartWidget
						v-if="chartAvailable"
						type="bar"
						:series="histogramSeries"
						:categories="histogramCategories"
						@error="chartAvailable = false" />
					<table v-else class="histogramTable" data-testid="histogram-fallback-table">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Bucket') }}</th>
								<th>{{ t('openregister', 'Count') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(count, index) in histogram" :key="index">
								<td>{{ histogramCategories[index] }}</td>
								<td>{{ count }}</td>
							</tr>
						</tbody>
					</table>
				</section>

				<!-- Lowest-quality table -->
				<section class="lowestQualitySection">
					<h2>{{ t('openregister', 'Lowest-quality objects') }}</h2>
					<NcEmptyContent
						v-if="lowQualityObjects.length === 0"
						:name="t('openregister', 'No objects to show')">
						<template #icon>
							<ChartBoxOutline :size="48" />
						</template>
					</NcEmptyContent>
					<table v-else class="lowestQualityTable">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Id') }}</th>
								<th>{{ t('openregister', 'Quality score') }}</th>
								<th>{{ t('openregister', 'Quality status') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="item in lowQualityObjects" :key="item.id">
								<td>{{ item.id }}</td>
								<td>{{ item.qualityScore }}</td>
								<td><span :class="'badge badge-status-' + item.qualityStatus">{{ item.qualityStatus }}</span></td>
							</tr>
						</tbody>
					</table>
					<div v-if="lowQualityTotal > lowQualityLimit" class="pagination">
						<NcButton :disabled="lowQualityOffset === 0" @click="previousPage">
							{{ t('openregister', 'Previous') }}
						</NcButton>
						<span class="paginationInfo">
							{{ t('openregister', 'Showing {from}-{to} of {total}', {
								from: lowQualityOffset + 1,
								to: Math.min(lowQualityOffset + lowQualityLimit, lowQualityTotal),
								total: lowQualityTotal,
							}) }}
						</span>
						<NcButton :disabled="lowQualityOffset + lowQualityLimit >= lowQualityTotal" @click="nextPage">
							{{ t('openregister', 'Next') }}
						</NcButton>
					</div>
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcButton } from '@nextcloud/vue'
import { CnChartWidget } from '@conduction/nextcloud-vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import RegisterSchemaSelector from './RegisterSchemaSelector.vue'
import { qualityStore } from '../../store/store.js'

export default {
	name: 'QualityIndex',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		CnChartWidget,
		ChartBoxOutline,
		RegisterSchemaSelector,
	},

	data() {
		return {
			chartAvailable: true,
		}
	},

	computed: {
		/**
		 * Expose the l10n translate helper to the template.
		 *
		 * @spec exclude UI plumbing — template translation helper
		 * @return {Function}
		 */
		t() {
			return t
		},

		/**
		 * @spec exclude UI plumbing — proxies the store's hasSelection getter, no backend contract of its own
		 */
		hasSelection() {
			return qualityStore.hasSelection
		},

		/**
		 * @spec exclude UI plumbing — proxies the store's loading flag, no backend contract of its own
		 */
		loading() {
			return qualityStore.isLoading
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#kpi-cards-and-histogram-reflect-the-stats-envelope
		 */
		qualityStats() {
			return qualityStore.qualityStats || {}
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#empty-state-on-an-unscored-schema
		 */
		isEmpty() {
			return (this.qualityStats.total ?? 0) === 0
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#kpi-cards-and-histogram-reflect-the-stats-envelope
		 */
		formattedAverage() {
			const avg = this.qualityStats.average
			return typeof avg === 'number' ? avg.toFixed(2) : (avg ?? '—')
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#kpi-cards-and-histogram-reflect-the-stats-envelope
		 */
		histogram() {
			return this.qualityStats.histogram ?? []
		},

		/**
		 * @spec exclude UI plumbing — derives histogram bucket labels for the chart/table, no backend contract of its own
		 */
		histogramCategories() {
			return this.histogram.map((_, index) => `${index * 10}-${(index + 1) * 10}`)
		},

		/**
		 * @spec exclude UI plumbing — shapes histogram data for the CnChartWidget series prop, no backend contract of its own
		 */
		histogramSeries() {
			return [{ name: t('openregister', 'Objects'), data: this.histogram }]
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#lowest-quality-table-lists-scored-objects
		 */
		lowQualityObjects() {
			return qualityStore.lowQualityObjects
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#lowest-quality-table-lists-scored-objects
		 */
		lowQualityTotal() {
			return qualityStore.lowQualityTotal
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#lowest-quality-table-lists-scored-objects
		 */
		lowQualityLimit() {
			return qualityStore.lowQualityLimit
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#lowest-quality-table-lists-scored-objects
		 */
		lowQualityOffset() {
			return qualityStore.lowQualityOffset
		},
	},

	/**
	 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#selection-persists-across-mdm-views
	 */
	mounted() {
		if (this.hasSelection) {
			this.loadData()
		}
		this.$watch(
			() => [qualityStore.selectedRegister, qualityStore.selectedSchema],
			() => this.loadData(),
		)
	},

	methods: {
		/**
		 * Load quality stats + lowest-quality listing for the current
		 * selection. No request is issued unless both register and schema
		 * are selected.
		 *
		 * @spec exclude UI data-loading orchestration — delegates to store actions which carry the @spec tags
		 */
		async loadData() {
			if (!qualityStore.hasSelection) return
			this.chartAvailable = true
			await Promise.all([
				qualityStore.fetchQualityStats(qualityStore.selectedRegister, qualityStore.selectedSchema),
				qualityStore.fetchLowQualityObjects(qualityStore.selectedRegister, qualityStore.selectedSchema, { limit: 20, offset: 0 }),
			])
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchLowQualityObjects
		 */
		async previousPage() {
			const offset = Math.max(0, this.lowQualityOffset - this.lowQualityLimit)
			await qualityStore.fetchLowQualityObjects(qualityStore.selectedRegister, qualityStore.selectedSchema, { limit: this.lowQualityLimit, offset })
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchLowQualityObjects
		 */
		async nextPage() {
			const offset = this.lowQualityOffset + this.lowQualityLimit
			await qualityStore.fetchLowQualityObjects(qualityStore.selectedRegister, qualityStore.selectedSchema, { limit: this.lowQualityLimit, offset })
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 1200px;
}

.viewHeader h1 {
	margin-bottom: 4px;
}

.kpiRow {
	display: flex;
	gap: 12px;
	margin: 16px 0;
	flex-wrap: wrap;
}

.kpiCard {
	display: flex;
	flex-direction: column;
	padding: 16px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	min-width: 140px;
}

.kpiLabel {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.kpiValue {
	font-size: 1.8em;
	font-weight: bold;
}

.histogramSection,
.lowestQualitySection {
	margin-top: 24px;
}

.histogramTable,
.lowestQualityTable {
	width: 100%;
	border-collapse: collapse;
}

.histogramTable th,
.histogramTable td,
.lowestQualityTable th,
.lowestQualityTable td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 12px;
}

.badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
}
</style>
