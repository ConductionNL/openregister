<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'massCopyObjects'"
		:name="`Copy ${objectStore.selectedObjects.length} Objects`"
		size="normal"
		:can-close="false">
		<div v-if="success === null">
			<p>
				Create copies of <b>{{ objectStore.selectedObjects.length }} selected objects</b>
			</p>

			<div class="form-group">
				<label for="namingPattern">Naming pattern for copies:</label>
				<NcSelect
					v-model="selectedNamingPattern"
					:options="namingPatternOptions"
					:disabled="loading"
					label="label"
					track-by="value" />
				<p class="help-text">
					Preview: "{{ getPreviewName(objectStore.selectedObjects[0]) }}"
				</p>
			</div>

			<div v-if="selectedNamingPattern?.value === 'custom'" class="form-group">
				<label for="customPattern">Custom pattern:</label>
				<NcTextField
					id="customPattern"
					v-model="customPattern"
					placeholder="Copy of {name}"
					:disabled="loading"
					@input="updateCustomPreview" />
				<p class="help-text">
					Use {name} for the original name, {id} for the original ID
				</p>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Successfully copied {{ successCount }} of {{ totalCount }} objects</p>
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
				type="primary"
				@click="copyObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentCopy v-if="!loading" :size="20" />
				</template>
				Copy All
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
	NcSelect,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

export default {
	name: 'MassCopyObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcSelect,
		// Icons
		ContentCopy,
		Cancel,
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			successCount: 0,
			totalCount: 0,
			closeModalTimeout: null,
			selectedNamingPattern: null,
			customPattern: 'Copy of {name}',
			namingPatternOptions: [
				{
					label: 'Copy of [Original Name]',
					value: 'copy_of',
					pattern: 'Copy of {name}',
				},
				{
					label: '[Original Name] - Copy',
					value: 'name_copy',
					pattern: '{name} - Copy',
				},
				{
					label: '[Original Name] (Copy)',
					value: 'name_copy_parentheses',
					pattern: '{name} (Copy)',
				},
				{
					label: 'Custom pattern',
					value: 'custom',
					pattern: 'Copy of {name}',
				},
			],
		}
	},
	watch: {
		'navigationStore.dialog'(newDialog) {
			if (newDialog === 'massCopyObjects') {
				this.selectedNamingPattern = this.namingPatternOptions[0]
				this.customPattern = 'Copy of {name}'
				this.success = null
				this.loading = false
				this.error = false
				this.successCount = 0
				this.totalCount = 0
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
			this.successCount = 0
			this.totalCount = 0
			// Clear selection after closing
			objectStore.selectedObjects = []
		},
		getPreviewName(object) {
			if (!object) return 'Preview Name'

			const originalName = object['@self']?.name
				|| object.name
				|| object['@self']?.title
				|| `Object ${object['@self']?.id}`

			const pattern = this.selectedNamingPattern?.value === 'custom'
				? this.customPattern
				: this.selectedNamingPattern?.pattern || 'Copy of {name}'

			return pattern
				.replace('{name}', originalName)
				.replace('{id}', object['@self']?.id || object.id || 'ID')
		},
		updateCustomPreview() {
			// Trigger reactivity for preview update
			this.$forceUpdate()
		},
		async copyObjects() {
			this.loading = true
			this.error = false
			this.successCount = 0
			this.totalCount = objectStore.selectedObjects.length

			const pattern = this.selectedNamingPattern?.value === 'custom'
				? this.customPattern
				: this.selectedNamingPattern?.pattern || 'Copy of {name}'

			try {
				for (const object of objectStore.selectedObjects) {
					try {
						// Create a copy of the object
						const objectToCopy = { ...object }

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
						}

						// Generate new name using the pattern
						const originalName = object['@self']?.name
							|| object.name
							|| object['@self']?.title
							|| `Object ${object['@self']?.id}`

						const newName = pattern
							.replace('{name}', originalName)
							.replace('{id}', object['@self']?.id || object.id || 'ID')

						// Set the new name
						if (objectToCopy['@self']) {
							objectToCopy['@self'].name = newName
						}
						if (objectToCopy.name !== undefined) {
							objectToCopy.name = newName
						}
						if (objectToCopy.title !== undefined) {
							objectToCopy.title = newName
						}

						// Create the new object using the proper register and schema
						const response = await objectStore.saveObject(objectToCopy, {
							register: object['@self'].register,
							schema: object['@self'].schema,
						})

						if (response.response.ok) {
							this.successCount++
						}
					} catch (error) {
						console.error('Failed to copy object:', object['@self']?.id, error)
						// Continue with other objects even if one fails
					}
				}

				if (this.successCount > 0) {
					this.success = true
					// Refresh the object list to show the new copies
					objectStore.refreshObjectList()
					this.closeModalTimeout = setTimeout(this.closeDialog, 3000)
				} else {
					throw new Error('Failed to copy any objects')
				}
			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while copying the objects'
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

.help-text {
	margin-top: 0.25rem;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}
</style>
