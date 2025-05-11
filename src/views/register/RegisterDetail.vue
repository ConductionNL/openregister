<script setup>
import { dashboardStore, registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="registerDetailContent">
			<!-- Loading and error states -->
			<div v-if="dashboardStore.loading" class="loadingContainer">
				<NcLoadingIcon :size="32" />
				<span>Loading register data...</span>
			</div>
			<div v-else-if="dashboardStore.error" class="emptyContainer">
				<NcEmptyContent
					:title="dashboardStore.error"
					icon="icon-error">
					<template #action>
						<NcButton @click="navigationStore.setSelected('registers')">
							{{ t('openregister', 'Back to Registers') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</div>
			<div v-else-if="!register" class="emptyContainer">
				<NcEmptyContent
					:title="t('openregister', 'Register not found')"
					icon="icon-error">
					<template #action>
						<NcButton @click="navigationStore.setSelected('registers')">
							{{ t('openregister', 'Back to Registers') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</div>

			<!-- Stats Tab Content -->
			<div v-else-if="registerStore.getActiveTab === 'stats-tab'" class="chartsContainer">
				<!-- Audit Trail Actions Chart -->
				<div class="chartCard">
					<h3>Audit Trail Actions</h3>
					<apexchart
						type="line"
						height="350"
						:options="auditTrailChartOptions"
						:series="dashboardStore.chartData.auditTrailActions?.series || []" />
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

			<!-- Schemas Tab Content -->
			<div v-else-if="registerStore.getActiveTab === 'schemas-tab'" class="schemaGrid">
				<div v-if="!register.schemas?.length" class="emptyContainer">
					<NcEmptyContent
						:title="t('openregister', 'No schemas found')"
						icon="icon-folder">
						<template #action>
							<NcButton @click="navigationStore.setModal('editRegister')">
								{{ t('openregister', 'Add Schema') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>
				<div v-else class="schemaGrid">
					<div v-for="schema in register.schemas" :key="schema.id" class="schemaCard">
						<div class="schemaHeader">
							<h3>
								<FileCodeOutline :size="20" />
								{{ schema.title }}
							</h3>
							<NcActions :primary="true" menu-name="Schema Actions">
								<template #icon>
									<DotsHorizontal :size="20" />
								</template>
								<NcActionButton @click="editSchema(schema)">
									<template #icon>
										<Pencil :size="20" />
									</template>
									Edit Schema
								</NcActionButton>
							</NcActions>
						</div>
						<div class="schemaStats">
							<div class="statItem">
								<span class="statLabel">{{ t('openregister', 'Total Objects') }}</span>
								<span class="statValue">{{ schema.stats?.objects?.total || 0 }}</span>
							</div>
							<div class="statItem">
								<span class="statLabel">{{ t('openregister', 'Total Size') }}</span>
								<span class="statValue">{{ formatBytes(schema.stats?.objects?.size || 0) }}</span>
							</div>
						</div>
						<div class="schemaChart">
							<apexchart
								type="pie"
								height="200"
								:options="getSchemaChartOptions(schema)"
								:series="[
									schema.stats?.objects?.valid || 0,
									schema.stats?.objects?.invalid || 0,
									schema.stats?.objects?.deleted || 0,
									schema.stats?.objects?.locked || 0,
									schema.stats?.objects?.published || 0
								]" />
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcButton } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Export from 'vue-material-design-icons/Export.vue'
import Import from 'vue-material-design-icons/Import.vue'
import formatBytes from '../../services/formatBytes.js'

export default {
	name: 'RegisterDetail',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcButton,
		apexchart: VueApexCharts,
		FileCodeOutline,
		DotsHorizontal,
		Pencil,
		Export,
		Import,
	},
	computed: {
		register() {
			// Find the register in the dashboard store using the ID from register store
			const registerId = registerStore.getRegisterItem?.id
			return dashboardStore.registers.find(r => r.id === registerId)
		},
		auditTrailChartOptions() {
			return {
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
					categories: dashboardStore.chartData.auditTrailActions?.labels || [],
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
			}
		},
		schemaChartOptions() {
			return {
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
			}
		},
		sizeChartOptions() {
			return {
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
					categories: dashboardStore.chartData.objectsBySize?.labels || [],
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
			}
		},
	},
	async mounted() {
		// If we have a register ID but no data, fetch dashboard data
		if (registerStore.getRegisterItem?.id && !this.register) {
			try {
				await dashboardStore.fetchRegisters()
				await dashboardStore.fetchAllChartData()
			} catch (error) {
				console.error('Failed to fetch register details:', error)
				navigationStore.setSelected('registers')
			}
		} else if (!registerStore.getRegisterItem?.id) {
			// If no register ID at all, go back to list
			navigationStore.setSelected('registers')
		}
	},
	methods: {
		getSchemaChartOptions() {
			return {
				chart: {
					type: 'pie',
				},
				labels: ['Valid', 'Invalid', 'Deleted', 'Locked', 'Published'],
				legend: {
					position: 'bottom',
					fontSize: '14px',
				},
				colors: ['#41B883', '#E46651', '#00D8FF', '#DD6B20', '#38A169'],
				tooltip: {
					y: {
						formatter(val) {
							return val + ' objects'
						},
					},
				},
			}
		},

		editSchema(schema) {
			registerStore.setSchemaItem(schema)
			navigationStore.setModal('editSchema')
		},
	},
}
</script>

<style lang="scss" scoped>
.registerDetailContent {
	margin-inline: auto;
	max-width: 1200px;
	padding: 20px;
}

.loadingContainer {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	justify-content: center;
	padding-block: 40px;
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

.schemaGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
}

.schemaCard {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.schemaHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;

	h3 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1.1em;
	}
}

.schemaStats {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 12px;
	margin-bottom: 16px;
}

.statItem {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.statLabel {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.statValue {
	font-size: 1.1em;
	font-weight: 600;
}

@media screen and (max-width: 1024px) {
	.chartsContainer {
		grid-template-columns: 1fr;
	}
}
</style>
