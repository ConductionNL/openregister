<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'Vectorize All Files')"
		size="large"
		@closing="$emit('closing')">
		<div class="vectorization-config-content">
			<p class="description">
				{{
					t(
						'openregister',
						'Configure parameters for file vectorization. This process will generate vector embeddings for all extracted file chunks.',
					)
				}}
			</p>

			<VectorizationModeChoice
				v-model="executionMode"
				:serialText="
					t('openregister', 'Processes file chunks sequentially (safest).')
				"
				:parallelText="
					t(
						'openregister',
						'Processes chunks in batches with simulated parallelism.',
					)
				" />

			<VectorizationPrediction
				:title="t('openregister', 'File Chunk Prediction')"
				:batches="estimatedBatches"
				:duration="estimatedDuration"
				:cost="estimatedCost">
				<div class="stat">
					<span class="label">{{
						t('openregister', 'Files with Completed Extraction:')
					}}</span>
					<span class="value">{{
						stats.extractedFiles.toLocaleString()
					}}</span>
				</div>
				<div class="stat highlight">
					<span class="label">{{
						t('openregister', 'Total Chunks Available:')
					}}</span>
					<span class="value">{{
						stats.totalChunks.toLocaleString()
					}}</span>
				</div>
				<div class="stat highlight primary">
					<span class="label">{{
						t('openregister', 'Chunks to Vectorize:')
					}}</span>
					<span class="value">{{
						stats.chunksToProcess.toLocaleString()
					}}</span>
				</div>
			</VectorizationPrediction>

			<h3>{{ t('openregister', 'Processing Limits') }}</h3>
			<VectorizationNumberField
				id="max-files"
				v-model="maxFiles"
				min="0"
				:label="t('openregister', 'Max Files (0 = all)')"
				:hint="
					t(
						'openregister',
						'Maximum number of files to process. Set to 0 to process all files.',
					)
				" />
			<VectorizationNumberField
				id="batch-size"
				v-model="batchSize"
				max="100"
				min="1"
				:label="t('openregister', 'Batch Size')"
				:placeholder="
					t(
						'openregister',
						'Number of chunks to vectorize in one API call (1-100).',
					)
				"
				:hint="chunkBatchSizeHint" />

			<VectorizationScope
				v-model="vectorizeAllTypes"
				:title="t('openregister', 'File Type Selection')"
				:info="
					t(
						'openregister',
						'Choose which file types to include in the vectorization process. Only files with extracted text and chunks will be processed.',
					)
				"
				:allLabel="t('openregister', 'Vectorize all file types')">
				<div
					v-if="!vectorizeAllTypes && fileTypes.length > 0"
					class="file-type-selection">
					<label>{{
						t('openregister', 'Select File Types to Vectorize:')
					}}</label>
					<div class="type-list">
						<NcCheckboxRadioSwitch
							v-for="type in fileTypes"
							:key="type.mime"
							v-model="selectedTypes"
							:value="type.mime"
							type="checkbox">
							{{ type.name }} ({{ type.fileCount }}
							{{
								type.fileCount === 1
									? t('openregister', 'file')
									: t('openregister', 'files')
							}}, {{ type.chunkCount }}
							{{
								type.chunkCount === 1
									? t('openregister', 'chunk')
									: t('openregister', 'chunks')
							}})
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</VectorizationScope>

			<VectorizationProgress
				:total="stats.chunksToProcess"
				:run="$data"
				:unitLabel="t('openregister', 'chunks processed')" />
		</div>

		<template #actions>
			<VectorizationActions
				:processing="processing"
				:empty="stats.chunksToProcess === 0"
				@cancel="$emit('closing')"
				@start="startVectorization" />
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { vectorizationModalComponents } from './vectorization/vectorizationModalComponents.js'
import { beginRun, idleRun } from './vectorization/vectorizationRun.js'
import { chunkBatchSizeHint } from './vectorization/vectorizationSettings.js'

/**
 * Whether a failed request says file vectorization does not exist yet.
 *
 * @param {object} error The request error
 * @return {boolean} Whether the server has no file vectorization
 */
function isNotImplemented(error) {
	return (
		error.response?.status === 501
		|| error.response?.data?.error === 'File vectorization not yet implemented'
	)
}

