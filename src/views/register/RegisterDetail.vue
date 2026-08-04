<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { dashboardStore, registerStore, navigationStore, configurationStore, schemaStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
</script>

<template>
	<NcAppContent>
		<CnDetailPage
			:title="register?.title || ''"
			:loading="dashboardStore.loading"
			:loading-label="t('openregister', 'Loading register data...')"
			:error="!!dashboardStore.error || (!dashboardStore.loading && !register)"
			:error-message="dashboardStore.error || t('openregister', 'Register not found')"
			:stats-title="registerStats ? t('openregister', 'Register Statistics') : ''"
			:stats-columns="registerStats ? [
				{ key: 'type', label: t('openregister', 'Type') },
				{ key: 'total', label: t('openregister', 'Total') },
				{ key: 'size', label: t('openregister', 'Size') },
			] : []">
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

			<!-- Schemas -->
			<div v-if="loadingSchemas" class="loadingContainer">
				<NcLoadingIcon :size="32" />
				<span>Loading schemas...</span>
			</div>
			<div v-else-if="!loadedSchemas?.length" class="emptyContainer">
				<NcEmptyContent
					:name="t('openregister', 'No schemas found')">
					<template #icon>
						<FolderOutline :size="48" />
					</template>
					<template #action>
						<NcButton v-if="!managingConfiguration" @click="showEditDialog = true">
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
							<span v-if="managingConfiguration" :title="'Managed by configuration: ' + managingConfiguration.title" class="managedBadge">
								<Database :size="16" />
								Managed
							</span>
						</h3>
						<NcActions :primary="true" menu-name="Schema Actions">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="viewObjects(schema)">
								<template #icon>
									<TableEye :size="20" />
								</template>
								{{ t('openregister', 'View objects') }}
							</NcActionButton>
							<NcActionButton v-if="!managingConfiguration" close-after-click @click="editSchema(schema)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openregister', 'Edit Schema') }}
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
							]" />
					</div>
				</div>
			</div>
		</CnDetailPage>

		<CnFormDialog
			v-if="showEditDialog"
			ref="editRegisterDialog"
			:schema="registerSchema"
			:item="register"
			:dialog-title="t('openregister', 'Edit Register')"
			@confirm="onSaveRegister"
			@close="showEditDialog = false">
			<template #form="{ formData, errors, updateField }">
				<div class="formContainer">
					<NcTextField
						:label="t('openregister', 'Title') + ' *'"
						:model-value="formData.title || ''"
						:error="!!errors.title"
						:helper-text="errors.title"
						@update:modelValue="v => updateField('title', v)" />
					<NcTextField
						:label="t('openregister', 'Slug') + ' *'"
						:model-value="formData.slug || ''"
						:error="!!errors.slug"
						:helper-text="errors.slug"
						@update:modelValue="v => updateField('slug', v)" />
					<NcTextArea
						:label="t('openregister', 'Description')"
						:model-value="formData.description || ''"
						@update:modelValue="v => updateField('description', v)" />
					<NcSelect
						:input-label="t('openregister', 'Schemas')"
						:options="schemaSelectOptions"
						:model-value="getSchemaSelectValue(formData.schemas)"
						:multiple="true"
						:close-on-select="false"
						:loading="schemasLoading"
						@update:modelValue="vals => updateField('schemas', vals)" />
				</div>
			</template>
		</CnFormDialog>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcButton, NcTextField, NcTextArea, NcSelect } from '@nextcloud/vue'
import { CnDetailPage, CnFormDialog } from '@conduction/nextcloud-vue'
import VueApexCharts from 'vue3-apexcharts'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Database from 'vue-material-design-icons/Database.vue'
import TableEye from 'vue-material-design-icons/TableEye.vue'
import { getTheme } from '@/services/getTheme.js'

