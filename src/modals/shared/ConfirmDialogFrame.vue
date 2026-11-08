<template>
	<NcDialog
		:name="name"
		:size="size"
		:noClose="noClose"
		@closing="$emit('cancel')">
		<slot />
		<NcNoteCard v-if="success && successText" type="success">
			<p>{{ successText }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<CloseCancelButton
				:label="closeLabel"
				:disabled="cancelDisabled"
				@click="$emit('cancel')" />
			<NcButton
				v-if="showConfirm"
				:disabled="loading || confirmDisabled"
				variant="error"
				@click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<slot v-if="!loading" name="confirmIcon">
						<TrashCanOutline :size="20" />
					</slot>
				</template>
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'

/**
 * The frame of a dialog that asks before an irreversible action: the question
 * in its default slot, the outcome below it and the two buttons.
 *
 * It asks to be answered through its buttons unless `noClose` is false; then
 * the dialog's own close (button, Escape) emits `cancel` as well.
 *
 * @spec exclude Presentational dialog frame shared by the confirm dialogs.
 */
export default {
	name: 'ConfirmDialogFrame',
	components: {
		CloseCancelButton,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		TrashCanOutline,
	},

	props: {
		name: { type: String, required: true },
		size: { type: String, default: 'normal' },
		noClose: { type: Boolean, default: true },
		/** Disables the cancel button; unset leaves it out of the markup. */
		cancelDisabled: { type: Boolean, default: undefined },
		closeLabel: { type: String, required: true },
		confirmLabel: { type: String, required: true },
		showConfirm: { type: Boolean, default: false },
		confirmDisabled: { type: Boolean, default: false },
		success: { type: Boolean, default: false },
		successText: { type: String, default: '' },
		error: { type: [Boolean, String], default: false },
		loading: { type: Boolean, default: false },
	},

	emits: ['cancel', 'confirm'],
}
</script>
