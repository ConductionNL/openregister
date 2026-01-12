<template>
	<NcDialog
		v-if="show"
		name="Clear SOLR Index"
		message="This will permanently delete all documents from the SOLR index. This action cannot be undone."
		:can-close="!clearing"
		@closing="$emit('close')">
		<template #actions>
			<NcButton
				:disabled="clearing"
				@click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="clearing"
				type="error"
				@click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="clearing" :size="20" />
					<Delete v-else :size="20" />
				</template>
				{{ clearing ? 'Clearing...' : 'Clear Index' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'ClearIndexModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		Cancel,
		Delete,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		clearing: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],
}
</script>
