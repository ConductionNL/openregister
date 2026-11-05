<template>
	<NcButton @click="$emit('click')">
		<template #icon>
			<Cancel :size="20" />
		</template>
		{{ success ? t('openregister', 'Close') : t('openregister', 'Cancel') }}
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import Cancel from 'vue-material-design-icons/Cancel.vue'

/**
 * The button that leaves a form modal: cancel, or close once the form succeeded.
 *
 * @spec exclude Presentational dialog action shared by the form modals.
 */
export default {
	name: 'CloseCancelButton',
	components: { NcButton, Cancel },

	props: {
		success: { type: Boolean, default: false },
	},

	emits: ['click'],

	methods: { t },
}
</script>
