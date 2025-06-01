<script setup>
import { navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Deleted Items Management')"
		:subtitle="t('openregister', 'Filter and manage soft deleted items')"
		:subname="t('openregister', 'Restore or permanently delete items')"
		:open="navigationStore.sidebarState.deleted"
		@update:open="(e) => navigationStore.setSidebarState('deleted', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openregister', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Deleted Items') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
					<NcSelect
						:model-value="selectedRegisterValue"
						:options="registerOptions"
						:placeholder="t('openregister', 'All registers')"
						:input-label="t('openregister', 'Register')"
						:clearable="true"
						@update:model-value="handleRegisterChange">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect
						:model-value="selectedSchemaValue"
						:options="schemaOptions"
						:placeholder="t('openregister', 'All schemas')"
						:input-label="t('openregister', 'Schema')"
						:disabled="!registerStore.registerItem"
						:clearable="true"
						@update:model-value="handleSchemaChange">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label for="deletedBySelect">{{ t('openregister', 'Deleted By') }}</label>
					<NcSelect
						v-model="selectedDeletedBy"
						:options="userOptions"
						:placeholder="t('openregister', 'Any user')"
						:input-label="t('openregister', 'Deleted By')"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Deletion Date Range') }}</label>
					<NcDateTimePickerNative
						v-model="dateFrom"
						:label="t('openregister', 'From date')"
						type="date"
						@input="applyFilters" />
					<NcDateTimePickerNative
						v-model="dateTo"
						:label="t('openregister', 'To date')"
						type="date"
						@input="applyFilters" />
				</div>
			</div>

			<NcNoteCard type="info" class="filter-hint">
				{{ t('openregister', 'Use filters to narrow down deleted items by register, schema, deletion date, or user who deleted them.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="actions-tab" :name="t('openregister', 'Bulk Actions')" :order="2">
			<template #icon>
				<PlaylistCheck :size="20" />
			</template>

			<!-- Bulk Actions Section -->
			<div class="actionsSection">
				<h3>{{ t('openregister', 'Bulk Operations') }}</h3>
				<div class="actionGroup">
					<NcButton
						type="primary"
						:disabled="selectedCount === 0"
						@click="bulkRestore">
						<template #icon>
							<Restore :size="20" />
						</template>
						{{ t('openregister', 'Restore Selected ({count})', { count: selectedCount }) }}
					</NcButton>
				</div>
				<div class="actionGroup">
					<NcButton
						type="error"
						:disabled="selectedCount === 0"
						@click="bulkDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Permanently Delete Selected ({count})', { count: selectedCount }) }}
					</NcButton>
				</div>
				<div class="actionGroup">
					<NcButton
						:disabled="filteredCount === 0"
						@click="exportFiltered">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openregister', 'Export Filtered Items') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard type="warning" class="action-hint">
				{{ t('openregister', 'Permanent deletion cannot be undone. Please be careful with bulk operations.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openregister', 'Statistics')" :order="3">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Deletion Statistics') }}</h3>
				<div class="statCard">
					<div class="statNumber">
						{{ totalDeleted }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Total Deleted Items') }}
					</div>
				</div>
				<div class="statCard">
					<div class="statNumber">
						{{ deletedToday }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Deleted Today') }}
					</div>
				</div>
				<div class="statCard">
					<div class="statNumber">
						{{ deletedThisWeek }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Deleted This Week') }}
					</div>
				</div>
				<div class="statCard">
					<div class="statNumber">
						{{ oldestDays }}
					</div>
					<div class="statLabel">
						{{ t('openregister', 'Oldest Item (days)') }}
					</div>
				</div>
			</div>

			<!-- Top Deleters -->
			<div class="topDeleters">
				<h4>{{ t('openregister', 'Top Deleters') }}</h4>
				<NcListItem v-for="(deleter, index) in topDeleters"
					:key="index"
					:name="deleter.user"
					:bold="false">
					<template #icon>
						<AccountCircle :size="32" />
					</template>
					<template #subname>
						{{ t('openregister', '{count} deletions', { count: deleter.count }) }}
					</template>
				</NcListItem>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcSelect,
	NcNoteCard,
	NcButton,
	NcListItem,
	NcDateTimePickerNative,
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import PlaylistCheck from 'vue-material-design-icons/PlaylistCheck.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Download from 'vue-material-design-icons/Download.vue'
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'

export default {
	name: 'DeletedSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcButton,
		NcListItem,
		NcDateTimePickerNative,
		FilterOutline,
		PlaylistCheck,
		ChartLine,
		Restore,
		Delete,
		Download,
		AccountCircle,
	},
	data() {
		return {
			activeTab: 'filters-tab',
			selectedDeletedBy: null,
			dateFrom: null,
			dateTo: null,
			selectedCount: 0,
			filteredCount: 0,
			totalDeleted: 0,
			deletedToday: 0,
			deletedThisWeek: 0,
			oldestDays: 0,
			topDeleters: [],
		}
	},
	computed: {
		registerOptions() {
			if (!registerStore.registerList || !registerStore.registerList.length) {
				return [
					{ label: this.t('openregister', 'Sample Register'), value: 'sample-register' },
					{ label: this.t('openregister', 'Another Register'), value: 'another-register' },
					{ label: this.t('openregister', 'Test Register'), value: 'test-register' },
				]
			}
			return registerStore.registerList.map(register => ({
				label: register.title || `Register ${register.id}`,
				value: register.id,
			}))
		},
		schemaOptions() {
			if (!registerStore.registerItem || !schemaStore.schemaList || !schemaStore.schemaList.length) {
				return [
					{ label: this.t('openregister', 'Sample Schema'), value: 'sample-schema' },
					{ label: this.t('openregister', 'Another Schema'), value: 'another-schema' },
					{ label: this.t('openregister', 'Test Schema'), value: 'test-schema' },
				]
			}
			return schemaStore.schemaList
				.filter(schema => registerStore.registerItem.schemas.includes(schema.id))
				.map(schema => ({
					label: schema.title || `Schema ${schema.id}`,
					value: schema.id,
				}))
		},
		selectedRegisterValue() {
			if (!registerStore.registerItem) return null
			return {
				label: registerStore.registerItem.title || `Register ${registerStore.registerItem.id}`,
				value: registerStore.registerItem.id,
			}
		},
		selectedSchemaValue() {
			if (!schemaStore.schemaItem) return null
			return {
				label: schemaStore.schemaItem.title || `Schema ${schemaStore.schemaItem.id}`,
				value: schemaStore.schemaItem.id,
			}
		},
		userOptions() {
			// For deleted items, we might want to show users who have deleted items
			// For now, return mock data
			return [
				{ label: this.t('openregister', 'Admin'), value: 'admin' },
				{ label: this.t('openregister', 'User1'), value: 'user1' },
				{ label: this.t('openregister', 'User2'), value: 'user2' },
			]
		},
	},
	mounted() {
		// Load required data
		if (!registerStore.registerList.length) {
			registerStore.refreshRegisterList()
		}

		if (!schemaStore.schemaList.length) {
			schemaStore.refreshSchemaList()
		}

		this.loadStatistics()
		this.loadTopDeleters()

		// Listen for selection count updates
		this.$root.$on('deleted-selection-count', (count) => {
			this.selectedCount = count
		})

		// Listen for filtered count updates
		this.$root.$on('deleted-filtered-count', (count) => {
			this.filteredCount = count
		})
	},
	beforeDestroy() {
		this.$root.$off('deleted-selection-count')
		this.$root.$off('deleted-filtered-count')
	},
	methods: {
		/**
		 * Apply filters and emit to parent components
		 * @return {void}
		 */
		applyFilters() {
			const filters = {
				register: registerStore.registerItem?.id || null,
				schema: schemaStore.schemaItem?.id || null,
				deletedBy: this.selectedDeletedBy?.value || null,
				dateFrom: this.dateFrom || null,
				dateTo: this.dateTo || null,
			}
			this.$root.$emit('deleted-filters-changed', filters)
		},
		/**
		 * Execute bulk restore operation
		 * @return {void}
		 */
		bulkRestore() {
			this.$root.$emit('deleted-bulk-restore')
		},
		/**
		 * Execute bulk delete operation
		 * @return {void}
		 */
		bulkDelete() {
			this.$root.$emit('deleted-bulk-delete')
		},
		/**
		 * Export filtered items
		 * @return {void}
		 */
		exportFiltered() {
			this.$root.$emit('deleted-export-filtered')
		},
		/**
		 * Load deletion statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/deleted-items/statistics')
				// const stats = await response.json()

				// Mock data for now
				this.totalDeleted = 15
				this.deletedToday = 2
				this.deletedThisWeek = 8
				this.oldestDays = 45
			} catch (error) {
				console.error('Error loading statistics:', error)
			}
		},
		/**
		 * Load top deleters statistics
		 * @return {Promise<void>}
		 */
		async loadTopDeleters() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/deleted-items/top-deleters')
				// this.topDeleters = await response.json()

				// Mock data for now
				this.topDeleters = [
					{ user: 'admin', count: 8 },
					{ user: 'user1', count: 4 },
					{ user: 'user2', count: 3 },
				]
			} catch (error) {
				console.error('Error loading top deleters:', error)
			}
		},
		/**
		 * Handle register change
		 * @param {Object} value - The selected register value
		 * @return {void}
		 */
		handleRegisterChange(value) {
			// Find the actual register object
			const register = registerStore.registerList.find(r => r.id === value?.value)
			registerStore.setRegisterItem(register || null)
			schemaStore.setSchemaItem(null) // Clear schema when register changes
			this.applyFilters()
		},
		/**
		 * Handle schema change
		 * @param {Object} value - The selected schema value
		 * @return {void}
		 */
		handleSchemaChange(value) {
			// Find the actual schema object
			const schema = schemaStore.schemaList.find(s => s.id === value?.value)
			schemaStore.setSchemaItem(schema || null)
			this.applyFilters()
		},
	},
}
</script>

<style scoped>
.filterSection,
.actionsSection,
.statsSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child,
.actionsSection:last-child,
.statsSection:last-child {
	border-bottom: none;
}

.filterSection h3,
.actionsSection h3,
.statsSection h3 {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 16px;
	margin-bottom: 16px;
}

.filterGroup label {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.actionGroup {
	padding: 0 16px;
	margin-bottom: 12px;
}

.filter-hint,
.action-hint {
	margin: 8px 16px;
}

.statsSection {
	padding: 16px;
}

.statCard {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 12px;
	text-align: center;
}

.statNumber {
	font-size: 2rem;
	font-weight: bold;
	color: var(--color-primary);
	margin-bottom: 4px;
}

.statLabel {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.topDeleters {
	margin-top: 20px;
}

.topDeleters h4 {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>
