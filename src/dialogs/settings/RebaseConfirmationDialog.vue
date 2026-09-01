<template>
	<NcDialog
		:open="open"
		name="Confirm Rebase Operation"
		@closing="$emit('closing')">
		<div class="rebase-dialog-content">
			<h3>⚠️ Confirm Rebase Operation</h3>
			<p>
				This operation will recalculate deletion times for all objects and
				logs based on current retention settings. It will also assign default
				owners and organizations to objects that don't have them assigned.
			</p>
			<p><strong>This operation may take some time to complete.</strong></p>

			<div class="dialog-actions">
				<NcButton @click="$emit('closing')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="rebasing"
					@click="$emit('confirm')">
					<template #icon>
						<NcLoadingIcon v-if="rebasing" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ rebasing ? 'Rebasing...' : 'Confirm Rebase' }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'RebaseConfirmationDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, Refresh },
	props: {
		open: { type: Boolean, required: true },
		rebasing: { type: Boolean, default: false },
	},

	emits: ['closing', 'confirm'],
}
</script>

<style scoped>
.rebase-dialog-content {
	padding: 20px;
}

.rebase-dialog-content h3 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
}

.rebase-dialog-content p {
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
