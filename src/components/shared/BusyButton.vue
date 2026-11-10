<template>
	<NcButton @click="$emit('click', $event)">
		<template #icon>
			<NcLoadingIcon v-if="busy" :size="20" />
			<slot v-else name="icon" />
		</template>
		<slot />
	</NcButton>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

/**
 * A dialog's action button that shows a spinner in place of its icon while
 * the action runs. The `icon` slot holds the idle icon, the default slot the
 * label; other attributes (variant, disabled) go to the NcButton.
 *
 * @spec exclude Presentational dialog action shared by the import and export dialogs.
 */
export default {
	name: 'BusyButton',
	components: { NcButton, NcLoadingIcon },

	props: {
		/** Whether the action is running. */
		busy: { type: Boolean, default: false },
	},

	emits: ['click'],
}
</script>
