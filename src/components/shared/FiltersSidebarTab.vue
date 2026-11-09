<template>
	<NcAppSidebarTab
		id="filters-tab"
		:name="t('openregister', 'Filters')"
		:order="1">
		<template #icon>
			<FilterOutline :size="20" />
		</template>
		<slot />
	</NcAppSidebarTab>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppSidebarTab } from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'

/**
 * The first tab of a filter sidebar: "Filters", with the filter icon.
 * The sidebar puts its filter fields in the default slot.
 *
 * @spec exclude Presentational sidebar tab; the sidebars own the filters.
 */
export default {
	name: 'FiltersSidebarTab',
	components: {
		NcAppSidebarTab,
		FilterOutline,
	},

	methods: {
		t,
	},
}
</script>
