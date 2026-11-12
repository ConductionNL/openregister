<template>
	<NcActions
		:primary="primary"
		:forceName="inline || undefined"
		:inline="inline ? shownActions.length : undefined"
		:menuName="primary || inline ? t('openregister', 'Actions') : undefined">
		<template #icon>
			<DotsHorizontal :size="20" />
		</template>
		<NcActionButton
			v-for="action in shownActions"
			:key="action.label"
			:class="action.class"
			:title="action.title"
			closeAfterClick
			:disabled="action.disabled"
			@click="action.run()">
			<template #icon>
				<component :is="action.icon" :size="20" :class="action.iconClass" />
			</template>
			{{ action.label }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { NcActionButton, NcActions } from '@nextcloud/vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import l10nMethods from '../../composables/l10nMethods.js'

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

	mixins: [l10nMethods],

	props: {
		/**
		 * Menu entries in display order: `{ label, icon, run }`, optionally
		 * `hidden`, `disabled`, `title`, `class` and `iconClass`.
		 */
		actions: {
			type: Array,
			required: true,
		},

		primary: {
			type: Boolean,
			default: false,
		},

		/** Shows every entry as a named button instead of behind the menu. */
		inline: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		shownActions() {
			return this.actions.filter((action) => !action.hidden)
		},
	},
}
</script>
