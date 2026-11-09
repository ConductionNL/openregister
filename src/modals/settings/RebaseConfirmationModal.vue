<template>
	<ConfirmDialogFrame
		v-if="show"
		name="Confirm Rebase Operation"
		size="small"
		:closable="!rebasing"
		:loading="rebasing"
		:cancelDisabled="rebasing"
		closeLabel="Cancel"
		:confirmLabel="rebasing ? 'Rebasing...' : 'Confirm Rebase'"
		showConfirm
		@cancel="$emit('close')"
		@confirm="$emit('confirm')">
		<div class="dialog-content">
			<h3>⚠️ Confirm Rebase Operation</h3>
			<p>
				This operation will recalculate deletion times for all objects and
				logs based on current retention settings. It will also assign default
				owners and organizations to objects that don't have them assigned.
			</p>
			<p><strong>This operation may take some time to complete.</strong></p>
		</div>
		<template #confirmIcon>
			<Refresh :size="20" />
		</template>
	</ConfirmDialogFrame>
</template>

<script>
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'

export default {
	name: 'RebaseConfirmationModal',

	components: { ConfirmDialogFrame, Refresh },

	props: {
		show: {
			type: Boolean,
			default: false,
		},

		rebasing: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],
}
</script>

<style scoped>
.dialog-content {
	padding: 0 20px;
}

.dialog-content h3 {
	color: var(--color-error);
	margin-bottom: 1rem;
	font-size: 1.2rem;
}

.dialog-content p {
	color: var(--color-text-light);
	line-height: 1.5;
	margin-bottom: 1rem;
}

.dialog-content p:last-child {
	margin-bottom: 0;
}

.dialog-content strong {
	color: var(--color-text);
}
</style>
