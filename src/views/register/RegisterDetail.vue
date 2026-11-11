<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import formatBytes from '../../services/formatBytes.js'
import {
	configurationStore,
	dashboardStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnDetailPage
			:title="register?.title || ''"
			:loading="dashboardStore.loading || hydrating"
			:loadingLabel="t('openregister', 'Loading register data...')"
			:error="
				!!dashboardStore.error
				|| (!dashboardStore.loading && !hydrating && !register)
			"
			:errorMessage="
				dashboardStore.error || t('openregister', 'Register not found')
			"
			:statsTitle="
				registerStats ? t('openregister', 'Register Statistics') : ''
			"
			:statsColumns="
				registerStats
					? [
							{ key: 'type', label: t('openregister', 'Type') },
							{ key: 'total', label: t('openregister', 'Total') },
							{ key: 'size', label: t('openregister', 'Size') },
						]
					: []
			">
			<!-- Error actions -->
			<template #error-actions>
				<NcButton @click="$router.push('/registers')">
					{{ t('openregister', 'Back to Registers') }}
				</NcButton>
			</template>

			<!-- Custom stats rows (uses formatBytes formatting) -->
			<template v-if="registerStats" #stats-rows>
				<tr>
					<td>{{ t('openregister', 'Objects') }}</td>
					<td>{{ registerStats.objects?.total || 0 }}</td>
					<td>{{ formatBytes(registerStats.objects?.size || 0) }}</td>
				</tr>
				<tr class="cn-detail-page__stats-row--sub">
					<td class="cn-detail-page__stats-cell--indented">
						{{ t('openregister', 'Invalid') }}
					</td>
					<td>{{ registerStats.objects?.invalid || 0 }}</td>
					<td>-</td>
				</tr>
				<tr class="cn-detail-page__stats-row--sub">
					<td class="cn-detail-page__stats-cell--indented">
						{{ t('openregister', 'Deleted') }}
					</td>
					<td>{{ registerStats.objects?.deleted || 0 }}</td>
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
			</template>

			<!-- Charts -->
			<DetailChartGrid
				:chartData="dashboardStore.chartData"
				distributionKey="objectsBySchema"
				distributionTitle="Objects by Schema"
				:auditOptions="auditTrailChartOptions"
				:distributionOptions="schemaChartOptions"
				:sizeOptions="sizeChartOptions" />

			<!-- Schemas -->
			<div v-if="loadingSchemas" class="loadingContainer">
				<NcLoadingIcon :size="32" />
				<span>Loading schemas...</span>
			</div>
			<div v-else-if="!loadedSchemas?.length" class="emptyContainer">
				<NcEmptyContent :name="t('openregister', 'No schemas found')">
					<template #icon>
						<FolderOutline :size="48" />
					</template>
					<template #action>
						<NcButton
							v-if="!managingConfiguration"
							@click="showEditDialog = true">
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
							<span
								v-if="managingConfiguration"
								:title="
									'Managed by configuration: '
									+ managingConfiguration.title
								"
								class="managedBadge">
								<Database :size="16" />
								Managed
							</span>
						</h3>
						<NcActions :primary="true" menuName="Schema Actions">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton
								closeAfterClick
								@click="viewObjects(schema)">
								<template #icon>
									<TableEye :size="20" />
								</template>
								{{ t('openregister', 'View objects') }}
							</NcActionButton>
							<NcActionButton
								v-if="!managingConfiguration"
								closeAfterClick
								@click="editSchema(schema)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openregister', 'Edit Schema') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="statGrid">
						<div class="statItem">
							<span class="statLabel">{{
								t('openregister', 'Total Objects')
							}}</span>
							<span class="statValue">{{
								schema.stats?.objects?.total || 0
							}}</span>
						</div>
						<div class="statItem">
							<span class="statLabel">{{
								t('openregister', 'Total Size')
							}}</span>
							<span class="statValue">{{
								formatBytes(schema.stats?.objects?.size || 0)
							}}</span>
						</div>
					</div>
					<div class="schemaChart">
						<Apexchart
							type="pie"
							height="200"
							:options="getSchemaChartOptions(schema)"
							:series="[
								schema.stats?.objects?.valid || 0,
								schema.stats?.objects?.invalid || 0,
								schema.stats?.objects?.deleted || 0,
								schema.stats?.objects?.locked || 0,
							]" />
					</div>
				</div>
			</div>
		</CnDetailPage>

		<EditRegisterFormDialog
			v-if="showEditDialog"
			:item="register"
			:afterSave="() => dashboardStore.fetchRegisters()"
			@close="showEditDialog = false" />
	</NcAppContent>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActions,
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import VueApexCharts from 'vue3-apexcharts'
import Database from 'vue-material-design-icons/Database.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TableEye from 'vue-material-design-icons/TableEye.vue'
import EditRegisterFormDialog from '../../modals/register/EditRegisterFormDialog.vue'
import DetailChartGrid from './DetailChartGrid.vue'
import { findActiveRegister } from './activeRegister.js'
import {
	auditTrailChartOptions,
	distributionChartOptions,
	sizeChartOptions,
} from './detailChartOptions.js'
import { getTheme } from '@/services/getTheme.js'

