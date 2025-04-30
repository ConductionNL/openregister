<script setup>
import { dashboardStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		name="Dashboard"
		subtitle="System Overview"
		subname="Statistics and Metrics">
		<NcAppSidebarTab id="overview-tab" name="Overview" :order="1">
			<template #icon>
				<ChartBar :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Statistics') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
					<NcSelect
						id="registerSelect"
						:options="registerOptions"
						v-model="selectedRegisterId"
						:placeholder="t('openregister', 'All Registers')"
						@update:modelValue="onRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect
						id="schemaSelect"
						:options="schemaOptions"
						v-model="selectedSchemaId"
						:placeholder="t('openregister', 'All Schemas')"
						:disabled="!selectedRegisterId"
						@update:modelValue="onSchemaChange" />
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Date Range') }}</label>
					<div class="dateRangeInputs">
						<NcDatetimePicker
							v-model="dateRange.from"
							type="date"
							:placeholder="t('openregister', 'From')"
							@update:modelValue="onDateRangeChange" />
						<NcDatetimePicker
							v-model="dateRange.till"
							type="date"
							:placeholder="t('openregister', 'To')"
							@update:modelValue="onDateRangeChange" />
					</div>
				</div>
			</div>

			<!-- System Totals Section -->
			<div class="section">
				<h3 class="section-title">
					System Totals
				</h3>
				<div v-if="dashboardStore.loading" class="loading-container">
					<NcLoadingIcon :size="20" />
					<span>Loading statistics...</span>
				</div>
				<div v-else-if="systemTotals" class="stats-container">
					<table class="statisticsTable">
						<tbody>
							<tr>
								<td>{{ t('openregister', 'Registers') }}</td>
								<td>{{ filteredRegisters.length }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Schemas') }}</td>
								<td>{{ totalSchemas }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Objects') }}</td>
								<td>{{ systemTotals.stats?.objects?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Locked') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Published') }}
								</td>
								<td>{{ systemTotals.stats?.objects?.published || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ systemTotals.stats?.logs?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ systemTotals.stats?.files?.total || 0 }}</td>
								<td>{{ formatBytes(systemTotals.stats?.files?.size || 0) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Orphaned Items Section -->
			<div class="section">
				<h3 class="section-title">
					Orphaned Items
				</h3>
				<div v-if="dashboardStore.loading" class="loading-container">
					<NcLoadingIcon :size="20" />
					<span>Loading statistics...</span>
				</div>
				<div v-else-if="orphanedItems" class="stats-container">
					<table class="statisticsTable">
						<tbody>
							<tr>
								<td>{{ t('openregister', 'Objects') }}</td>
								<td>{{ orphanedItems.stats?.objects?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Locked') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Published') }}
								</td>
								<td>{{ orphanedItems.stats?.objects?.published || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ orphanedItems.stats?.logs?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ orphanedItems.stats?.files?.total || 0 }}</td>
								<td>{{ formatBytes(orphanedItems.stats?.files?.size || 0) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="settings-tab" name="Settings" :order="2">
			<template #icon>
				<Cog :size="20" />
			</template>

			<!-- Settings Section -->
			<div class="section">
				<h3 class="section-title">
					Dashboard Settings
				</h3>
				<NcNoteCard type="info">
					Settings will be added in a future update
				</NcNoteCard>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import { NcSelect, NcDatetimePicker } from '@nextcloud/vue'

// Ensure data is loaded
dashboardStore.preload()

export default {
	name: 'DashboardSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ChartBar,
		Cog,
		NcSelect,
		NcDatetimePicker,
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
		registerOptions() {
			return this.filteredRegisters.map(register => ({
				label: register.title,
				value: register.id,
			}))
		},
		schemaOptions() {
			if (!this.selectedRegisterId) return []
			const register = this.filteredRegisters.find(r => r.id === this.selectedRegisterId)
			return register?.schemas?.map(schema => ({
				label: schema.title,
				value: schema.id,
			})) || []
		},
	},
	methods: {
		formatBytes(bytes) {
			if (!bytes || bytes === 0) return '0 KB'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
		},
		onRegisterChange(value) {
			this.selectedRegisterId = value
			this.selectedSchemaId = null // Reset schema selection when register changes
			dashboardStore.setSelectedRegisterId(value)
		},
		onSchemaChange(value) {
			this.selectedSchemaId = value
			dashboardStore.setSelectedSchemaId(value)
		},
		onDateRangeChange() {
			dashboardStore.setDateRange(this.dateRange.from, this.dateRange.till)
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

.section-title {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.loading-container {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 16px;
	color: var(--color-text-maxcontrast);
}

.stats-container {
	padding: 0 16px;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;

	td {
		padding: 4px 8px;
		border-bottom: 1px solid var(--color-border);

		&:nth-child(2),
		&:nth-child(3) {
			text-align: right;
		}
	}

	.subRow td {
		color: var(--color-text-maxcontrast);
	}

	.indented {
		padding-left: 24px;
	}

	tr:last-child td {
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
