<template>
	<NcDialog v-if="show"
		:name="t('openregister', 'Object Vectorization')"
		size="large"
		@closing="$emit('closing')">
		<div class="object-config-content">
			<!-- Info box -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>
					{{ t('openregister', 'Configure how database objects are converted into vector embeddings for semantic search. Objects are directly vectorized without needing text extraction.') }}
				</p>
			</div>

			<!-- Important Note -->
			<div class="info-box warning">
				<AlertCircle :size="20" />
				<div>
					<strong>{{ t('openregister', 'Prerequisites') }}</strong>
					<p>{{ t('openregister', 'Before object vectorization can work:') }}</p>
					<ul>
						<li>{{ t('openregister', 'LLM must be enabled with an embedding provider configured') }}</li>
						<li>{{ t('openregister', 'Objects will be serialized as JSON text before vectorization') }}</li>
					</ul>
				</div>
			</div>

			<!-- Vectorization Settings -->
			<div class="config-section">
				<h3>{{ t('openregister', '🔢 Vectorization Settings') }}</h3>

				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.vectorizationEnabled"
						type="switch">
						{{ t('openregister', 'Enable automatic object vectorization') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Automatically generate vector embeddings when objects are created or updated') }}</small>
				</div>

				<div v-if="config.vectorizationEnabled" class="trigger-settings">
					<h4>{{ t('openregister', 'Vectorization Triggers') }}</h4>
					<div class="form-group">
						<NcCheckboxRadioSwitch
							v-model="config.vectorizeOnCreate"
							type="switch">
							{{ t('openregister', 'Vectorize on object creation') }}
						</NcCheckboxRadioSwitch>
						<small>{{ t('openregister', 'Generate vectors immediately when new objects are created') }}</small>
					</div>

					<div class="form-group">
						<NcCheckboxRadioSwitch
							v-model="config.vectorizeOnUpdate"
							type="switch">
							{{ t('openregister', 'Re-vectorize on object update') }}
						</NcCheckboxRadioSwitch>
						<small>{{ t('openregister', 'Update vectors when object data changes (recommended for accurate search)') }}</small>
					</div>
				</div>
			</div>

			<!-- Schema-Specific Settings (Cost Optimization) -->
			<div v-if="config.vectorizationEnabled" class="config-section">
				<h3>{{ t('openregister', '💰 Schema Selection (Cost Optimization)') }}</h3>
				<p class="section-description">
					{{ t('openregister', 'Control which object schemas should be vectorized to reduce API costs. Only vectorize schemas that benefit from semantic search.') }}
				</p>

				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.vectorizeAllSchemas"
						type="switch">
						{{ t('openregister', 'Vectorize all schemas') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Enable vectorization for all existing and future schemas (may increase costs)') }}</small>
				</div>

				<div v-if="!config.vectorizeAllSchemas" class="schema-selection">
					<div class="selection-header">
						<label>{{ t('openregister', 'Select schemas to vectorize:') }}</label>
						<div class="selection-stats">
							<span class="stat-badge">{{ selectedSchemasCount }} / {{ schemas.length }} {{ t('openregister', 'schemas selected') }}</span>
							<NcButton
								v-if="config.enabledSchemas.length > 0"
								type="tertiary"
								@click="config.enabledSchemas = []">
								{{ t('openregister', 'Clear all') }}
							</NcButton>
						</div>
					</div>

					<div v-if="schemas.length > 0" class="schema-list">
						<div v-for="schema in schemas" :key="schema.id" class="schema-item">
							<NcCheckboxRadioSwitch
								v-model="config.enabledSchemas"
								:value="schema.id"
								type="checkbox">
								<span class="schema-name">{{ schema.title || schema.name }}</span>
							</NcCheckboxRadioSwitch>
							<span v-if="schema.description" class="schema-description">{{ schema.description }}</span>
						</div>
					</div>

					<div v-else class="no-schemas">
						<AlertCircle :size="24" />
						<p>{{ t('openregister', 'No schemas found. Create schemas first before configuring vectorization.') }}</p>
					</div>

					<div class="cost-note">
						<InformationOutline :size="16" />
						<small>{{ t('openregister', 'Tip: Only enable schemas that need semantic search to minimize embedding costs. Simple lookup tables rarely need vectorization.') }}</small>
					</div>
				</div>
			</div>

			<!-- Object Serialization Settings -->
			<div v-if="config.vectorizationEnabled" class="config-section">
				<h3>{{ t('openregister', '📄 Object Serialization') }}</h3>
				<p class="section-description">
					{{ t('openregister', 'Configure how objects are converted to text before vectorization. These settings affect search quality and context.') }}
				</p>

				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.includeMetadata"
						type="switch">
						{{ t('openregister', 'Include schema and register metadata') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Add schema titles, descriptions, and register information to provide richer context for search') }}</small>
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
					<small>{{ t('openregister', 'How deep to traverse nested object properties (1-20). Higher values capture more detail but increase vector size.') }}</small>
				</div>

				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.includeRelations"
						type="switch">
						{{ t('openregister', 'Include related object references') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Include IDs and names of related objects for better contextual search') }}</small>
				</div>
			</div>

			<!-- Batch Processing -->
			<div v-if="config.vectorizationEnabled" class="config-section">
				<h3>{{ t('openregister', '⚡ Batch Processing') }}</h3>

				<div class="form-group">
					<label for="batch-size">{{ t('openregister', 'Batch Size') }}</label>
					<input
						id="batch-size"
						v-model.number="config.batchSize"
						type="number"
						min="1"
						max="100"
						step="1"
						class="input-field">
					<small>{{ t('openregister', 'Number of objects to vectorize in one API call. Higher = faster but more memory. Recommended: 10-50.') }}</small>
				</div>

				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.autoRetry"
						type="switch">
						{{ t('openregister', 'Auto-retry failed vectorizations') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Automatically retry failed vectorization attempts (max 3 retries)') }}</small>
				</div>
			</div>

			<!-- Embedding Provider Info -->
			<div class="config-section">
				<h3>{{ t('openregister', 'ℹ️ Current Configuration') }}</h3>

				<div class="info-grid">
					<div class="info-item">
						<span class="info-label">{{ t('openregister', 'Embedding Provider') }}</span>
						<span class="info-value">{{ embeddingProviderName }}</span>
					</div>
					<div class="info-item">
						<span class="info-label">{{ t('openregister', 'Embedding Model') }}</span>
						<span class="info-value">{{ embeddingModelName }}</span>
					</div>
					<div class="info-item">
						<span class="info-label">{{ t('openregister', 'Vector Dimensions') }}</span>
						<span class="info-value">{{ vectorDimensions }}</span>
					</div>
				</div>

				<div class="info-note">
					<small>{{ t('openregister', 'To change the embedding provider or model, go to LLM Configuration.') }}</small>
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
import { NcDialog, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'ObjectManagementModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		InformationOutline,
		AlertCircle,
		ContentSave,
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

			config: {
				vectorizationEnabled: true,
				vectorizeOnCreate: true,
				vectorizeOnUpdate: false,
				vectorizeAllSchemas: true,
				enabledSchemas: [],
				includeMetadata: true,
				includeRelations: true,
				maxNestingDepth: 10,
				batchSize: 25,
				autoRetry: true,
			},

			schemas: [],
			embeddingProviderName: 'Not configured',
			embeddingModelName: 'Not configured',
			vectorDimensions: 'N/A',
		}
	},

	computed: {
		selectedSchemasCount() {
			return this.config.enabledSchemas.length
		},
	},

	mounted() {
		this.loadConfiguration()
		this.loadSchemas()
		this.loadEmbeddingProviderInfo()
	},

	methods: {
		async loadConfiguration() {
			try {
				// TODO: Load object vectorization config from backend
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
				this.schemas = []
			}
		},

		async loadEmbeddingProviderInfo() {
			try {
				// Load current LLM configuration to show embedding provider info
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/llm'))
				const data = response.data

				this.embeddingProviderName = data.embeddingProvider || 'Not configured'
				this.embeddingModelName = data.embeddingModel || 'Not configured'
				this.vectorDimensions = data.vectorDimensions || 'N/A'
			} catch (error) {
				console.error('Failed to load embedding provider info:', error)
			}
		},

	async saveConfiguration() {
		this.saving = true

		try {
			await axios.patch(generateUrl('/apps/openregister/api/settings/objects/vectorize'), this.config)
			showSuccess(this.t('openregister', 'Object vectorization configuration saved successfully'))
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

	ul {
		margin: 8px 0 0 0;
		padding-left: 20px;
		color: var(--color-text-maxcontrast);

		li {
			margin: 4px 0;
		}
	}

	&.warning {
		background: var(--color-warning-light);
		border-left: 4px solid var(--color-warning);

		strong {
			color: var(--color-main-text);
			display: block;
			margin-bottom: 8px;
		}
	}
}

.config-section {
	margin-bottom: 32px;

	h3 {
		margin: 0 0 8px 0;
		font-size: 16px;
		font-weight: 600;
	}

	h4 {
		margin: 16px 0 8px 0;
		font-size: 14px;
		font-weight: 500;
		color: var(--color-text-maxcontrast);
	}

	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		line-height: 1.5;
	}
}

.trigger-settings {
	margin-top: 16px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: 8px;
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
	margin-top: 20px;

	.selection-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 12px;

		label {
			font-weight: 500;
			margin: 0;
		}

		.selection-stats {
			display: flex;
			gap: 12px;
			align-items: center;

			.stat-badge {
				padding: 4px 12px;
				background: var(--color-primary-element-light);
				color: var(--color-primary-element);
				border-radius: 12px;
				font-size: 12px;
				font-weight: 600;
			}
		}
	}

	.schema-list {
		max-height: 300px;
		overflow-y: auto;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		padding: 12px;
		background: var(--color-background-dark);
	}

	.schema-item {
		margin-bottom: 12px;
		padding: 8px;
		border-radius: 6px;
		background: var(--color-main-background);

		&:last-child {
			margin-bottom: 0;
		}

		.schema-name {
			font-weight: 500;
		}

		.schema-description {
			display: block;
			margin-top: 4px;
			margin-left: 32px;
			font-size: 12px;
			color: var(--color-text-maxcontrast);
		}
	}

	.no-schemas {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		padding: 32px;
		text-align: center;
		color: var(--color-text-maxcontrast);
		border: 2px dashed var(--color-border);
		border-radius: 8px;

		p {
			margin: 0;
		}
	}

	.cost-note {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		margin-top: 12px;
		padding: 12px;
		background: var(--color-background-dark);
		border-radius: 6px;

		small {
			color: var(--color-text-maxcontrast);
			font-size: 13px;
			line-height: 1.4;
		}
	}
}

.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	margin-bottom: 16px;
}

.info-item {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: 6px;

	.info-label {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		font-weight: 500;
	}

	.info-value {
		font-size: 14px;
		color: var(--color-main-text);
		font-weight: 600;
	}
}

.info-note {
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	margin-top: 16px;

	small {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}

/* Actions layout */
:deep(.dialog__actions) {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.actions-left {
	display: flex;
	gap: 12px;
	align-items: center;
}

.actions-right {
	display: flex;
	gap: 8px;
	margin-left: auto;
}

.progress-indicator {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 150px;

	.progress-text {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		font-weight: 500;
	}
}

@media (max-width: 768px) {
	:deep(.dialog__actions) {
		flex-direction: column;
		align-items: stretch;
	}

	.actions-left,
	.actions-right {
		width: 100%;
		justify-content: center;
	}
}
</style>
