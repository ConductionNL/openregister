<script setup>
import { dashboardStore, registerStore, navigationStore, schemaStore, configurationStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
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
						<NcButton @click="$router.push('/registers')">
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
						<NcButton @click="$router.push('/registers')">
							{{ t('openregister', 'Back to Registers') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</div>

			<!-- Stats Tab Content -->
			<div v-else-if="registerStore.getActiveTab === 'stats-tab'">
				<!-- Register Statistics -->
				<div v-if="registerStats" class="statsContainer">
					<h3>{{ t('openregister', 'Register Statistics') }}</h3>
					<table class="statisticsTable registerStats">
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
								<td>{{ registerStats.objects?.total || 0 }}</td>
								<td>{{ formatBytes(registerStats.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ registerStats.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ registerStats.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Published') }}
								</td>
								<td>{{ registerStats.objects?.published || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ registerStats.files?.total || 0 }}</td>
								<td>{{ formatBytes(registerStats.files?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ registerStats.logs?.total || 0 }}</td>
								<td>{{ formatBytes(registerStats.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Schemas') }}</td>
								<td>{{ registerStats.schemas || 0 }}</td>
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
			</div>

			<!-- Schemas Tab Content -->
			<div v-else class="cardGrid">
				<div v-if="loadingSchemas" class="loadingContainer">
					<NcLoadingIcon :size="32" />
					<span>Loading schemas...</span>
				</div>
				<div v-else-if="!loadedSchemas?.length" class="emptyContainer">
					<NcEmptyContent
						:title="t('openregister', 'No schemas found')"
						icon="icon-folder">
						<template #action>
							<NcButton v-if="!managingConfiguration" @click="navigationStore.setModal('editRegister')">
								{{ t('openregister', 'Add Schema') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>
				<div v-else class="cardGrid">
					<div v-for="schema in loadedSchemas" :key="schema.id" class="card">
						<div class="cardHeader">
							<h3>
								<FileCodeOutline :size="20" />
								{{ schema.title }}
								<span v-if="managingConfiguration" v-tooltip.bottom="'Managed by configuration: ' + managingConfiguration.title" class="managedBadge">
									<Database :size="16" />
									Managed
								</span>
							</h3>
							<NcActions v-if="!managingConfiguration" :primary="true" menu-name="Schema Actions">
								<template #icon>
									<DotsHorizontal :size="20" />
								</template>
								<NcActionButton close-after-click @click="editSchema(schema)">
									<template #icon>
										<Pencil :size="20" />
									</template>
									Edit Schema
								</NcActionButton>
							</NcActions>
						</div>
						<div class="statGrid">
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
import Database from 'vue-material-design-icons/Database.vue'

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
		Database,
	},
	data() {
		return {
			registerStats: null,
			statsLoading: false,
			statsError: null,
			loadedSchemas: [],
			loadingSchemas: false,
			managingConfiguration: null,
		}
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
	watch: {
		register: {
			handler() {
				// Reload schemas and check configuration when register changes
				this.loadSchemas()
				this.checkManagingConfiguration()
			},
			deep: true,
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
				this.$router.push('/registers')
			}
		} else if (!registerStore.getRegisterItem?.id) {
			// If no register ID at all, go back to list
			this.$router.push('/registers')
		}

		// Load register stats if register is available
		if (registerStore.getRegisterItem?.id) {
			await this.loadRegisterStats()
		}

		// Load schemas and check for managing configuration
		await this.loadSchemas()
		await this.checkManagingConfiguration()
	},
	methods: {
		/**
		 * Load register statistics from the dedicated stats endpoint
		 * @return {Promise<void>}
		 */
		async loadRegisterStats() {
			if (!registerStore.getRegisterItem?.id) {
				return
			}

			this.statsLoading = true
			this.statsError = null

			try {
				this.registerStats = await registerStore.getRegisterStats(registerStore.getRegisterItem.id)
			} catch (error) {
				console.error('Error loading register stats:', error)
				this.statsError = error.message
			} finally {
				this.statsLoading = false
			}
		},
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
		/**
		 * Load full schema details from schema IDs
		 * @return {Promise<void>}
		 */
		async loadSchemas() {
			if (!this.register?.schemas || !Array.isArray(this.register.schemas) || this.register.schemas.length === 0) {
				this.loadedSchemas = []
				return
			}

			this.loadingSchemas = true
			try {
				// Fetch all schemas in parallel
				const promises = this.register.schemas.map(async schemaId => {
					try {
						const response = await fetch(`/index.php/apps/openregister/api/schemas/${schemaId}`)
						if (response.ok) {
							return await response.json()
						}
						return null
					} catch (error) {
						console.error(`Failed to load schema ${schemaId}:`, error)
						return null
					}
				})

				const schemas = await Promise.all(promises)
				this.loadedSchemas = schemas.filter(Boolean) // Remove null entries
			} catch (error) {
				console.error('Error loading schemas:', error)
				this.loadedSchemas = []
			} finally {
				this.loadingSchemas = false
			}
		},
		/**
		 * Check if this register is managed by a configuration
		 * @return {Promise<void>}
		 */
		async checkManagingConfiguration() {
			if (!this.register?.id) {
				this.managingConfiguration = null
				return
			}

			try {
				// Check all configurations to see if any manages this register
				const configurations = configurationStore.configurationList || []
				for (const config of configurations) {
					if (config.registers && Array.isArray(config.registers) && config.registers.includes(this.register.id)) {
						this.managingConfiguration = config
						return
					}
				}
				this.managingConfiguration = null
			} catch (error) {
				console.error('Error checking managing configuration:', error)
				this.managingConfiguration = null
			}
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

.cardGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
}

.card {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.managedBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	margin-left: 8px;
	vertical-align: middle;
}

.cardHeader {
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

.statGrid {
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
	.chartGrid {
		grid-template-columns: 1fr;
	}
}

.schemaChart {
	margin-top: 16px;
}

.statsContainer {
	margin-bottom: 30px;

	h3 {
		margin-bottom: 15px;
		color: var(--color-main-text);
	}
}
</style>
