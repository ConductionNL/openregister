<script setup>
import { dashboardStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="dashboardContent">
			<div v-if="dashboardStore.loading" class="error">
				<NcEmptyContent name="Loading" description="Loading dashboard data...">
					<template #icon>
						<NcLoadingIcon :size="64" />
					</template>
				</NcEmptyContent>
			</div>
			<div v-else-if="dashboardStore.error" class="error">
				<NcEmptyContent name="Error" :description="dashboardStore.error">
					<template #icon>
						<AlertCircle :size="64" />
					</template>
				</NcEmptyContent>
			</div>
			<div v-else class="chartsContainer">
				<!-- Audit Trail Actions Chart -->
				<div class="chartCard">
					<h3>Audit Trail Actions</h3>
					<apexchart
						type="line"
						height="350"
						:options="auditTrailChartOptions"
						:series="dashboardStore.chartData.auditTrailActions?.series || []" />
				</div>

				<!-- Objects by Register Chart -->
				<div class="chartCard">
					<h3>Objects by Register</h3>
					<apexchart
						type="pie"
						height="350"
						:options="registerChartOptions"
						:series="dashboardStore.chartData.objectsByRegister?.series || []"
						:labels="dashboardStore.chartData.objectsByRegister?.labels || []" />
				</div>

				<!-- Objects by Schema Chart -->
				<div class="chartCard">
					<h3>Objects by Schema</h3>
					<apexchart
						type="pie"
						height="350"
						:options="schemaChartOptions"
						:series="dashboardStore.chartData.objectsBySchema?.series || []"
						:labels="dashboardStore.chartData.objectsBySchema?.labels || []" />
				</div>

				<!-- Objects by Size Chart -->
				<div class="chartCard">
					<h3>Objects by Size Distribution</h3>
					<apexchart
						type="bar"
						height="350"
						:options="sizeChartOptions"
						:series="[{ name: 'Objects', data: dashboardStore.chartData.objectsBySize?.series || [] }]" />
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		apexchart: VueApexCharts,
		AlertCircle,
	},
	data() {
		return {
			expandedSchemas: [],
			calculating: null,
			showSchemas: {},
			auditTrailChartOptions: {
				chart: {
					type: 'line',
					toolbar: {
						show: true,
					},
					zoom: {
						enabled: true,
					},
				},
				xaxis: {
					categories: [],
					title: {
						text: 'Date',
					},
				},
				yaxis: {
					title: {
						text: 'Number of Actions',
					},
				},
				colors: ['#41B883', '#E46651', '#00D8FF'],
				stroke: {
					curve: 'smooth',
					width: 2,
				},
				legend: {
					position: 'top',
				},
				theme: {
					mode: 'light',
				},
			},
			registerChartOptions: {
				chart: {
					type: 'pie',
				},
				labels: dashboardStore.chartData.objectsByRegister?.labels || [],
				legend: {
					position: 'bottom',
				},
				responsive: [{
					breakpoint: 480,
					options: {
						chart: {
							width: 200,
						},
						legend: {
							position: 'bottom',
						},
					},
				}],
			},
			schemaChartOptions: {
				chart: {
					type: 'pie',
				},
				labels: dashboardStore.chartData.objectsBySchema?.labels || [],
				legend: {
					position: 'bottom',
				},
				responsive: [{
					breakpoint: 480,
					options: {
						chart: {
							width: 200,
						},
						legend: {
							position: 'bottom',
						},
					},
				}],
			},
			sizeChartOptions: {
				chart: {
					type: 'bar',
				},
				plotOptions: {
					bar: {
						horizontal: false,
						columnWidth: '55%',
						endingShape: 'rounded',
					},
				},
				xaxis: {
					categories: [],
					title: {
						text: 'Size Range',
					},
				},
				yaxis: {
					title: {
						text: 'Number of Objects',
					},
				},
				fill: {
					opacity: 1,
				},
			},
		}
	},
	computed: {
		filteredRegisters() {
			return dashboardStore.registers.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		isSchemaExpanded() {
			return (schemaId) => this.expandedSchemas.includes(schemaId)
		},
		isSchemasVisible() {
			return (registerId) => this.showSchemas[registerId] || false
		},
	},
	watch: {
		'dashboardStore.chartData.auditTrailActions'(newVal) {
			if (newVal) {
				this.auditTrailChartOptions.xaxis.categories = newVal.labels || []
			}
		},
		'dashboardStore.chartData.objectsByRegister'(newVal) {
			if (newVal) {
				this.registerChartOptions.labels = newVal.labels || []
			}
		},
		'dashboardStore.chartData.objectsBySchema'(newVal) {
			if (newVal) {
				this.schemaChartOptions.labels = newVal.labels || []
			}
		},
		'dashboardStore.chartData.objectsBySize'(newVal) {
			if (newVal) {
				this.sizeChartOptions.xaxis.categories = newVal.labels || []
			}
		},
	},
	mounted() {
		dashboardStore.preload()
		dashboardStore.fetchAllChartData()
	},
	methods: {
		toggleSchema(schemaId) {
			const index = this.expandedSchemas.indexOf(schemaId)
			if (index > -1) {
				this.expandedSchemas.splice(index, 1)
			} else {
				this.expandedSchemas.push(schemaId)
			}

			// Force reactivity update
			this.expandedSchemas = [...this.expandedSchemas]
		},

		async calculateSizes(register) {
			// Set the active register in the store
			registerStore.setRegisterItem(register)

			// Set the calculating state for this register
			this.calculating = register.id
			try {
				// Call the dashboard store to calculate sizes
				await dashboardStore.calculateSizes(register.id)
				// Refresh the registers list to get updated sizes
				await dashboardStore.fetchRegisters()
			} catch (error) {
				console.error('Error calculating sizes:', error)
				showError(t('openregister', 'Failed to calculate sizes'))
			} finally {
				this.calculating = null
			}
		},

		async downloadOas(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			try {
				const response = await axios.get(apiUrl)
				const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
				const downloadLink = document.createElement('a')
				downloadLink.href = URL.createObjectURL(blob)
				downloadLink.download = `${register.title.toLowerCase()}-api-specification.json`
				document.body.appendChild(downloadLink)
				downloadLink.click()
				document.body.removeChild(downloadLink)
				URL.revokeObjectURL(downloadLink.href)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		viewOasDoc(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},

		toggleSchemaVisibility(registerId) {
			this.$set(this.showSchemas, registerId, !this.showSchemas[registerId])
		},

		openAllApisDoc() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/apps/openregister/api/registers/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},
	},
}
</script>

<style lang="scss" scoped>
.pageHeaderContainer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0;
}

.pageHeader {
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, Oxygen-Sans, Cantarell, Ubuntu, "Helvetica Neue", "Noto Sans", "Liberation Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
	font-size: 30px;
	font-weight: 600;
	margin-left: 50px;
}

/* Add styles for the action buttons container */
:deep(.button-vue) {
	margin-top: 15px;
	margin-right: 15px;
	padding-right: 15px;
}

.dashboardContent {
	margin-inline: auto;
	max-width: 1200px;
	padding-block: 20px;
	padding-inline: 20px;
}

.chartsContainer {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	padding: 20px;
}

.chartCard {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	border: 1px solid var(--color-border);

	h3 {
		margin: 0 0 20px 0;
		font-size: 1.2em;
		color: var(--color-main-text);
	}
}

@media screen and (max-width: 1024px) {
	.chartsContainer {
		grid-template-columns: 1fr;
	}
}
</style>
