<template>
	<NcAppSidebarTab
		id="stats-tab"
		:name="t('openregister', 'Statistics')"
		:order="2">
		<template #icon>
			<ChartLine :size="20" />
		</template>
		<slot />
	</NcAppSidebarTab>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppSidebarTab } from '@nextcloud/vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'

/**
 * The second tab of a filter sidebar: "Statistics", with the chart icon.
 * The sidebar puts its statistics in the default slot.
 *
 * @spec exclude Presentational sidebar tab; the sidebars own the statistics.
 */
export default {
	name: 'StatisticsSidebarTab',
	components: {
		NcAppSidebarTab,
		ChartLine,
	},

	methods: {
		t,
	},
}
</script>
