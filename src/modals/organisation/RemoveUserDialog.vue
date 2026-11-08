<template>
	<NcDialog
		v-if="show"
		name="Remove User"
		:noClose="removing"
		class="remove-user-dialog"
		@closing="$emit('cancel')">
		<p>
			Are you sure you want to remove user <strong>{{ userId }}</strong> from
			this organisation?
		</p>

		<template #actions>
			<CloseCancelButton label="Cancel" :disabled="removing" @click="$emit('cancel')" />
			<NcButton :disabled="removing" variant="error" @click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="removing" :size="20" />
					<AccountMinus v-else :size="20" />
				</template>
				Remove User
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'

export default {
	name: 'RemoveUserDialog',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		CloseCancelButton,
		AccountMinus,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},

		userId: {
			type: String,
			default: '',
		},

		removing: {
			type: Boolean,
			default: false,
		},
	},
}
</script>

<style scoped>
/* Ensure the remove user dialog appears above the parent dialog */
.remove-user-dialog :deep(.modal-container) {
	z-index: 10001 !important;
}
</style>
