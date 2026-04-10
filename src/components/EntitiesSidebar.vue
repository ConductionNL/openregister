<template>
	<div class="entities-sidebar">
		<div class="sidebar-header">
			<h3>{{ t('openregister', 'Search Entities') }}</h3>
		</div>

		<div class="search-section">
			<NcTextField
				:value.sync="localSearch"
				:placeholder="t('openregister', 'Search by value')"
				@input="handleSearchInput">
				<Magnify :size="20" />
			</NcTextField>
		</div>

		<div class="filter-section">
			<h4>{{ t('openregister', 'Type') }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					:checked="selectedType === null"
					type="radio"
					value="all"
					@update:checked="updateType(null)">
					{{ t('openregister', 'All Types') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedType === 'PERSON'"
					type="radio"
					value="PERSON"
					@update:checked="updateType('PERSON')">
					{{ t('openregister', 'Person') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedType === 'ORGANIZATION'"
					type="radio"
					value="ORGANIZATION"
					@update:checked="updateType('ORGANIZATION')">
					{{ t('openregister', 'Organization') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedType === 'EMAIL'"
					type="radio"
					value="EMAIL"
					@update:checked="updateType('EMAIL')">
					{{ t('openregister', 'Email') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedType === 'PHONE'"
					type="radio"
					value="PHONE"
					@update:checked="updateType('PHONE')">
					{{ t('openregister', 'Phone') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<div class="filter-section">
			<h4>{{ t('openregister', 'Category') }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					:checked="selectedCategory === null"
					type="radio"
					value="all"
					@update:checked="updateCategory(null)">
					{{ t('openregister', 'All Categories') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedCategory === 'personal_data'"
					type="radio"
					value="personal_data"
					@update:checked="updateCategory('personal_data')">
					{{ t('openregister', 'Personal Data') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedCategory === 'sensitive_pii'"
					type="radio"
					value="sensitive_pii"
					@update:checked="updateCategory('sensitive_pii')">
					{{ t('openregister', 'Sensitive PII') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="selectedCategory === 'business_data'"
					type="radio"
					value="business_data"
					@update:checked="updateCategory('business_data')">
					{{ t('openregister', 'Business Data') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

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
	name: 'EntitiesSidebar',
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
		type: {
			type: String,
			default: null,
		},
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
			return this.selectedType !== null || this.selectedCategory !== null || this.localSearch !== ''
		},
	},
	watch: {
		search(newVal) {
			this.localSearch = newVal
		},
		type(newVal) {
			this.selectedType = newVal
		},
		category(newVal) {
			this.selectedCategory = newVal
		},
	},
	methods: {
		t,
		handleSearchInput(value) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.$emit('update:search', value)
			}, 500)
		},
		updateType(type) {
			this.selectedType = type
			this.$emit('update:type', type)
		},
		updateCategory(category) {
			this.selectedCategory = category
			this.$emit('update:category', category)
		},
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
