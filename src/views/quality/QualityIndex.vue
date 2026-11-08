<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<h1>{{ t('openregister', 'Data Quality') }}</h1>
				<p>
					{{
						t(
							'openregister',
							'Review score distribution and the lowest-quality objects for a register and schema.',
						)
					}}
				</p>
			</div>

			<RegisterSchemaSelector />

			<NcEmptyContent
				v-if="!hasSelection"
				:name="t('openregister', 'Select a register and schema')"
				:description="
					t(
						'openregister',
						'Choose a register and schema above to see its quality statistics.',
					)
				">
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
					:description="
						t(
							'openregister',
							'This schema has no objects with a materialised quality score yet.',
						)
					">
					<template #icon>
						<ChartBoxOutline :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<!-- KPI cards -->
				<div class="kpiRow">
					<div class="kpiCard">
						<span class="kpiLabel">{{
							t('openregister', 'Average score')
						}}</span>
						<span class="kpiValue">{{ formattedAverage }}</span>
					</div>
					<div class="kpiCard kpiGood">
						<span class="kpiLabel">{{ t('openregister', 'Good') }}</span>
						<span class="kpiValue">{{
							qualityStats.buckets?.good ?? 0
						}}</span>
					</div>
					<div class="kpiCard kpiFair">
						<span class="kpiLabel">{{ t('openregister', 'Fair') }}</span>
						<span class="kpiValue">{{
							qualityStats.buckets?.fair ?? 0
						}}</span>
					</div>
					<div class="kpiCard kpiPoor">
						<span class="kpiLabel">{{ t('openregister', 'Poor') }}</span>
						<span class="kpiValue">{{
							qualityStats.buckets?.poor ?? 0
						}}</span>
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
					<CnDataTable
						v-else
						data-testid="histogram-fallback-table"
						:columns="histogramColumns"
						:rows="histogramRows" />
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
					<CnDataTable v-else :columns="lowQualityColumns" :rows="lowQualityObjects">
						<template #column-qualityStatus="{ row }">
							<span :class="'badge badge-status-' + row.qualityStatus">{{ row.qualityStatus }}</span>
						</template>
					</CnDataTable>
					<RangePager
						:offset="lowQualityOffset"
						:limit="lowQualityLimit"
						:total="lowQualityTotal"
						@previous="previousPage"
						@next="nextPage" />
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { CnChartWidget, CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import RangePager from './RangePager.vue'
import RegisterSchemaSelector from './RegisterSchemaSelector.vue'
import { qualityStore } from '../../store/store.js'
import { qualitySelection } from './qualitySelection.js'

export default {
	name: 'QualityIndex',

	components: {
		RangePager,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		CnChartWidget,
		CnDataTable,
		ChartBoxOutline,
		RegisterSchemaSelector,
	},

	mixins: [qualitySelection],

	data() {
		return {
			chartAvailable: true,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		qualityStats() {
			return qualityStore.qualityStats || {}
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		isEmpty() {
			return (this.qualityStats.total ?? 0) === 0
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		formattedAverage() {
			const avg = this.qualityStats.average
			return typeof avg === 'number' ? avg.toFixed(2) : (avg ?? '—')
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		histogram() {
			return this.qualityStats.histogram ?? []
		},

		/**
		 * @spec exclude UI plumbing — derives histogram bucket labels for the chart/table, no backend contract of its own
		 */
		histogramCategories() {
			return this.histogram.map(
				(_, index) => `${index * 10}-${(index + 1) * 10}`,
			)
		},

		/**
		 * @spec exclude UI plumbing — shapes histogram data for the CnChartWidget series prop, no backend contract of its own
		 */
		histogramSeries() {
			return [{ name: t('openregister', 'Objects'), data: this.histogram }]
		},

		/**
		 * @spec exclude UI plumbing — static column definitions for the histogram fallback table
		 * @return {Array<object>}
		 */
		histogramColumns() {
			return [
				{ key: 'bucket', label: t('openregister', 'Bucket') },
				{ key: 'count', label: t('openregister', 'Count') },
			]
		},

		/**
		 * @spec exclude UI plumbing — one fallback-table row per histogram bucket
		 * @return {Array<object>}
		 */
		histogramRows() {
			return this.histogram.map((count, index) => ({
				id: index,
				bucket: this.histogramCategories[index],
				count,
			}))
		},

		/**
		 * @spec exclude UI plumbing — static column definitions for the lowest-quality table
		 * @return {Array<object>}
		 */
		lowQualityColumns() {
			return [
				{ key: 'id', label: t('openregister', 'Id') },
				{ key: 'qualityScore', label: t('openregister', 'Quality score') },
				{ key: 'qualityStatus', label: t('openregister', 'Quality status') },
			]
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		lowQualityObjects() {
			return qualityStore.lowQualityObjects
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		lowQualityTotal() {
			return qualityStore.lowQualityTotal
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		lowQualityLimit() {
			return qualityStore.lowQualityLimit
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		lowQualityOffset() {
			return qualityStore.lowQualityOffset
		},
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
				qualityStore.fetchQualityStats(
					qualityStore.selectedRegister,
					qualityStore.selectedSchema,
				),
				qualityStore.fetchLowQualityObjects(
					qualityStore.selectedRegister,
					qualityStore.selectedSchema,
					{ limit: 20, offset: 0 },
				),
			])
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchLowQualityObjects
		 */
		async previousPage() {
			const offset = Math.max(0, this.lowQualityOffset - this.lowQualityLimit)
			await qualityStore.fetchLowQualityObjects(
				qualityStore.selectedRegister,
				qualityStore.selectedSchema,
				{ limit: this.lowQualityLimit, offset },
			)
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchLowQualityObjects
		 */
		async nextPage() {
			const offset = this.lowQualityOffset + this.lowQualityLimit
			await qualityStore.fetchLowQualityObjects(
				qualityStore.selectedRegister,
				qualityStore.selectedSchema,
				{ limit: this.lowQualityLimit, offset },
			)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include view-container-3-subtitled;

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

@include pagination-2-rules;
</style>
