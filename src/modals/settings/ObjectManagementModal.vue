<template>
	<NcDialog v-if="show"
		:name="t('openregister', 'Object Management')"
		size="large"
		@closing="$emit('closing')">
		<div class="object-config-content">
			<!-- Info box -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>
					{{ t('openregister', 'Configure object vectorization settings for semantic object search.') }}
				</p>
			</div>

			<!-- Vectorization Settings -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Vectorization Settings') }}</h3>
				
				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.vectorizationEnabled"
						type="switch">
						{{ t('openregister', 'Enable automatic vectorization') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Automatically generate vector embeddings when objects are created or updated') }}</small>
				</div>

				<div v-if="config.vectorizationEnabled" class="form-group">
					<label>{{ t('openregister', 'Vectorization Provider') }}</label>
					<NcSelect
						v-model="config.provider"
						:options="providerOptions"
						label="name"
						:placeholder="t('openregister', 'Select provider')">
					</NcSelect>
				</div>

				<div v-if="config.vectorizationEnabled" class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.vectorizeOnCreate"
						type="switch">
						{{ t('openregister', 'Vectorize on object creation') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="config.vectorizationEnabled" class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.vectorizeOnUpdate"
						type="switch">
						{{ t('openregister', 'Re-vectorize on object update') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Schema-Specific Settings -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Schema-Specific Settings') }}</h3>
				<p class="section-description">
					{{ t('openregister', 'Configure which object schemas should be vectorized.') }}
				</p>
				
				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.vectorizeAllSchemas"
						type="switch">
						{{ t('openregister', 'Vectorize all schemas') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="!config.vectorizeAllSchemas && schemas.length > 0" class="schema-selection">
					<label>{{ t('openregister', 'Select schemas to vectorize:') }}</label>
					<div class="schema-list">
						<div v-for="schema in schemas" :key="schema.id" class="schema-item">
							<NcCheckboxRadioSwitch
								v-model="config.enabledSchemas"
								:value="schema.id"
								type="checkbox">
								{{ schema.title || schema.name }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</div>
			</div>

			<!-- Text Extraction Settings -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Text Extraction Settings') }}</h3>
				
				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.includeMetadata"
						type="switch">
						{{ t('openregister', 'Include schema and register metadata') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Include schema titles, descriptions, and register information in vector text') }}</small>
				</div>

				<div class="form-group">
					<label for="max-depth">{{ t('openregister', 'Maximum Nesting Depth') }}</label>
					<input
						id="max-depth"
						v-model.number="config.maxNestingDepth"
						type="number"
						min="1"
						max="20"
						class="input-field">
					<small>{{ t('openregister', 'Maximum depth for extracting nested object properties (1-20)') }}</small>
				</div>
			</div>

			<!-- Bulk Operations -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Bulk Operations') }}</h3>
				
				<div class="bulk-actions">
					<NcButton
						type="primary"
						:disabled="vectorizing"
						@click="startBulkVectorization">
						<template #icon>
							<NcLoadingIcon v-if="vectorizing" :size="20" />
							<Play v-else :size="20" />
						</template>
						{{ vectorizing ? t('openregister', 'Vectorizing...') : t('openregister', 'Vectorize All Objects') }}
					</NcButton>

					<div v-if="vectorizationProgress" class="progress-info">
						<div class="progress-bar">
							<div class="progress-fill" :style="{ width: vectorizationProgress.percentage + '%' }" />
						</div>
						<div class="progress-text">
							{{ vectorizationProgress.processed }} / {{ vectorizationProgress.total }} objects
							({{ vectorizationProgress.percentage }}%)
						</div>
					</div>
				</div>
			</div>

			<!-- Stats -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Object Statistics') }}</h3>
				
				<div class="stats-grid">
					<div class="stat-card">
						<div class="stat-value">{{ stats.totalObjects }}</div>
						<div class="stat-label">{{ t('openregister', 'Total Objects') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ stats.vectorizedObjects }}</div>
						<div class="stat-label">{{ t('openregister', 'Vectorized Objects') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ stats.pendingObjects }}</div>
						<div class="stat-label">{{ t('openregister', 'Pending') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ stats.progressPercentage }}%</div>
						<div class="stat-label">{{ t('openregister', 'Progress') }}</div>
					</div>
				</div>
			</div>

			<!-- Vector Embeddings Stats -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Vector Embeddings Statistics') }}</h3>
				
				<div class="stats-grid">
					<div class="stat-card highlight">
						<div class="stat-value">{{ vectorStats.totalVectors }}</div>
						<div class="stat-label">{{ t('openregister', 'Total Vectors') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ vectorStats.objectVectors }}</div>
						<div class="stat-label">{{ t('openregister', 'Object Vectors') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ vectorStats.fileVectors }}</div>
						<div class="stat-label">{{ t('openregister', 'File Vectors') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ vectorStats.storageMB }}</div>
						<div class="stat-label">{{ t('openregister', 'Storage (MB)') }}</div>
					</div>
				</div>

				<!-- Vector Models Breakdown -->
				<div v-if="vectorStats.byModel && Object.keys(vectorStats.byModel).length > 0" class="model-breakdown">
					<h4>{{ t('openregister', 'Vectors by Model') }}</h4>
					<div class="model-list">
						<div v-for="(count, model) in vectorStats.byModel" :key="model" class="model-item">
							<span class="model-name">{{ model }}</span>
							<span class="model-count">{{ count }} {{ t('openregister', 'vectors') }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Dialog Actions -->
		<template #actions>
			<NcButton @click="$emit('closing')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="saving"
				@click="saveConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ saving ? t('openregister', 'Saving...') : t('openregister', 'Save Configuration') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Play from 'vue-material-design-icons/Play.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'ObjectManagementModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcCheckboxRadioSwitch,
		InformationOutline,
		ContentSave,
		Play,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			saving: false,
			vectorizing: false,
			vectorizationProgress: null,
			
			config: {
				vectorizationEnabled: true,
				provider: { id: 'openai', name: 'OpenAI' },
				vectorizeOnCreate: true,
				vectorizeOnUpdate: false,
				vectorizeAllSchemas: true,
				enabledSchemas: [],
				includeMetadata: true,
				maxNestingDepth: 10,
			},
			
			stats: {
				totalObjects: 0,
				vectorizedObjects: 0,
				pendingObjects: 0,
				progressPercentage: 0,
			},

			vectorStats: {
				totalVectors: 0,
				objectVectors: 0,
				fileVectors: 0,
				storageMB: '0.0',
				byModel: {},
			},
			
			schemas: [],
			
			providerOptions: [
				{ id: 'openai', name: 'OpenAI' },
				{ id: 'ollama', name: 'Ollama (Local)' },
			],
		}
	},

	mounted() {
		this.loadConfiguration()
		this.loadSchemas()
		this.loadStats()
	},

	methods: {
		async loadConfiguration() {
			try {
				// TODO: Load from backend
				this.loading = false
			} catch (error) {
				console.error('Failed to load configuration:', error)
				this.loading = false
			}
		},

		async loadSchemas() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/schemas'))
				this.schemas = response.data.results || []
			} catch (error) {
				console.error('Failed to load schemas:', error)
			}
		},

		async loadStats() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/objects/vectorize/stats'))
				const data = response.data

				// Update object stats
				if (data.objects) {
					this.stats.totalObjects = data.objects.total_objects || 0
					this.stats.vectorizedObjects = data.objects.vectorized_objects || 0
					this.stats.pendingObjects = data.objects.pending_objects || 0
					this.stats.progressPercentage = data.objects.percentage_complete || 0
				}

				// Update vector stats
				this.vectorStats.totalVectors = data.total_vectors || 0
				this.vectorStats.objectVectors = data.by_type?.object || 0
				this.vectorStats.fileVectors = data.by_type?.file || 0
				this.vectorStats.storageMB = data.storage?.total_mb?.toFixed(1) || '0.0'
				this.vectorStats.byModel = data.by_model || {}
			} catch (error) {
				console.error('Failed to load stats:', error)
			}
		},

		async startBulkVectorization() {
			if (!confirm(this.t('openregister', 'This will vectorize all objects. This may take a long time. Continue?'))) {
				return
			}

			this.vectorizing = true
			this.vectorizationProgress = { processed: 0, total: this.stats.totalObjects, percentage: 0 }

			try {
				// Vectorize in batches
				const batchSize = 100
				let offset = 0
				let hasMore = true

				while (hasMore) {
					const response = await axios.post(
						generateUrl('/apps/openregister/api/objects/vectorize/bulk'),
						{
							limit: batchSize,
							offset,
						}
					)

					this.vectorizationProgress.processed += response.data.successful
					this.vectorizationProgress.percentage = Math.round(
						(this.vectorizationProgress.processed / this.vectorizationProgress.total) * 100
					)

					hasMore = response.data.pagination?.has_more
					offset += batchSize
				}

				showSuccess(this.t('openregister', 'Bulk vectorization completed'))
				await this.loadStats()
			} catch (error) {
				showError(this.t('openregister', 'Failed to vectorize objects: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.vectorizing = false
				this.vectorizationProgress = null
			}
		},

		async saveConfiguration() {
			this.saving = true

			try {
				await axios.post(generateUrl('/apps/openregister/api/settings/objects'), this.config)
				showSuccess(this.t('openregister', 'Object configuration saved successfully'))
				this.$emit('closing')
			} catch (error) {
				showError(this.t('openregister', 'Failed to save configuration: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.object-config-content {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.info-box {
	display: flex;
	gap: 12px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	margin-bottom: 24px;
	align-items: flex-start;

	p {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}
}

.config-section {
	margin-bottom: 32px;

	h3 {
		margin: 0 0 8px 0;
		font-size: 16px;
		font-weight: 600;
	}

	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
	}
}

.form-group {
	margin-bottom: 20px;

	label {
		display: block;
		margin-bottom: 8px;
		font-weight: 500;
	}

	.input-field {
		width: 100%;
		padding: 10px 12px;
		border: 1px solid var(--color-border);
		border-radius: 6px;
		font-size: 14px;
		background: var(--color-main-background);
		color: var(--color-main-text);

		&:focus {
			outline: none;
			border-color: var(--color-primary-element);
		}
	}

	small {
		display: block;
		margin-top: 6px;
		color: var(--color-text-maxcontrast);
		font-size: 12px;
	}
}

.schema-selection {
	margin-top: 16px;

	label {
		display: block;
		margin-bottom: 12px;
		font-weight: 500;
	}

	.schema-list {
		max-height: 200px;
		overflow-y: auto;
		border: 1px solid var(--color-border);
		border-radius: 6px;
		padding: 12px;
	}

	.schema-item {
		margin-bottom: 8px;
	}
}

.bulk-actions {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.progress-info {
	.progress-bar {
		height: 8px;
		background: var(--color-background-dark);
		border-radius: 4px;
		overflow: hidden;
		margin-bottom: 8px;

		.progress-fill {
			height: 100%;
			background: var(--color-primary-element);
			transition: width 0.3s ease;
		}
	}

	.progress-text {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		text-align: center;
	}
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 16px;
}

.stat-card {
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	text-align: center;

	.stat-value {
		font-size: 32px;
		font-weight: 700;
		color: var(--color-primary-element);
		margin-bottom: 8px;
	}

	.stat-label {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&.highlight {
		background: var(--color-primary-element-light);
		border: 2px solid var(--color-primary-element);

		.stat-value {
			color: var(--color-primary-element);
			font-size: 36px;
		}
	}
}

.model-breakdown {
	margin-top: 20px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: 8px;

	h4 {
		margin: 0 0 12px 0;
		font-size: 14px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	.model-list {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.model-item {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 8px 12px;
		background: var(--color-background-hover);
		border-radius: 6px;

		.model-name {
			font-size: 13px;
			font-weight: 500;
			color: var(--color-main-text);
		}

		.model-count {
			font-size: 12px;
			color: var(--color-text-maxcontrast);
			font-weight: 600;
		}
	}
}
</style>

