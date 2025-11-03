<template>
	<NcDialog
		v-if="show"
		:name="'Remove User'"
		:can-close="!removing"
		class="remove-user-dialog"
		@closing="$emit('cancel')">
		<p>Are you sure you want to remove user <strong>{{ userId }}</strong> from this organisation?</p>

		<template #actions>
			<NcButton
				:disabled="removing"
				@click="$emit('cancel')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="removing"
				type="error"
				@click="$emit('confirm')">
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
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'

export default {
	name: 'RemoveUserDialog',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		Cancel,
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
