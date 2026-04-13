<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { objectStore, registerStore, schemaStore, dashboardStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		name="Registers"
		subtitle="Register Overview"
		subname="Statistics and Metrics"
		:open="navigationStore.sidebarState.registers"
		@update:open="(e) => {
			navigationStore.setSidebarState('registers', e)
		}">
		<NcAppSidebarTab id="overview-tab" name="Overview" :order="1">
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
						aria-label-combobox="Select a register"
						placeholder="Select a register"
						@update:model-value="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect v-bind="schemaOptions"
						id="schemaSelect"
						:model-value="selectedSchemaValue"
						:loading="schemaLoading"
						:disabled="!registerStore.item || schemaLoading"
						aria-label-combobox="Select a schema"
						placeholder="Select a schema"
						@update:model-value="handleSchemaChange" />
				</div>
			</div>

			<div v-if="dashboardStore.loading" class="loadingContainer">
				<NcLoadingIcon :size="20" />
				<span>{{ t('openregister', 'Loading statistics...') }}</span>
			</div>
			<CnStatsPanel v-else :sections="registerStatsSections" />
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { CnStatsPanel } from '@conduction/nextcloud-vue'
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
		CnStatsPanel,
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
		systemTotals() {
			return dashboardStore.getSystemTotals
		},
		orphanedItems() {
			return dashboardStore.getOrphanedItems
		},
		filteredRegisters() {
			return dashboardStore.registers.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		totalSchemas() {
			return this.filteredRegisters.reduce((total, register) => {
				return total + (register.schemas?.length || 0)
			}, 0)
		},
		registerStatsSections() {
			const sections = []

			if (this.systemTotals) {
				sections.push({
					type: 'stats',
					id: 'registerTotals',
					title: t('openregister', 'Register Totals'),
					layout: 'stack',
					items: [
						{
							title: t('openregister', 'Registers'),
							count: this.filteredRegisters.length,
							countLabel: t('openregister', 'register{plural}', {
								plural: this.filteredRegisters.length !== 1 ? 's' : '',
							}),
							icon: DatabaseOutline,
							variant: 'primary',
						},
						{
							title: t('openregister', 'Schemas'),
							count: this.totalSchemas,
							countLabel: t('openregister', 'schema{plural}', {
								plural: this.totalSchemas !== 1 ? 's' : '',
							}),
							icon: TableIcon,
							variant: 'primary',
						},
						{
							title: t('openregister', 'Objects'),
							count: this.systemTotals.stats?.objects?.total || 0,
							countLabel: t('openregister', 'object{plural}', {
								plural: this.systemTotals.stats?.objects?.total !== 1 ? 's' : '',
							}),
							icon: PackageVariantClosed,
							variant: 'primary',
							breakdown: this.objectsBreakdown(this.systemTotals),
						},
						{
							title: t('openregister', 'Logs'),
							count: this.systemTotals.stats?.logs?.total || 0,
							countLabel: t('openregister', 'log{plural}', {
								plural: this.systemTotals.stats?.logs?.total !== 1 ? 's' : '',
							}),
							icon: TextBoxOutline,
							breakdown: this.sizeBreakdown(this.systemTotals.stats?.logs?.size),
						},
						{
							title: t('openregister', 'Files'),
							count: this.systemTotals.stats?.files?.total || 0,
							countLabel: t('openregister', 'file{plural}', {
								plural: this.systemTotals.stats?.files?.total !== 1 ? 's' : '',
							}),
							icon: FileDocumentOutline,
							breakdown: this.sizeBreakdown(this.systemTotals.stats?.files?.size),
						},
					],
				})
			}

			if (this.orphanedItems) {
				sections.push({
					type: 'stats',
					id: 'orphanedItems',
					title: t('openregister', 'Orphaned Items'),
					layout: 'stack',
					items: [
						{
							title: t('openregister', 'Objects'),
							count: this.orphanedItems.stats?.objects?.total || 0,
							countLabel: t('openregister', 'object{plural}', {
								plural: this.orphanedItems.stats?.objects?.total !== 1 ? 's' : '',
							}),
							icon: PackageVariantClosed,
							variant: 'warning',
							breakdown: this.objectsBreakdown(this.orphanedItems),
						},
						{
							title: t('openregister', 'Logs'),
							count: this.orphanedItems.stats?.logs?.total || 0,
							countLabel: t('openregister', 'log{plural}', {
								plural: this.orphanedItems.stats?.logs?.total !== 1 ? 's' : '',
							}),
							icon: TextBoxOutline,
							variant: 'warning',
							breakdown: this.sizeBreakdown(this.orphanedItems.stats?.logs?.size),
						},
						{
							title: t('openregister', 'Files'),
							count: this.orphanedItems.stats?.files?.total || 0,
							countLabel: t('openregister', 'file{plural}', {
								plural: this.orphanedItems.stats?.files?.total !== 1 ? 's' : '',
							}),
							icon: FileDocumentOutline,
							variant: 'warning',
							breakdown: this.sizeBreakdown(this.orphanedItems.stats?.files?.size),
						},
					],
				})
			}

			return sections
		},
		registerOptions() {
			return {
				options: registerStore.list.map(register => ({
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
		schemaOptions() {
			if (!registerStore.item) return { options: [] }

			return {
				options: schemaStore.list
					.filter(schema => registerStore.item.schemas.some(registerSchema => registerSchema.id === schema.id))
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
		selectedRegisterValue() {
			if (!registerStore.item) return null
			const register = registerStore.item
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
		selectedSchemaValue() {
			if (!schemaStore.item) return null
			const schema = schemaStore.item
			return {
				value: schema.id,
				label: schema.title,
				title: schema.title,
				schema,
			}
		},
	},
	methods: {
		handleRegisterChange(option) {
			registerStore.setItem(option)
			schemaStore.setItem(null)
		},
		handleSchemaChange(option) {
			schemaStore.setItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},
		onDateRangeChange() {
			dashboardStore.setDateRange(this.dateRange.from, this.dateRange.till)
		},
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
		sizeBreakdown(size) {
			if (!size) return null
			return { size: formatBytes(size) }
		},
	},
}
</script>

<style lang="scss" scoped>
.loadingContainer {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 16px;
	color: var(--color-text-maxcontrast);
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
