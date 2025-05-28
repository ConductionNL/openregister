<script setup>
import { navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Logs Management')"
		:subtitle="t('openregister', 'Filter and analyze system logs')"
		:subname="t('openregister', 'Advanced log filtering and export')"
		:open="navigationStore.sidebarState.logs"
		@update:open="(e) => navigationStore.setSidebarState('logs', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openregister', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Advanced Filters') }}</h3>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Log Level') }}</label>
					<NcSelect
						v-model="selectedLevels"
						:options="logLevelOptions"
						:placeholder="t('openregister', 'All levels')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							<span class="levelOption" :class="`level-${option.value}`">
								<AlertCircle v-if="option.value === 'error'" :size="16" />
								<Alert v-else-if="option.value === 'warning'" :size="16" />
								<Information v-else-if="option.value === 'info'" :size="16" />
								<BugOutline v-else-if="option.value === 'debug'" :size="16" />
								{{ option.label }}
							</span>
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Source') }}</label>
					<NcSelect
						v-model="selectedSources"
						:options="sourceOptions"
						:placeholder="t('openregister', 'All sources')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'User') }}</label>
					<NcSelect
						v-model="selectedUsers"
						:options="userOptions"
						:placeholder="t('openregister', 'All users')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Date Range') }}</label>
					<NcDateTimePickerNative
						v-model="dateFrom"
						:label="t('openregister', 'From date')"
						type="datetime-local"
						@input="applyFilters" />
					<NcDateTimePickerNative
						v-model="dateTo"
						:label="t('openregister', 'To date')"
						type="datetime-local"
						@input="applyFilters" />
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Message Contains') }}</label>
					<NcTextField
						v-model="messageFilter"
						:label="t('openregister', 'Search in messages')"
						:placeholder="t('openregister', 'Enter keywords...')"
						@update:modelValue="debouncedApplyFilters" />
				</div>
				<div class="filterGroup">
					<NcCheckboxRadioSwitch
						:checked="showOnlyWithErrors"
						@update:checked="updateErrorFilter">
						{{ t('openregister', 'Show only entries with stack traces') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<NcNoteCard type="info" class="filter-hint">
				{{ t('openregister', 'Use filters to narrow down log entries by level, source, time period, or content.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="export-tab" :name="t('openregister', 'Export & Actions')" :order="2">
			<template #icon>
				<Download :size="20" />
			</template>

			<!-- Export Section -->
			<div class="exportSection">
				<h3>{{ t('openregister', 'Export Options') }}</h3>
				<div class="actionGroup">
					<label>{{ t('openregister', 'Export Format') }}</label>
					<NcSelect
						v-model="exportFormat"
						:options="exportFormatOptions"
						:placeholder="t('openregister', 'Select format')"
						:clearable="false">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>
				<div class="actionGroup">
					<NcCheckboxRadioSwitch
						:checked="includeContext"
						@update:checked="(value) => includeContext = value">
						{{ t('openregister', 'Include context data') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="actionGroup">
					<NcCheckboxRadioSwitch
						:checked="includeStackTrace"
						@update:checked="(value) => includeStackTrace = value">
						{{ t('openregister', 'Include stack traces') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="actionGroup">
					<NcButton
						type="primary"
						:disabled="filteredCount === 0"
						@click="exportLogs">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openregister', 'Export Filtered Logs ({count})', { count: filteredCount }) }}
					</NcButton>
				</div>
			</div>

			<!-- Actions Section -->
			<div class="actionsSection">
				<h3>{{ t('openregister', 'Log Actions') }}</h3>
				<div class="actionGroup">
					<NcButton
						:disabled="filteredCount === 0"
						@click="clearFilteredLogs">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Clear Filtered Logs') }}
					</NcButton>
				</div>
				<div class="actionGroup">
					<NcButton
						@click="refreshLogs">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Refresh Logs') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard type="warning" class="export-hint">
				{{ t('openregister', 'Large exports may take some time. Consider using date filters for better performance.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openregister', 'Statistics')" :order="3">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Log Statistics') }}</h3>
				<div class="statCard">
					<div class="statNumber">{{ totalLogs }}</div>
					<div class="statLabel">{{ t('openregister', 'Total Log Entries') }}</div>
				</div>
				<div class="statCard error">
					<div class="statNumber">{{ errorCount }}</div>
					<div class="statLabel">{{ t('openregister', 'Errors (24h)') }}</div>
				</div>
				<div class="statCard warning">
					<div class="statNumber">{{ warningCount }}</div>
					<div class="statLabel">{{ t('openregister', 'Warnings (24h)') }}</div>
				</div>
				<div class="statCard info">
					<div class="statNumber">{{ infoCount }}</div>
					<div class="statLabel">{{ t('openregister', 'Info Messages (24h)') }}</div>
				</div>
			</div>

			<!-- Level Distribution -->
			<div class="levelDistribution">
				<h4>{{ t('openregister', 'Log Level Distribution (24h)') }}</h4>
				<div v-for="level in levelDistribution" :key="level.name" class="levelBar">
					<div class="levelLabel">
						<span :class="`level-${level.name}`">{{ level.name.toUpperCase() }}</span>
						<span class="levelCount">{{ level.count }}</span>
					</div>
					<div class="levelProgress">
						<div
							class="levelProgressBar"
							:class="`level-${level.name}`"
							:style="{ width: `${level.percentage}%` }" />
					</div>
				</div>
			</div>

			<!-- Top Sources -->
			<div class="topSources">
				<h4>{{ t('openregister', 'Top Log Sources') }}</h4>
				<NcListItem v-for="(source, index) in topSources"
					:key="index"
					:name="source.name"
					:bold="false">
					<template #icon>
						<CogOutline :size="32" />
					</template>
					<template #subname>
						{{ t('openregister', '{count} entries', { count: source.count }) }}
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
	NcTextField,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import Information from 'vue-material-design-icons/Information.vue'
import BugOutline from 'vue-material-design-icons/BugOutline.vue'

export default {
	name: 'LogsSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcButton,
		NcListItem,
		NcDateTimePickerNative,
		NcTextField,
		NcCheckboxRadioSwitch,
		FilterOutline,
		Download,
		ChartLine,
		Delete,
		Refresh,
		CogOutline,
		AlertCircle,
		Alert,
		Information,
		BugOutline,
	},
	data() {
		return {
			activeTab: 'filters-tab',
			selectedLevels: [],
			selectedSources: [],
			selectedUsers: [],
			dateFrom: '',
			dateTo: '',
			messageFilter: '',
			showOnlyWithErrors: false,
			exportFormat: { label: 'CSV', value: 'csv' },
			includeContext: true,
			includeStackTrace: false,
			filteredCount: 0,
			totalLogs: 0,
			errorCount: 0,
			warningCount: 0,
			infoCount: 0,
			levelDistribution: [],
			topSources: [],
			filterTimeout: null,
			logLevelOptions: [
				{ label: this.t('openregister', 'Error'), value: 'error' },
				{ label: this.t('openregister', 'Warning'), value: 'warning' },
				{ label: this.t('openregister', 'Info'), value: 'info' },
				{ label: this.t('openregister', 'Debug'), value: 'debug' },
			],
			sourceOptions: [
				{ label: this.t('openregister', 'ObjectService'), value: 'ObjectService' },
				{ label: this.t('openregister', 'ValidationService'), value: 'ValidationService' },
				{ label: this.t('openregister', 'AuthService'), value: 'AuthService' },
				{ label: this.t('openregister', 'CacheService'), value: 'CacheService' },
			],
			userOptions: [
				{ label: this.t('openregister', 'Admin'), value: 'admin' },
				{ label: this.t('openregister', 'User1'), value: 'user1' },
				{ label: this.t('openregister', 'User2'), value: 'user2' },
			],
			exportFormatOptions: [
				{ label: 'CSV', value: 'csv' },
				{ label: 'JSON', value: 'json' },
				{ label: 'XML', value: 'xml' },
				{ label: 'Plain Text', value: 'txt' },
			],
		}
	},
	mounted() {
		this.loadStatistics()
		this.loadLevelDistribution()
		this.loadTopSources()

		// Listen for filtered count updates
		this.$root.$on('logs-filtered-count', (count) => {
			this.filteredCount = count
		})
	},
	beforeDestroy() {
		this.$root.$off('logs-filtered-count')
	},
	methods: {
		/**
		 * Apply filters and emit to parent components
		 * @return {void}
		 */
		applyFilters() {
			const filters = {
				levels: this.selectedLevels.map(l => l.value),
				sources: this.selectedSources.map(s => s.value),
				users: this.selectedUsers.map(u => u.value),
				dateFrom: this.dateFrom || null,
				dateTo: this.dateTo || null,
				message: this.messageFilter || null,
				onlyWithErrors: this.showOnlyWithErrors,
			}
			this.$root.$emit('logs-filters-changed', filters)
		},
		/**
		 * Debounced version of applyFilters for text input
		 * @return {void}
		 */
		debouncedApplyFilters() {
			clearTimeout(this.filterTimeout)
			this.filterTimeout = setTimeout(() => {
				this.applyFilters()
			}, 500)
		},
		/**
		 * Update error filter
		 * @param {boolean} value - Whether to show only errors
		 * @return {void}
		 */
		updateErrorFilter(value) {
			this.showOnlyWithErrors = value
			this.applyFilters()
		},
		/**
		 * Export logs with current filters
		 * @return {void}
		 */
		exportLogs() {
			const exportOptions = {
				format: this.exportFormat.value,
				includeContext: this.includeContext,
				includeStackTrace: this.includeStackTrace,
			}
			this.$root.$emit('logs-export', exportOptions)
		},
		/**
		 * Clear filtered logs
		 * @return {void}
		 */
		clearFilteredLogs() {
			this.$root.$emit('logs-clear-filtered')
		},
		/**
		 * Refresh logs
		 * @return {void}
		 */
		refreshLogs() {
			this.$root.$emit('logs-refresh')
		},
		/**
		 * Load log statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/logs/statistics')
				// const stats = await response.json()

				// Mock data for now
				this.totalLogs = 1247
				this.errorCount = 23
				this.warningCount = 67
				this.infoCount = 156
			} catch (error) {
				console.error('Error loading statistics:', error)
			}
		},
		/**
		 * Load log level distribution data
		 * @return {Promise<void>}
		 */
		async loadLevelDistribution() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/logs/distribution')
				// const distribution = await response.json()

				// Mock data for now
				const data = [
					{ name: 'error', count: 23 },
					{ name: 'warning', count: 67 },
					{ name: 'info', count: 156 },
					{ name: 'debug', count: 89 },
				]

				const total = data.reduce((sum, item) => sum + item.count, 0)
				this.levelDistribution = data.map(item => ({
					...item,
					percentage: (item.count / total) * 100,
				}))
			} catch (error) {
				console.error('Error loading level distribution:', error)
			}
		},
		/**
		 * Load top log sources
		 * @return {Promise<void>}
		 */
		async loadTopSources() {
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/logs/top-sources')
				// this.topSources = await response.json()

				// Mock data for now
				this.topSources = [
					{ name: 'ObjectService', count: 342 },
					{ name: 'ValidationService', count: 234 },
					{ name: 'AuthService', count: 123 },
					{ name: 'CacheService', count: 89 },
				]
			} catch (error) {
				console.error('Error loading top sources:', error)
			}
		},
	},
}
</script>

<style scoped>
.filterSection,
.exportSection,
.actionsSection,
.statsSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child,
.exportSection:last-child,
.actionsSection:last-child,
.statsSection:last-child {
	border-bottom: none;
}

.filterSection h3,
.exportSection h3,
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

.levelOption {
	display: flex;
	align-items: center;
	gap: 8px;
}

.levelOption.level-error {
	color: var(--color-error);
}

.levelOption.level-warning {
	color: var(--color-warning);
}

.levelOption.level-info {
	color: var(--color-info);
}

.levelOption.level-debug {
	color: var(--color-text-maxcontrast);
}

.filter-hint,
.export-hint {
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

.statCard.error {
	border-left: 4px solid var(--color-error);
}

.statCard.warning {
	border-left: 4px solid var(--color-warning);
}

.statCard.info {
	border-left: 4px solid var(--color-info);
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

.levelDistribution,
.topSources {
	margin-top: 20px;
}

.levelDistribution h4,
.topSources h4 {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.levelBar {
	margin-bottom: 12px;
}

.levelLabel {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
	font-size: 0.9rem;
}

.levelLabel .level-error {
	color: var(--color-error);
	font-weight: bold;
}

.levelLabel .level-warning {
	color: var(--color-warning);
	font-weight: bold;
}

.levelLabel .level-info {
	color: var(--color-info);
	font-weight: bold;
}

.levelLabel .level-debug {
	color: var(--color-text-maxcontrast);
	font-weight: bold;
}

.levelProgress {
	background: var(--color-background-darker);
	border-radius: 4px;
	height: 8px;
	overflow: hidden;
}

.levelProgressBar {
	height: 100%;
	transition: width 0.3s ease;
}

.levelProgressBar.level-error {
	background: var(--color-error);
}

.levelProgressBar.level-warning {
	background: var(--color-warning);
}

.levelProgressBar.level-info {
	background: var(--color-info);
}

.levelProgressBar.level-debug {
	background: var(--color-text-maxcontrast);
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>
