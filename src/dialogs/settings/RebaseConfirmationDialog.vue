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

			<SettingsDialogActions
				:busy="rebasing"
				:label="rebasing ? 'Rebasing...' : 'Confirm Rebase'"
				@cancel="$emit('closing')"
				@confirm="$emit('confirm')">
				<template #icon>
					<Refresh :size="20" />
				</template>
			</SettingsDialogActions>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import SettingsDialogActions from './SettingsDialogActions.vue'

export default {
	name: 'RebaseConfirmationDialog',
	components: { NcDialog, Refresh, SettingsDialogActions },
	props: {
		open: { type: Boolean, required: true },
		rebasing: { type: Boolean, default: false },
	},

	emits: ['closing', 'confirm'],
}
</script>

<style scoped lang="scss">
@use '../../styles/dialogs.scss' as *;

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

@include dialog-actions;
</style>
