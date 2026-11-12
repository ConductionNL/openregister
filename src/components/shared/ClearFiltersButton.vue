<template>
	<div class="actionGroup">
		<NcButton @click="$emit('clear')">
			<template #icon>
				<FilterOffOutline :size="20" />
			</template>
			{{ t('openregister', 'Clear filters') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'

/**
 * The "Clear filters" button under a filter sidebar's fields. The sidebar
 * styles the wrapper (`.actionGroup`) and clears its filters on `clear`.
 *
 * @spec exclude Presentational button; the sidebars own the filters.
 */
export default {
	name: 'ClearFiltersButton',
	components: {
		NcButton,
		FilterOffOutline,
	},

	emits: ['clear'],

	methods: {
		t,
	},
}
</script>
