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
		:objects="objectsToDelete"
		:success="success"
		:successMessage="successMessage"
		:error="error"
		:loading="loading"
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
import eventBus from '../../eventBus.js'
import {
	closeBulkDialog,
	dropFromSelection,
	objectTitle,
	takeSelection,
} from './bulkSelection.js'

export default {
	name: 'PurgeMultiple',
	components: {
		BulkSelectionDialog,
		TrashCanOutline,
	},

	data() {
		return {
			success: null,
			successMessage: '',
			loading: false,
			error: false,
			closeModalTimeout: null,
			selectedObjects: [],
		}
	},

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

	watch: {
		'navigationStore.dialog': function (newValue, oldValue) {
			if (
				newValue === 'permanentlyDeleteMultiple'
				&& oldValue !== 'permanentlyDeleteMultiple'
			) {
				this.initializeSelection()
			}
		},
	},

	mounted() {
		this.initializeSelection()
	},

	methods: {
		/**
		 * Initialize selection from transfer data
		 *
		 * @return {void}
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeSelection() {
			takeSelection(this)
		},

		/**
		 * Remove object from selection
		 *
		 * @param {string} objectId - ID of object to remove
		 * @return {void}
		 * @spec exclude Removes one object from the local bulk-selection list; UI selection plumbing.
		 */
		removeObject(objectId) {
			dropFromSelection(this, objectId)
		},

		/**
		 * Close the dialog and reset state
		 *
		 * @return {void}
		 * @spec exclude Modal close handler resetting navigationStore.dialog and local state; UI plumbing.
		 */
		closeDialog() {
			closeBulkDialog(this)
		},

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

			this.loading = true

			try {
				const ids = this.objectsToDelete.map((obj) => obj.id)
				const result = await deletedStore.permanentlyDeleteMultiple(ids)

				this.success = true
				this.error = false

				this.successMessage = this.purgedMessage(result)

				// Auto-close after 3 seconds
				this.closeModalTimeout = setTimeout(this.closeDialog, 3000)

				// Emit event to refresh parent list
				eventBus.emit('deleted-objects-permanently-deleted', ids)
			} catch (error) {
				this.success = false
				this.error =
					error.message
					|| t(
						'openregister',
						'An error occurred while permanently deleting the objects',
					)
			} finally {
				this.loading = false
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
			if (result.deleted > 0) {
				const message = t(
					'openregister',
					'Successfully permanently deleted {count} objects',
					{ count: result.deleted },
				)
				return result.failed > 0
					? message
							+ t('openregister', ', {failed} failed', {
								failed: result.failed,
							})
					: message
			}
			return t('openregister', 'No objects were permanently deleted')
		},

		/**
		 * Get object title from object data
		 *
		 * @param {object} object - The object
		 * @return {string} The object title
		 */
		getObjectTitle(object) {
			return objectTitle(object)
		},
	},
}
</script>
