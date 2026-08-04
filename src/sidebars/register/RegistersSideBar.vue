<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { objectStore, registerStore, schemaStore, dashboardStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Registers')"
		:subtitle="t('openregister', 'Register Overview')"
		:subname="t('openregister', 'Statistics and Metrics')"
		:open="navigationStore.sidebarState.registers"
		@update:open="(e) => {
			navigationStore.setSidebarState('registers', e)
		}">
		<NcAppSidebarTab id="overview-tab" :name="t('openregister', 'Overview')" :order="1">
			<template #icon>
				<ChartBar :size="20" />
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
						:aria-label-combobox="t('openregister', 'Select a register')"
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
						:aria-label-combobox="t('openregister', 'Select a schema')"
						:placeholder="t('openregister', 'Select a schema')"
						@update:modelValue="handleSchemaChange" />
				</div>
			</div>

			<!-- System Totals Section -->
			<div class="section">
				<h3 class="sectionTitle">
					{{ t('openregister', 'Register Totals') }}
				</h3>
				<div v-if="dashboardStore.loading" class="loadingContainer">
					<NcLoadingIcon :size="20" />
					<span>{{ t('openregister', 'Loading statistics...') }}</span>
				</div>
				<div v-else-if="systemTotals" class="statsStack">
					<CnStatsBlock
						:title="t('openregister', 'Registers')"
						:count="filteredRegisters.length"
						:count-label="t('openregister', 'register{plural}', {
							plural: filteredRegisters.length !== 1 ? 's' : ''
						})"
						:icon="DatabaseOutline"
						variant="primary"
						horizontal
						show-zero-count />
					<CnStatsBlock
						:title="t('openregister', 'Schemas')"
						:count="totalSchemas"
						:count-label="t('openregister', 'schema{plural}', {
							plural: totalSchemas !== 1 ? 's' : ''
						})"
						:icon="TableIcon"
						variant="primary"
						horizontal
						show-zero-count />
					<CnStatsBlock
						:title="t('openregister', 'Objects')"
						:count="systemTotals.stats?.objects?.total || 0"
						:count-label="t('openregister', 'object{plural}', {
							plural: systemTotals.stats?.objects?.total !== 1 ? 's' : ''
						})"
						:icon="PackageVariantClosed"
						variant="primary"
						horizontal
						show-zero-count
						:breakdown="objectsBreakdown(systemTotals)" />
					<CnStatsBlock
						:title="t('openregister', 'Logs')"
						:count="systemTotals.stats?.logs?.total || 0"
						:count-label="t('openregister', 'log{plural}', {
							plural: systemTotals.stats?.logs?.total !== 1 ? 's' : ''
						})"
						:icon="TextBoxOutline"
						horizontal
						show-zero-count
						:breakdown="sizeBreakdown(systemTotals.stats?.logs?.size)" />
					<CnStatsBlock
						:title="t('openregister', 'Files')"
						:count="systemTotals.stats?.files?.total || 0"
						:count-label="t('openregister', 'file{plural}', {
							plural: systemTotals.stats?.files?.total !== 1 ? 's' : ''
						})"
						:icon="FileDocumentOutline"
						horizontal
						show-zero-count
						:breakdown="sizeBreakdown(systemTotals.stats?.files?.size)" />
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
				<div v-else-if="orphanedItems" class="statsStack">
					<CnStatsBlock
						:title="t('openregister', 'Objects')"
						:count="orphanedItems.stats?.objects?.total || 0"
						:count-label="t('openregister', 'object{plural}', {
							plural: systemTotals.stats?.objects?.total !== 1 ? 's' : ''
						})"
						:icon="PackageVariantClosed"
						variant="warning"
						horizontal
						show-zero-count
						:breakdown="objectsBreakdown(orphanedItems)" />
					<CnStatsBlock
						:title="t('openregister', 'Logs')"
						:count="orphanedItems.stats?.logs?.total || 0"
						:count-label="t('openregister', 'log{plural}', {
							plural: systemTotals.stats?.logs?.total !== 1 ? 's' : ''
						})"
						:icon="TextBoxOutline"
						variant="warning"
						horizontal
						show-zero-count
						:breakdown="sizeBreakdown(orphanedItems.stats?.logs?.size)" />
					<CnStatsBlock
						:title="t('openregister', 'Files')"
						:count="orphanedItems.stats?.files?.total || 0"
						:count-label="t('openregister', 'file{plural}', {
							plural: systemTotals.stats?.files?.total !== 1 ? 's' : ''
						})"
						:icon="FileDocumentOutline"
						variant="warning"
						horizontal
						show-zero-count
						:breakdown="sizeBreakdown(orphanedItems.stats?.files?.size)" />
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import formatBytes from '../../services/formatBytes.js'
// Ensure data is loaded
dashboardStore.preload()

