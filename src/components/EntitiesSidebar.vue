<template>
	<div class="entities-sidebar">
		<div class="sidebar-header">
			<h3>{{ t('openregister', 'Search Entities') }}</h3>
		</div>

		<div class="search-section">
			<NcTextField
				v-model="localSearch"
				:aria-label="t('openregister', 'Search by value')"
				:placeholder="t('openregister', 'Search by value')"
				@update:modelValue="handleSearchInput">
				<Magnify :size="20" />
			</NcTextField>
		</div>

		<div class="filter-section">
			<h4>{{ t('openregister', 'Type') }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					:model-value="selectedType === null"
					type="radio"
					value="all"
					@update:modelValue="updateType(null)">
					{{ t('openregister', 'All Types') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedType === 'PERSON'"
					type="radio"
					value="PERSON"
					@update:modelValue="updateType('PERSON')">
					{{ t('openregister', 'Person') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedType === 'ORGANIZATION'"
					type="radio"
					value="ORGANIZATION"
					@update:modelValue="updateType('ORGANIZATION')">
					{{ t('openregister', 'Organization') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedType === 'EMAIL'"
					type="radio"
					value="EMAIL"
					@update:modelValue="updateType('EMAIL')">
					{{ t('openregister', 'Email') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedType === 'PHONE'"
					type="radio"
					value="PHONE"
					@update:modelValue="updateType('PHONE')">
					{{ t('openregister', 'Phone') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<div class="filter-section">
			<h4>{{ t('openregister', 'Category') }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					:model-value="selectedCategory === null"
					type="radio"
					value="all"
					@update:modelValue="updateCategory(null)">
					{{ t('openregister', 'All Categories') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedCategory === 'personal_data'"
					type="radio"
					value="personal_data"
					@update:modelValue="updateCategory('personal_data')">
					{{ t('openregister', 'Personal Data') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedCategory === 'sensitive_pii'"
					type="radio"
					value="sensitive_pii"
					@update:modelValue="updateCategory('sensitive_pii')">
					{{ t('openregister', 'Sensitive PII') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="selectedCategory === 'business_data'"
					type="radio"
					value="business_data"
					@update:modelValue="updateCategory('business_data')">
					{{ t('openregister', 'Business Data') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<div v-if="hasActiveFilters" class="clear-filters">
			<NcButton variant="secondary" @click="clearFilters">
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
	name: 'EntitiesSidebar',
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
		 * @spec exclude two-way-bound entity-type filter prop, UI plumbing
		 */
		type: {
			type: String,
			default: null,
		},
		/**
		 * @spec exclude two-way-bound category filter prop, UI plumbing
		 */
		category: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			localSearch: this.search,
			selectedType: this.type,
			selectedCategory: this.category,
			searchTimeout: null,
		}
	},
	computed: {
		hasActiveFilters() {
			return (
				this.selectedType !== null
				|| this.selectedCategory !== null
				|| this.localSearch !== ''
			)
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
		type(newVal) {
			this.selectedType = newVal
		},
		/**
		 * @param newVal
		 * @spec exclude computed filter-state binding
		 */
		category(newVal) {
			this.selectedCategory = newVal
		},
	},
	methods: {
		t,
		/**
		 * Handle search input with 500ms debounce; emits `update:search` once typing pauses.
		 *
		 * @param {string} value - The search value
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-files-sidebar-tabs/tasks.md#task-1
		 */
		handleSearchInput(value) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.$emit('update:search', value)
			}, 500)
		},
		/**
		 * @param type
		 * @spec exclude filter-state writer emitting update:type to parent, UI plumbing
		 */
		updateType(type) {
			this.selectedType = type
			this.$emit('update:type', type)
		},
		/**
		 * @param category
		 * @spec exclude filter-state writer emitting update:category to parent, UI plumbing
		 */
		updateCategory(category) {
			this.selectedCategory = category
			this.$emit('update:category', category)
		},
		/**
		 * @spec exclude filter-reset emitting cleared values to parent, UI plumbing
		 */
		clearFilters() {
			this.localSearch = ''
			this.selectedType = null
			this.selectedCategory = null
			this.$emit('update:search', '')
			this.$emit('update:type', null)
			this.$emit('update:category', null)
		},
	},
}
</script>

<style scoped>
.entities-sidebar {
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
