<template>
	<div class="viewActionsBar">
		<div class="viewInfo">
			<span v-if="summary" class="viewTotalCount">{{ summary }}</span>
			<slot name="info" />
			<span v-if="selectedCount > 0" class="viewIndicator">
				({{
					t('openregister', '{count} selected', { count: selectedCount })
				}})
			</span>
		</div>
		<div class="viewActions">
			<slot />
			<ActionMenu v-if="actions.length" :actions="actions" inline />
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import ActionMenu from './ActionMenu.vue'

/**
 * The bar above a list: what is shown on the left, what can be done on the right.
 *
 * @spec exclude Presentational bar shared by the list pages.
 */
export default {
	name: 'ListActionsBar',
	components: {
		ActionMenu,
	},

	props: {
		/** The count line; left out when empty. */
		summary: {
			type: String,
			default: '',
		},

		selectedCount: {
			type: Number,
			default: 0,
		},

		/** ActionMenu entries, all shown inline after the default slot. */
		actions: {
			type: Array,
			default: () => [],
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include view-actions-bar;
@include view-info;
@include view-actions;

.viewTotalCount {
	font-weight: 600;
}

.viewIndicator {
	color: var(--color-text-maxcontrast);
}
</style>
