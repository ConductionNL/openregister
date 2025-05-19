<script setup>
import { dashboardStore, schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
			<!-- Loading and error states -->
			<div v-if="dashboardStore.loading" class="error">
				<NcEmptyContent name="Loading" description="Loading schema statistics...">
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
			<div v-else>
				<span class="pageHeaderContainer">
					<h2 class="pageHeader">
						{{ schemaStore.schemaItem.title }}
					</h2>
					<div class="headerActionsContainer">
						<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton @click="navigationStore.setModal('editSchema')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton @click="schemaStore.setSchemaPropertyKey(null); navigationStore.setModal('editSchemaProperty')">
							<template #icon>
								<PlusCircleOutline />
							</template>
							Add Property
						</NcActionButton>
						<NcActionButton @click="navigationStore.setModal('uploadSchema')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Upload
						</NcActionButton>
						<NcActionButton @click="schemaStore.downloadSchema(schemaStore.schemaItem)">
							<template #icon>
								<Download :size="20" />
							</template>
							Download
						</NcActionButton>
						<NcActionButton @click="navigationStore.setDialog('deleteSchema')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
					</div>
				</span>
				<div class="dashboardContent">
					<span>{{ schemaStore.schemaItem.description }}</span>
					<div class="chartsContainer">
						<!-- Audit Trail Actions Chart -->
						<div class="chartCard">
						<h3>Audit Trail Actions</h3>
						<apexchart
							type="line"
							height="350"
							:options="auditTrailChartOptions"
							:series="dashboardStore.chartData?.auditTrailActions?.series || []" />
					</div>

					<!-- Objects by Register Chart -->
					<div class="chartCard">
						<h3>Objects by Register</h3>
						<apexchart
							type="pie"
							height="350"
							:options="registerChartOptions"
							:series="dashboardStore.chartData?.objectsByRegister?.series || []"
							:labels="dashboardStore.chartData?.objectsByRegister?.labels || []" />
					</div>

					<!-- Objects by Size Chart -->
					<div class="chartCard">
						<h3>Objects by Size Distribution</h3>
						<apexchart
							type="bar"
							height="350"
							:options="sizeChartOptions"
							:series="[{ name: 'Objects', data: dashboardStore.chartData?.objectsBySize?.series || [] }]" />
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcActions, NcActionButton, NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'SchemaDetails',
	components: {
		NcActions,
		NcActionButton,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		apexchart: VueApexCharts,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		PlusCircleOutline,
		Download,
		Upload,
		AlertCircle,
	},
	computed: {
		/**
		 * Chart options for the Audit Trail Actions chart
		 * @return {object}
		 */
		auditTrailChartOptions() {
			return {
				chart: {
					type: 'line',
					toolbar: { show: true },
					zoom: { enabled: true },
				},
				xaxis: {
					categories: dashboardStore.chartData?.auditTrailActions?.labels || [],
					title: { text: 'Date' },
				},
				yaxis: { title: { text: 'Number of Actions' } },
				colors: ['#41B883', '#E46651', '#00D8FF'],
				stroke: { curve: 'smooth', width: 2 },
				legend: { position: 'top' },
				theme: { mode: 'light' },
			}
		},
		/**
		 * Chart options for the Objects by Register chart
		 * @return {object}
		 */
		registerChartOptions() {
			return {
				chart: { type: 'pie' },
				labels: dashboardStore.chartData?.objectsByRegister?.labels || [],
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
		 * Chart options for the Objects by Size Distribution chart
		 * @return {object}
		 */
		sizeChartOptions() {
			return {
				chart: { type: 'bar' },
				plotOptions: {
					bar: {
						horizontal: false,
						columnWidth: '55%',
						endingShape: 'rounded',
					},
				},
				xaxis: {
					categories: dashboardStore.chartData?.objectsBySize?.labels || [],
					title: { text: 'Size Range' },
				},
				yaxis: { title: { text: 'Number of Objects' } },
				fill: { opacity: 1 },
			}
		},
	},
	async mounted() {
		// Fetch dashboard data if not already loaded
		if (!dashboardStore.chartData || Object.keys(dashboardStore.chartData).length === 0) {
			await dashboardStore.fetchAllChartData()
		}
	},
	methods: {
		/**
		 * Set the active property for editing
		 * @param {string|null} key - The key to process
		 * @return {void}
		 */
		setActiveProperty(key) {
			if (JSON.stringify(schemaStore.schemaPropertyKey) === JSON.stringify(key)) {
				schemaStore.setSchemaPropertyKey(null)
			} else {
				schemaStore.setSchemaPropertyKey(key)
			}
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
