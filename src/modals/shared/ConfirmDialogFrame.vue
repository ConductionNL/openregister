<template>
	<NcDialog :name="name" size="normal" noClose>
		<slot />
		<NcNoteCard v-if="success && successText" type="success">
			<p>{{ successText }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<CloseCancelButton :label="closeLabel" @click="$emit('cancel')" />
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
