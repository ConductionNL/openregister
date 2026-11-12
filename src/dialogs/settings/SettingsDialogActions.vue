<template>
	<div class="dialog-actions">
		<NcButton
			:disabled="cancelDisabled || undefined"
			@click="$emit('cancel')">
			{{ t('openregister', 'Cancel') }}
		</NcButton>
		<NcButton variant="error" :disabled="busy" @click="$emit('confirm')">
			<template #icon>
				<NcLoadingIcon v-if="busy" :size="20" />
				<slot v-else name="icon" />
			</template>
			{{ label }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

/**
 * The Cancel and confirm buttons at the foot of a settings dialog's content:
 * the confirm button shows a loading icon instead of its own while busy.
 *
 * @spec exclude Presentational dialog footer shared by the settings dialogs.
 */
export default {
	name: 'SettingsDialogActions',
	components: { NcButton, NcLoadingIcon },
	props: {
		/** The confirm button's text, already translated. */
		label: { type: String, required: true },
		busy: { type: Boolean, default: false },
		cancelDisabled: { type: Boolean, default: false },
	},

	emits: ['cancel', 'confirm'],
	methods: { t },
}
</script>
