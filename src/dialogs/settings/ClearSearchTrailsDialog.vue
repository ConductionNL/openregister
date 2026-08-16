<template>
	<NcDialog
		:open="open"
		name="Confirm Clear Search Trails"
		@closing="$emit('closing')">
		<div class="clear-dialog-content">
			<h3>⚠️ Confirm Clear All Search Trails</h3>
			<p>
				This operation will permanently delete all search trail logs from the
				database. This action cannot be undone.
			</p>
			<p>
				<strong>Current search trails: {{ totalSearchTrails }}</strong>
			</p>
			<p><strong>This operation may take some time to complete.</strong></p>

			<div class="dialog-actions">
				<NcButton @click="$emit('closing')"> Cancel </NcButton>
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
	name: 'ClearSearchTrailsDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, Delete },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		totalSearchTrails: { type: Number, default: 0 },
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

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}
</style>
