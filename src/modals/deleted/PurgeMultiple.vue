<script setup>
import { deletedStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'permanentlyDeleteMultiple'"
		:name="`Purge ${objectsToDelete.length} object${objectsToDelete.length !== 1 ? 's' : ''} from database`"
		size="normal"
		:can-close="false">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="delete-step">
			<h3 class="step-title">
				Confirm Permanent Object Deletion
			</h3>

			<NcNoteCard type="warning">
				Review the selected objects below. You can remove any objects you don't want to permanently delete by clicking the remove button. This action cannot be undone.
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>Selected Objects ({{ objectsToDelete.length }})</h4>

				<div v-if="objectsToDelete.length" class="selected-objects-list">
					<div v-for="obj in objectsToDelete"
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
						No objects are currently selected for permanent deletion.
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
				:disabled="loading || objectsToDelete.length === 0"
				type="error"
				@click="permanentlyDeleteMultiple()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Purge') }}
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
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'PurgeMultiple',
	components: {
		NcDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
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
		objectsToDelete() {
			return this.selectedObjects
		},
	},
	watch: {
		'navigationStore.dialog'(newValue, oldValue) {
			if (newValue === 'permanentlyDeleteMultiple' && oldValue !== 'permanentlyDeleteMultiple') {
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
		 * Permanently delete multiple objects
		 * @return {Promise<void>}
		 */
		async permanentlyDeleteMultiple() {
			if (!this.objectsToDelete || this.objectsToDelete.length === 0) {
				this.error = t('openregister', 'No objects selected for deletion')
				return
			}

			this.loading = true

			try {
				const ids = this.objectsToDelete.map(obj => obj.id)
				const result = await deletedStore.permanentlyDeleteMultiple(ids)

				this.success = true
				this.error = false

				// Build success message
				if (result.deleted > 0) {
					let message = t('openregister', 'Successfully permanently deleted {count} objects', { count: result.deleted })
					if (result.failed > 0) {
						message += t('openregister', ', {failed} failed', { failed: result.failed })
					}
					this.successMessage = message
				} else {
					this.successMessage = t('openregister', 'No objects were permanently deleted')
				}

				// Auto-close after 3 seconds
				this.closeModalTimeout = setTimeout(this.closeDialog, 3000)

				// Emit event to refresh parent list
				this.$root.$emit('deleted-objects-permanently-deleted', ids)
			} catch (error) {
				this.success = false
				this.error = error.message || t('openregister', 'An error occurred while permanently deleting the objects')
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
.delete-step {
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
