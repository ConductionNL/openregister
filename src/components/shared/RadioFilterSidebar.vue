<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div :class="rootClass">
		<div class="sidebar-header">
			<h3>{{ title }}</h3>
		</div>

		<!-- Search Field -->
		<div class="search-section">
			<NcTextField
				v-model="localSearch"
				:aria-label="searchLabel"
				:placeholder="searchLabel"
				@update:modelValue="handleSearchInput">
				<Magnify :size="20" />
			</NcTextField>
		</div>

		<!-- One radio group per filter -->
		<div v-for="group in groups" :key="group.key" class="filter-section">
			<h4>{{ group.heading }}</h4>
			<div class="filter-options">
				<NcCheckboxRadioSwitch
					v-for="option in group.options"
					:key="option.id"
					:modelValue="selected[group.key] === option.value"
					type="radio"
					:value="option.id"
					@update:modelValue="updateFilter(group.key, option.value)">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Clear Filters Button -->
		<div v-if="hasActiveFilters" class="clear-filters">
			<NcButton variant="secondary" @click="clearFilters">
				{{ t('openregister', 'Clear filters') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

/**
 * A list page's filter sidebar: a debounced search field and one radio group
 * per filter. `null` is the "all" value of every group.
 *
 * @spec exclude Presentational filter sidebar; the list pages own the filters.
 */
export default {
	name: 'RadioFilterSidebar',

	components: {
		NcTextField,
		NcCheckboxRadioSwitch,
		NcButton,
		Magnify,
	},

	props: {
		/** The class of the root element, which the sidebar styles hang off. */
		rootClass: {
			type: String,
			required: true,
		},

		/** The heading of the sidebar. */
		title: {
			type: String,
			required: true,
		},

		/** The label and placeholder of the search field. */
		searchLabel: {
			type: String,
			required: true,
		},

		/** The search text, two-way bound by the page. */
		search: {
			type: String,
			default: '',
		},

		/**
		 * The radio groups: `{ key, heading, options: [{ id, value, label }] }`.
		 * `id` is the radio's value attribute, `value` the filter value.
		 */
		groups: {
			type: Array,
			required: true,
		},

		/** The current value of each filter, by group key. */
		values: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:search', 'update:filter'],

	data() {
		return {
			localSearch: this.search,
			selected: { ...this.values },
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
			return (
				Object.values(this.selected).some((value) => value !== null)
				|| this.localSearch !== ''
			)
		},
	},

	watch: {
		/**
		 * @param {string} newVal
		 * @spec exclude computed filter-state binding
		 */
		search(newVal) {
			this.localSearch = newVal
		},

		/**
		 * Follow a filter only when the page changed that filter.
		 *
		 * @param {object} newValues The filter values
		 * @param {object} oldValues The previous filter values
		 * @spec exclude computed filter-state binding
		 */
		values(newValues, oldValues) {
			for (const [key, value] of Object.entries(newValues)) {
				if (value !== oldValues[key]) {
					this.selected[key] = value
				}
			}
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
		 * Select a value of one filter
		 *
		 * @param {string} key - The filter
		 * @param {*} value - The value to filter by, null for all
		 * @return {void}
		 * @spec exclude filter-state writer emitting update:filter, UI plumbing
		 */
		updateFilter(key, value) {
			this.selected[key] = value
			this.$emit('update:filter', key, value)
		},

		/**
		 * Clear all filters
		 *
		 * @return {void}
		 * @spec exclude filter-reset emitting cleared values, UI plumbing
		 */
		clearFilters() {
			this.localSearch = ''
			for (const key of Object.keys(this.selected)) {
				this.selected[key] = null
			}
			this.$emit('update:search', '')
			for (const group of this.groups) {
				this.$emit('update:filter', group.key, null)
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

@include filter-sidebar('files-sidebar, .webhooks-sidebar');
</style>
