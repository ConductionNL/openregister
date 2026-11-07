/** * @file MassValidateObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="`Validate ${selectedObjects.length} object${selectedObjects.length !== 1 ? 's' : ''}`"
		noClose
		size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="validate-step">
			<h3 class="step-title">Confirm Object Validation</h3>

			<NcNoteCard type="info">
				Review the selected objects below. You can remove any objects you
				don't want to validate by clicking the remove button.<br /><br />
				<strong>When to use mass validation:</strong><br />
				• After updating the schema to apply new validation rules<br />
				• When objects need to be re-enriched with updated name/description
				logic<br />
				• To refresh computed properties or auto-generated fields<br />
				• After changing schema configuration that affects existing
				objects<br /><br />
				Objects will be saved without modification to trigger validation and
				enrichment processes against the current schema.
			</NcNoteCard>

			<SelectedObjectsList
				:objects="selectedObjects"
				:emptyDescription="
					t(
						'openregister',
						'No objects are currently selected for validation.',
					)
				"
				@remove="removeObject" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>
				Object{{ selectedObjects.length > 1 ? 's' : '' }} successfully
				validated
			</p>
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
				:disabled="loading || selectedObjects.length === 0"
				variant="primary"
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
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import SelectedObjectsList from '../../components/shared/SelectedObjectsList.vue'

export default {
	name: 'MassValidateObjects',
	components: {
		SelectedObjectsList,
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		CheckCircle,
		Cancel,
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
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeSelection() {
			// Get selected objects from the store or navigation context
			this.selectedObjects = objectStore.selectedObjects || []
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},

		/**
		 * @param {string|number} objectId
		 * @spec exclude UI selection plumbing — removes an object from the mass-validate selection.
		 */
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(
				(obj) => obj.id !== objectId,
			)
			// Update the store as well
			objectStore.selectedObjects = this.selectedObjects
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},

		/**
		 * @spec exclude Modal close plumbing — closes the mass-validate dialog.
		 */
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.startClosing = true
			navigationStore.setDialog(false)
		},

		/**
		 * @spec exclude Modal action plumbing — re-saves each selected object to trigger validation.
		 */
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
							console.error(
								`Failed to validate object ${obj.id}:`,
								error,
							)
							return {
								success: false,
								id: obj.id,
								error: error.message,
							}
						}
					}),
				)

				// Count successful and failed operations
				const successful = results.filter(
					(r) => r.status === 'fulfilled' && r.value.success,
				)
				const failed = results.filter(
					(r) =>
						r.status === 'rejected'
						|| (r.status === 'fulfilled' && !r.value.success),
				)

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
				this.error =
					error.message || 'An error occurred while validating objects'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.validate-step {
	padding: 0;
}

@include step-title;
</style>
