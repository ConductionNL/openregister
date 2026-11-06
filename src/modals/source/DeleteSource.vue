<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, sourceStore } from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'deleteSource'"
		name="Delete Source"
		:success="success"
		:error="error"
		:loading="loading"
		:showConfirm="!success"
		successText="Source successfully deleted"
		:closeLabel="success ? 'Close' : 'Cancel'"
		confirmLabel="Delete"
		@cancel="closeDialog"
		@confirm="deleteSource()">
		<p v-if="!success">
			Do you want to permanently delete
			<b>{{ sourceStore.sourceItem?.title }}</b
			>? This action cannot be undone.
		</p>
	</ConfirmDialogFrame>
</template>

<script>
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'
import { confirmDialogState } from '../shared/confirmDialogState.js'

export default {
	name: 'DeleteSource',
	components: {
		ConfirmDialogFrame,
	},

	mixins: [confirmDialogState(false)],

	methods: {
		/**
		 * @spec exclude Delete-confirm handler delegating to sourceStore.deleteSource; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		async deleteSource() {
			this.loading = true

			sourceStore
				.deleteSource({
					...sourceStore.sourceItem,
				})
				.then(({ response }) => {
					this.success = response.ok
					this.error = false
					if (response.ok) {
						this.closeModalTimeout = setTimeout(this.closeDialog, 2000)
					}
				})
				.catch((error) => {
					this.success = false
					this.error =
						error.message
						|| 'An error occurred while deleting the source'
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>
