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
import {
	confirmDialogState,
	settleConfirmRequest,
} from '../shared/confirmDialogState.js'

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

			settleConfirmRequest(
				this,
				sourceStore.deleteSource({ ...sourceStore.sourceItem }),
				'An error occurred while deleting the source',
			)
		},
	},
}
</script>
