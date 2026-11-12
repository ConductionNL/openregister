<template>
	<div class="section">
		<h3 class="sectionTitle">
			{{ title }}
		</h3>
		<div v-if="loading" class="loadingContainer">
			<NcLoadingIcon :size="20" />
			<!-- eslint-disable-next-line @nextcloud/l10n-enforce-ellipsis -- the catalogue key has three dots -->
			<span>{{ t('openregister', 'Loading statistics...') }}</span>
		</div>
		<slot v-else />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'

/**
 * A titled statistics section of the dashboard and registers sidebars: a
 * loading line while the statistics load, the default slot after.
 *
 * @spec exclude Presentational section; the sidebars own the statistics.
 */
export default {
	name: 'SidebarStatsSection',
	components: { NcLoadingIcon },

	props: {
		title: {
			type: String,
			required: true,
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../styles/sidebars.scss' as *;

@include sidebar-section;

@include section-title;

@include sidebar-loading;
</style>
