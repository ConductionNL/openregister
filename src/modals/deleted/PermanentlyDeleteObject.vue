<script setup>
import { deletedStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'permanentlyDeleteObject'"
		:name="`Permanently delete 1 object`"
		size="normal"
		:can-close="false">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="delete-step">
			<h3 class="step-title">
				Confirm Permanent Object Deletion
			</h3>

			<NcNoteCard type="warning">
				Review the selected object below. This action cannot be undone.
			</NcNoteCard>

			<div v-if="objectToDelete" class="selected-objects-container">
				<h4>Selected Object (1)</h4>

				<div class="selected-objects-list">
					<div class="selected-object-item">
						<div class="object-info">
							<strong>{{ getObjectTitle(objectToDelete) }}</strong>
							<p class="object-id">
								ID: {{ objectToDelete.id }}
							</p>
							<p v-if="objectToDelete['@self']?.register" class="object-meta">
								Register: {{ getRegisterName(objectToDelete['@self'].register) }}
							</p>
							<p v-if="objectToDelete['@self']?.schema" class="object-meta">
								Schema: {{ getSchemaName(objectToDelete['@self'].schema) }}
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openregister', 'Object permanently deleted successfully') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? t('openregister', 'Cancel') : t('openregister', 'Close') }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				type="error"
				@click="permanentlyDeleteObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Permanently Delete') }}
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
	name: 'PermanentlyDeleteObject',
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
	computed: {
		objectToDelete() {
			if (navigationStore.dialog === 'permanentlyDeleteObject') {
				const data = navigationStore.getTransferData()
				return data
			}
			return null
		},
	},
	watch: {
		'navigationStore.dialog'(newValue, oldValue) {
			if (newValue === 'permanentlyDeleteObject' && oldValue !== 'permanentlyDeleteObject') {
				// Dialog opened - computed property will handle data retrieval
			}
		},
	},
	mounted() {
		// Component mounted - data handled by computed property
	},
	methods: {
		/**
		 * Close the dialog and reset state
		 * @return {void}
		 */
		closeDialog() {
			navigationStore.setDialog(false)
			navigationStore.clearTransferData()
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
		},

		/**
		 * Permanently delete the object
		 * @return {Promise<void>}
		 */
		async permanentlyDeleteObject() {
			if (!this.objectToDelete) {
				this.error = t('openregister', 'No object selected for deletion')
				return
			}

			this.loading = true

			try {
				await deletedStore.permanentlyDelete(this.objectToDelete.id)
				this.success = true
				this.error = false
				// Auto-close after 2 seconds
				this.closeModalTimeout = setTimeout(this.closeDialog, 2000)

				// Emit event to refresh parent list
				this.$root.$emit('deleted-object-permanently-deleted', this.objectToDelete.id)
			} catch (error) {
				this.success = false
				this.error = error.message || t('openregister', 'An error occurred while permanently deleting the object')
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
			return object?.title || object?.fileName || object?.name || object?.object?.title || object?.object?.name || object?.id || t('openregister', 'Unknown')
		},

		/**
		 * Get register name by ID
		 * @param {string|number} registerId - The register ID
		 * @return {string} The register name
		 */
		getRegisterName(registerId) {
			// TODO: Implement register name lookup
			return `Register ${registerId}`
		},

		/**
		 * Get schema name by ID
		 * @param {string|number} schemaId - The schema ID
		 * @return {string} The schema name
		 */
		getSchemaName(schemaId) {
			// TODO: Implement schema name lookup
			return `Schema ${schemaId}`
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
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	padding: 12px;
	background-color: var(--color-background-hover);
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-id, .object-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 4px 0;
}
</style>
