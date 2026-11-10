<template>
	<NcDialog :name="name" size="normal" noClose>
		<!-- Object Selection Review -->
		<div v-if="success === null" :class="stepClass">
			<h3 class="step-title">
				{{ heading }}
			</h3>

			<NcNoteCard :type="noteType">
				{{ note }}
			</NcNoteCard>

			<SelectedObjectsList
				:objects="objects"
				:emptyDescription="emptyText"
				:titleOf="objectTitle"
				@remove="$emit('remove', $event)" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<CloseCancelButton :label="success === null ? t('openregister', 'Cancel') : t('openregister', 'Close')" @click="$emit('close')" />
			<NcButton
				v-if="success === null"
				:disabled="loading || objects.length === 0"
				:variant="submitVariant"
				@click="$emit('submit')">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<slot v-if="!loading" name="submitIcon" />
				</template>
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import SelectedObjectsList from '../../components/shared/SelectedObjectsList.vue'
import { objectTitle } from './bulkSelection.js'

/**
 * The review step of a bulk action on deleted objects: the selected objects,
 * each removable, the outcome, and the cancel and submit buttons. The
 * dialog that uses it owns the selection and the action.
 *
 * @spec exclude Presentational dialog body shared by the bulk purge and restore dialogs.
 */
export default {
	name: 'BulkSelectionDialog',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CloseCancelButton,
		SelectedObjectsList,
	},

	props: {
		name: { type: String, required: true },
		heading: { type: String, required: true },
		note: { type: String, required: true },
		noteType: { type: String, required: true },
		emptyText: { type: String, required: true },
		submitLabel: { type: String, required: true },
		submitVariant: { type: String, required: true },
		stepClass: { type: String, required: true },
		objects: { type: Array, required: true },
		success: { type: Boolean, default: null },
		successMessage: { type: String, default: '' },
		error: { type: [Boolean, String], default: false },
		loading: { type: Boolean, default: false },
	},

	emits: ['close', 'remove', 'submit'],

	methods: {
		t,
		objectTitle,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.delete-step,
.restore-step {
	padding: 0;
}

@include step-title;
</style>
