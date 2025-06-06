<script setup>
import { deletedStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'permanentlyDeleteObject'"
		:name="t('openregister', 'Permanently Delete Object')"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			{{ t('openregister', 'Do you want to permanently delete this object? This action cannot be undone.') }}
		</p>

		<div v-if="success === null && objectToDelete" class="object-info">
			<p><strong>{{ t('openregister', 'Title:') }}</strong> {{ getObjectTitle(objectToDelete) }}</p>
			<p><strong>{{ t('openregister', 'ID:') }}</strong> {{ objectToDelete.id }}</p>
			<p v-if="objectToDelete['@self']?.register">
				<strong>{{ t('openregister', 'Register:') }}</strong> {{ getRegisterName(objectToDelete['@self'].register) }}
			</p>
			<p v-if="objectToDelete['@self']?.schema">
				<strong>{{ t('openregister', 'Schema:') }}</strong> {{ getSchemaName(objectToDelete['@self'].schema) }}
			</p>
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
.object-info {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: var(--border-radius);
	margin: 16px 0;
}

.object-info p {
	margin: 8px 0;
}
</style>
