/**
 * @file MassValidateObjects.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="`Validate ${selectedObjects.length} object${selectedObjects.length !== 1 ? 's' : ''}`"
		:can-close="false"
		size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="validate-step">
			<h3 class="step-title">
				Confirm Object Validation
			</h3>

			<NcNoteCard type="info">
				Review the selected objects below. You can remove any objects you don't want to validate by clicking the remove button.<br><br>
				<strong>When to use mass validation:</strong><br>
				• After updating the schema to apply new validation rules<br>
				• When objects need to be re-enriched with updated name/description logic<br>
				• To refresh computed properties or auto-generated fields<br>
				• After changing schema configuration that affects existing objects<br><br>
				Objects will be saved without modification to trigger validation and enrichment processes against the current schema.
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>Selected Objects ({{ selectedObjects.length }})</h4>

				<div v-if="selectedObjects.length" class="selected-objects-list">
					<div v-for="obj in selectedObjects"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{ obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || 'Unnamed Object' }}</strong>
							<p class="object-id">
								ID: {{ obj.id || obj['@self']?.id }}
							</p>
						</div>
						<NcButton type="tertiary"
							:aria-label="`Remove ${obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || obj.id}`"
							@click="removeObject(obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-else name="No objects selected">
					<template #description>
						No objects are currently selected for validation.
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Object{{ selectedObjects.length > 1 ? 's' : '' }} successfully validated</p>
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
				:disabled="loading || selectedObjects.length === 0"
				type="primary"
				@click="validateObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<CheckCircle v-if="!loading" :size="20" />
				</template>
				Validate
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
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'MassValidateObjects',
	components: {
		NcDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		CheckCircle,
		Cancel,
		Close,
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			result: null,
			closeModalTimeout: null,
			selectedObjects: [],
		}
	},
	mounted() {
		this.initializeSelection()
	},
	methods: {
		initializeSelection() {
			// Get selected objects from the store or navigation context
			this.selectedObjects = objectStore.selectedObjects || []
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(obj => obj.id !== objectId)
			// Update the store as well
			objectStore.selectedObjects = this.selectedObjects
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.startClosing = true
			navigationStore.setDialog(false)
		},
		async validateObjects() {
			this.loading = true

			try {
				// Validate each object individually by saving it without modifications
				const results = await Promise.allSettled(
					this.selectedObjects.map(async (obj) => {
						try {
							// Save the object as-is to trigger validation and enrichment
							await objectStore.saveObject(obj, {
								register: obj['@self']?.register || obj.register,
								schema: obj['@self']?.schema || obj.schema,
							})
							return { success: true, id: obj.id }
						} catch (error) {
							console.error(`Failed to validate object ${obj.id}:`, error)
							return { success: false, id: obj.id, error: error.message }
						}
					}),
				)

				// Count successful and failed operations
				const successful = results.filter(r => r.status === 'fulfilled' && r.value.success)
				const failed = results.filter(r => r.status === 'rejected' || (r.status === 'fulfilled' && !r.value.success))

				if (successful.length > 0) {
					this.success = true
					// Clear selected objects and refresh the object list
					objectStore.selectedObjects = []
					objectStore.refreshObjectList()

					this.closeModalTimeout = setTimeout(() => {
						this.closeDialog()
					}, 2000)
				}

				if (failed.length > 0) {
					this.error = `Failed to validate ${failed.length} object${failed.length > 1 ? 's' : ''}`
				}

			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while validating objects'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.validate-step {
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