export default {
	name: 'RegisterDetail',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		CnDetailPage,
		CnFormDialog,
		apexchart: VueApexCharts,
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
			schemaSelectOptions: [],
			schemasLoading: false,
		}
	},
	computed: {
		/**
		 * Inline JSON-schema describing the register edit form.
		 *
		 * @spec exclude UI plumbing — static form-schema for the edit dialog, no observable contract.
		 * @return {object}
		 */
		registerSchema() {
			return {
				title: t('openregister', 'Register'),
				properties: {
					title: { type: 'string', title: t('openregister', 'Title'), required: true, minLength: 1, order: 1 },
					slug: { type: 'string', title: t('openregister', 'Slug'), required: true, minLength: 1, order: 2 },
					description: { type: 'string', title: t('openregister', 'Description'), order: 3 },
					schemas: { type: 'array', title: t('openregister', 'Schemas'), order: 4 },
				},
				required: ['title', 'slug'],
			}
		},
		/**
		 * Resolve the active register from the dashboard store.
		 *
		 * @spec exclude UI plumbing — store lookup; register dashboard contract owned by built-in-dashboards.
		 * @return {object|undefined}
		 */
		register() {
			// Find the register in the dashboard store using the ID from register store
			const registerId = registerStore.getRegisterItem?.id
			return dashboardStore.registers.find(r => r.id === registerId)
		},
		/**
		 * ApexCharts options for the audit-trail line chart.
		 *
		 * @spec exclude UI plumbing — chart config computed; dashboard contract owned by built-in-dashboards.
		 * @return {object}
		 */
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
					mode: getTheme(),
				},
			}
		},
		/**
		 * ApexCharts options for the objects-by-schema pie chart.
		 *
		 * @spec exclude UI plumbing — chart config computed; dashboard contract owned by built-in-dashboards.
		 * @return {object}
		 */
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
		/**
		 * ApexCharts options for the objects-by-size bar chart.
		 *
		 * @spec exclude UI plumbing — chart config computed; dashboard contract owned by built-in-dashboards.
		 * @return {object}
		 */
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
		/**
		 * Lazy-load schema options when the edit dialog opens.
		 *
		 * @spec exclude UI plumbing — watcher triggering option load on dialog open.
		 * @param {boolean} val - dialog visibility
		 * @return {void}
		 */
		showEditDialog(val) {
			if (val) {
				this.loadSchemaOptions()
			}
		},
	},
	/**
	 * Fetch register/dashboard data and stats on mount.
	 *
	 * @spec exclude UI plumbing — lifecycle hook delegating to store loaders; dashboard contract owned by built-in-dashboards.
	 * @return {Promise<void>}
	 */
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
				this.registerStats = await registerStore.getRegisterStats(registerStore.getRegisterItem.id)
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
		 * Load schema select options for the edit dialog.
		 *
		 * @spec exclude UI plumbing — store delegation hydrating select options.
		 * @return {Promise<void>}
		 */
		async loadSchemaOptions() {
			this.schemasLoading = true
			try {
				await schemaStore.refreshSchemaList()
				this.schemaSelectOptions = schemaStore.schemaList.map(s => ({ id: s.id, label: s.title }))
			} catch (error) {
				console.error('Failed to load schemas:', error)
			} finally {
				this.schemasLoading = false
			}
		},
		/**
		 * Map schema ids/objects to NcSelect option values.
		 *
		 * @spec exclude UI plumbing — select-value normalizer for the edit form.
		 * @param {Array} schemas - schema ids or objects
		 * @return {Array} option objects
		 */
		getSchemaSelectValue(schemas) {
			if (!Array.isArray(schemas)) return []
			return schemas.map(s => {
				const id = typeof s === 'object' ? s.id : s
				return this.schemaSelectOptions.find(o => String(o.id) === String(id))
					|| { id, label: String(id) }
			})
		},
		/**
		 * Persist the register edit form and refresh dashboard data.
		 *
		 * @spec exclude UI plumbing — store delegation + dialog result; register CRUD contract owned elsewhere.
		 * @param {object} formData - edited register fields
		 * @return {Promise<void>}
		 */
		async onSaveRegister(formData) {
			try {
				await registerStore.saveRegister({
					...formData,
					schemas: (formData.schemas || []).map(s => typeof s === 'object' ? s.id : s),
				})
				this.$refs.editRegisterDialog.setResult({ success: true })
				await dashboardStore.fetchRegisters()
			} catch (error) {
				this.$refs.editRegisterDialog.setResult({ error: error.message })
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
			registerStore.setSchemaItem(schema)
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
			this.$router.push({
				path: '/tables',
				query: { register: String(registerId), schema: String(schema.id) },
			}).catch(() => {})
		},
		/**
		 * Load full schema details from schema IDs
		 *
		 * @spec exclude UI plumbing — parallel fetch hydrating local schema cards; schema contract owned elsewhere.
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
							const schema = await response.json()
							// Convert properties array to object if needed (backend sometimes returns array when empty)
							if (schema && Array.isArray(schema.properties)) {
								schema.properties = {}
							}
							return schema
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
	grid-template-columns: repeat( auto-fit, minmax(330px, 1fr) );
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
</style>
