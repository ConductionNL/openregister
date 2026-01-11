<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'copyObject'"
		:name="'Copy ' + (objectStore.objectItem?.['@self']?.name || objectStore.objectItem?.name || objectStore.objectItem?.['@self']?.title || objectStore.objectItem?.id || 'Object')"
		size="normal"
		:can-close="false">
		<div v-if="success === null">
			<p>
				Create a copy of <b>{{ objectStore.objectItem?.['@self']?.name || objectStore.objectItem?.name || objectStore.objectItem?.['@self']?.title || objectStore.objectItem?.id }}</b>
			</p>

			<div class="form-group">
				<label for="copyName">Name for the copy:</label>
				<NcTextField
					id="copyName"
					v-model="copyName"
					:placeholder="defaultCopyName"
					:disabled="loading"
					@keyup.enter="copyObject" />
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Object successfully copied as "{{ copyName }}"</p>
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
				:disabled="loading || !copyName.trim()"
				type="primary"
				@click="copyObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentCopy v-if="!loading" :size="20" />
				</template>
				Copy
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
	NcTextField,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

export default {
	name: 'CopyObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		// Icons
		ContentCopy,
		Cancel,
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			copyName: '',
			closeModalTimeout: null,
		}
	},
	computed: {
		defaultCopyName() {
			const originalName = objectStore.objectItem?.['@self']?.name
				|| objectStore.objectItem?.name
				|| objectStore.objectItem?.['@self']?.title
				|| `Object ${objectStore.objectItem?.['@self']?.id}`

			return `Copy of ${originalName}`
		},
	},
	watch: {
		'navigationStore.dialog'(newDialog) {
			if (newDialog === 'copyObject') {
				this.copyName = this.defaultCopyName
				this.success = null
				this.loading = false
				this.error = false
			}
		},
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.copyName = ''
		},
		async copyObject() {
			if (!this.copyName.trim()) {
				this.error = 'Please provide a name for the copy'
				return
			}

			this.loading = true
			this.error = false

			try {
				// Create a copy of the object with the new name
				const objectToCopy = { ...objectStore.objectItem }

				// Remove the root-level id field that should not be copied
				delete objectToCopy.id

				// Remove metadata that should not be copied
				if (objectToCopy['@self']) {
					delete objectToCopy['@self'].id
					delete objectToCopy['@self'].uuid
					delete objectToCopy['@self'].uri
					delete objectToCopy['@self'].created
					delete objectToCopy['@self'].updated
					delete objectToCopy['@self'].published
					delete objectToCopy['@self'].depublished
					delete objectToCopy['@self'].version
					delete objectToCopy['@self'].files
					delete objectToCopy['@self'].relations
					delete objectToCopy['@self'].folder
					delete objectToCopy['@self'].size
					delete objectToCopy['@self'].deleted

					// Set the new name
					objectToCopy['@self'].name = this.copyName.trim()
				}

				// If the object has a name property at root level, update it too
				if (objectToCopy.name !== undefined) {
					objectToCopy.name = this.copyName.trim()
				}
				if (objectToCopy.title !== undefined) {
					objectToCopy.title = this.copyName.trim()
				}

				// Create the new object using the proper register and schema
				const response = await objectStore.saveObject(objectToCopy, {
					register: objectStore.objectItem['@self'].register,
					schema: objectStore.objectItem['@self'].schema,
				})

				if (response.response.ok) {
					this.success = true
					this.closeModalTimeout = setTimeout(() => {
						this.closeDialog()

						// Set the new object as selected and open ViewObject modal
						objectStore.setObjectItem(response.data)
						navigationStore.setModal('viewObject')
					}, 2000)

					// Refresh the object list to show the new copy
					objectStore.refreshObjectList()
				} else {
					throw new Error('Failed to create copy')
				}
			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while copying the object'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 1rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: bold;
}
</style>
