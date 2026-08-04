<template>
	<NcDialog :open="open"
		name="Confirm Clear Blob Objects"
		@closing="$emit('closing')">
		<div class="clear-dialog-content">
			<h3>⚠️ Confirm Clear All Blob Storage Objects</h3>
			<p>
				This operation will permanently delete all objects stored in blob storage mode from the database.
				Magic Mapper objects will NOT be affected.
			</p>
			<p><strong>Current blob storage objects: {{ totalBlobObjects }}</strong></p>
			<p class="warning-text">
				⚠️ This action cannot be undone!
			</p>
			<p><strong>This operation may take some time to complete.</strong></p>

			<div class="dialog-actions">
				<NcButton @click="$emit('closing')">
					Cancel
				</NcButton>
				<NcButton variant="error"
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

<style scoped>
.clear-dialog-content {
	padding: 20px;
}

.clear-dialog-content h3 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
}

.clear-dialog-content p {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 12px 0;
}

.clear-dialog-content .warning-text {
	color: var(--color-error);
	font-weight: 600;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}
</style>
