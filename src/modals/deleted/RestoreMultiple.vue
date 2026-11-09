<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { deletedStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<BulkSelectionDialog
		v-if="navigationStore.dialog === 'restoreMultiple'"
		:name="
			n(
				'openregister',
				'Restore {count} object',
				'Restore {count} objects',
				objectsToRestore.length,
				{ count: objectsToRestore.length },
			)
		"
		:heading="t('openregister', 'Confirm Object Restoration')"
		:note="reviewNote"
		noteType="info"
		:emptyText="
			t('openregister', 'No objects are currently selected for restoration.')
		"
		:submitLabel="t('openregister', 'Restore')"
		submitVariant="primary"
		stepClass="restore-step"
		v-bind="reviewStateOf($data)"
		@close="closeDialog"
		@remove="removeObject"
		@submit="restoreMultiple()">
		<template #submitIcon>
			<Restore :size="20" />
		</template>
	</BulkSelectionDialog>
</template>

<script>
import Restore from 'vue-material-design-icons/Restore.vue'
import { bulkDialog, BulkSelectionDialog, reviewStateOf, runBulkAction } from './bulkDialog.js'

export default {
	name: 'RestoreMultiple',
	components: {
		BulkSelectionDialog,
		Restore,
	},

	mixins: [bulkDialog('restoreMultiple')],

	computed: {
		/**
		 * @spec exclude Computed passthrough exposing the selected-objects list to the template; UI state helper.
		 */
		objectsToRestore() {
			return this.selectedObjects
		},

		/**
		 * The note above the selection.
		 *
		 * @return {string} The note
		 * @spec exclude Computed dialog text; UI plumbing.
		 */
		reviewNote() {
			return t(
				'openregister',
				"Review the selected objects below. You can remove any objects you don't want to restore by clicking the remove button. Objects will be restored to their original location.",
			)
		},
	},

	methods: {
		/**
		 * Restore multiple objects
		 *
		 * @return {Promise<void>}
		 * @spec exclude Bulk-restore confirm handler delegating to deletedStore.restoreMultiple; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		async restoreMultiple() {
			if (!this.objectsToRestore || this.objectsToRestore.length === 0) {
				this.error = t('openregister', 'No objects selected for restoration')
				return
			}

			await runBulkAction(this, this.restoreAction())
		},

		/**
		 * What a restore runs and reports.
		 *
		 * @return {object} The bulk action
		 * @spec exclude Bulk-restore confirm handler delegating to deletedStore.restoreMultiple; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		restoreAction() {
			return {
				run: (ids) => deletedStore.restoreMultiple(ids),
				done: () => this.restoredMessage(),
				event: 'deleted-objects-restored',
				closeAfter: 2000,
				failure: t(
					'openregister',
					'An error occurred while restoring the objects',
				),
			}
		},

		/**
		 * The message for a finished restore.
		 *
		 * @return {string} The message
		 * @spec exclude Bulk-restore confirm handler delegating to deletedStore.restoreMultiple; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		restoredMessage() {
			return n(
				'openregister',
				'Successfully restored {count} object',
				'Successfully restored {count} objects',
				this.objectsToRestore.length,
				{ count: this.objectsToRestore.length },
			)
		},
	},
}
</script>
