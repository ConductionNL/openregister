<template>
	<div class="chartGrid">
		<!-- Audit Trail Actions Chart -->
		<div class="chartCard">
			<h3>Audit Trail Actions</h3>
			<VueApexCharts
				type="line"
				height="350"
				:options="auditOptions"
				:series="chartData?.auditTrailActions?.series || []" />
		</div>

		<!-- Objects by Schema / Register Chart -->
		<div class="chartCard">
			<h3>{{ distributionTitle }}</h3>
			<VueApexCharts
				type="pie"
				height="350"
				:options="distributionOptions"
				:series="chartData?.[distributionKey]?.series || []"
				:labels="chartData?.[distributionKey]?.labels || []" />
		</div>

		<!-- Objects by Size Chart -->
		<div class="chartCard">
			<h3>Objects by Size Distribution</h3>
			<VueApexCharts
				type="bar"
				height="350"
				:options="sizeOptions"
				:series="sizeSeries" />
		</div>
	</div>
</template>

<script>
import VueApexCharts from 'vue3-apexcharts'

/**
 * The three dashboard charts of a register or schema detail page: audit-trail
 * actions, the objects distribution and the objects by size.
 */
export default {
	name: 'DetailChartGrid',
	components: {
		VueApexCharts,
	},

	props: {
		/** The dashboard store's chart data. */
		chartData: {
			type: Object,
			default: null,
		},

		/** The chart data key of the distribution pie chart. */
		distributionKey: {
			type: String,
			required: true,
		},

		/** The heading of the distribution pie chart. */
		distributionTitle: {
			type: String,
			required: true,
		},

		/** The options of the audit-trail line chart. */
		auditOptions: {
			type: Object,
			required: true,
		},

		/** The options of the distribution pie chart. */
		distributionOptions: {
			type: Object,
			required: true,
		},

		/** The options of the objects-by-size bar chart. */
		sizeOptions: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/**
		 * The single series of the objects-by-size bar chart.
		 *
		 * @spec exclude UI plumbing — chart series computed for display
		 * @return {Array<object>}
		 */
		sizeSeries() {
			return [
				{
					name: 'Objects',
					data: this.chartData?.objectsBySize?.series || [],
				},
			]
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/views.scss' as *;

@include chart-card;
</style>
