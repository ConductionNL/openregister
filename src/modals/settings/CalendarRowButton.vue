<template>
	<NcButton
		v-if="action === 'remove'"
		variant="tertiary"
		:ariaLabel="label"
		@click="$emit('click', $event)">
		<template #icon>
			<Delete :size="20" />
		</template>
	</NcButton>
	<NcButton
		v-else
		variant="secondary"
		@click="$emit('click', $event)">
		<template #icon>
			<Plus :size="20" />
		</template>
		{{ label }}
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

/**
 * The buttons of a repeated row in the working calendar form: the icon-only
 * button that removes one row, and the labelled button that adds one. Other
 * attributes (data-testid) go to the NcButton.
 *
 * @spec exclude Presentational row button of the working calendar form.
 */
export default {
	name: 'CalendarRowButton',
	components: { NcButton, Delete, Plus },

	props: {
		/** 'remove' for the row's remove button, 'add' for the list's add button. */
		action: { type: String, default: 'add' },
		/** The button's text, or its accessible name when it removes; already translated. */
		label: { type: String, required: true },
	},

	emits: ['click'],
}
</script>
