<!--
	SPDX-FileCopyrightText: 2026 Open Register Contributors
	SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcAppSidebar
		ref="sidebar"
		:name="t('openregister', 'Entities')"
		:subtitle="t('openregister', 'Filter and search entities')"
		:open="navigationStore.sidebarState.entities"
		@update:open="(e) => navigationStore.setSidebarState('entities', e)">
		<NcAppSidebarTab
			id="search-tab"
			:name="t('openregister', 'Search')"
			:order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

			<!-- Search Field -->
			<div class="filterSection">
				<div class="filterGroup">
					<NcTextField
						:model-value="localSearch"
						:placeholder="t('openregister', 'Search by value')"
						:label="t('openregister', 'Search by value')"
						@update:modelValue="handleSearchInput">
						<template #trailing-button-icon>
							<Magnify :size="20" />
						</template>
					</NcTextField>
				</div>
			</div>

			<!-- Type Filter -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Type') }}</h3>
				<div class="filterGroup filterGroupRadio">
					<NcCheckboxRadioSwitch
						:model-value="selectedType === null"
						type="radio"
						value="all"
						name="entity_type_radio"
						@update:modelValue="updateType(null)">
						{{ t('openregister', 'All Types') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="selectedType === 'PERSON'"
						type="radio"
						value="PERSON"
						name="entity_type_radio"
						@update:modelValue="updateType('PERSON')">
						{{ t('openregister', 'Person') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="selectedType === 'ORGANIZATION'"
						type="radio"
						value="ORGANIZATION"
						name="entity_type_radio"
						@update:modelValue="updateType('ORGANIZATION')">
						{{ t('openregister', 'Organization') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="selectedType === 'EMAIL'"
						type="radio"
						value="EMAIL"
						name="entity_type_radio"
						@update:modelValue="updateType('EMAIL')">
						{{ t('openregister', 'Email') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="selectedType === 'PHONE'"
						type="radio"
						value="PHONE"
						name="entity_type_radio"
						@update:modelValue="updateType('PHONE')">
						{{ t('openregister', 'Phone') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Category Filter -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Category') }}</h3>
				<div class="filterGroup filterGroupRadio">
					<NcCheckboxRadioSwitch
						:model-value="selectedCategory === null"
						type="radio"
						value="all"
						name="entity_category_radio"
						@update:modelValue="updateCategory(null)">
						{{ t('openregister', 'All Categories') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="selectedCategory === 'personal_data'"
						type="radio"
						value="personal_data"
						name="entity_category_radio"
						@update:modelValue="updateCategory('personal_data')">
						{{ t('openregister', 'Personal Data') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="selectedCategory === 'sensitive_pii'"
						type="radio"
						value="sensitive_pii"
						name="entity_category_radio"
						@update:modelValue="updateCategory('sensitive_pii')">
						{{ t('openregister', 'Sensitive PII') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="selectedCategory === 'business_data'"
						type="radio"
						value="business_data"
						name="entity_category_radio"
						@update:modelValue="updateCategory('business_data')">
						{{ t('openregister', 'Business Data') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Clear Filters -->
			<div v-if="hasActiveFilters" class="filterSection">
				<NcButton variant="secondary" wide @click="clearFilters">
					{{ t('openregister', 'Clear filters') }}
				</NcButton>
			</div>

			<NcNoteCard type="info" class="filter-hint">
				{{
					t(
						'openregister',
						'Use filters to narrow down entities by type or category.',
					)
				}}
			</NcNoteCard>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
/**
 * @file EntitiesSideBar.vue
 * @description NcAppSidebar-based filter panel for the /entities view.
 *   Rendered from SideBars.vue when route is /entities.
 *   Communicates filter changes to EntitiesIndex.vue via root event bus
 *   (`entities-search-changed`, `entities-type-changed`, `entities-category-changed`).
 *
 * @package
 * @category Sidebar
 * @author Ruben Linde <ruben@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 */

import { t } from '@nextcloud/l10n'
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcButton,
	NcNoteCard,
} from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { navigationStore } from '../../store/store.js'
import eventBus from '../../eventBus.js'

export default {
	name: 'EntitiesSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcButton,
		NcNoteCard,
		Magnify,
	},
	data() {
		return {
			localSearch: '',
			selectedType: null,
			selectedCategory: null,
			searchTimeout: null,
		}
	},
	computed: {
		/**
		 * Expose navigationStore to the template.
		 *
		 * @spec exclude Trivial store-passthrough getter; no business contract.
		 * @return {object} The navigation store instance.
		 */
		navigationStore() {
			return navigationStore
		},
		/**
		 * Whether any filter is currently active.
		 *
		 * @return {boolean}
		 */
		hasActiveFilters() {
			return (
				this.selectedType !== null
				|| this.selectedCategory !== null
				|| this.localSearch !== ''
			)
		},
	},
	methods: {
		t,
		/**
		 * Handle search input with 500ms debounce; emits `update:search` once typing pauses.
		 *
		 * Each invocation clears the previous timeout so that only the final value is
		 * emitted after the debounce window expires.
		 *
		 * @param {string} value - The current search input value.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-files-sidebar-tabs/tasks.md#task-1
		 */
		handleSearchInput(value) {
			this.localSearch = value
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				eventBus.emit('entities-search-changed', value)
			}, 500)
		},
		/**
		 * Update the entity-type filter and notify the parent view.
		 *
		 * @param {string|null} type - Entity type value, or null for all.
		 * @return {void}
		 * @spec exclude filter-state writer emitting update:type to parent, UI plumbing
		 */
		updateType(type) {
			this.selectedType = type
			eventBus.emit('entities-type-changed', type)
		},
		/**
		 * Update the entity-category filter and notify the parent view.
		 *
		 * @param {string|null} category - Category value, or null for all.
		 * @return {void}
		 * @spec exclude filter-state writer emitting update:category to parent, UI plumbing
		 */
		updateCategory(category) {
			this.selectedCategory = category
			eventBus.emit('entities-category-changed', category)
		},
		/**
		 * Clear all active filters and notify the parent view.
		 *
		 * @return {void}
		 * @spec exclude filter-reset emitting cleared values to parent, UI plumbing
		 */
		clearFilters() {
			this.localSearch = ''
			this.selectedType = null
			this.selectedCategory = null
			eventBus.emit('entities-search-changed', '')
			eventBus.emit('entities-type-changed', null)
			eventBus.emit('entities-category-changed', null)
		},
	},
}
</script>

<style scoped>
.filterSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child {
	border-bottom: none;
}

.filterSection h3 {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 8px 0;
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 0 16px;
	margin-bottom: 8px;
}

.filterGroupRadio {
	gap: 2px;
}

.filter-hint {
	margin: 8px 16px;
}
</style>
