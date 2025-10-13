<template>
	<NcDialog v-if="show"
		:name="t('openregister', 'File Management')"
		size="large"
		@closing="$emit('closing')">
		<div class="file-config-content">
			<!-- Info box -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>
					{{ t('openregister', 'Configure file processing and vectorization settings for semantic file search.') }}
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
					<small>{{ t('openregister', 'Automatically generate vector embeddings when files are uploaded') }}</small>
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
			</div>

			<!-- Document Chunking -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Document Chunking') }}</h3>
				
				<div class="form-group">
					<label>{{ t('openregister', 'Chunking Strategy') }}</label>
					<NcSelect
						v-model="config.chunkingStrategy"
						:options="chunkingStrategyOptions"
						label="name"
						:placeholder="t('openregister', 'Select strategy')">
						<template #option="{ name, description }">
							<div class="option-with-desc">
								<strong>{{ name }}</strong>
								<small>{{ description }}</small>
							</div>
						</template>
					</NcSelect>
				</div>

				<div class="form-group">
					<label for="chunk-size">{{ t('openregister', 'Chunk Size') }}</label>
					<input
						id="chunk-size"
						v-model.number="config.chunkSize"
						type="number"
						min="100"
						max="4000"
						step="100"
						class="input-field">
					<small>{{ t('openregister', 'Number of characters per chunk (100-4000)') }}</small>
				</div>

				<div class="form-group">
					<label for="chunk-overlap">{{ t('openregister', 'Chunk Overlap') }}</label>
					<input
						id="chunk-overlap"
						v-model.number="config.chunkOverlap"
						type="number"
						min="0"
						:max="Math.floor(config.chunkSize / 2)"
						step="10"
						class="input-field">
					<small>{{ t('openregister', 'Overlap between chunks (0-{max} for current chunk size)', { max: Math.floor(config.chunkSize / 2) }) }}</small>
				</div>
			</div>

			<!-- File Type Support -->
			<div class="config-section">
				<h3>{{ t('openregister', 'File Type Support') }}</h3>
				
				<div class="file-types-grid">
					<div v-for="category in fileCategoriesConfig" :key="category.name" class="file-category">
						<h4>{{ category.name }}</h4>
						<div v-for="type in category.types" :key="type.ext" class="file-type-item">
							<NcCheckboxRadioSwitch
								v-model="config.enabledFileTypes"
								:value="type.ext"
								type="checkbox">
								{{ type.label }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</div>
			</div>

			<!-- OCR Settings -->
			<div class="config-section">
				<h3>{{ t('openregister', 'OCR Settings') }}</h3>
				
				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.ocrEnabled"
						type="switch">
						{{ t('openregister', 'Enable OCR for images') }}
					</NcCheckboxRadioSwitch>
					<small>{{ t('openregister', 'Extract text from images using Tesseract OCR (requires Tesseract installation)') }}</small>
				</div>
			</div>

			<!-- Processing Limits -->
			<div class="config-section">
				<h3>{{ t('openregister', 'Processing Limits') }}</h3>
				
				<div class="form-group">
					<label for="max-file-size">{{ t('openregister', 'Maximum File Size (MB)') }}</label>
					<input
						id="max-file-size"
						v-model.number="config.maxFileSizeMB"
						type="number"
						min="1"
						max="500"
						class="input-field">
					<small>{{ t('openregister', 'Maximum file size to process (1-500 MB)') }}</small>
				</div>
			</div>

			<!-- Stats -->
			<div class="config-section">
				<h3>{{ t('openregister', 'File Statistics') }}</h3>
				
				<div class="stats-grid">
					<div class="stat-card">
						<div class="stat-value">{{ stats.totalFiles }}</div>
						<div class="stat-label">{{ t('openregister', 'Total Files') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ stats.vectorizedFiles }}</div>
						<div class="stat-label">{{ t('openregister', 'Vectorized Files') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ stats.pendingFiles }}</div>
						<div class="stat-label">{{ t('openregister', 'Pending') }}</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">{{ stats.totalChunks }}</div>
						<div class="stat-label">{{ t('openregister', 'Total Chunks') }}</div>
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
				provider: { id: 'openai', name: 'OpenAI' },
				chunkingStrategy: { id: 'RECURSIVE_CHARACTER', name: 'Recursive Character' },
				chunkSize: 1000,
				chunkOverlap: 200,
				enabledFileTypes: ['pdf', 'docx', 'txt', 'md', 'html', 'json', 'xml'],
				ocrEnabled: false,
				maxFileSizeMB: 100,
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
			
			providerOptions: [
				{ id: 'openai', name: 'OpenAI' },
				{ id: 'ollama', name: 'Ollama (Local)' },
			],
			
			chunkingStrategyOptions: [
				{ id: 'FIXED_SIZE', name: 'Fixed Size', description: 'Split by character count' },
				{ id: 'RECURSIVE_CHARACTER', name: 'Recursive Character', description: 'Split preserving structure' },
			],
			
			fileCategoriesConfig: [
				{
					name: 'Office Documents',
					types: [
						{ ext: 'pdf', label: 'PDF' },
						{ ext: 'docx', label: 'Word (DOCX)' },
						{ ext: 'xlsx', label: 'Excel (XLSX)' },
						{ ext: 'pptx', label: 'PowerPoint (PPTX)' },
					],
				},
				{
					name: 'Text Formats',
					types: [
						{ ext: 'txt', label: 'Plain Text' },
						{ ext: 'md', label: 'Markdown' },
						{ ext: 'html', label: 'HTML' },
						{ ext: 'json', label: 'JSON' },
						{ ext: 'xml', label: 'XML' },
					],
				},
				{
					name: 'Images (OCR)',
					types: [
						{ ext: 'jpg', label: 'JPEG' },
						{ ext: 'png', label: 'PNG' },
						{ ext: 'gif', label: 'GIF' },
						{ ext: 'tiff', label: 'TIFF' },
					],
				},
			],
		}
	},

	mounted() {
		this.loadConfiguration()
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

		async saveConfiguration() {
			this.saving = true

			try {
				await axios.post(generateUrl('/apps/openregister/api/settings/files'), this.config)
				showSuccess(this.t('openregister', 'File configuration saved successfully'))
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

.file-types-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 24px;
}

.file-category {
	.file-type-item {
		margin-bottom: 8px;
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
</style>

