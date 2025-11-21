<template>
	<NcDialog v-if="navigationStore.modal === 'publishSchemaObjects'"
		name="Publish Schema Objects"
		size="normal"
		:can-close="false">
		<!-- Confirmation State -->
		<div v-if="!success && !loading">
			<p>
				Are you sure you want to publish <strong>all objects</strong> in the schema
				<strong>{{ schemaStore.schemaItem?.title }}</strong>?
			</p>

			<!-- Dynamic Info Messages -->
			<div v-if="objectCount > 0" class="publish-info-section">
				<!-- Publish Info -->
				<NcNoteCard type="info" class="publish-info">
					<template #icon>
						<CheckCircle :size="20" />
					</template>
					<template #title>
						{{ t('openregister', 'Publish Mode') }}
					</template>
					{{ t('openregister', 'All {total} objects will be published with the current timestamp. Published objects become visible to users and are included in search results.', { total: objectCount }) }}
				</NcNoteCard>
			</div>

			<div v-if="objectCount > 0" class="object-count-info">
				<SchemaStatsBlock
					:object-count="objectCount"
					:object-stats="objectStats"
					:loading="false"
					:title="t('openregister', 'Objects to be published')" />
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
			<p>Publishing objects from schema '{{ schemaStore.schemaItem?.title }}'...</p>
			<p class="loading-subtitle">
				This may take a moment for large datasets.
			</p>
		</div>

		<!-- Success State -->
		<div v-if="success" class="success-container">
			<NcNoteCard type="success">
				<template #icon>
					<CheckCircle :size="20" />
				</template>
				<template #title>
					{{ t('openregister', 'Publishing Completed Successfully') }}
				</template>
				{{ t('openregister', 'Successfully published {count} objects from schema "{schema}".', { count: publishResult?.published_count || 0, schema: schemaStore.schemaItem?.title }) }}
			</NcNoteCard>
		</div>

		<!-- Error State -->
		<div v-if="error" class="error-container">
			<NcNoteCard type="error">
				<template #icon>
					<AlertCircle :size="20" />
				</template>
				<template #title>
					{{ t('openregister', 'Publishing Failed') }}
				</template>
				{{ error }}
			</NcNoteCard>
		</div>

		<!-- Action Buttons -->
		<div class="modal-actions">
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>

			<NcButton
				v-if="!success && !loading && objectCount > 0"
				type="primary"
				@click="confirmPublishing">
				<template #icon>
					<CheckCircle :size="20" />
				</template>
				{{ t('openregister', 'Publish All Objects') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import { t } from '@nextcloud/l10n'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

import { navigationStore, schemaStore, registerStore } from '../../store/store.js'
import SchemaStatsBlock from '../../components/SchemaStatsBlock.vue'

export default {
	name: 'PublishSchemaObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Cancel,
		CheckCircle,
		AlertCircle,
		SchemaStatsBlock,
	},
	data() {
		return {
			navigationStore,
			schemaStore,
			registerStore,
			loading: false,
			error: false,
			success: false,
			publishResult: null,
			objectCount: 0,
			objectStats: null,
		}
	},
	watch: {
		// Watch for changes in schemaItem and reload count if needed
		'schemaStore.schemaItem': {
			handler(newSchemaItem) {
				console.info('Schema item changed in PublishSchemaObjects:', newSchemaItem)
				if (newSchemaItem?.id && this.objectCount === 0) {
					this.loadObjectCount()
				}
			},
			immediate: true,
		},
		// Watch for dialog state changes to load count when modal becomes visible
		'navigationStore.modal': {
			handler(newModal) {
				console.info('Modal changed to:', newModal)
				if (newModal === 'publishSchemaObjects' && schemaStore.schemaItem?.id) {
					console.info('PublishSchemaObjects modal opened, loading object count')
					this.loadObjectCount()
				}
			},
			immediate: true,
		},
	},
	async mounted() {
		console.info('PublishSchemaObjects modal mounted, schemaItem:', schemaStore.schemaItem)
		await this.loadObjectCount()
	},
	methods: {
		t,
		async loadObjectCount() {
			console.info('PublishSchemaObjects loadObjectCount called, schemaItem:', schemaStore.schemaItem)
			try {
				if (schemaStore.schemaItem?.id) {
					console.info('Calling getSchemaStats for schema ID:', schemaStore.schemaItem.id)
					// Use the upgraded stats endpoint to get detailed object counts
					const stats = await schemaStore.getSchemaStats(schemaStore.schemaItem.id)
					console.info('PublishSchemaObjects received stats:', stats)
					this.objectStats = stats.objects
					this.objectCount = stats.objects?.total || 0
					console.info('PublishSchemaObjects set objectCount to:', this.objectCount)
				} else {
					console.info('PublishSchemaObjects: No schema item ID available')
				}
			} catch (err) {
				console.error('PublishSchemaObjects error in loadObjectCount:', err)
				console.warn('Could not load object count:', err)
				this.objectCount = 0
				this.objectStats = null
			}
		},

		async confirmPublishing() {
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

				// Call the publishing API
				const response = await fetch(
					`/index.php/apps/openregister/api/bulk/${register.id}/${schemaStore.schemaItem.id}/publish-schema`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							publishAll: true,
						}),
					},
				)

				if (!response.ok) {
					throw new Error(`Publishing failed: ${response.statusText}`)
				}

				const data = await response.json()
				console.info('Publishing response:', data)

				this.publishResult = data
				this.success = true
				this.loading = false

			} catch (err) {
				console.error('Publishing error:', err)
				this.error = err.message || 'An error occurred during publishing'
				this.loading = false
			} finally {
				this.loading = false
			}
		},

		closeDialog() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = false
			this.success = false
			this.publishResult = null
			this.objectCount = 0
			this.objectStats = null
		},
	},
}
</script>

<style scoped lang="scss">
.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 2rem;
	gap: 1rem;
}

.loading-subtitle {
	color: var(--color-text-lighter);
	font-size: 0.9rem;
}

.success-container,
.error-container {
	margin-bottom: 1rem;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 1rem;
	margin-top: 2rem;
}

.object-count-info {
	margin-top: 1rem;
}

.publish-info-section {
	margin-top: 1rem;
}

.publish-info {
	border-left: 4px solid var(--color-primary);
}

.no-objects-info {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	text-align: center;
	color: var(--color-text-lighter);
}
</style>
