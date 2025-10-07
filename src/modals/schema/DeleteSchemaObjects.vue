<script setup>
import { schemaStore, navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteSchemaObjects'"
		name="Delete Schema Objects"
		size="normal"
		:can-close="false">
		
		<!-- Confirmation State -->
		<div v-if="!success && !loading">
			<p>
				Are you sure you want to delete <strong>all objects</strong> in the schema 
				<strong>{{ schemaStore.schemaItem?.title }}</strong>?
			</p>
			<p class="warning-text">
				⚠️ This action cannot be undone. All objects belonging to this schema will be permanently deleted.
			</p>
			
			<div v-if="objectCount > 0" class="object-count-info">
				<p><strong>Objects to be deleted:</strong> {{ objectCount }}</p>
			</div>
			
			<div v-else class="no-objects-info">
				<p>No objects found in this schema.</p>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="40" />
			<p>Deleting objects from schema '{{ schemaStore.schemaItem?.title }}'...</p>
			<p class="loading-subtitle">This may take a moment for large datasets.</p>
		</div>

		<!-- Success State -->
		<div v-if="success" class="success-container">
			<NcNoteCard type="success">
				<h3>Deletion Completed Successfully</h3>
				<p><strong>Objects deleted:</strong> {{ deletionResult?.deleted_count || 0 }}</p>
				<p><strong>Schema:</strong> {{ schemaStore.schemaItem?.title }}</p>
			</NcNoteCard>
		</div>

		<!-- Error State -->
		<NcNoteCard v-if="error" type="error">
			<h3>Deletion Failed</h3>
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!success && !loading && !error && objectCount > 0"
				:disabled="loading"
				type="error"
				@click="confirmDeletion()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<DeleteSweep v-if="!loading" :size="20" />
				</template>
				Delete All Objects
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
import DeleteSweep from 'vue-material-design-icons/DeleteSweep.vue'

export default {
	name: 'DeleteSchemaObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Cancel,
		DeleteSweep,
	},
	data() {
		return {
			loading: false,
			error: false,
			success: false,
			deletionResult: null,
			objectCount: 0,
		}
	},
	async mounted() {
		await this.loadObjectCount()
	},
	methods: {
		async loadObjectCount() {
			try {
				if (schemaStore.schemaItem?.id) {
					// First try to use existing stats if available
					if (schemaStore.schemaItem.stats?.objects?.total !== undefined) {
						this.objectCount = schemaStore.schemaItem.stats.objects.total
						console.log('Using schema item stats:', this.objectCount)
					} else {
						// Fallback to API call
						this.objectCount = await schemaStore.getObjectCount(schemaStore.schemaItem.id)
					}
				}
			} catch (err) {
				console.warn('Could not load object count:', err)
				this.objectCount = 0
			}
		},
		
		async confirmDeletion() {
			this.loading = true
			this.error = false
			
			try {
				// Find the register that contains this schema
				await registerStore.refreshRegisterList()
				const register = registerStore.registerList.find(reg => 
					reg.schemas.includes(schemaStore.schemaItem.id)
				)
				
				if (!register) {
					throw new Error('Could not find register containing this schema')
				}
				
				// Call the deletion API
				const response = await fetch(
					`/index.php/apps/openregister/api/bulk/${register.id}/${schemaStore.schemaItem.id}/delete-schema`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
					}
				)
				
				if (!response.ok) {
					throw new Error(`Deletion failed: ${response.statusText}`)
				}
				
				const data = await response.json()
				
				if (data.error) {
					throw new Error(data.error)
				}
				
				this.deletionResult = data
				this.success = true
				
				// Refresh schema stats after successful deletion
				await schemaStore.getSchemaStats(schemaStore.schemaItem.id)
				
			} catch (err) {
				this.error = err.message || 'An error occurred during deletion'
				console.error('Deletion error:', err)
			} finally {
				this.loading = false
			}
		},
		
		closeDialog() {
			navigationStore.setDialog(false)
			this.loading = false
			this.error = false
			this.success = false
			this.deletionResult = null
			this.objectCount = 0
		},
	},
}
</script>

<style scoped>
.loading-container {
	text-align: center;
	padding: 2rem;
}

.loading-subtitle {
	color: var(--color-text-lighter);
	font-size: 0.9rem;
	margin-top: 0.5rem;
}

.success-container {
	padding: 1rem 0;
}

.warning-text {
	color: var(--color-warning);
	font-weight: 600;
	margin: 1rem 0;
	padding: 1rem;
	background: var(--color-warning-light);
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-warning);
}

.object-count-info {
	margin: 1rem 0;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.no-objects-info {
	margin: 1rem 0;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	color: var(--color-text-lighter);
}
</style>
