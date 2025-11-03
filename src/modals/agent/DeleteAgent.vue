<script setup>
import { agentStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteAgent'"
		name="Delete Agent"
		:can-close="true"
		@update:open="closeDialog">
		<div class="dialog-content">
			<p>Are you sure you want to delete the agent <strong>{{ agentStore.agentItem?.name }}</strong>?</p>
			<p class="warning-text">This action cannot be undone.</p>
		</div>

		<template #actions>
			<NcButton @click="closeDialog">
				Cancel
			</NcButton>
			<NcButton
				type="error"
				:disabled="loading"
				@click="confirmDelete">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-else :size="20" />
				</template>
				Delete
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcLoadingIcon,
} from '@nextcloud/vue'

import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteAgent',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		TrashCanOutline,
	},
	data() {
		return {
			loading: false,
		}
	},
	methods: {
		async confirmDelete() {
			this.loading = true

			try {
				await agentStore.deleteAgent(agentStore.agentItem)
				this.closeDialog()
			} catch (error) {
				console.error('Error deleting agent:', error)
			} finally {
				this.loading = false
			}
		},
		closeDialog() {
			navigationStore.setDialog(null)
		},
	},
}
</script>

<style scoped>
.dialog-content {
	padding: 16px;
}

.warning-text {
	color: var(--color-error);
	font-weight: 600;
	margin-top: 12px;
}
</style>

