<script setup>
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteEndpoint'"
		name="Delete Endpoint"
		:can-close="true"
		@update:open="navigationStore.setDialog(false)">
		<div class="modal">
			<p>
				Are you sure you want to delete the endpoint <b>{{ endpointStore.endpointItem?.name }}</b>?
			</p>
			<p>This action cannot be undone.</p>
		</div>
		<template #actions>
			<NcButton @click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton type="error" @click="deleteEndpoint()">
				<template #icon>
					<TrashCanOutline :size="20" />
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
} from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteEndpoint',
	components: {
		NcDialog,
		NcButton,
		Cancel,
		TrashCanOutline,
	},
	methods: {
		deleteEndpoint() {
			endpointStore.deleteEndpoint(endpointStore.endpointItem)
				.then(() => {
					navigationStore.setDialog(false)
					OCP.Toast.success('Endpoint deleted successfully')
				})
				.catch((error) => {
					OCP.Toast.error(`Error deleting endpoint: ${error.message}`)
				})
		},
	},
}
</script>

<style>
.modal {
	margin: 20px;
}
</style>

