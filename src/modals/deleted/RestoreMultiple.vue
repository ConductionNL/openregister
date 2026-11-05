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
import BulkSelectionDialog from './BulkSelectionDialog.vue'
import eventBus from '../../eventBus.js'
import {
	closeBulkDialog,
	dropFromSelection,
	objectTitle,
	reviewStateOf,
	takeSelection,
} from './bulkSelection.js'

export default {
	name: 'RestoreMultiple',
	components: {
		BulkSelectionDialog,
		Restore,
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

	watch: {
		'navigationStore.dialog': function (newValue, oldValue) {
			if (newValue === 'restoreMultiple' && oldValue !== 'restoreMultiple') {
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

			this.loading = true

			try {
				const ids = this.objectsToRestore.map((obj) => obj.id)
				await deletedStore.restoreMultiple(ids)

				this.success = true
				this.error = false
				this.successMessage = n(
					'openregister',
					'Successfully restored {count} object',
					'Successfully restored {count} objects',
					this.objectsToRestore.length,
					{ count: this.objectsToRestore.length },
				)

				// Auto-close after 2 seconds
				this.closeModalTimeout = setTimeout(this.closeDialog, 2000)

				// Emit event to refresh parent list
				eventBus.emit('deleted-objects-restored', ids)
			} catch (error) {
				this.success = false
				this.error =
					error.message
					|| t(
						'openregister',
						'An error occurred while restoring the objects',
					)
			} finally {
				this.loading = false
			}
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
