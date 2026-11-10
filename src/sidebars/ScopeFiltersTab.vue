<template>
	<FiltersSidebarTab>
		<!-- Filter Section -->
		<div class="filterSection">
			<h3>{{ title }}</h3>
			<ScopeFilterFields
				@registerChange="$emit('registerChange', $event)"
				@schemaChange="$emit('schemaChange', $event)" />
			<slot />
		</div>

		<ClearFiltersButton v-if="clearable" @clear="$emit('clear')" />

		<NcNoteCard type="info" class="filter-hint">
			{{ hint }}
		</NcNoteCard>
	</FiltersSidebarTab>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import ClearFiltersButton from '../components/shared/ClearFiltersButton.vue'
import FiltersSidebarTab from '../components/shared/FiltersSidebarTab.vue'
import ScopeFilterFields from './ScopeFilterFields.vue'

/**
 * The filters tab of a register/schema scoped list sidebar: its heading,
 * the register and schema pickers, the sidebar's own fields in the default
 * slot, an optional "Clear filters" button and the hint under them.
 *
 * @spec exclude Presentational sidebar tab; the sidebars own the filters.
 */
export default {
	name: 'ScopeFiltersTab',
	components: {
		ClearFiltersButton,
		FiltersSidebarTab,
		NcNoteCard,
		ScopeFilterFields,
	},

	props: {
		title: {
			type: String,
			required: true,
		},

		hint: {
			type: String,
			required: true,
		},

		clearable: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['registerChange', 'schemaChange', 'clear'],
}
</script>

<style scoped lang="scss">
@use '../styles/sidebars.scss' as *;

@include divided-sections(filterSection);

@include action-group;

.filter-hint {
	margin: 8px 16px;
}
</style>
