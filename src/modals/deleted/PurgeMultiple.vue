<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { deletedStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<BulkSelectionDialog
		v-if="navigationStore.dialog === 'permanentlyDeleteMultiple'"
		:name="
			n(
				'openregister',
				'Purge {count} object from database',
				'Purge {count} objects from database',
				objectsToDelete.length,
				{ count: objectsToDelete.length },
			)
		"
		:heading="t('openregister', 'Confirm Permanent Object Deletion')"
		:note="reviewNote"
		noteType="warning"
		:emptyText="
			t(
				'openregister',
				'No objects are currently selected for permanent deletion.',
			)
		"
		:submitLabel="t('openregister', 'Purge')"
		submitVariant="error"
		stepClass="delete-step"
		v-bind="reviewStateOf($data)"
		@close="closeDialog"
		@remove="removeObject"
		@submit="permanentlyDeleteMultiple()">
		<template #submitIcon>
			<TrashCanOutline :size="20" />
		</template>
	</BulkSelectionDialog>
</template>

<script>
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import BulkSelectionDialog from './BulkSelectionDialog.vue'
import {
	bulkDialogData,
	bulkDialogLifecycle,
	bulkDialogMethods,
	reviewStateOf,
	runBulkAction,
} from './bulkSelection.js'

export default {
	name: 'PurgeMultiple',
	components: {
		BulkSelectionDialog,
		TrashCanOutline,
	},

	data: bulkDialogData,

	computed: {
		/**
		 * @spec exclude Computed passthrough exposing the selected-objects list to the template; UI state helper.
		 */
		objectsToDelete() {
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
				"Review the selected objects below. You can remove any objects you don't want to permanently delete by clicking the remove button. This action cannot be undone.",
			)
		},
	},

	...bulkDialogLifecycle('permanentlyDeleteMultiple'),

	methods: {
		...bulkDialogMethods,

		/**
		 * Permanently delete multiple objects
		 *
		 * @return {Promise<void>}
		 * @spec exclude Bulk-purge confirm handler delegating to deletedStore.permanentlyDeleteMultiple; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		async permanentlyDeleteMultiple() {
			if (!this.objectsToDelete || this.objectsToDelete.length === 0) {
				this.error = t('openregister', 'No objects selected for deletion')
				return
			}

			await runBulkAction(this, this.purgeAction())
		},

		/**
		 * What a purge runs and reports.
		 *
		 * @return {object} The bulk action
		 * @spec exclude Bulk-purge confirm handler delegating to deletedStore.permanentlyDeleteMultiple; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		purgeAction() {
			return {
				run: (ids) => deletedStore.permanentlyDeleteMultiple(ids),
				done: (result) => this.purgedMessage(result),
				event: 'deleted-objects-permanently-deleted',
				closeAfter: 3000,
				failure: t(
					'openregister',
					'An error occurred while permanently deleting the objects',
				),
			}
		},

		/**
		 * The message for a finished purge: how many objects were deleted and
		 * how many failed.
		 *
		 * @param {object} result The store's answer, with deleted and failed counts
		 * @return {string} The message
		 * @spec exclude Bulk-purge confirm handler delegating to deletedStore.permanentlyDeleteMultiple; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		purgedMessage(result) {
			if (!(result.deleted > 0)) {
				return t('openregister', 'No objects were permanently deleted')
			}
			const message = t(
				'openregister',
				'Successfully permanently deleted {count} objects',
				{ count: result.deleted },
			)
			return message + this.failedNote(result)
		},

		/**
		 * The note on failed objects added to the purge message, empty when none failed.
		 *
		 * @param {object} result The store's answer, with deleted and failed counts
		 * @return {string} The note
		 * @spec exclude Bulk-purge confirm handler delegating to deletedStore.permanentlyDeleteMultiple; entity mutation lives in the store, this is modal orchestration plumbing.
		 */
		failedNote(result) {
			if (!(result.failed > 0)) {
				return ''
			}
			return t('openregister', ', {failed} failed', { failed: result.failed })
		},
	},
}
</script>
