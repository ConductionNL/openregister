<template>
	<NcDialog v-if="show"
		:name="t('openregister', 'Vectorize All Files')"
		size="large"
		@closing="$emit('closing')">
		<div class="vectorization-config-content">
			<p class="description">
				{{ t('openregister', 'Configure parameters for file vectorization. This process will generate vector embeddings for all extracted file chunks.') }}
			</p>

			<!-- Execution Mode -->
			<h3>{{ t('openregister', 'Execution Mode') }}</h3>
			<div class="execution-modes">
				<NcCheckboxRadioSwitch
					v-model="executionMode"
					value="serial"
					name="execution_mode"
					type="radio">
					{{ t('openregister', 'Serial Mode (Safer, slower)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="executionMode"
					value="parallel"
					name="execution_mode"
					type="radio">
					{{ t('openregister', 'Parallel Mode (Faster, more resource intensive)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="mode-descriptions">
				<p><strong>{{ t('openregister', 'Serial:') }}</strong> {{ t('openregister', 'Processes file chunks sequentially (safest).') }}</p>
				<p><strong>{{ t('openregister', 'Parallel:') }}</strong> {{ t('openregister', 'Processes chunks in batches with simulated parallelism.') }}</p>
			</div>

			<!-- File Chunk Prediction -->
			<div class="prediction-box">
				<h4>📊 {{ t('openregister', 'File Chunk Prediction') }}</h4>
				<div class="prediction-stats">
					<div class="stat">
						<span class="label">{{ t('openregister', 'Files with Completed Extraction:') }}</span>
						<span class="value">{{ stats.extractedFiles.toLocaleString() }}</span>
					</div>
					<div class="stat highlight">
						<span class="label">{{ t('openregister', 'Total Chunks Available:') }}</span>
						<span class="value">{{ stats.totalChunks.toLocaleString() }}</span>
					</div>
					<div class="stat highlight primary">
						<span class="label">{{ t('openregister', 'Chunks to Vectorize:') }}</span>
						<span class="value">{{ stats.chunksToProcess.toLocaleString() }}</span>
					</div>
					<div class="stat">
						<span class="label">{{ t('openregister', 'Estimated Batches:') }}</span>
						<span class="value">{{ estimatedBatches }}</span>
					</div>
					<div class="stat">
						<span class="label">{{ t('openregister', 'Estimated Duration:') }}</span>
						<span class="value">{{ estimatedDuration }}</span>
					</div>
					<div class="stat">
						<span class="label">{{ t('openregister', 'Estimated Cost:') }}</span>
						<span class="value">{{ estimatedCost }}</span>
					</div>
				</div>
			</div>

			<!-- Processing Limits -->
			<h3>{{ t('openregister', 'Processing Limits') }}</h3>

			<div class="form-group">
				<label for="max-files">{{ t('openregister', 'Max Files (0 = all)') }}</label>
				<input
					id="max-files"
					v-model.number="maxFiles"
					type="number"
					min="0"
					:placeholder="t('openregister', 'Maximum number of files to process. Set to 0 to process all files.')">
				<small>{{ t('openregister', 'Maximum number of files to process. Set to 0 to process all files.') }}</small>
			</div>

			<div class="form-group">
				<label for="batch-size">{{ t('openregister', 'Batch Size') }}</label>
				<input
					id="batch-size"
					v-model.number="batchSize"
					type="number"
					min="1"
					max="100"
					:placeholder="t('openregister', 'Number of chunks to vectorize in one API call (1-100).')">
				<small>{{ t('openregister', 'Number of chunks to vectorize in one API call. Higher = faster but more memory. Recommended: 10-50.') }}</small>
			</div>

			<!-- File Type Selection -->
			<h3>{{ t('openregister', 'File Type Selection') }}</h3>
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>{{ t('openregister', 'Choose which file types to include in the vectorization process. Only files with extracted text and chunks will be processed.') }}</p>
			</div>

			<div class="form-group">
				<NcCheckboxRadioSwitch
					v-model="vectorizeAllTypes"
					type="switch">
					{{ t('openregister', 'Vectorize all file types') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="!vectorizeAllTypes && fileTypes.length > 0" class="file-type-selection">
				<label>{{ t('openregister', 'Select File Types to Vectorize:') }}</label>
				<div class="type-list">
					<NcCheckboxRadioSwitch
						v-for="type in fileTypes"
						:key="type.mime"
						v-model="selectedTypes"
						:value="type.mime"
						type="checkbox">
						{{ type.name }} ({{ type.count }} {{ t('openregister', 'files') }})
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Progress (shown during processing) -->
			<div v-if="processing" class="progress-section">
				<NcProgressBar :value="progress" :error="failed > 0">
					{{ processed }} / {{ stats.chunksToProcess }} {{ t('openregister', 'chunks processed') }}
				</NcProgressBar>
				<div class="progress-stats">
					<span class="success">✓ {{ vectorized }} {{ t('openregister', 'vectorized') }}</span>
					<span v-if="failed > 0" class="error">✗ {{ failed }} {{ t('openregister', 'failed') }}</span>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('closing')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="processing || (stats.chunksToProcess === 0)"
				@click="startVectorization">
				<template #icon>
					<PlayCircle v-if="!processing" :size="20" />
					<NcLoadingIcon v-else :size="20" />
				</template>
				{{ processing ? t('openregister', 'Processing...') : t('openregister', 'Start Vectorization') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'FileVectorizationModal',

	components: {
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcProgressBar,
		InformationOutline,
		PlayCircle,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},
		extractionStats: {
			type: Object,
			default: () => ({
				totalFiles: 0,
				completed: 0,
				processedFiles: 0,
				totalChunks: 0,
			}),
		},
		vectorStats: {
			type: Object,
			default: () => ({
				stats: {
					file_vectors: 0,
				},
			}),
		},
	},

	data() {
		return {
			executionMode: 'serial',
			maxFiles: 0,
			batchSize: 25,
			vectorizeAllTypes: true,
			selectedTypes: [],
			fileTypes: [],

			processing: false,
			processed: 0,
			vectorized: 0,
			failed: 0,
		}
	},

	computed: {
		stats() {
			const extractedFiles = this.extractionStats?.completed || this.extractionStats?.processedFiles || 0
			const totalChunks = this.extractionStats?.totalChunks || 0
			const vectorizedChunks = this.vectorStats?.stats?.file_vectors || 0
			const chunksToProcess = Math.max(0, totalChunks - vectorizedChunks)

			return {
				extractedFiles,
				totalChunks,
				chunksToProcess,
			}
		},

		progress() {
			if (this.stats.chunksToProcess === 0) return 0
			return Math.round((this.processed / this.stats.chunksToProcess) * 100)
		},

		estimatedBatches() {
			if (this.stats.chunksToProcess === 0 || this.batchSize === 0) return 0
			return Math.ceil(this.stats.chunksToProcess / this.batchSize)
		},

		estimatedDuration() {
			// Estimate ~100-200ms per chunk for embedding generation
			const avgTimePerChunk = 0.15 // seconds
			const totalSeconds = this.stats.chunksToProcess * avgTimePerChunk
			
			if (totalSeconds < 60) {
				return `~${Math.ceil(totalSeconds)} seconds`
			} else if (totalSeconds < 3600) {
				return `~${Math.ceil(totalSeconds / 60)} minutes`
			} else {
				return `~${Math.ceil(totalSeconds / 3600)} hours`
			}
		},

		estimatedCost() {
			// Assuming OpenAI pricing: $0.0001 per 1K tokens
			// Average chunk is ~1000 characters = ~750 tokens
			const tokensPerChunk = 750
			const totalTokens = this.stats.chunksToProcess * tokensPerChunk
			const cost = (totalTokens / 1000) * 0.0001
			
			return `$${cost.toFixed(4)}`
		},
	},

	methods: {
		async loadFileTypes() {
			try {
				// Load file types with counts
				// This would need a backend endpoint to provide this data
				this.fileTypes = [
					{ mime: 'application/pdf', name: 'PDF', count: 0 },
					{ mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', name: 'DOCX', count: 0 },
					{ mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', name: 'XLSX', count: 0 },
					{ mime: 'text/plain', name: 'Text', count: 0 },
					{ mime: 'text/markdown', name: 'Markdown', count: 0 },
				]
			} catch (error) {
				console.error('Failed to load file types:', error)
			}
		},

		async startVectorization() {
			if (this.stats.chunksToProcess === 0) {
				showError(this.t('openregister', 'No chunks to vectorize'))
				return
			}

			this.processing = true
			this.processed = 0
			this.vectorized = 0
			this.failed = 0

			try {
				const params = {
					mode: this.executionMode,
					max_files: this.maxFiles,
					batch_size: this.batchSize,
					file_types: this.vectorizeAllTypes ? [] : this.selectedTypes,
				}

				const response = await axios.post(
					generateUrl('/apps/openregister/api/files/vectorize/batch'),
					params
				)

				if (response.data.success) {
					this.vectorized = response.data.vectorized || 0
					this.failed = response.data.failed || 0
					this.processed = this.vectorized + this.failed

					showSuccess(this.t('openregister', 'File vectorization completed. {vectorized} chunks vectorized, {failed} failed.', {
						vectorized: this.vectorized,
						failed: this.failed,
					}))

					// Reload stats
					await this.loadStats()
				} else {
					throw new Error(response.data.error || 'Unknown error')
				}
			} catch (error) {
				console.error('Vectorization failed:', error)
				showError(this.t('openregister', 'Failed to vectorize files: {error}', {
					error: error.response?.data?.error || error.message,
				}))
			} finally {
				this.processing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.vectorization-config-content {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 24px;
}

h3 {
	margin: 24px 0 12px 0;
	font-size: 16px;
	font-weight: 600;
}

.execution-modes {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 16px;
}

.mode-descriptions {
	padding: 12px 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	margin-bottom: 24px;

	p {
		margin: 8px 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);

		strong {
			color: var(--color-main-text);
		}
	}
}

.prediction-box {
	background: var(--color-primary-element-light);
	border-left: 4px solid var(--color-primary-element);
	padding: 16px;
	border-radius: 8px;
	margin-bottom: 24px;

	h4 {
		margin: 0 0 16px 0;
		font-size: 15px;
		font-weight: 600;
	}
}

.prediction-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;

	.stat {
		display: flex;
		flex-direction: column;
		gap: 4px;

		.label {
			font-size: 13px;
			color: var(--color-text-maxcontrast);
		}

		.value {
			font-size: 20px;
			font-weight: 700;
			color: var(--color-primary-element);
		}

		&.highlight {
			padding: 8px;
			background: var(--color-background-hover);
			border-radius: 6px;

			.value {
				font-size: 24px;
			}
		}

		&.highlight.primary {
			background: var(--color-primary-element);
			
			.label {
				color: var(--color-primary-element-text);
			}

			.value {
				color: var(--color-primary-element-text);
				font-size: 28px;
			}
		}
	}
}

.form-group {
	margin-bottom: 20px;

	label {
		display: block;
		margin-bottom: 8px;
		font-weight: 500;
	}

	input {
		width: 100%;
		padding: 8px 12px;
		border: 2px solid var(--color-border);
		border-radius: 8px;
		font-size: 14px;

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

.info-box {
	display: flex;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	margin-bottom: 16px;
	align-items: flex-start;

	p {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}
}

.file-type-selection {
	margin-top: 16px;

	label {
		display: block;
		margin-bottom: 12px;
		font-weight: 500;
	}
}

.type-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: 8px;
	max-height: 200px;
	overflow-y: auto;
}

.progress-section {
	margin-top: 24px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: 8px;

	.progress-stats {
		display: flex;
		gap: 20px;
		margin-top: 12px;
		font-size: 14px;

		.success {
			color: var(--color-success);
		}

		.error {
			color: var(--color-error);
		}
	}
}
</style>

