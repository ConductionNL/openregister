<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { objectStore, registerStore, schemaStore, dashboardStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Dashboard')"
		:subname="t('openregister', 'Get real-time insights into your organization\'s data health by focusing on registers, schema definitions, and object storage and usage.')"
		:open="isSidebarOpen"
		class="dashboard-sidebar"
		@update:open="(e) => isSidebarOpen = e">
		<NcAppSidebarTab id="overview-tab" :name="t('openregister', 'Overview')" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Statistics') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
					<NcSelect v-bind="registerOptions"
						id="registerSelect"
						:model-value="selectedRegisterValue"
						:loading="registerLoading"
						:disabled="registerLoading"
						:input-label="t('openregister', 'Register')"
						:placeholder="t('openregister', 'Select a register')"
						@update:modelValue="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect v-bind="schemaOptions"
						id="schemaSelect"
						:model-value="selectedSchemaValue"
						:loading="schemaLoading"
						:disabled="!registerStore.registerItem || schemaLoading"
						:input-label="t('openregister', 'Schema')"
						:placeholder="t('openregister', 'Select a schema')"
						@update:modelValue="handleSchemaChange" />
				</div>
			</div>

			<!-- System Totals Section -->
			<div class="section">
				<h3 class="sectionTitle">
					{{ t('openregister', 'Totals') }}
				</h3>
				<div v-if="dashboardStore.loading" class="loadingContainer">
					<NcLoadingIcon :size="20" />
					<span>{{ t('openregister', 'Loading statistics...') }}</span>
				</div>
				<div v-else-if="systemTotals" class="statsContainer">
					<table class="statisticsTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Name') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Count') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Size') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Registers') }}
								</th>
								<td>{{ filteredRegisters.length }}</td>
								<td>-</td>
							</tr>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Schemas') }}
								</th>
								<td>{{ totalSchemas }}</td>
								<td>-</td>
							</tr>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Objects') }}
								</th>
								<td>{{ systemTotals.stats?.objects?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<th scope="row" class="indented">
									{{ t('openregister', 'Invalid') }}
								</th>
								<td>{{ systemTotals.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<th scope="row" class="indented">
									{{ t('openregister', 'Deleted') }}
								</th>
								<td>{{ systemTotals.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<th scope="row" class="indented">
									{{ t('openregister', 'Locked') }}
								</th>
								<td>{{ systemTotals.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Logs') }}
								</th>
								<td>{{ systemTotals.stats?.logs?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Files') }}
								</th>
								<td>{{ systemTotals.stats?.files?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.files?.size || 0) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Orphaned Items Section -->
			<div class="section">
				<h3 class="sectionTitle">
					{{ t('openregister', 'Orphaned Items') }}
				</h3>
				<div v-if="dashboardStore.loading" class="loadingContainer">
					<NcLoadingIcon :size="20" />
					<span>{{ t('openregister', 'Loading statistics...') }}</span>
				</div>
				<div v-else-if="orphanedItems" class="statsContainer">
					<table class="statisticsTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Name') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Count') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Size') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Objects') }}
								</th>
								<td>{{ orphanedItems.stats?.objects?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<th scope="row" class="indented">
									{{ t('openregister', 'Invalid') }}
								</th>
								<td>{{ orphanedItems.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<th scope="row" class="indented">
									{{ t('openregister', 'Deleted') }}
								</th>
								<td>{{ orphanedItems.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<th scope="row" class="indented">
									{{ t('openregister', 'Locked') }}
								</th>
								<td>{{ orphanedItems.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Logs') }}
								</th>
								<td>{{ orphanedItems.stats?.logs?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<th scope="row">
									{{ t('openregister', 'Files') }}
								</th>
								<td>{{ orphanedItems.stats?.files?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.files?.size || 0) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import formatBytes from '../../services/formatBytes.js'
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'DashboardSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcLoadingIcon,
		Magnify,
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			ignoreNextPageWatch: false,
			searchQuery: '',
			activeTab: 'overview-tab',
			searchTimeout: null,
			// Dashboard opens with the sidebar collapsed; the user can open it
			// manually via the standard NC toggle (which writes back here).
			isSidebarOpen: false,
		}
	},
	computed: {
		/**
		 * Build the register dropdown options for the dashboard filter.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object} NcSelect options bag for registers
		 */
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					value: register.id,
					label: register.title,
					title: register.title,
					register,
				})),
				reduce: option => option.register,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.register && option.register.title) || option.label || ''
				},
			}
		},
		/**
		 * Build the schema dropdown options scoped to the selected register.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object} NcSelect options bag for schemas
		 */
		schemaOptions() {
			if (!registerStore.registerItem) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => registerStore.registerItem.schemas.some(registerSchema => registerSchema.id === schema.id))
					.map(schema => ({
						value: schema.id,
						label: schema.title,
						title: schema.title,
						schema,
					})),
				reduce: option => option.schema,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.schema && option.schema.title) || option.label || ''
				},
			}
		},
		/**
		 * Resolve the currently-selected register into NcSelect value shape.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null} Selected register option, or null
		 */
		selectedRegisterValue() {
			if (!registerStore.registerItem) return null
			const register = registerStore.registerItem
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
		/**
		 * Resolve the currently-selected schema into NcSelect value shape.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null} Selected schema option, or null
		 */
		selectedSchemaValue() {
			if (!schemaStore.schemaItem) return null
			const schema = schemaStore.schemaItem
			return {
				value: schema.id,
				label: schema.title,
				title: schema.title,
				schema,
			}
		},
		/**
		 * Flatten the object metadata map into column descriptors for display.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Array} Metadata column descriptors
		 */
		metadataColumns() {
			return Object.entries(objectStore.metadata).map(([id, meta]) => ({
				id,
				...meta,
			}))
		},
		/**
		 * Get system totals from dashboardStore
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object | null}
		 */
		systemTotals() {
			return dashboardStore.registers.find(register => register.title === 'System Totals')
		},
		/**
		 * Get orphaned items from dashboardStore
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object | null}
		 */
		orphanedItems() {
			return dashboardStore.registers.find(register => register.title === 'Orphaned Items')
		},
		/**
		 * Get filtered registers (excluding system and orphaned)
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Array}
		 */
		filteredRegisters() {
			return dashboardStore.registers.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		/**
		 * Get total number of schemas in filtered registers
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {number}
		 */
		totalSchemas() {
			return this.filteredRegisters.reduce((total, register) => {
				return total + (register.schemas?.length || 0)
			}, 0)
		},
	},
	watch: {
		'searchQuery'(value) {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			this.searchTimeout = setTimeout(() => {
				objectStore.setFilters({
					_search: value || '',
				})
				if (registerStore.registerItem && schemaStore.schemaItem) {
					objectStore.refreshObjectList({
						register: registerStore.registerItem.id,
						schema: schemaStore.schemaItem.id,
					})
				}
			}, 1000)
		},
		// Watch for schema changes to initialize properties
		// Use immediate: true equivalent in mounted
		// This watcher will update properties when schema changes
		'$root.schemaStore.schemaItem': {
			/**
			 * @param newSchema
			 * @spec exclude Vue watch handler plumbing; re-initialises object properties when the selected schema changes.
			 */
			handler(newSchema) {
				if (newSchema) {
					objectStore.initializeProperties(newSchema)
				} else {
					objectStore.properties = {}
					objectStore.initializeColumnFilters()
				}
			},
			deep: true,
		},
	},
	/**
	 * @spec exclude Lifecycle plumbing; fire-and-forget load of register/schema/object lists for the dashboard filter, no domain logic.
	 */
	mounted() {
		objectStore.initializeColumnFilters()
		this.registerLoading = true
		this.schemaLoading = true

		// Only load lists if they're empty
		if (!registerStore.registerList.length) {
			registerStore.refreshRegisterList()
				.finally(() => (this.registerLoading = false))
		} else {
			this.registerLoading = false
		}

		if (!schemaStore.schemaList.length) {
			schemaStore.refreshSchemaList()
				.finally(() => (this.schemaLoading = false))
		} else {
			this.schemaLoading = false
		}

		// Load objects if register and schema are already selected
		if (registerStore.registerItem && schemaStore.schemaItem) {
			objectStore.refreshObjectList()
		}
	},
	methods: {
		/**
		 * Apply a new register selection and reset the dependent schema state.
		 *
		 * @param {object|null} option - The selected register (or null to clear)
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-files-sidebar-tabs/tasks.md#task-2
		 */
		handleRegisterChange(option) {
			registerStore.setRegisterItem(option)
			schemaStore.setSchemaItem(null)
		},
		/**
		 * Apply a schema selection: set the schema, initialise its properties, and
		 * refresh the object list for the new filter context.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-5
		 * @param {object|null} option - The selected schema (or null to clear)
		 * @return {Promise<void>}
		 */
		async handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},
		/**
		 * Re-run the object search for the current register/schema with the search term.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		handleSearch() {
			if (registerStore.registerItem && schemaStore.schemaItem) {
				objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
					search: this.searchQuery,
				})
			}
		},
	},
}
</script>

<style>
.dashboard-sidebar .app-sidebar-header__subname {
	white-space: normal !important;
}
</style>

<style lang="scss" scoped>
.section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.section:last-child {
	border-bottom: none;
}

.sectionTitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.loadingContainer {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 16px;
	color: var(--color-text-maxcontrast);
}

.statsContainer {
	padding: 0 16px;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;

	td,
	th {
		padding: 4px 8px;
		border-bottom: 1px solid var(--color-border);
		text-align: left;

		&:nth-child(2),
		&:nth-child(3) {
			text-align: right;
		}
	}

	/* Row headers are labels, not emphasis — keep the original visual weight. */
	tbody th {
		font-weight: normal;
	}

	.subRow td,
	.subRow th {
		color: var(--color-text-maxcontrast);
	}

	.indented {
		padding-left: 24px;
	}

	tr:last-child td,
	tr:last-child th {
		border-bottom: none;
	}
}

.filterSection {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--color-border);

	h3 {
		margin: 0;
		font-size: 1.1em;
		color: var(--color-main-text);
	}
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 8px;

	label {
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
	}
}

.dateRangeInputs {
	display: flex;
	gap: 8px;
}
</style>
