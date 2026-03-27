<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteObject'"
		:name="'Delete ' + (objectStore.objectItem?.['@self']?.schema?.title ? objectStore.objectItem['@self'].schema.title + ' — ' : '') + (objectStore.objectItem?.['@self']?.name || objectStore.objectItem?.name || objectStore.objectItem?.['@self']?.title || objectStore.objectItem?.id || 'Object')"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			Do you want to permanently delete
			<span v-if="objectStore.objectItem?.['@self']?.schema?.title">{{ objectStore.objectItem['@self'].schema.title.toLowerCase() }} </span>
			<b>{{ objectStore.objectItem?.['@self']?.name || objectStore.objectItem?.name || objectStore.objectItem?.['@self']?.title || objectStore.objectItem?.id }}</b>? This action cannot be undone.
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>Object successfully deleted</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? 'Cancel' : 'Close' }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				type="error"
				@click="deleteObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				Delete
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
		},
		async deleteObject() {
			this.loading = true

			try {
				const self = objectStore.objectItem?.['@self']
				const register = self?.register
				const schema = self?.schema
				const id = self?.id

				if (!register || !schema || !id) {
					throw new Error('Object is missing required metadata (register, schema, or id)')
				}

				const type = objectStore.createObjectTypeSlug(register, schema)
				if (!objectStore.objectTypes.includes(type)) {
					objectStore.registerObjectType(type, schema, register)
				}

				const success = await objectStore.deleteObject(type, id)
				this.success = success
				this.error = success ? false : (objectStore.errors?.[type] || 'Failed to delete object')
				if (success) {
					this.closeModalTimeout = setTimeout(this.closeDialog, 2000)
				}
			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while deleting the object'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
