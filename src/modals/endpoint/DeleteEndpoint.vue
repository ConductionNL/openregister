<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'deleteEndpoint'"
		name="Delete Endpoint"
		size="small"
		closable
		closeLabel="Cancel"
		confirmLabel="Delete"
		showConfirm
		@cancel="navigationStore.setDialog(false)"
		@confirm="deleteEndpoint()">
		<div class="modal">
			<p>
				Are you sure you want to delete the endpoint
				<b>{{ endpointStore.endpointItem?.name }}</b
				>?
			</p>
			<p>{{ t('openregister', 'This action cannot be undone.') }}</p>
		</div>
	</ConfirmDialogFrame>
</template>

<script>
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'

export default {
	name: 'DeleteEndpoint',
	components: { ConfirmDialogFrame },

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		deleteEndpoint() {
			endpointStore
				.deleteEndpoint(endpointStore.endpointItem)
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
