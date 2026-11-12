<template>
	<NcButton variant="tertiary" :aria-label="label" @click="$emit('unlink')">
		<template #icon>
			<CloseCircleOutline :size="20" />
		</template>
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'

/**
 * The icon button that unlinks one item from an object in a relation tab.
 *
 * @spec exclude Presentational button; the tab unlinks the item.
 */
export default {
	name: 'UnlinkButton',
	components: {
		NcButton,
		CloseCircleOutline,
	},

	props: {
		// The accessible name, e.g. "Remove contact".
		label: {
			type: String,
			required: true,
		},
	},

	emits: ['unlink'],
}
</script>
