<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import SchemaStatsBlock from '../../components/SchemaStatsBlock.vue'
import { navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'deleteSchemaObjects'"
		name="Delete Schema Objects"
		size="normal"
		noClose>
		<!-- Confirmation State -->
		<div v-if="!success && !loading">
			<p>
				Are you sure you want to delete <strong>all objects</strong> in the
				schema <strong>{{ schemaStore.schemaItem?.title }}</strong
				>?
			</p>

			<!-- Dynamic Warning/Danger Messages -->
			<div v-if="objectCount > 0" class="deletion-warning-section">
				<!-- Soft Delete Warning (when checkbox is unchecked) -->
				<NcNoteCard
					v-if="!hardDelete.includes('hardDelete')"
					type="warning"
					class="deletion-warning">
					<template #icon>
						<AlertCircle :size="20" />
					</template>
					<template #title>
						{{ t('openregister', 'Soft Delete Mode') }}
					</template>
					{{
						t(
							'openregister',
							'Objects will be soft-deleted (marked as deleted but kept in database). They can be recovered later if needed.',
						)
					}}
				</NcNoteCard>

				<!-- Hard Delete Danger (when checkbox is checked) -->
				<NcNoteCard v-else type="error" class="deletion-danger">
					<template #icon>
						<AlertCircle :size="20" />
					</template>
					<template #title>
						{{ t('openregister', 'Permanent Delete Mode') }}
					</template>
					{{
						t(
							'openregister',
							'⚠️ DANGER: All {total} objects will be PERMANENTLY DELETED from the database. This action is UNRECOVERABLE and cannot be undone!',
							{ total: objectCount },
						)
					}}
				</NcNoteCard>
			</div>

			<div v-if="objectCount > 0" class="object-count-info">
				<SchemaStatsBlock
					:objectCount="objectCount"
					:objectStats="objectStats"
					:loading="false"
					:title="t('openregister', 'Objects to be deleted')" />

				<!-- Hard Delete Option -->
				<div
					v-if="objectStats && objectStats.deleted > 0"
					class="hard-delete-option">
					<NcCheckboxRadioSwitch
						v-model="hardDelete"
						name="hardDelete"
						:label="
							t(
								'openregister',
								'Permanently delete already soft-deleted objects',
							)
						"
						type="checkbox"
						value="hardDelete" />
					<p class="hard-delete-description">
						{{
							t(
								'openregister',
								'This will permanently remove {count} already soft-deleted objects from the database. This action cannot be undone.',
								{ count: objectStats.deleted },
							)
						}}
					</p>
				</div>
			</div>

			<div v-else class="no-objects-info">
				<SchemaStatsBlock
					:objectCount="0"
					:objectStats="null"
					:loading="countLoading"
					:failed="countFailed"
					:title="t('openregister', 'Objects in schema')" />
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="40" />
			<p>
				Deleting objects from schema '{{ schemaStore.schemaItem?.title }}'...
			</p>
			<p class="loading-subtitle">
				This may take a moment for large datasets.
			</p>
		</div>

		<!-- Success State -->
		<div v-if="success" class="success-container">
			<NcNoteCard type="success">
				<h3>Deletion Completed Successfully</h3>
				<p>
					<strong>Objects deleted:</strong>
					{{ deletionResult?.deleted_count || 0 }}
				</p>
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
			<NcButton
				v-if="!success && !loading && !error && objectCount > 0"
				:disabled="loading"
				variant="error"
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
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import DeleteSweep from 'vue-material-design-icons/DeleteSweep.vue'
import { schemaObjectCount } from './schemaObjectCount.js'

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

	mixins: [schemaObjectCount],

	data() {
		return {
			loading: false,
			error: false,
			success: false,
			deletionResult: null,
			hardDelete: [],
		}
	},

	/**
	 * @spec exclude Vue lifecycle hook — loads the object count on mount.
	 */
	async mounted() {
		await this.loadObjectCount()
	},

	methods: {
		/**
		 * Report a failed object count.
		 *
		 * @param {Error} err The failure
		 * @spec exclude Modal data-load plumbing — logs a failed object count.
		 */
		logCountFailure(err) {
			console.error('DeleteSchemaObjects error in loadObjectCount:', err)
		},

		/**
		 * @spec exclude modal submit handler for bulk schema-object deletion
		 */
		async confirmDeletion() {
			this.loading = true
			this.error = false

			try {
				// Find the register that contains this schema
				await registerStore.refreshRegisterList()
				const register = registerStore.registerList.find((reg) =>
					reg.schemas.some(
						(regSchema) => regSchema.id === schemaStore.schemaItem.id,
					),
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

		/**
		 * @spec exclude modal close + form-state reset handler
		 */
		closeDialog() {
			navigationStore.setDialog(false)
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

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include loading-container-2-rules;

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

@include object-breakdown;

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

@include breakdown-item;

.breakdown-item:last-child {
	margin-bottom: 0;
}

@include breakdown-label-rules;

.no-objects-info {
	margin: 1rem 0;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	color: var(--color-text-lighter);
}
</style>
