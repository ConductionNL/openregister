<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog name="Delete Object"
		:can-close="false"
		size="normal">
		<p v-if="success === null">
			Do you want to permanently delete <b>{{ objectStore.selectedObjects.length }}</b> {{ objectStore.selectedObjects.length > 1 ? 'objects' : 'object' }}? This action cannot be undone.
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>Object{{ objectStore.selectedObjects.length > 1 ? 's' : '' }} successfully deleted</p>
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
			<NcButton v-if="success === null"
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
	name: 'MassDeleteObject',
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
			result: null,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.startClosing = true
			navigationStore.setDialog(false)
		},
		async deleteObject() {
			this.loading = true

			objectStore.massDeleteObject(objectStore.selectedObjects)
				.then((result) => {
					this.result = result
					this.success = result.successfulIds.length > 0
					this.error = result.failedIds.length > 0
					if (result.successfulIds.length > 0) {
						// Clear selected objects and refresh the object list
						objectStore.selectedObjects = []
						objectStore.refreshObjectList()

						this.closeModalTimeout = setTimeout(() => {
							this.closeDialog()
						}, 2000)
					}
				}).catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while deleting the object'
				}).finally(() => {
					this.loading = false
				})
		},
	},
}
</script>
