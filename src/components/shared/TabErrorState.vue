<template>
	<NcEmptyContent :name="name" :description="description">
		<template #icon>
			<AlertCircleOutline :size="44" />
		</template>
	</NcEmptyContent>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

/**
 * The state a sidebar tab shows when its data could not be loaded.
 *
 * @spec exclude Presentational error state; the tabs own the message.
 */
export default {
	name: 'TabErrorState',
	components: {
		NcEmptyContent,
		AlertCircleOutline,
	},

	props: {
		name: {
			type: String,
			required: true,
		},

		description: {
			type: String,
			default: '',
		},
	},
}
</script>
