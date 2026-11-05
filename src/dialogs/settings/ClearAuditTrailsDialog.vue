<template>
	<NcDialog
		:open="open"
		name="Confirm Clear Audit Trails"
		@closing="$emit('closing')">
		<div class="clear-dialog-content">
			<h3>⚠️ Confirm Clear All Audit Trails</h3>
			<p>
				This operation will permanently delete all audit trail logs from the
				database. This action cannot be undone.
			</p>
			<p>
				<strong>Current audit trails: {{ totalAuditTrails }}</strong>
			</p>
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
	name: 'ClearAuditTrailsDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, Delete },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		totalAuditTrails: { type: Number, default: 0 },
	},

	emits: ['closing', 'confirm'],
}
</script>

<style scoped lang="scss">
@use '../../styles/dialogs.scss' as *;

.clear-dialog-content {
	padding: 20px;
}

@include clear-dialog-content-h3-rules-2;
</style>
