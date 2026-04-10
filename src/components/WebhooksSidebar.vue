<template>
	<div class="webhooks-sidebar">
		<div class="sidebar-header">
			<h3>{{ t('openregister', 'Search Webhooks') }}</h3>
		</div>

		<!-- Search Field -->
		<div class="search-section">
			<NcTextField
				:value.sync="localSearch"
				:placeholder="t('openregister', 'Search by name or URL')"
				@input="handleSearchInput">
				<Magnify :size="20" />
			</NcTextField>
		</div>

		<!-- Filter by Status -->
		<div class="filter-section">
			<h4>{{ t('openregister', 'Status') }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					:checked="selectedEnabled === null"
					type="radio"
					value="all"
					@update:checked="updateEnabled(null)">
					{{ t('openregister', 'All Webhooks') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedEnabled === true"
					type="radio"
					value="enabled"
					@update:checked="updateEnabled(true)">
					{{ t('openregister', 'Enabled') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedEnabled === false"
					type="radio"
					value="disabled"
					@update:checked="updateEnabled(false)">
					{{ t('openregister', 'Disabled') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Clear Filters Button -->
		<div v-if="hasActiveFilters" class="clear-filters">
			<NcButton
				type="secondary"
				@click="clearFilters">
				{{ t('openregister', 'Clear Filters') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'WebhooksSidebar',

	components: {
		NcTextField,
		NcCheckboxRadioSwitch,
		NcButton,
		Magnify,
	},

	props: {
		search: {
			type: String,
			default: '',
		},
		enabled: {
			type: Boolean,
			default: null,
		},
	},

	data() {
		return {
			localSearch: this.search,
			selectedEnabled: this.enabled,
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
			return this.selectedEnabled !== null || this.localSearch !== ''
		},
	},

	watch: {
		search(newVal) {
			this.localSearch = newVal
		},
		enabled(newVal) {
			this.selectedEnabled = newVal
		},
	},

	methods: {
		t,

		/**
		 * Handle search input with debouncing
		 *
		 * @param {string} value - The search value
		 * @return {void}
		 */
		handleSearchInput(value) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.$emit('update:search', value)
			}, 500)
		},

		/**
		 * Update the selected enabled filter
		 *
		 * @param {boolean|null} enabled - The enabled status to filter by
		 * @return {void}
		 */
		updateEnabled(enabled) {
			this.selectedEnabled = enabled
			this.$emit('update:enabled', enabled)
		},

		/**
		 * Clear all filters
		 *
		 * @return {void}
		 */
		clearFilters() {
			this.localSearch = ''
			this.selectedEnabled = null
			this.$emit('update:search', '')
			this.$emit('update:enabled', null)
		},
	},
}
</script>

<style scoped>
.webhooks-sidebar {
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
