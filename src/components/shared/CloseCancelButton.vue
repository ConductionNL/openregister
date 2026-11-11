<template>
	<NcButton @click="$emit('click', $event)">
		<template #icon>
			<Cancel :size="20" />
		</template>
		{{
			label === null
				? success
					? t('openregister', 'Close')
					: t('openregister', 'Cancel')
				: label
		}}
	</NcButton>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

/**
 * The button that leaves a modal or dialog: the cancel icon, and "Cancel" or,
 * once the form succeeded, "Close". The `label` prop replaces that text for
 * a modal that words it differently. Other attributes (disabled, variant)
 * go to the NcButton.
 *
 * @spec exclude Presentational dialog action shared by the form modals.
 */
export default {
	name: 'CloseCancelButton',
	components: { NcButton, Cancel },

	props: {
		success: { type: Boolean, default: false },
		/** The text instead of Cancel/Close, already translated. */
		label: { type: String, default: null },
	},

	emits: ['click'],

	methods: { t },
}
</script>
