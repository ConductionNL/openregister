<script setup>
import { deletedStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'permanentlyDeleteMultiple'"
		:name="t('openregister', 'Permanently Delete Multiple Objects')"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			{{ t('openregister', 'Do you want to permanently delete {count} selected objects? This action cannot be undone.', { count: objectsToDelete.length }) }}
		</p>

		<div v-if="success === null && objectsToDelete.length > 0" class="objects-info">
			<p><strong>{{ t('openregister', 'Objects to be permanently deleted:') }}</strong></p>
			<div class="objects-list">
				<div v-for="obj in objectsToDelete.slice(0, 5)" :key="obj.id" class="object-item">
					<span class="object-title">{{ getObjectTitle(obj) }}</span>
					<span class="object-id">{{ obj.id }}</span>
				</div>
				<div v-if="objectsToDelete.length > 5" class="more-objects">
					{{ t('openregister', '... and {count} more objects', { count: objectsToDelete.length - 5 }) }}
				</div>
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
				{{ success === null ? t('openregister', 'Cancel') : t('openregister', 'Close') }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				type="error"
				@click="permanentlyDeleteMultiple()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Permanently Delete All') }}
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
	name: 'PermanentlyDeleteMultiple',
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
			successMessage: '',
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		objectsToDelete() {
			if (navigationStore.dialog === 'permanentlyDeleteMultiple') {
				const data = navigationStore.getTransferData() || []
				return data
			}
			return []
		},
	},
	watch: {
		'navigationStore.dialog'(newValue, oldValue) {
			if (newValue === 'permanentlyDeleteMultiple' && oldValue !== 'permanentlyDeleteMultiple') {
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
			return object?.title || object?.fileName || object?.name || object?.object?.title || object?.object?.name || object?.id || t('openregister', 'Unknown')
		},
	},
}
</script>

<style scoped>
.objects-info {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: var(--border-radius);
	margin: 16px 0;
}

.objects-info p {
	margin: 8px 0;
}

.objects-list {
	max-height: 200px;
	overflow-y: auto;
	margin-top: 12px;
}

.object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.object-item:last-child {
	border-bottom: none;
}

.object-title {
	font-weight: 500;
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-right: 12px;
}

.object-id {
	font-family: monospace;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.more-objects {
	padding: 8px 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
