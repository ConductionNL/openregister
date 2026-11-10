<template>
	<ConfirmDialogFrame
		v-if="show"
		name="Remove User"
		size="small"
		:closable="!removing"
		class="remove-user-dialog"
		:loading="removing"
		:cancelDisabled="removing"
		closeLabel="Cancel"
		confirmLabel="Remove User"
		showConfirm
		@cancel="$emit('cancel')"
		@confirm="$emit('confirm')">
		<p>
			Are you sure you want to remove user <strong>{{ userId }}</strong> from
			this organisation?
		</p>
		<template #confirmIcon>
			<AccountMinus :size="20" />
		</template>
	</ConfirmDialogFrame>
</template>

<script>
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'

export default {
	name: 'RemoveUserDialog',
	components: { ConfirmDialogFrame, AccountMinus },

	props: {
		show: { type: Boolean, required: true },
		userId: { type: String, default: '' },
		removing: { type: Boolean, default: false },
	},

	emits: ['cancel', 'confirm'],
}
</script>

<style scoped>
/* Ensure the remove user dialog appears above the parent dialog */
.remove-user-dialog :deep(.modal-container) {
	z-index: 10001 !important;
}
</style>
