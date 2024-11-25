<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Dashboard
		</h2>

		<div class="dashboard-content">
			<!-- Statistics Cards -->
			<div class="stats">
				<div v-for="(stat, key) in statsConfig" 
					:key="key"
					@click="navigateTo(key)"
					class="stat-card">
					<h5>{{ stat.label }}</h5>
					<div class="content">
						<NcLoadingIcon v-if="isLoading" :size="44" />
						<template v-else>
							{{ stats[key] || 0 }}
						</template>
					</div>
				</div>
			</div>

			<!-- Date Range Selector -->
			<div class="date-range-selector">
				<div class="date-picker">
					<label>From:</label>
					<NcDateTimePicker
						v-model="dateRange.from"
						:max-date="dateRange.to"
						:show-time="true"
						@change="handleDateChange"
					/>
				</div>
				<div class="date-picker">
					<label>To:</label>
					<NcDateTimePicker
						v-model="dateRange.to"
						:min-date="dateRange.from"
						:max-date="new Date()"
						:show-time="true"
						@change="handleDateChange"
					/>
				</div>
			</div>

			<!-- Audit Trail Graphs -->
			<div class="graph-section">
				<h3>Object Mutations</h3>
				<div class="graphs">
					<div class="graph-container">
						<h5>Daily Object Changes</h5>
						<div class="content">
							<apexchart
								width="100%"
								:options="objectChanges.options"
								:series="objectChanges.series"
							/>
						</div>
					</div>
					<div class="graph-container">
						<h5>Mutations by Operation Type</h5>
						<div class="content">
							<apexchart
								width="100%"
								:options="operationTypes.options"
								:series="operationTypes.series"
							/>
						</div>
					</div>
				</div>
			</div>

			<!-- New Growth Analysis Section -->
			<div class="graph-section">
				<h3>Growth Analysis</h3>
				<div class="graphs">
					<div>
						<h5>Register Size Over Time</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="registerGrowth.options"
								:series="registerGrowth.series"
							/>
						</div>
					</div>
					<div>
						<h5>Schema Distribution Over Time</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="schemaDistribution.options"
								:series="schemaDistribution.series"
							/>
						</div>
					</div>
				</div>
			</div>

			<!-- Add after the Growth Analysis section -->
			<div class="graph-section">
				<h3>Data Quality Analysis</h3>
				<div class="graphs">
					<div class="graph-container">
						<h5>Validation Errors Over Time</h5>
						<div class="content">
							<apexchart
								width="100%"
								:options="validationErrors.options"
								:series="validationErrors.series"
							/>
						</div>
					</div>
					<div class="graph-container">
						<h5>Field Completeness by Schema</h5>
						<div class="content">
							<apexchart
								width="100%"
								:options="fieldCompleteness.options"
								:series="fieldCompleteness.series"
							/>
						</div>
					</div>
					<div class="graph-container">
						<h5>Object Revisions Over Time</h5>
						<div class="content">
							<apexchart
								width="100%"
								:options="objectRevisions.options"
								:series="objectRevisions.series"
							/>
						</div>
					</div>
				</div>
			</div>

			<div class="graph-section">
				<h3>Schema Analysis</h3>
				<div class="graphs">
					<div class="graph-container">
						<h5>Field Types Distribution</h5>
						<div class="content">
							<apexchart
								width="100%"
								:options="fieldTypes.options"
								:series="fieldTypes.series"
							/>
						</div>
					</div>
					<div class="graph-container">
						<h5>Schema Complexity</h5>
						<div class="content">
							<apexchart
								width="100%"
								:options="schemaComplexity.options"
								:series="schemaComplexity.series"
							/>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcLoadingIcon, NcDateTimePicker } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getTheme } from '../../services/getTheme.js'

/**
 * @component DashboardIndex
 * @description Dashboard component showing statistics and graphs for the OpenRegister app
 */
