<template>
	<NcDialog
		v-if="show"
		name="Confirm Rebase Operation"
		:can-close="!rebasing"
		@closing="$emit('close')">
		<div class="dialog-content">
			<h3>⚠️ Confirm Rebase Operation</h3>
			<p>
				This operation will recalculate deletion times for all objects and logs based on current retention settings.
				It will also assign default owners and organizations to objects that don't have them assigned.
			</p>
			<p><strong>This operation may take some time to complete.</strong></p>
		</div>

		<template #actions>
			<NcButton
				:disabled="rebasing"
				@click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				type="error"
				:disabled="rebasing"
				@click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="rebasing" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ rebasing ? 'Rebasing...' : 'Confirm Rebase' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'RebaseConfirmationModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		Cancel,
		Refresh,
	},

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
