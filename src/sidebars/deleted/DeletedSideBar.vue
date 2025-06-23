<script setup>
import { deletedStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
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
						id="registerSelect"
						v-bind="registerOptions"
						:model-value="selectedRegisterValue"
						:placeholder="t('openregister', 'All registers')"
						:input-label="t('openregister', 'Register')"
						:clearable="true"
						@update:model-value="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect
						id="schemaSelect"
						v-bind="schemaOptions"
						:model-value="selectedSchemaValue"
						:placeholder="t('openregister', 'All schemas')"
						:input-label="t('openregister', 'Schema')"
						:disabled="!registerStore.registerItem"
						:clearable="true"
						@update:model-value="handleSchemaChange" />
				</div>
				<div class="filterGroup">
					<label for="deletedBySelect">{{ t('openregister', 'Deleted By') }}</label>
					<NcSelect
						id="deletedBySelect"
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

		<NcAppSidebarTab id="stats-tab" :name="t('openregister', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Deletion Statistics') }}</h3>

				<div v-if="deletedStore.statisticsLoading" class="loading-stats">
					<NcLoadingIcon :size="32" />
					<p>{{ t('openregister', 'Loading statistics...') }}</p>
				</div>

				<div v-else>
					<div class="statCard">
						<div class="statNumber">
							{{ deletedStore.statistics.totalDeleted }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Total Deleted Items') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ deletedStore.statistics.deletedToday }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Deleted Today') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ deletedStore.statistics.deletedThisWeek }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Deleted This Week') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ deletedStore.statistics.oldestDays }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Oldest Item (days)') }}
						</div>
					</div>
				</div>
			</div>

			<!-- Top Deleters -->
			<div class="topDeleters">
				<h4>{{ t('openregister', 'Top Deleters') }}</h4>

				<div v-if="deletedStore.topDeletersLoading" class="loading-stats">
					<NcLoadingIcon :size="24" />
				</div>

				<div v-else-if="deletedStore.topDeleters.length === 0" class="no-data">
					<p>{{ t('openregister', 'No deletion data available') }}</p>
				</div>

				<NcListItem v-for="(deleter, index) in deletedStore.topDeleters"
					v-else
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
	NcListItem,
	NcDateTimePickerNative,
	NcLoadingIcon,
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'

export default {
	name: 'DeletedSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcListItem,
		NcDateTimePickerNative,
		NcLoadingIcon,
		FilterOutline,
		ChartLine,
		AccountCircle,
	},
	data() {
		return {
			activeTab: 'filters-tab',
			selectedDeletedBy: null,
			dateFrom: null,
			dateTo: null,
			filteredCount: 0,
		}
	},
	computed: {
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
		schemaOptions() {
			if (!registerStore.registerItem) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => registerStore.registerItem.schemas.includes(schema.id))
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
			if (!registerStore.registerItem) return null
			const register = registerStore.registerItem
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
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
		userOptions() {
			// Get unique users from deleted items or provide default options
			const users = new Set()
			deletedStore.deletedList.forEach(item => {
				const deletedBy = item['@self']?.deleted?.deletedBy
				if (deletedBy) {
					users.add(deletedBy)
				}
			})

			const userOptions = Array.from(users).map(user => ({
				label: user,
				value: user,
			}))

			// Add some common default users if no data
			if (userOptions.length === 0) {
				return [
					{ label: this.t('openregister', 'Admin'), value: 'admin' },
				]
			}

			return userOptions
		},
	},
	async mounted() {
		// Load required data
		if (!registerStore.registerList.length) {
			await registerStore.refreshRegisterList()
		}

		if (!schemaStore.schemaList.length) {
			await schemaStore.refreshSchemaList()
		}

		// Load statistics and top deleters
		await this.loadStatistics()
		await this.loadTopDeleters()

		// Listen for filtered count updates
		this.$root.$on('deleted-filtered-count', (count) => {
			this.filteredCount = count
		})
	},
	beforeDestroy() {
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
		 * Load deletion statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				await deletedStore.fetchStatistics()
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
				await deletedStore.fetchTopDeleters()
			} catch (error) {
				console.error('Error loading top deleters:', error)
			}
		},
		/**
		 * Handle register change
		 * @param {object} register - The selected register object
		 * @return {void}
		 */
		handleRegisterChange(register) {
			registerStore.setRegisterItem(register)
			schemaStore.setSchemaItem(null) // Clear schema when register changes
			this.applyFilters()
		},
		/**
		 * Handle schema change
		 * @param {object} schema - The selected schema object
		 * @return {void}
		 */
		handleSchemaChange(schema) {
			schemaStore.setSchemaItem(schema)
			this.applyFilters()
		},
	},
}
</script>

<style scoped>
.filterSection,
.statsSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child,
.statsSection:last-child {
	border-bottom: none;
}

.filterSection h3,
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

.filter-hint {
	margin: 8px 16px;
}

.statsSection {
	padding: 16px;
}

.loading-stats {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
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

.no-data {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	padding: 20px;
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>