export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcLoadingIcon,
		NcDateTimePicker,
		apexchart: VueApexCharts,
	},
	data() {
		const to = new Date()
		const from = new Date()
		from.setDate(from.getDate() - 7)

		return {
			isLoading: true,
			stats: {
				registers: 0,
				schemas: 0,
				objects: 0,
				auditLogs: 0,
			},
			statsConfig: {
				registers: { label: 'Registers' },
				schemas: { label: 'Schemas' },
				objects: { label: 'Objects' },
				auditLogs: { label: 'Audit Logs' },
			},
			dateRange: {
				from,
				to,
			},
			objectChanges: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'area',
						stacked: true,
						height: 350,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
					},
					colors: ['#46ba61', '#0082c9', '#e9322d'],
					title: {
						text: 'Daily Object Changes',
						align: 'left',
					},
				},
				series: [
					{
						name: 'Created',
						data: [],
					},
					{
						name: 'Updated',
						data: [],
					},
					{
						name: 'Deleted',
						data: [],
					},
				],
			},
			operationTypes: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'pie',
						height: 350,
					},
					labels: ['Created', 'Updated', 'Deleted'],
					colors: ['#46ba61', '#0082c9', '#e9322d'],
					title: {
						text: 'Mutations by Operation Type',
						align: 'left',
					},
				},
				series: [0, 0, 0],
			},
			registerGrowth: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'line',
						height: 350,
					},
					stroke: {
						curve: 'smooth',
						width: 2,
					},
					xaxis: {
						type: 'datetime',
						labels: {
							format: 'dd MMM',
						},
					},
					yaxis: {
						title: {
							text: 'Number of Objects',
						},
						min: 0,
					},
					colors: ['#0082c9'], // Nextcloud blue
					title: {
						text: 'Register Growth',
							align: 'left',
					},
					legend: {
						position: 'top',
					},
				},
				series: [], // Will be populated with data per register
			},
			schemaDistribution: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'area',
						stacked: true,
					},
					stroke: {
						curve: 'smooth',
						width: 1,
					},
					xaxis: {
						type: 'datetime',
					},
					yaxis: {
						title: {
							text: 'Objects per Schema',
						},
					},
					title: {
						text: 'Schema Distribution',
						align: 'left',
					},
				},
				series: [], // Will be populated with data per schema
			},
			validationErrors: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'area',
						stacked: true,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
					},
					colors: ['#46ba61'],
					title: {
						text: 'Validation Errors Over Time',
						align: 'left',
					},
				},
				series: [],
			},
			fieldCompleteness: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'area',
						stacked: true,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
					},
					colors: ['#0082c9'],
					title: {
						text: 'Field Completeness by Schema',
						align: 'left',
					},
				},
				series: [],
			},
			fieldTypes: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'pie',
						height: 350,
					},
					labels: ['String', 'Number', 'Boolean', 'Date', 'Object'],
					colors: ['#46ba61', '#0082c9', '#e9322d', '#f39c12', '#d35400'],
					title: {
						text: 'Field Types Distribution',
						align: 'left',
					},
				},
				series: [],
			},
			schemaComplexity: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'area',
						stacked: true,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
					},
					colors: ['#0082c9'],
					title: {
						text: 'Schema Complexity',
						align: 'left',
					},
				},
				series: [],
			},
			objectRevisions: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						type: 'line',
						height: 350,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
					},
					yaxis: [
						{
							title: {
								text: 'Number of Objects',
							},
						},
						{
							opposite: true,
							title: {
								text: 'Average Revisions',
							},
						},
					],
					colors: ['#46ba61', '#0082c9'], // Green for objects, Blue for revisions
					title: {
						text: 'Object Revisions Over Time',
						align: 'left',
					},
				},
				series: [
					{
						name: 'Objects',
						type: 'column',
						data: [],
					},
					{
						name: 'Average Revisions',
						type: 'line',
						data: [],
					},
				],
			},
		}
	},
	async mounted() {
		await this.fetchAllStats()
	},
	methods: {
		/**
		 * Fetches all dashboard statistics
		 * @returns {Promise<void>}
		 */
		async fetchAllStats() {
			this.isLoading = true
			try {
				await Promise.all([
					this.fetchStats(),
					this.fetchAuditStats(),
					this.fetchGrowthStats(),
					this.fetchQualityStats(),
					this.fetchSchemaAnalysis(),
				])
			} finally {
				this.isLoading = false
			}
		},

		/**
		 * Fetches basic statistics from the backend
		 * @returns {Promise<void>}
		 */
		async fetchStats() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/stats'))
				this.stats = response.data
			} catch (error) {
				console.error('Error fetching stats:', error)
			}
		},

		/**
		 * Fetches audit trail statistics
		 * @returns {Promise<void>}
		 */
		async fetchAuditStats() {
			try {
				const params = {
					from: this.dateRange.from.toISOString(),
					to: this.dateRange.to.toISOString(),
				}
				const response = await axios.get(
					generateUrl('/apps/openregister/api/dashboard/audit-stats'),
					{ params }
				)
				this.updateAuditGraphs(response.data)
			} catch (error) {
				console.error('Error fetching audit stats:', error)
			}
		},

		/**
		 * Updates the audit graphs with new data
		 * @param {Object} data - The audit statistics data
		 */
		updateAuditGraphs(data) {
			// Update daily changes graph
			this.objectChanges.series = [
				{
					name: 'Created',
					data: this.formatTimeseriesData(data.daily.created),
				},
				{
					name: 'Updated',
					data: this.formatTimeseriesData(data.daily.updated),
				},
				{
					name: 'Deleted',
					data: this.formatTimeseriesData(data.daily.deleted),
				},
			]

			// Update operation types pie chart
			this.operationTypes.series = [
				data.totals.created || 0,
				data.totals.updated || 0,
				data.totals.deleted || 0,
			]
		},

		/**
		 * Formats timeseries data for ApexCharts
		 * @param {Object} data - The raw timeseries data
		 * @returns {Array} Formatted data for ApexCharts
		 */
		formatTimeseriesData(data) {
			if (!data || typeof data !== 'object') {
				return [];
			}

			return Object.entries(data).map(([date, value]) => ({
				x: new Date(date).getTime(),
				y: value || 0,
			})).filter(point => !isNaN(point.x));
		},

		/**
		 * Handles date range changes
		 */
		async handleDateChange() {
			await this.fetchAuditStats()
		},

		/**
		 * Navigates to a specific section
		 * @param {string} section - The section to navigate to
		 */
		navigateTo(section) {
			// Implementation depends on your routing setup
			console.log('Navigate to:', section)
		},

		/**
		 * Fetches growth statistics
		 * @returns {Promise<void>}
		 */
		async fetchGrowthStats() {
			try {
				const params = {
					from: this.dateRange.from.toISOString(),
					to: this.dateRange.to.toISOString(),
				}
				const response = await axios.get(
					generateUrl('/apps/openregister/api/dashboard/growth-stats'),
					{ params }
				)
				this.updateGrowthGraphs(response.data)
			} catch (error) {
				console.error('Error fetching growth stats:', error)
			}
		},

		/**
		 * Updates the growth graphs with new data
		 * @param {Object} data - The growth statistics data
		 */
		updateGrowthGraphs(data) {
			// Update register growth graph - handle multiple registers
			this.registerGrowth.series = data.registerGrowth.map(register => ({
				name: register.name,
				data: this.formatTimeseriesData(register.data),
			}))

			// Update schema distribution graph
			this.schemaDistribution.series = data.schemaDistribution.map(schema => ({
				name: schema.name,
				data: this.formatTimeseriesData(schema.data),
				}))
		},

		async fetchQualityStats() {
			try {
				const params = {
					from: this.dateRange.from.toISOString(),
					to: this.dateRange.to.toISOString(),
				}
				const response = await axios.get(
					generateUrl('/apps/openregister/api/dashboard/quality-stats'),
					{ params }
				)
				this.updateQualityGraphs(response.data)
			} catch (error) {
				console.error('Error fetching quality stats:', error)
			}
		},

		updateQualityGraphs(data) {
			console.log('Quality Stats Data:', data); // Debug log

			// Update validation errors graph
			if (data.validationErrors && data.validationErrors.daily) {
				this.validationErrors.series = [
					{
						name: 'Total Objects',
						data: this.formatTimeseriesData(data.validationErrors.daily.total),
					},
					{
						name: 'Error Rate',
						data: this.formatTimeseriesData(data.validationErrors.daily.error_rate),
					},
				];
			}

			// Update field completeness graph
			if (data.completeness && data.completeness.schemas) {
				const completenessData = Object.entries(data.completeness.schemas).map(([name, stats]) => ({
					x: name,
					y: stats.average_completeness || 0,
				}));

				this.fieldCompleteness.series = [{
					name: 'Completeness Rate',
					data: completenessData,
				}];
			}

			// Update object revisions graph
			if (data.revisions && data.revisions.daily) {
				const { objects, avg_revisions } = data.revisions.daily;
				
				this.objectRevisions.series = [
					{
						name: 'Objects',
						type: 'column',
						data: objects ? this.formatTimeseriesData(objects) : [],
					},
					{
						name: 'Average Revisions',
						type: 'line',
						data: avg_revisions ? this.formatTimeseriesData(avg_revisions) : [],
					},
				];
			}
		},

		async fetchSchemaAnalysis() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/dashboard/schema-analysis')
				)
				this.updateSchemaGraphs(response.data)
			} catch (error) {
				console.error('Error fetching schema analysis:', error)
			}
		},

		updateSchemaGraphs(data) {
			// Safely update field types pie chart
			if (data && data.fieldTypes && Array.isArray(data.fieldTypes)) {
				this.fieldTypes.series = data.fieldTypes.map(type => type.value || 0);
				this.fieldTypes.options.labels = data.fieldTypes.map(type => type.name || 'Unknown');
			} else {
				// Set default values if no data
				this.fieldTypes.series = [0, 0, 0, 0, 0];
				this.fieldTypes.options.labels = ['String', 'Number', 'Boolean', 'Date', 'Object'];
			}

			// Safely update schema complexity chart
			if (data && Array.isArray(data.complexity)) {
				const complexityData = data.complexity.map(schema => ({
					x: schema.name || 'Unknown Schema',
					y: schema.value || 0,
				}));

				this.schemaComplexity.series = [{
					name: 'Complexity Score',
					data: complexityData,
				}];

				// Update categories
				this.schemaComplexity.options.xaxis = {
					categories: data.complexity.map(schema => schema.name || 'Unknown Schema'),
				};
			} else {
				// Set empty state
				this.schemaComplexity.series = [{
					name: 'Complexity Score',
					data: [],
				}];
				this.schemaComplexity.options.xaxis = {
					categories: [],
				};
			}

			// Add tooltips for complexity chart
			if (data && Array.isArray(data.complexity)) {
				this.schemaComplexity.options.tooltip = {
					custom: function({ seriesIndex, dataPointIndex, w }) {
						const schema = data.complexity[dataPointIndex];
						if (!schema) return '';
						
						return `
							<div class="custom-tooltip">
								<strong>${schema.name || 'Unknown Schema'}</strong><br/>
								Complexity Score: ${schema.value || 0}<br/>
								Total Fields: ${schema.fields || 0}<br/>
								Required Fields: ${schema.required || 0}<br/>
								Max Depth: ${schema.depth || 0}
							</div>
						`;
					}
				};
			}
		}
	},
}
</script>

