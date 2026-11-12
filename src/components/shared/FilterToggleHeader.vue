<template>
	<div class="viewHeader">
		<div class="viewHeaderTitle">
			<h1 class="viewHeaderTitleIndented">
				{{ title }}
			</h1>
			<NcButton
				variant="tertiary"
				:aria-label="t('openregister', 'Toggle search sidebar')"
				@click="$emit('toggle')">
				<template #icon>
					<FilterVariant :size="20" />
				</template>
				{{
					sidebarOpen
						? t('openregister', 'Hide Filters')
						: t('openregister', 'Show Filters')
				}}
			</NcButton>
		</div>
		<p>
			{{ description }}
		</p>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'

/**
 * The header of a list page: its title, what it holds, and the button that
 * shows or hides the filters.
 *
 * @spec exclude Presentational header shared by the list pages.
 */
export default {
	name: 'FilterToggleHeader',
	components: { FilterVariant, NcButton },
	props: {
		title: { type: String, required: true },
		description: { type: String, required: true },
		sidebarOpen: { type: Boolean, default: false },
	},

	emits: ['toggle'],
	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

.viewHeader {
	margin-bottom: 20px;
}

@include view-header-title;
@include view-header-title-indented;
@include view-header-p;
</style>
