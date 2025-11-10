<script setup>
import { schemaStore, navigationStore, registerStore } from '../../store/store.js'
import SchemaStatsBlock from '../../components/SchemaStatsBlock.vue'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'deleteSchemaObjects'"
		name="Delete Schema Objects"
		size="normal"
		:can-close="false">
		<!-- Confirmation State -->
		<div v-if="!success && !loading">
			<p>
				Are you sure you want to delete <strong>all objects</strong> in the schema
				<strong>{{ schemaStore.schemaItem?.title }}</strong>?
			</p>

			<!-- Dynamic Warning/Danger Messages -->
			<div v-if="objectCount > 0" class="deletion-warning-section">
				<!-- Soft Delete Warning (when checkbox is unchecked) -->
				<NcNoteCard v-if="!hardDelete.includes('hardDelete')" type="warning" class="deletion-warning">
					<template #icon>
						<AlertCircle :size="20" />
					</template>
					<template #title>
						{{ t('openregister', 'Soft Delete Mode') }}
					</template>
					{{ t('openregister', 'Objects will be soft-deleted (marked as deleted but kept in database). They can be recovered later if needed.') }}
				</NcNoteCard>

				<!-- Hard Delete Danger (when checkbox is checked) -->
				<NcNoteCard v-else type="error" class="deletion-danger">
					<template #icon>
						<AlertCircle :size="20" />
					</template>
					<template #title>
						{{ t('openregister', 'Permanent Delete Mode') }}
					</template>
					{{ t('openregister', '⚠️ DANGER: All {total} objects will be PERMANENTLY DELETED from the database. This action is UNRECOVERABLE and cannot be undone!', { total: objectCount }) }}
				</NcNoteCard>
			</div>

			<div v-if="objectCount > 0" class="object-count-info">
				<SchemaStatsBlock
					:object-count="objectCount"
					:object-stats="objectStats"
					:loading="false"
					:title="t('openregister', 'Objects to be deleted')" />

				<!-- Hard Delete Option -->
				<div v-if="objectStats && objectStats.deleted > 0" class="hard-delete-option">
					<NcCheckboxRadioSwitch
						v-model="hardDelete"
						:name="'hardDelete'"
						:label="t('openregister', 'Permanently delete already soft-deleted objects')"
						type="checkbox"
						:value="'hardDelete'" />
					<p class="hard-delete-description">
						{{ t('openregister', 'This will permanently remove {count} already soft-deleted objects from the database. This action cannot be undone.', { count: objectStats.deleted }) }}
					</p>
				</div>
			</div>

			<div v-else class="no-objects-info">
				<SchemaStatsBlock
					:object-count="0"
					:object-stats="null"
					:loading="false"
					:title="t('openregister', 'Objects in schema')" />
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="40" />
			<p>Deleting objects from schema '{{ schemaStore.schemaItem?.title }}'...</p>
			<p class="loading-subtitle">
				This may take a moment for large datasets.
			</p>
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
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import DeleteSweep from 'vue-material-design-icons/DeleteSweep.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'DeleteSchemaObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		Cancel,
		DeleteSweep,
		AlertCircle,
		SchemaStatsBlock,
	},
	data() {
		return {
			loading: false,
			error: false,
			success: false,
			deletionResult: null,
			objectCount: 0,
			objectStats: null,
			hardDelete: [],
		}
	},
	watch: {
		// Watch for changes in schemaItem and reload count if needed
		'schemaStore.schemaItem': {
			handler(newSchemaItem) {
				console.log('Schema item changed in DeleteSchemaObjects:', newSchemaItem)
				if (newSchemaItem?.id && this.objectCount === 0) {
					this.loadObjectCount()
				}
			},
			immediate: true,
		},
		// Watch for dialog state changes to load count when modal becomes visible
		'navigationStore.modal': {
			handler(newModal) {
				console.log('Modal changed to:', newModal)
				if (newModal === 'deleteSchemaObjects' && schemaStore.schemaItem?.id) {
					console.log('DeleteSchemaObjects modal opened, loading object count')
					this.loadObjectCount()
				}
			},
			immediate: true,
		},
	},
	async mounted() {
		console.log('DeleteSchemaObjects modal mounted, schemaItem:', schemaStore.schemaItem)
		await this.loadObjectCount()
	},
	methods: {
		async loadObjectCount() {
			console.log('DeleteSchemaObjects loadObjectCount called, schemaItem:', schemaStore.schemaItem)
			try {
				if (schemaStore.schemaItem?.id) {
					console.log('Calling getSchemaStats for schema ID:', schemaStore.schemaItem.id)
					// Use the upgraded stats endpoint to get detailed object counts
					const stats = await schemaStore.getSchemaStats(schemaStore.schemaItem.id)
					console.log('DeleteSchemaObjects received stats:', stats)
					this.objectStats = stats.objects
					this.objectCount = stats.objects?.total || 0
					console.log('DeleteSchemaObjects set objectCount to:', this.objectCount)
				} else {
					console.log('DeleteSchemaObjects: No schema item ID available')
				}
			} catch (err) {
				console.error('DeleteSchemaObjects error in loadObjectCount:', err)
				console.warn('Could not load object count:', err)
				this.objectCount = 0
				this.objectStats = null
			}
		},

		async confirmDeletion() {
			this.loading = true
			this.error = false

			try {
				// Find the register that contains this schema
				await registerStore.refreshRegisterList()
				const register = registerStore.registerList.find(reg =>
					reg.schemas.includes(schemaStore.schemaItem.id),
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
						body: JSON.stringify({
							hardDelete: this.hardDelete.includes('hardDelete'),
						}),
					},
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
			navigationStore.setModal(false)
			this.loading = false
			this.error = false
			this.success = false
			this.deletionResult = null
			this.objectCount = 0
			this.objectStats = null
			this.hardDelete = []
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

.object-breakdown {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.hard-delete-option {
	margin-top: 1rem;
	padding: 0;
	background: transparent;
	border-radius: 0;
	border: none;
}

.hard-delete-description {
	margin-top: 0.5rem;
	margin-bottom: 0;
	font-size: 0.9rem;
	color: var(--color-text-lighter);
	line-height: 1.4;
}

.deletion-warning-section {
	margin-top: 1rem;
}

.deletion-warning {
	border-left: 4px solid var(--color-warning);
}

.deletion-danger {
	border-left: 4px solid var(--color-error);
}

.breakdown-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.breakdown-item:last-child {
	margin-bottom: 0;
}

.breakdown-label {
	font-weight: 500;
	color: var(--color-text);
}

.breakdown-value {
	font-weight: 600;
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.breakdown-value.invalid {
	color: var(--color-warning);
	background: var(--color-warning-light);
}

.breakdown-value.deleted {
	color: var(--color-error);
	background: var(--color-error-light);
}

.breakdown-value.published {
	color: var(--color-success);
	background: var(--color-success-light);
}

.no-objects-info {
	margin: 1rem 0;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	color: var(--color-text-lighter);
}
</style>
