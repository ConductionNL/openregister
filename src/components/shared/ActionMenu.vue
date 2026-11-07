<template>
	<NcActions
		:primary="primary"
		:menuName="primary ? t('openregister', 'Actions') : undefined">
		<template #icon>
			<DotsHorizontal :size="20" />
		</template>
		<NcActionButton
			v-for="action in shownActions"
			:key="action.label"
			:title="action.title"
			closeAfterClick
			:disabled="action.disabled"
			@click="action.run()">
			<template #icon>
				<component :is="action.icon" :size="20" />
			</template>
			{{ action.label }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions } from '@nextcloud/vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'

/**
 * The three-dots menu of a card or a table row.
 *
 * @spec exclude Presentational wrapper around NcActions; the actions belong to the pages.
 */
export default {
	name: 'ActionMenu',
	components: {
		NcActions,
		NcActionButton,
		DotsHorizontal,
	},

	props: {
		/**
		 * Menu entries in display order: `{ label, icon, run }`, optionally
		 * `hidden`, `disabled` and `title`.
		 */
		actions: {
			type: Array,
			required: true,
		},

		primary: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		shownActions() {
			return this.actions.filter((action) => !action.hidden)
		},
	},

	methods: {
		t,
	},
}
</script>
