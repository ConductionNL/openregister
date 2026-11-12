<script setup>
/**
 * The frame of a bulk object dialog: the selection to review with its
 * explanation, the outcome, and the cancel and action buttons.
 *
 * @spec exclude Presentational dialog frame shared by the bulk object dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import SelectedObjectsList from '../../components/shared/SelectedObjectsList.vue'

defineOptions({ name: 'SelectedObjectsDialogFrame' })

defineProps({
	/** The dialog title. */
	name: { type: String, required: true },
	/** The class of the review step, naming the dialog's action. */
	stepClass: { type: String, required: true },
	/** The heading of the review step. */
	title: { type: String, required: true },
	/** The selected objects to review. */
	objects: { type: Array, required: true },
	/** What the list says when nothing is selected. */
	emptyDescription: { type: String, required: true },
	/** Null before the action ran, else whether it succeeded. */
	success: { type: Boolean, default: null },
	/** The success message. */
	successText: { type: String, default: '' },
	/** The error to show, or false. */
	error: { type: [Boolean, String], default: false },
	/** Whether the action is running. */
	loading: { type: Boolean, default: false },
	/** The action button label. */
	actionLabel: { type: String, required: true },
	/** The action button variant. */
	actionVariant: { type: String, required: true },
})

defineEmits(['close', 'remove', 'submit'])
</script>

<template>
	<NcDialog :name="name" noClose size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" :class="stepClass">
			<h3 class="step-title">
				{{ title }}
			</h3>

			<NcNoteCard type="info">
				<slot name="intro" />
			</NcNoteCard>

			<SelectedObjectsList
				:objects="objects"
				:emptyDescription="emptyDescription"
				@remove="$emit('remove', $event)" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>
				{{ successText }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null
						? t('openregister', 'Cancel')
						: t('openregister', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading || objects.length === 0"
				:variant="actionVariant"
				@click="$emit('submit')">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<slot v-if="!loading" name="actionIcon" />
				</template>
				{{ actionLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include step-title;
</style>
