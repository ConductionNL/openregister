<script setup>
import { deletedStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'restoreMultiple'"
		:name="`Restore ${objectsToRestore.length} object${objectsToRestore.length !== 1 ? 's' : ''}`"
		size="normal"
		:can-close="false">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="restore-step">
			<h3 class="step-title">
				Confirm Object Restoration
			</h3>

			<NcNoteCard type="info">
				Review the selected objects below. You can remove any objects you don't want to restore by clicking the remove button. Objects will be restored to their original location.
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>Selected Objects ({{ objectsToRestore.length }})</h4>

				<div v-if="objectsToRestore.length" class="selected-objects-list">
					<div v-for="obj in objectsToRestore"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{ getObjectTitle(obj) }}</strong>
							<p class="object-id">
								ID: {{ obj.id }}
							</p>
						</div>
						<NcButton type="tertiary"
							:aria-label="`Remove ${getObjectTitle(obj)}`"
							@click="removeObject(obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-else name="No objects selected">
					<template #description>
						No objects are currently selected for restoration.
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
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
				:disabled="loading || objectsToRestore.length === 0"
				type="primary"
				@click="restoreMultiple()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Restore v-if="!loading" :size="20" />
				</template>
				Restore
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'RestoreMultiple',
	components: {
		NcDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		Restore,
		Cancel,
		Close,
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
		objectsToRestore() {
			return this.selectedObjects
		},
	},
	watch: {
		'navigationStore.dialog'(newValue, oldValue) {
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
		 * @return {void}
		 */
		initializeSelection() {
			const data = deletedStore.selectedForBulkAction || []
			this.selectedObjects.splice(0, this.selectedObjects.length, ...data)
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		/**
		 * Remove object from selection
		 * @param {string} objectId - ID of object to remove
		 * @return {void}
		 */
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(obj => obj.id !== objectId)
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		/**
		 * Close the dialog and reset state
		 * @return {void}
		 */
		closeDialog() {
			navigationStore.setDialog(false)
			deletedStore.clearSelectedForBulkAction()
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.successMessage = ''
			this.loading = false
			this.error = false
		},

		/**
		 * Restore multiple objects
		 * @return {Promise<void>}
		 */
		async restoreMultiple() {
			if (!this.objectsToRestore || this.objectsToRestore.length === 0) {
				this.error = 'No objects selected for restoration'
				return
			}

			this.loading = true

			try {
				const ids = this.objectsToRestore.map(obj => obj.id)
				await deletedStore.restoreMultiple(ids)

				this.success = true
				this.error = false
				this.successMessage = `Successfully restored ${this.objectsToRestore.length} object${this.objectsToRestore.length !== 1 ? 's' : ''}`

				// Auto-close after 2 seconds
				this.closeModalTimeout = setTimeout(this.closeDialog, 2000)

				// Emit event to refresh parent list
				this.$root.$emit('deleted-objects-restored', ids)
			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while restoring the objects'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get object title from object data
		 * @param {object} object - The object
		 * @return {string} The object title
		 */
		getObjectTitle(object) {
			return object?.title || object?.fileName || object?.name || object?.object?.title || object?.object?.name || object?.id || 'Unknown'
		},
	},
}
</script>

<style scoped>
.restore-step {
	padding: 0;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.selected-objects-container {
	margin: 20px 0;
}

.selected-objects-list {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.selected-object-item:last-child {
	border-bottom: none;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