export default {
	name: 'RegisterDetail',
	components: {
		EditRegisterFormDialog,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcButton,
		CnDetailPage,
		DetailChartGrid,
		Apexchart: VueApexCharts,
		FileCodeOutline,
		FolderOutline,
		DotsHorizontal,
		Pencil,
		Database,
		TableEye,
	},

	data() {
		return {
			registerStats: null,
			statsLoading: false,
			statsError: null,
			loadedSchemas: [],
			loadingSchemas: false,
			managingConfiguration: null,
			showEditDialog: false,
			// True until mounted() has hydrated the register, so a deep link shows
			// the loading state instead of flashing "Register not found".
			hydrating: true,
		}
	},

	computed: {
		/**
		 * Resolve the active register from the dashboard store.
		 *
		 * @spec exclude UI plumbing — store lookup; register dashboard contract owned by built-in-dashboards.
		 * @return {object|undefined}
		 */
		register() {
			return findActiveRegister(this.$route)
		},

		/**
		 * ApexCharts options for the audit-trail line chart.
		 *
		 * @spec exclude UI plumbing — chart config computed; dashboard contract owned by built-in-dashboards.
		 * @return {object}
		 */
		auditTrailChartOptions() {
			return auditTrailChartOptions(
				dashboardStore.chartData.auditTrailActions?.labels || [],
				getTheme(),
			)
		},

		/**
		 * ApexCharts options for the objects-by-schema pie chart.
		 *
		 * @spec exclude UI plumbing — chart config computed; dashboard contract owned by built-in-dashboards.
		 * @return {object}
		 */
		schemaChartOptions() {
			return distributionChartOptions(
				dashboardStore.chartData.objectsBySchema?.labels || [],
			)
		},

		/**
		 * ApexCharts options for the objects-by-size bar chart.
		 *
		 * @spec exclude UI plumbing — chart config computed; dashboard contract owned by built-in-dashboards.
		 * @return {object}
		 */
		sizeChartOptions() {
			return sizeChartOptions(
				dashboardStore.chartData.objectsBySize?.labels || [],
			)
		},
	},

	watch: {
		register: {
			/**
			 * Reload schemas + managing configuration when the register changes.
			 *
			 * @spec exclude UI plumbing — watcher delegating to loaders; dashboard contract owned by built-in-dashboards.
			 * @return {void}
			 */
			handler() {
				// Reload schemas and check configuration when register changes
				this.loadSchemas()
				this.checkManagingConfiguration()
			},

			deep: true,
		},
	},

	/**
	 * Fetch register/dashboard data and stats on mount.
	 *
	 * @spec exclude UI plumbing — lifecycle hook delegating to store loaders; dashboard contract owned by built-in-dashboards.
	 * @return {Promise<void>}
	 */
	async mounted() {
		// The active register normally reaches the store from RegistersIndex before
		// it navigates here. A deep link or page refresh has no such hand-off, so
		// seed the store from the `:id` route param — without this the page bounced
		// straight back to /registers.
		const routeId = this.$route.params.id
		if (
			routeId
			&& String(registerStore.getRegisterItem?.id || '') !== String(routeId)
		) {
			registerStore.setRegisterItem({ id: routeId })
		}

		if (!registerStore.getRegisterItem?.id) {
			// No id in the route and none in the store — nothing to render.
			this.hydrating = false
			this.$router.push('/registers')
			return
		}

		// If we have a register ID but no data, fetch dashboard data
		if (!this.register) {
			try {
				await dashboardStore.fetchRegisters()
				await dashboardStore.fetchAllChartData()
			} catch (error) {
				// Stay on the page: CnDetailPage surfaces dashboardStore.error with a
				// "Back to Registers" action, which beats a silent redirect.
				console.error('Failed to fetch register details:', error)
			}
		}
		this.hydrating = false

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
		 *
		 * @spec exclude UI plumbing — store delegation hydrating local stats; dashboard contract owned by built-in-dashboards.
		 * @return {Promise<void>}
		 */
		async loadRegisterStats() {
			if (!registerStore.getRegisterItem?.id) {
				return
			}

			this.statsLoading = true
			this.statsError = null

			try {
				this.registerStats = await registerStore.getRegisterStats(
					registerStore.getRegisterItem.id,
				)
			} catch (error) {
				console.error('Error loading register stats:', error)
				this.statsError = error.message
			} finally {
				this.statsLoading = false
			}
		},

		/**
		 * ApexCharts options for a per-schema validity pie chart.
		 *
		 * @spec exclude UI plumbing — chart config builder; dashboard contract owned by built-in-dashboards.
		 * @return {object}
		 */
		getSchemaChartOptions() {
			return {
				chart: {
					type: 'pie',
				},

				labels: ['Valid', 'Invalid', 'Deleted', 'Locked'],
				legend: {
					position: 'bottom',
					fontSize: '14px',
				},

				colors: ['#41B883', '#E46651', '#00D8FF', '#DD6B20'],
				tooltip: {
					y: {
						/**
						 * Format a chart tooltip value as an object count.
						 *
						 * @spec exclude UI plumbing — inline chart tooltip formatter, no observable contract.
						 * @param {number} val - data point value
						 * @return {string}
						 */
						formatter(val) {
							return val + ' objects'
						},
					},
				},
			}
		},

		/**
		 * Open the edit-schema modal for a schema row.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch.
		 * @param {object} schema - schema row
		 * @return {void}
		 */
		editSchema(schema) {
			schemaStore.setSchemaItem(schema)
			navigationStore.setModal('editSchema')
		},

		/**
		 * Drill into this register's objects for the given schema by deep-linking
		 * to the search/tables view with both ids preselected. The SearchSideBar
		 * reads `?register=&schema=` and runs the search automatically.
		 *
		 * @spec exclude UI plumbing — router navigation to the pre-filtered tables view.
		 * @param {object} schema - schema row
		 * @return {void}
		 */
		viewObjects(schema) {
			const registerId = registerStore.getRegisterItem?.id
			if (!registerId || !schema?.id) {
				return
			}
			this.$router
				.push({
					path: '/tables',
					query: {
						register: String(registerId),
						schema: String(schema.id),
					},
				})
				.catch(() => {})
		},

		/**
		 * Normalise one schema for the cards.
		 *
		 * @spec exclude UI plumbing — shape fix-up for local schema cards; schema contract owned elsewhere.
		 * @param {object} schema - schema payload, hydrated or fetched
		 * @return {object} the schema, with `properties` guaranteed to be an object
		 */
		normalizeSchema(schema) {
			// The backend returns `properties: []` instead of `{}` for a schema with
			// none. Copy rather than mutate — hydrated entries belong to the store.
			if (Array.isArray(schema?.properties)) {
				return { ...schema, properties: {} }
			}
			return schema
		},

		/**
		 * Load full schema details for the register's schemas.
		 *
		 * @spec exclude UI plumbing — parallel fetch hydrating local schema cards; schema contract owned elsewhere.
		 * @return {Promise<void>}
		 */
		async loadSchemas() {
			const schemas = this.register?.schemas
			if (!Array.isArray(schemas) || schemas.length === 0) {
				this.loadedSchemas = []
				return
			}

			this.loadingSchemas = true
			try {
				// The dashboard endpoint replaces register.schemas with full schema
				// objects that also carry the per-schema `stats` the cards render and
				// GET /api/schemas/{id} does not return — so use those as they are.
				// Only bare ids need fetching (stringifying an object gave us
				// /api/schemas/[object Object]).
				const promises = schemas.map(async (schema) => {
					if (schema !== null && typeof schema === 'object') {
						return this.normalizeSchema(schema)
					}
					try {
						const response = await fetch(
							`/index.php/apps/openregister/api/schemas/${schema}`,
						)
						if (response.ok) {
							return this.normalizeSchema(await response.json())
						}
						return null
					} catch (error) {
						console.error(`Failed to load schema ${schema}:`, error)
						return null
					}
				})

				const loaded = await Promise.all(promises)
				this.loadedSchemas = loaded.filter(Boolean) // Remove null entries
			} catch (error) {
				console.error('Error loading schemas:', error)
				this.loadedSchemas = []
			} finally {
				this.loadingSchemas = false
			}
		},

		/**
		 * Check if this register is managed by a configuration
		 *
		 * @spec exclude UI plumbing — scans local configuration list to set a managed badge.
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
					if (
						config.registers
						&& Array.isArray(config.registers)
						&& config.registers.includes(this.register.id)
					) {
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
@use '../../styles/views.scss' as *;

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
	grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
	gap: 20px;
	padding: 20px;
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

@include media-screen-and-max-width-1024px;

.schemaChart {
	margin-top: 16px;
}
</style>
