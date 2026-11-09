<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'deleteObject'"
		:name="
			'Delete '
			+ (objectStore.objectItem?.['@self']?.schema?.title
				? objectStore.objectItem['@self'].schema.title + ' — '
				: '')
			+ (objectName(objectStore.objectItem) || 'Object')
		"
		:success="success"
		:error="error"
		:loading="loading"
		:showConfirm="success === null"
		:successText="
			n(
				'openregister',
				'Object successfully deleted',
				'Objects successfully deleted',
				1,
			)
		"
		:closeLabel="success === null ? 'Cancel' : 'Close'"
		confirmLabel="Delete"
		@cancel="closeDialog"
		@confirm="deleteObject()">
		<p v-if="success === null">
			Do you want to permanently delete
			<span v-if="objectStore.objectItem?.['@self']?.schema?.title"
				>{{ objectStore.objectItem['@self'].schema.title.toLowerCase() }}
			</span>
			<b>{{ objectName(objectStore.objectItem) }}</b
			>? This action cannot be undone.
		</p>
	</ConfirmDialogFrame>
</template>

<script>
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'
import { confirmDialogState } from '../shared/confirmDialogState.js'
import { objectName as nameOf } from './objectName.js'

export default {
	name: 'DeleteObject',
	components: {
		ConfirmDialogFrame,
	},

	mixins: [confirmDialogState(null)],

	methods: {
		objectName: nameOf,
		/**
		 * @spec exclude Modal action plumbing — delegates deletion to objectStore.deleteObject (lifecycle owned elsewhere).
		 */
		async deleteObject() {
			this.loading = true

			try {
				const self = objectStore.objectItem?.['@self']
				const register = self?.register
				const schema = self?.schema
				const id = self?.id

				if (!register || !schema || !id) {
					throw new Error(
						'Object is missing required metadata (register, schema, or id)',
					)
				}

				const type = objectStore.createObjectTypeSlug(register, schema)
				if (!objectStore.objectTypes.includes(type)) {
					objectStore.registerObjectType(type, schema, register)
				}

				const success = await objectStore.deleteObject(type, id)
				this.success = success
				this.error = success
					? false
					: objectStore.errors?.[type] || 'Failed to delete object'
				if (success) {
					objectStore.refetchSearchCollection()
					this.closeModalTimeout = setTimeout(this.closeDialog, 2000)
				}
			} catch (error) {
				this.success = false
				this.error =
					error.message || 'An error occurred while deleting the object'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
