<template>
	<div class="files-sidebar">
		<div class="sidebar-header">
			<h3>{{ t('openregister', 'Search Files') }}</h3>
		</div>

		<!-- Search Field -->
		<div class="search-section">
			<NcTextField
				v-model="localSearch"
				:placeholder="t('openregister', 'Search by file name or path')"
				@update:modelValue="handleSearchInput">
				<Magnify :size="20" />
			</NcTextField>
		</div>

		<!-- Filter by Status -->
		<div class="filter-section">
			<h4>{{ t('openregister', 'Extraction Status') }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					:model-value="selectedStatus === null"
					type="radio"
					value="all"
					@update:modelValue="updateStatus(null)">
					{{ t('openregister', 'All Files') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedStatus === 'pending'"
					type="radio"
					value="pending"
					@update:modelValue="updateStatus('pending')">
					{{ t('openregister', 'Pending') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedStatus === 'processing'"
					type="radio"
					value="processing"
					@update:modelValue="updateStatus('processing')">
					{{ t('openregister', 'Processing') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedStatus === 'completed'"
					type="radio"
					value="completed"
					@update:modelValue="updateStatus('completed')">
					{{ t('openregister', 'Completed') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedStatus === 'failed'"
					type="radio"
					value="failed"
					@update:modelValue="updateStatus('failed')">
					{{ t('openregister', 'Failed') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Filter by Risk Level -->
		<div class="filter-section">
			<h4>{{ t('openregister', 'Risk Level') }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					:model-value="selectedRiskLevel === null"
					type="radio"
					value="all"
					@update:modelValue="updateRiskLevel(null)">
					{{ t('openregister', 'All Levels') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedRiskLevel === 'none'"
					type="radio"
					value="none"
					@update:modelValue="updateRiskLevel('none')">
					{{ t('openregister', 'None') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedRiskLevel === 'low'"
					type="radio"
					value="low"
					@update:modelValue="updateRiskLevel('low')">
					{{ t('openregister', 'Low') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedRiskLevel === 'medium'"
					type="radio"
					value="medium"
					@update:modelValue="updateRiskLevel('medium')">
					{{ t('openregister', 'Medium') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedRiskLevel === 'high'"
					type="radio"
					value="high"
					@update:modelValue="updateRiskLevel('high')">
					{{ t('openregister', 'High') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedRiskLevel === 'very_high'"
					type="radio"
					value="very_high"
					@update:modelValue="updateRiskLevel('very_high')">
					{{ t('openregister', 'Very High') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Clear Filters Button -->
		<div v-if="hasActiveFilters" class="clear-filters">
			<NcButton
				variant="secondary"
				@click="clearFilters">
				{{ t('openregister', 'Clear filters') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'FilesSidebar',

	components: {
		NcTextField,
		NcCheckboxRadioSwitch,
		NcButton,
		Magnify,
	},

	props: {
		/**
		 * @spec exclude two-way-bound search prop, UI plumbing
		 */
		search: {
			type: String,
			default: '',
		},
		/**
		 * @spec exclude two-way-bound extraction-status filter prop, UI plumbing
		 */
		status: {
			type: String,
			default: null,
		},
		/**
		 * @spec exclude two-way-bound risk-level filter prop, UI plumbing
		 */
		riskLevel: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			localSearch: this.search,
			selectedStatus: this.status,
			selectedRiskLevel: this.riskLevel,
			searchTimeout: null,
		}
	},

	computed: {
		/**
		 * Check if there are active filters
		 *
		 * @return {boolean} True if filters are active
		 */
		hasActiveFilters() {
			return this.selectedStatus !== null || this.localSearch !== '' || this.selectedRiskLevel !== null
		},
	},

	watch: {
		/**
		 * @param newVal
		 * @spec exclude computed filter-state binding
		 */
		search(newVal) {
			this.localSearch = newVal
		},
		/**
		 * @param newVal
		 * @spec exclude computed filter-state binding
		 */
		status(newVal) {
			this.selectedStatus = newVal
		},
		/**
		 * @param newVal
		 * @spec exclude computed filter-state binding
		 */
		riskLevel(newVal) {
			this.selectedRiskLevel = newVal
		},
	},

	methods: {
		t,

		/**
		 * Handle search input with debouncing
		 *
		 * @param {string} value - The search value
		 * @return {void}
		 * @spec exclude debounced search-emit UI plumbing
		 */
		handleSearchInput(value) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.$emit('update:search', value)
			}, 500)
		},

		/**
		 * Update the selected status filter
		 *
		 * @param {string|null} status - The status to filter by
		 * @return {void}
		 * @spec exclude filter-state writer emitting update:status, UI plumbing
		 */
		updateStatus(status) {
			this.selectedStatus = status
			this.$emit('update:status', status)
		},

		/**
		 * Update the selected risk level filter
		 *
		 * @param {string|null} level - The risk level to filter by
		 * @return {void}
		 * @spec exclude filter-state writer emitting update:riskLevel, UI plumbing
		 */
		updateRiskLevel(level) {
			this.selectedRiskLevel = level
			this.$emit('update:riskLevel', level)
		},

		/**
		 * Clear all filters
		 *
		 * @return {void}
		 * @spec exclude filter-reset emitting cleared values, UI plumbing
		 */
		clearFilters() {
			this.localSearch = ''
			this.selectedStatus = null
			this.selectedRiskLevel = null
			this.$emit('update:search', '')
			this.$emit('update:status', null)
			this.$emit('update:riskLevel', null)
		},
	},
}
</script>

<style scoped>
.files-sidebar {
	padding: 16px;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	height: 100%;
	overflow-y: auto;
	min-width: 280px;
	max-width: 350px;
}

.sidebar-header {
	margin-bottom: 20px;
}

.sidebar-header h3 {
	font-size: 18px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.search-section {
	margin-bottom: 24px;
}

.filter-section {
	margin-bottom: 24px;
}

.filter-section h4 {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 12px 0;
}

.filter-options {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.clear-filters {
	margin-top: 20px;
}

.clear-filters button {
	width: 100%;
}
</style>