export default {
	name: 'RegistersSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcLoadingIcon,
		NcSelect,
		CnStatsBlock,
		ChartBar,
	},
	data() {
		return {
			activeTab: 'overview-tab',
			selectedRegisterId: null,
			selectedSchemaId: null,
			dateRange: {
				from: null,
				till: null,
			},
			registerLoading: false,
			schemaLoading: false,
			ignoreNextPageWatch: false,
			searchQuery: '',
			// Icon components for CnStatsBlock
			DatabaseOutline,
			TableIcon,
			PackageVariantClosed,
			TextBoxOutline,
			FileDocumentOutline,
		}
	},
	computed: {
		/**
		 * System totals stats from the dashboard store.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null}
		 */
		systemTotals() {
			return dashboardStore.getSystemTotals
		},
		/**
		 * Orphaned-items stats from the dashboard store.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null}
		 */
		orphanedItems() {
			return dashboardStore.getOrphanedItems
		},
		/**
		 * Registers excluding the synthetic System Totals / Orphaned Items rows.
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
		 * Total schema count across the filtered registers.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {number}
		 */
		totalSchemas() {
			return this.filteredRegisters.reduce((total, register) => {
				return total + (register.schemas?.length || 0)
			}, 0)
		},
		/**
		 * Build the register dropdown options for the registers-overview filter.
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
	},
	methods: {
		/**
		 * Apply a register selection and reset dependent schema state (cascade).
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-5
		 * @param {object|null} option - Selected register (or null to clear)
		 * @return {void}
		 */
		handleRegisterChange(option) {
			registerStore.setRegisterItem(option)
			schemaStore.setSchemaItem(null)
		},
		/**
		 * Apply a schema selection, initialise properties, and refresh the object list.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-5
		 * @param {object|null} option - Selected schema (or null to clear)
		 * @return {void}
		 */
		handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},
		/**
		 * Push the selected date range into the dashboard store for stats scoping.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {void}
		 */
		onDateRangeChange() {
			dashboardStore.setDateRange(this.dateRange.from, this.dateRange.till)
		},
		/**
		 * @spec exclude Presentation-only formatter; builds the objects stats breakdown descriptor for a stats source.
		 * @param {object} source - Stats-bearing record
		 * @return {object|null} Breakdown descriptor or null
		 */
		objectsBreakdown(source) {
			const stats = source?.stats?.objects
			if (!stats) return null
			const breakdown = {}
			if (stats.size) breakdown.size = formatBytes(stats.size)
			if (stats.invalid) breakdown.invalid = stats.invalid
			if (stats.deleted) breakdown.deleted = stats.deleted
			if (stats.locked) breakdown.locked = stats.locked
			return Object.keys(breakdown).length > 0 ? breakdown : null
		},
		/**
		 * @spec exclude Presentation-only formatter; renders a byte size as a stats breakdown descriptor.
		 * @param {number} size - Byte size
		 * @return {object|null} Breakdown descriptor or null
		 */
		sizeBreakdown(size) {
			if (!size) return null
			return { size: formatBytes(size) }
		},
	},
}
</script>

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

.statsStack {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 8px;
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
</style>
