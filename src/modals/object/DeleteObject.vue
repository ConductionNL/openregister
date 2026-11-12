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

/**
 * Where an object lives: its register, schema and id.
 *
 * @param {object|null} item The object
 * @return {{register: *, schema: *, id: *}} Its address
 * @throws {Error} When the object lacks its register, schema or id
 */
function objectAddress(item) {
	const self = item?.['@self']
	const register = self?.register
	const schema = self?.schema
	const id = self?.id

	if (!register || !schema || !id) {
		throw new Error(
			'Object is missing required metadata (register, schema, or id)',
		)
	}
	return { register, schema, id }
}

/**
 * The object type and id of an object, registering the type with the store
 * when it does not know it yet.
 *
 * @param {object|null} item The object to delete
 * @return {{type: string, id: string}} Its type slug and id
 * @throws {Error} When the object lacks its register, schema or id
 */
function registeredObjectType(item) {
	const { register, schema, id } = objectAddress(item)
	const type = objectStore.createObjectTypeSlug(register, schema)
	if (!objectStore.objectTypes.includes(type)) {
		objectStore.registerObjectType(type, schema, register)
	}
	return { type, id }
}

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
				const { type, id } = registeredObjectType(objectStore.objectItem)
				const success = await objectStore.deleteObject(type, id)
				this.showDeleteOutcome(success, type)
			} catch (error) {
				this.success = false
				this.error =
					error.message || 'An error occurred while deleting the object'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show whether the delete went through; on success refresh the list
		 * and close the dialog after a moment.
		 *
		 * @param {boolean} success Whether the store deleted the object
		 * @param {string} type The object type slug the delete went to
		 * @return {void}
		 */
		showDeleteOutcome(success, type) {
			this.success = success
			this.error = success
				? false
				: objectStore.errors?.[type] || 'Failed to delete object'
			if (success) {
				objectStore.refetchSearchCollection()
				this.closeModalTimeout = setTimeout(this.closeDialog, 2000)
			}
		},
	},
}
</script>
