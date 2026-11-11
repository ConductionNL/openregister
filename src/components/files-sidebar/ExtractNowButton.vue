<template>
	<NcButton :disabled="extracting" variant="primary" @click="$emit('extract')">
		<template v-if="extracting" #icon>
			<NcLoadingIcon :size="20" />
		</template>
		{{ t('openregister', 'Extract now') }}
	</NcButton>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

/**
 * The extraction tab's "Extract now" button, spinning while it extracts.
 *
 * @spec exclude Presentational button; the extraction tab starts the extraction.
 */
export default {
	name: 'ExtractNowButton',
	components: {
		NcButton,
		NcLoadingIcon,
	},

	props: {
		extracting: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['extract'],

	methods: { t },
}
</script>
