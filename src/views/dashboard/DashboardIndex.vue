<script setup>
import { dashboardStore, registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<span class="pageHeaderContainer">
			<h2 class="pageHeader">
				Dashboard
			</h2>

			<NcActions
				:force-name="true"
				:inline="1"
				:primary="true"
				menu-name="Dashboard actions">
				<NcActionButton @click="registerStore.setRegisterItem(null); navigationStore.setModal('editRegister')">
					<template #icon>
						<Plus :size="20" />
					</template>
					Add Register
				</NcActionButton>
				<NcActionButton @click="dashboardStore.fetchRegisters()">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Refresh
				</NcActionButton>
				<NcActionButton @click="registerStore.setRegisterItem(null); navigationStore.setModal('importRegister')">
					<template #icon>
						<Upload :size="20" />
					</template>
					Import
				</NcActionButton>
				<NcActionButton @click="openAllApisDoc">
					<template #icon>
						<ApiIcon :size="20" />
					</template>
					View APIs
				</NcActionButton>
			</NcActions>
		</span>

		<div class="dashboardContent">
			<div v-if="dashboardStore.loading" class="loading">
				<NcLoadingIcon :size="32" />
				<span>Loading dashboard data...</span>
			</div>
			<div v-else-if="dashboardStore.error" class="error">
				<NcEmptyContent :title="dashboardStore.error" icon="icon-error" />
			</div>
			<div v-else class="charts-container">
				<!-- Audit Trail Actions Chart -->
				<div class="chart-card">
					<h3>Audit Trail Actions</h3>
					<apexchart
						type="line"
						height="350"
						:options="auditTrailChartOptions"
						:series="dashboardStore.chartData.auditTrailActions?.series || []" />
				</div>

				<!-- Objects by Register Chart -->
				<div class="chart-card">
					<h3>Objects by Register</h3>
					<apexchart
						type="pie"
						height="350"
						:options="registerChartOptions"
						:series="dashboardStore.chartData.objectsByRegister?.series || []" />
				</div>

				<!-- Objects by Schema Chart -->
				<div class="chart-card">
					<h3>Objects by Schema</h3>
					<apexchart
						type="pie"
						height="350"
						:options="schemaChartOptions"
						:series="dashboardStore.chartData.objectsBySchema?.series || []" />
				</div>

				<!-- Objects by Size Chart -->
				<div class="chart-card">
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
import { tooltip, NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import Upload from 'vue-material-design-icons/Upload.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'DashboardIndex',
	directives: {
		tooltip,
	},
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		apexchart: VueApexCharts,
		Plus,
		Refresh,
		ApiIcon,
		Upload,
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
				labels: [],
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
				labels: [],
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

		formatBytes(bytes) {
			if (!bytes || bytes === 0) return '0 KB'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
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

.loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	justify-content: center;
	padding-block: 40px;
}

.charts-container {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	padding: 20px;
}

.chart-card {
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
	.charts-container {
		grid-template-columns: 1fr;
	}
}
</style>