export default {
	name: 'FileVectorizationModal',

	components: vectorizationModalComponents,

	props: {
		show: { type: Boolean, required: true },

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
			...idleRun(),
		}
	},

	computed: {
		chunkBatchSizeHint,

		/**
		 * @spec exclude computed display helper aggregating vectorization stats
		 */
		stats() {
			const extractedFiles =
				this.extractionStats?.completed
				|| this.extractionStats?.processedFiles
				|| 0
			const totalChunks = this.extractionStats?.totalChunks || 0
			const vectorizedChunks = this.vectorStats?.stats?.file_vectors || 0
			const chunksToProcess = Math.max(0, totalChunks - vectorizedChunks)

			return {
				extractedFiles,
				totalChunks,
				chunksToProcess,
			}
		},

		/**
		 * @spec exclude computed estimate of batch count
		 */
		estimatedBatches() {
			if (this.stats.chunksToProcess === 0 || this.batchSize === 0) return 0
			return Math.ceil(this.stats.chunksToProcess / this.batchSize)
		},

		/**
		 * @spec exclude computed estimate of duration
		 */
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

		/**
		 * @spec exclude computed estimate of cost
		 */
		estimatedCost() {
			// Assuming OpenAI pricing: $0.0001 per 1K tokens
			// Average chunk is ~1000 characters = ~750 tokens
			const tokensPerChunk = 750
			const totalTokens = this.stats.chunksToProcess * tokensPerChunk
			const cost = (totalTokens / 1000) * 0.0001

			return `$${cost.toFixed(4)}`
		},
	},

	watch: {
		/**
		 * @param {boolean} newValue
		 * @spec exclude watcher reloading file types when modal opens
		 */
		show(newValue) {
			if (newValue) {
				// Reload file types when modal is opened
				this.loadFileTypes()
			}
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook loading file types
	 */
	mounted() {
		if (this.show) {
			this.loadFileTypes()
		}
	},

	methods: {
		/**
		 * @spec exclude form-state loader for file types via API
		 */
		async loadFileTypes() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/files/types'),
				)
				if (response.data.success) {
					this.fileTypes = response.data.data
				}
			} catch (error) {
				console.error(
					'[FileVectorizationModal] Failed to load file types:',
					error,
				)
			}
		},

		/**
		 * @spec exclude modal submit handler triggering batch vectorization via API
		 */
		async startVectorization() {
			if (this.stats.chunksToProcess === 0) {
				showError(this.t('openregister', 'No chunks to vectorize'))
				return
			}

			beginRun(this)
			try {
				this.finishVectorization(await this.requestBatch())
			} catch (error) {
				console.error('Vectorization failed:', error)
				showError(this.vectorizeFailureText(error))
			} finally {
				this.processing = false
			}
		},

		/**
		 * Send the batch request and return its result.
		 *
		 * @spec exclude UI plumbing — the one batch request of the modal
		 * @return {Promise<object>} The batch result
		 */
		async requestBatch() {
			const response = await axios.post(
				generateUrl('/apps/openregister/api/files/vectorize/batch'),
				this.vectorizeParams(),
			)
			if (!response.data.success) {
				throw new Error(response.data.error || 'Unknown error')
			}
			return response.data.data
		},

		/**
		 * The batch request body from the chosen options.
		 *
		 * @spec exclude UI plumbing — builds the vectorize request from the form
		 * @return {object} The request parameters
		 */
		vectorizeParams() {
			return {
				mode: this.executionMode,
				max_files: this.maxFiles,
				batch_size: this.batchSize,
				file_types: this.vectorizeAllTypes ? [] : this.selectedTypes,
			}
		},

		/**
		 * Show the outcome of a finished batch and close the modal.
		 *
		 * @param {object} result The batch result
		 * @spec exclude UI plumbing — reports the batch result and closes the modal
		 * @return {void}
		 */
		finishVectorization(result) {
			this.vectorized = result.vectorized || 0
			this.failed = result.failed || 0
			this.processed = this.vectorized + this.failed

			showSuccess(this.completedText(result.total_files || 0))
			this.$emit('completed')
			this.$emit('closing')
		},

		/**
		 * What a finished batch did, in words.
		 *
		 * @param {number} files The number of files the batch covered
		 * @spec exclude UI plumbing — words a finished vectorize request
		 * @return {string} The translated message
		 */
		completedText(files) {
			return this.t(
				'openregister',
				'File vectorization completed! {vectorized} chunks vectorized from {files} files. {failed} failed.',
				{ vectorized: this.vectorized, files, failed: this.failed },
			)
		},

		/**
		 * Why a batch failed, in words.
		 *
		 * @param {object} error The request error
		 * @spec exclude UI plumbing — words a failed vectorize request
		 * @return {string} The translated message
		 */
		vectorizeFailureText(error) {
			const errorData = error.response?.data
			if (isNotImplemented(error)) {
				return this.t(
					'openregister',
					'File chunk vectorization is not yet implemented. The chunks are ready and stored, but the vectorization service is under development.',
				)
			}
			return this.t('openregister', 'Failed to vectorize files: {error}', {
				error: errorData?.error || errorData?.message || error.message,
			})
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include config-content('vectorization-config');

.description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 24px;
}

:deep(h3) {
	margin: 24px 0 12px 0;
	font-size: 16px;
	font-weight: 600;
}

:deep(.execution-modes) {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 16px;
}

:deep(.mode-descriptions) {
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

:deep(.prediction-box) {
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

:deep(.prediction-stats) {
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

:deep(.form-group) {
	> input {
		border: 2px solid var(--color-border);
		border-radius: 8px;
	}

	> small {
		margin-top: 6px;
	}
}

:deep(.info-box) {
	padding: 12px 16px;
	background: var(--color-background-hover);
	align-items: flex-start;
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

:deep(.progress-section) {
	background: var(--color-background-hover);

	.progress-stats {
		gap: 20px;
	}
}
</style>
