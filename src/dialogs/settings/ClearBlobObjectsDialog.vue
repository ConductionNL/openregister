<template>
	<NcDialog
		:open="open"
		name="Confirm Clear Blob Objects"
		@closing="$emit('closing')">
		<div class="clear-dialog-content">
			<h3>⚠️ Confirm Clear All Blob Storage Objects</h3>
			<p>
				This operation will permanently delete all objects stored in blob
				storage mode from the database. Magic Mapper objects will NOT be
				affected.
			</p>
			<p>
				<strong>Current blob storage objects: {{ totalBlobObjects }}</strong>
			</p>
			<p class="warning-text">⚠️ This action cannot be undone!</p>
			<p><strong>This operation may take some time to complete.</strong></p>

			<div class="dialog-actions">
				<NcButton @click="$emit('closing')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="clearing"
					@click="$emit('confirm')">
					<template #icon>
						<NcLoadingIcon v-if="clearing" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{ clearing ? 'Clearing...' : 'Confirm Clear All' }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'ClearBlobObjectsDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, Delete },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		totalBlobObjects: { type: Number, default: 0 },
	},

	emits: ['closing', 'confirm'],
}
</script>

<style scoped lang="scss">
@use '../../styles/dialogs.scss' as *;

.clear-dialog-content {
	padding: 20px;
}

@include clear-dialog-content-h3;

@include clear-dialog-content-p;

.clear-dialog-content .warning-text {
	color: var(--color-error);
	font-weight: 600;
}

@include dialog-actions;
</style>
