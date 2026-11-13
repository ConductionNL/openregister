<template>
	<RebaseConfirmationDialog
		v-if="show"
		:open="show"
		:rebasing="rebasing"
		@closing="$emit('close')"
		@confirm="$emit('confirm')" />
</template>

<script>
import RebaseConfirmationDialog from '../../dialogs/settings/RebaseConfirmationDialog.vue'

/**
 * The rebase confirmation as a modal: the settings rebase dialog, shown
 * while `show` is set, reporting `close` and `confirm`.
 */
export default {
	name: 'RebaseConfirmationModal',

	components: { RebaseConfirmationDialog },

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
