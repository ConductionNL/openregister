<template>
	<NcDialog v-if="show"
		:name="t('openregister', 'File Vectorization')"
		size="large"
		@closing="$emit('closing')">
		<div class="file-config-content">
			<!-- Info box -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>
					{{ t('openregister', 'Configure how extracted text chunks are converted into vector embeddings for semantic search. Text extraction must be configured separately in File Configuration.') }}
				</p>
			</div>

			<!-- Important Note -->
			<div class="info-box warning">
				<AlertCircle :size="20" />
				<div>
					<strong>{{ t('openregister', 'Prerequisites') }}</strong>
					<p>{{ t('openregister', 'Before vectorization can work:') }}</p>
					<ul>
						<li>{{ t('openregister', 'LLM must be enabled with an embedding provider configured') }}</li>
						<li>{{ t('openregister', 'Text extraction must be enabled in File Configuration') }}</li>
						<li>{{ t('openregister', 'Files must be extracted and chunked before vectorization') }}</li>
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
						{{ t('openregister', 'Enable automatic file vectorization') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Automatically generate vector embeddings from text chunks when files are uploaded and processed') }}</small>
				</div>
			</div>

			<!-- Chunk to Vector Configuration -->
			<div v-if="config.vectorizationEnabled" class="config-section">
				<h3>{{ t('openregister', '📄 Chunk Processing') }}</h3>
				<p class="section-description">
					{{ t('openregister', 'Configure how text chunks are processed before converting them into vector embeddings. These settings affect the granularity and context preservation of your semantic search.') }}
				</p>

				<div class="form-group">
					<label>{{ t('openregister', 'Chunking Strategy') }}</label>
					<NcSelect
						v-model="config.chunkingStrategy"
						:options="chunkingStrategyOptions"
						label="name"
						:placeholder="t('openregister', 'Select strategy')"
						:label-outside="true">
						<template #option="{ name, description }">
							<div class="option-with-desc">
								<strong>{{ name }}</strong>
								<small>{{ description }}</small>
							</div>
						</template>
					</NcSelect>
					<small>{{ t('openregister', 'How to split extracted text into smaller portions for vectorization') }}</small>
				</div>

				<div class="form-group">
					<label for="chunk-size">{{ t('openregister', 'Chunk Size (characters)') }}</label>
					<input
						id="chunk-size"
						v-model.number="config.chunkSize"
						type="number"
						min="100"
						max="4000"
						step="100"
						class="input-field">
					<small>{{ t('openregister', 'Number of characters per chunk. Smaller chunks = more precise but more vectors. Recommended: 1000-2000 characters.') }}</small>
				</div>

				<div class="form-group">
					<label for="chunk-overlap">{{ t('openregister', 'Chunk Overlap (characters)') }}</label>
					<input
						id="chunk-overlap"
						v-model.number="config.chunkOverlap"
						type="number"
						min="0"
						:max="Math.floor(config.chunkSize / 2)"
						step="10"
						class="input-field">
					<small>{{ t('openregister', 'Overlap between consecutive chunks to preserve context across boundaries. Recommended: 10-20% of chunk size (current max: {max})', { max: Math.floor(config.chunkSize / 2) }) }}</small>
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
					<small>{{ t('openregister', 'Number of chunks to vectorize in one API call. Higher = faster but more memory. Recommended: 10-50.') }}</small>
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

			<!-- Vectorization Statistics -->
			<div class="config-section">
				<h3>{{ t('openregister', '📊 Vectorization Statistics') }}</h3>

				<div class="stats-grid">
					<div class="stat-card">
						<div class="stat-value">
							{{ stats.totalChunks }}
						</div>
						<div class="stat-label">
							{{ t('openregister', 'Total Chunks') }}
						</div>
					</div>
					<div class="stat-card highlight">
						<div class="stat-value">
							{{ vectorStats.fileVectors }}
						</div>
						<div class="stat-label">
							{{ t('openregister', 'Vectorized Chunks') }}
						</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">
							{{ stats.pendingFiles }}
						</div>
						<div class="stat-label">
							{{ t('openregister', 'Pending Files') }}
						</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">
							{{ vectorStats.storageMB }}
						</div>
						<div class="stat-label">
							{{ t('openregister', 'Storage (MB)') }}
						</div>
					</div>
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

				<!-- Vector Models Breakdown -->
				<div v-if="vectorStats.byModel && Object.keys(vectorStats.byModel).length > 0" class="model-breakdown">
					<h4>{{ t('openregister', 'Vectors by Embedding Model') }}</h4>
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
			<div class="actions-left">
				<NcButton
					v-if="config.vectorizationEnabled"
					type="secondary"
					:disabled="processing"
					@click="processNow">
					<template #icon>
						<NcLoadingIcon v-if="processing" :size="20" />
						<PlayCircle v-else :size="20" />
					</template>
					{{ processing ? t('openregister', 'Processing...') : t('openregister', 'Vectorize Pending Files Now') }}
				</NcButton>
			</div>

			<div class="actions-right">
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
			</div>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'FileManagementModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcCheckboxRadioSwitch,
		InformationOutline,
		AlertCircle,
		ContentSave,
		PlayCircle,
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
			processing: false,

			config: {
				vectorizationEnabled: true,
				chunkingStrategy: { id: 'RECURSIVE_CHARACTER', name: 'Recursive Character' },
				chunkSize: 1000,
				chunkOverlap: 200,
				batchSize: 25,
				autoRetry: true,
			},

			stats: {
				totalFiles: 0,
				vectorizedFiles: 0,
				pendingFiles: 0,
				totalChunks: 0,
			},

			vectorStats: {
				totalVectors: 0,
				objectVectors: 0,
				fileVectors: 0,
				storageMB: '0.0',
				byModel: {},
			},

			embeddingProviderName: 'Not configured',
			embeddingModelName: 'Not configured',
			vectorDimensions: 'N/A',

			chunkingStrategyOptions: [
				{
					id: 'FIXED_SIZE',
					name: 'Fixed Size',
					description: 'Simple character-based splitting (faster, less context-aware)',
				},
				{
					id: 'RECURSIVE_CHARACTER',
					name: 'Recursive Character',
					description: 'Smart splitting that preserves paragraphs and sentences (recommended)',
				},
				{
					id: 'SEMANTIC',
					name: 'Semantic',
					description: 'AI-powered splitting based on meaning (slower, best quality)',
				},
			],
		}
	},

	mounted() {
		this.loadConfiguration()
		this.loadStats()
		this.loadEmbeddingProviderInfo()
	},

	methods: {
		async loadConfiguration() {
			try {
				// TODO: Load vectorization config from backend
				this.loading = false
			} catch (error) {
				console.error('Failed to load configuration:', error)
				this.loading = false
			}
		},

		async loadStats() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/objects/vectorize/stats'))
				const data = response.data

				// Update file stats
				if (data.files) {
					this.stats.totalFiles = data.files.total_files || 0
					this.stats.vectorizedFiles = data.files.vectorized_files || 0
					this.stats.pendingFiles = data.files.pending_files || 0
					this.stats.totalChunks = data.files.total_chunks || 0
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
				await axios.post(generateUrl('/apps/openregister/api/settings/file-vectorization'), this.config)
				showSuccess(this.t('openregister', 'File vectorization configuration saved successfully'))
				this.$emit('closing')
			} catch (error) {
				showError(this.t('openregister', 'Failed to save configuration: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.saving = false
			}
		},

		async processNow() {
			this.processing = true

			try {
				await axios.post(generateUrl('/apps/openregister/api/files/vectorize/batch'))
				showSuccess(this.t('openregister', 'File vectorization started. Check back soon for results.'))
				await this.loadStats()
			} catch (error) {
				showError(this.t('openregister', 'Failed to start vectorization: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.processing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.file-config-content {
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
		margin: 0 0 12px 0;
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

.option-with-desc {
	display: flex;
	flex-direction: column;
	gap: 4px;

	strong {
		font-size: 14px;
	}

	small {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
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

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
	gap: 8px;
	align-items: center;
}

.actions-right {
	display: flex;
	gap: 8px;
	margin-left: auto;
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
