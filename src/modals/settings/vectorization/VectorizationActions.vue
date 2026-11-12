<template>
	<NcButton @click="$emit('cancel')">
		{{ t('openregister', 'Cancel') }}
	</NcButton>
	<NcButton
		variant="primary"
		:disabled="processing || empty"
		@click="$emit('start')">
		<template #icon>
			<PlayCircle v-if="!processing" :size="20" />
			<NcLoadingIcon v-else :size="20" />
		</template>
		{{
			processing
				? t('openregister', 'Processing...')
				: t('openregister', 'Start Vectorization')
		}}
	</NcButton>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'

/**
 * The cancel and start buttons of a vectorization modal.
 *
 * @spec exclude Presentational dialog actions shared by the vectorization modals.
 */
export default {
	name: 'VectorizationActions',
	components: { NcButton, NcLoadingIcon, PlayCircle },

	props: {
		processing: { type: Boolean, required: true },
		empty: { type: Boolean, required: true },
	},

	emits: ['cancel', 'start'],
}
</script>
