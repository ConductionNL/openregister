<script setup>
import { dashboardStore, schemaStore, navigationStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
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
						<NcActionButton close-after-click @click="navigationStore.setModal('editSchema')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="schemaStore.setSchemaPropertyKey(null); navigationStore.setModal('editSchemaProperty')">
							<template #icon>
								<PlusCircleOutline />
							</template>
							Add Property
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setModal('uploadSchema')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Upload
						</NcActionButton>
						<NcActionButton close-after-click @click="schemaStore.downloadSchema(schemaStore.schemaItem)">
							<template #icon>
								<Download :size="20" />
							</template>
							Download
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteSchema')">
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

				<!-- Schema Statistics -->
				<div v-if="schemaStats" class="statsContainer">
					<h3>{{ t('openregister', 'Schema Statistics') }}</h3>
					<table class="statisticsTable schemaStats">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Type') }}</th>
								<th>{{ t('openregister', 'Total') }}</th>
								<th>{{ t('openregister', 'Size') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>{{ t('openregister', 'Objects') }}</td>
								<td>{{ schemaStats.objects?.total || 0 }}</td>
								<td>{{ formatBytes(schemaStats.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ schemaStats.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ schemaStats.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Published') }}
								</td>
								<td>{{ schemaStats.objects?.published || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ schemaStats.files?.total || 0 }}</td>
								<td>{{ formatBytes(schemaStats.files?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ schemaStats.logs?.total || 0 }}</td>
								<td>{{ formatBytes(schemaStats.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Registers') }}</td>
								<td>{{ schemaStats.registers || 0 }}</td>
								<td>-</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="chartGrid">
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
	data() {
		return {
			schemaStats: null,
			statsLoading: false,
			statsError: null,
		}
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

		// Fetch schema stats if schema is available
		if (schemaStore.schemaItem?.id) {
			await this.loadSchemaStats()
		}
	},
	methods: {
		/**
		 * Load schema statistics from the dedicated stats endpoint
		 * @return {Promise<void>}
		 */
		async loadSchemaStats() {
			if (!schemaStore.schemaItem?.id) {
				return
			}

			this.statsLoading = true
			this.statsError = null

			try {
				this.schemaStats = await schemaStore.getSchemaStats(schemaStore.schemaItem.id)
			} catch (error) {
				console.error('Error loading schema stats:', error)
				this.statsError = error.message
			} finally {
				this.statsLoading = false
			}
		},
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

.dashboardContent {
	margin-inline: auto;
	max-width: 1200px;
	padding-block: 20px;
	padding-inline: 20px;
}

.chartGrid {
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
	.chartGrid {
		grid-template-columns: 1fr;
	}
}

.statsContainer {
	margin-bottom: 30px;

	h3 {
		margin-bottom: 15px;
		color: var(--color-main-text);
	}
}
</style>