<style>
.apexcharts-svg {
    background-color: transparent !important;
}

.dashboard-content {
    margin-inline: auto;
    max-width: 1000px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
.dashboard-content > * {
    margin-block-end: 4rem;
}

/* default theme */
@media (prefers-color-scheme: light) {
    :root {
        --dashboard-item-background-color: rgba(0, 0, 0, 0.07);
    }
}
@media (prefers-color-scheme: dark) {
    :root {
        --dashboard-item-background-color: rgba(255, 255, 255, 0.1);
    }
}
/* do theme checks, light mode | dark mode */
:root:has(body[data-theme-light]) {
    --dashboard-item-background-color: rgba(0, 0, 0, 0.07);
}
:root:has(body[data-theme-dark]) {
    --dashboard-item-background-color: rgba(255, 255, 255, 0.1);
}

/* most searched terms */
.dashboard-content > .stats {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}
@media screen and (min-width: 880px) {
    .dashboard-content > .stats {
        grid-template-columns: 1fr 1fr;
    }
}
@media screen and (min-width: 1024px) {
    .dashboard-content > .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media screen and (min-width: 1220px) {
    .dashboard-content > .stats {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media screen and (min-width: 1590px) {
    .dashboard-content > .stats {
        grid-template-columns: repeat(3, 1fr);
    }
}
.dashboard-content > .stats > div {
    padding: 1rem;
    height: 150px;
    width: 250px;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s ease-in-out;
}

.dashboard-content > .stats > div:hover {
    transform: scale(1.02);
}

/* default theme */
@media (prefers-color-scheme: light) {
    .dashboard-content > .stats > div {
        background-color: rgba(0, 0, 0, 0.07);
    }
}
@media (prefers-color-scheme: dark) {
    .dashboard-content > .stats > div {
        background-color: rgba(255, 255, 255, 0.1);
    }
}
/* do theme checks, light mode | dark mode */
body[data-theme-light] .dashboard-content > .stats > div {
    background-color: rgba(0, 0, 0, 0.07);
}
body[data-theme-dark] .dashboard-content > .stats > div {
    background-color: rgba(255, 255, 255, 0.1);
}
.dashboard-content > .stats > div > h5 {
    margin: 0;
    font-weight: normal;
}
.dashboard-content > .stats > div > .content {
    display: flex;
    justify-content: center;
    align-items: center;
    height: calc(100% - 40px);

    font-size: 3.5rem;
}

/* Update the graph section styling */
.graph-section {
    width: 100%;
    margin-bottom: 4rem;
}

.graph-section > h3 {
    margin-bottom: 1rem;
    text-align: center;
}

/* Update the graphs container styling */
.dashboard-content > .graph-section > .graphs {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    width: 100%;
    justify-content: center;
    align-items: stretch;
}

.dashboard-content > .graph-section > .graphs > div {
    flex: 1;
    min-width: 300px;
    max-width: calc(50% - 1rem);
}

.graph-container {
    flex: 1;
    min-width: 300px;
    max-width: calc(50% - 1rem);
}

.graph-container .content {
    height: 350px;
}

/* On smaller screens (mobile) */
@media screen and (max-width: 768px) {
    .dashboard-content > .graph-section > .graphs {
        flex-direction: column;
        align-items: center;
    }
    
    .dashboard-content > .graph-section > .graphs > div {
        width: 100%;
        max-width: 100%;
    }
}

/* Remove the old .graphs styles that were causing the issue */
.dashboard-content > .graphs {
    display: none;
}

/* Add these new styles for the loading state */
.dashboard-content > .stats > div > .content {
	display: flex;
	justify-content: center;
	align-items: center;
	height: calc(100% - 40px);
	font-size: 3.5rem;
}

/* Adjust the loading icon size and color to match the theme */
.dashboard-content > .stats .icon-loading {
	width: 44px;
	height: 44px;
}

.clickable {
    cursor: pointer;
}

.date-range-selector {
	display: flex;
	gap: 2rem;
	margin: 2rem 0;
	padding: 1rem;
	width: 100%;
	justify-content: center;
	background-color: var(--dashboard-item-background-color);
	border-radius: 8px;
}

.date-picker {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.date-picker label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

/* Make date picker more visible */
:deep(.mx-input) {
	height: 34px;
	padding: 6px 12px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
}

:deep(.mx-input:hover),
:deep(.mx-input:focus) {
	border-color: var(--color-primary);
}
</style>
