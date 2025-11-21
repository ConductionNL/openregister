<template>
	<NcDialog :open="showDialog"
		:name="dialogTitle"
		size="large"
		@update:open="handleDialogUpdate">
		<div class="file-warmup-modal">
			<!-- Info Box -->
			<NcNoteCard type="info" class="info-box">
				<p>
					File warmup extracts text from files and indexes them in SOLR for fast full-text search.
					This process runs in batches and may take several minutes for large file collections.
				</p>
			</NcNoteCard>

			<!-- Configuration Section -->
			<div class="warmup-config">
				<h3>Warmup Configuration</h3>

				<!-- Max Files -->
				<div class="form-group">
					<label for="maxFiles">Maximum Files to Process</label>
					<input id="maxFiles"
						v-model.number="config.maxFiles"
						type="number"
						min="1"
						max="5000"
						class="input-field">
					<p class="hint">
						Maximum: 5000 files per warmup operation
					</p>
				</div>

				<!-- Batch Size -->
				<div class="form-group">
					<label for="batchSize">Batch Size</label>
					<input id="batchSize"
						v-model.number="config.batchSize"
						type="number"
						min="10"
						max="500"
						class="input-field">
					<p class="hint">
						Number of files to process per batch (recommended: 50-100)
					</p>
				</div>

				<!-- Skip Already Indexed -->
				<div class="form-group">
					<NcCheckboxRadioSwitch :checked.sync="config.skipIndexed"
						type="switch">
						Skip Already Indexed Files
					</NcCheckboxRadioSwitch>
					<p class="hint">
						Only process files that haven't been indexed yet
					</p>
				</div>

				<!-- File Type Filter -->
				<div class="form-group">
					<label>File Types to Process</label>
					<NcSelect v-model="config.selectedFileTypes"
						:options="fileTypeOptions"
						:multiple="true"
						:label-outside="true"
						placeholder="All file types">
						<template #selected-option="{ label }">
							<span class="option-label">{{ label }}</span>
						</template>
					</NcSelect>
					<p class="hint">
						Leave empty to process all supported file types
					</p>
				</div>
			</div>

			<!-- Statistics Section -->
			<div v-if="stats" class="stats-section">
				<h3>Current Statistics</h3>
				<div class="stats-grid">
					<div class="stat-card">
						<div class="stat-value">
							{{ stats.total_extracted || 0 }}
						</div>
						<div class="stat-label">
							Files Extracted
						</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">
							{{ stats.total_indexed || 0 }}
						</div>
						<div class="stat-label">
							Files Indexed
						</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">
							{{ stats.pending_extraction || 0 }}
						</div>
						<div class="stat-label">
							Pending Extraction
						</div>
					</div>
					<div class="stat-card">
						<div class="stat-value">
							{{ stats.pending_indexing || 0 }}
						</div>
						<div class="stat-label">
							Pending Indexing
						</div>
					</div>
				</div>
			</div>

			<!-- Progress Section -->
			<div v-if="isProcessing" class="progress-section">
				<h3>Processing Files...</h3>
				<NcProgressBar :value="progress" />
				<p class="progress-text">
					{{ processedFiles }} / {{ totalFiles }} files processed
					<span v-if="failedFiles > 0" class="error-count">({{ failedFiles }} failed)</span>
				</p>
			</div>

			<!-- Results Section -->
			<div v-if="results" class="results-section">
				<NcNoteCard :type="results.failed > 0 ? 'warning' : 'success'">
					<h4>Warmup Complete</h4>
					<ul>
						<li>Files Processed: {{ results.files_processed }}</li>
						<li>Successfully Indexed: {{ results.indexed }}</li>
						<li v-if="results.failed > 0" class="error-item">
							Failed: {{ results.failed }}
						</li>
					</ul>
					<div v-if="results.errors && results.errors.length > 0" class="error-list">
						<h5>Errors:</h5>
						<ul>
							<li v-for="(error, index) in results.errors.slice(0, 5)" :key="index">
								{{ error }}
							</li>
						</ul>
						<p v-if="results.errors.length > 5" class="more-errors">
							... and {{ results.errors.length - 5 }} more errors
						</p>
					</div>
				</NcNoteCard>
			</div>
		</div>

		<!-- Action Buttons -->
		<template #actions>
			<NcButton @click="handleClose">
				Close
			</NcButton>
			<NcButton @click="refreshStats">
				<template #icon>
					<Refresh :size="20" />
				</template>
				Refresh Stats
			</NcButton>
			<NcButton type="primary"
				:disabled="isProcessing"
				@click="startWarmup">
				<template #icon>
					<NcLoadingIcon v-if="isProcessing" :size="20" />
					<Play v-else :size="20" />
				</template>
				{{ isProcessing ? 'Processing...' : 'Start Warmup' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Play from 'vue-material-design-icons/Play.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'FileWarmupModal',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcProgressBar,
		NcLoadingIcon,
		Play,
		Refresh,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			showDialog: false,
			dialogTitle: 'File Warmup - Extract & Index Files',
			config: {
				maxFiles: 100,
				batchSize: 50,
				skipIndexed: true,
				selectedFileTypes: [],
			},
			fileTypeOptions: [
				{ id: 'application/pdf', label: 'PDF' },
				{ id: 'text/plain', label: 'Text' },
				{ id: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', label: 'Word (DOCX)' },
				{ id: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', label: 'Excel (XLSX)' },
				{ id: 'text/markdown', label: 'Markdown' },
				{ id: 'application/json', label: 'JSON' },
				{ id: 'text/xml', label: 'XML' },
				{ id: 'text/csv', label: 'CSV' },
			],
			stats: null,
			isProcessing: false,
			progress: 0,
			processedFiles: 0,
			totalFiles: 0,
			failedFiles: 0,
			results: null,
		}
	},
	watch: {
		open: {
			immediate: true,
			handler(newVal) {
				console.info('🔍 FileWarmupModal: open prop changed to:', newVal)
				this.showDialog = newVal
				console.info('🔍 FileWarmupModal: showDialog set to:', this.showDialog)
				if (newVal) {
					console.info('📊 FileWarmupModal: Loading stats...')
					this.loadStats()
				}
			},
		},
	},
	methods: {
		/**
		 * Load file processing statistics
		 */
		async loadStats() {
			try {
				console.info('📊 FileWarmupModal: Fetching extraction stats...')
				// Get extraction stats
				const extractionResponse = await axios.get(generateUrl('/apps/openregister/api/files/extraction/stats'))
				console.info('✅ FileWarmupModal: Extraction stats loaded:', extractionResponse.data)

				console.info('📊 FileWarmupModal: Fetching SOLR index stats...')
				// Get index stats
				const indexResponse = await axios.get(generateUrl('/apps/openregister/api/solr/files/stats'))
				console.info('✅ FileWarmupModal: SOLR stats loaded:', indexResponse.data)

				this.stats = {
					total_extracted: extractionResponse.data.stats?.completed || 0,
					total_indexed: indexResponse.data.unique_files || 0,
					pending_extraction: extractionResponse.data.stats?.pending || 0,
					pending_indexing: extractionResponse.data.stats?.completed - indexResponse.data.unique_files || 0,
				}
				console.info('✅ FileWarmupModal: Combined stats:', this.stats)
			} catch (error) {
				console.error('❌ FileWarmupModal: Failed to load stats:', error)
			}
		},

		/**
		 * Refresh statistics
		 */
		async refreshStats() {
			await this.loadStats()
			showSuccess('Statistics refreshed')
		},

		/**
		 * Start file warmup process
		 */
		async startWarmup() {
			this.isProcessing = true
			this.progress = 0
			this.processedFiles = 0
			this.totalFiles = 0
			this.failedFiles = 0
			this.results = null

			try {
				// Prepare request payload
				const payload = {
					max_files: this.config.maxFiles,
					batch_size: this.config.batchSize,
					skip_indexed: this.config.skipIndexed,
					file_types: this.config.selectedFileTypes.map(ft => ft.id),
				}

				// Call warmup API
				const response = await axios.post(
					generateUrl('/apps/openregister/api/solr/warmup/files'),
					payload,
				)

				if (response.data.success) {
					this.results = response.data
					this.progress = 100
					this.processedFiles = response.data.files_processed
					this.totalFiles = response.data.files_processed
					this.failedFiles = response.data.failed

					if (response.data.failed === 0) {
						showSuccess(`Successfully processed ${response.data.indexed} files!`)
					} else {
						showError(`Processed ${response.data.indexed} files, ${response.data.failed} failed`)
					}

					// Refresh stats
					await this.loadStats()
				} else {
					showError(response.data.message || 'Warmup failed')
				}

			} catch (error) {
				console.error('Warmup failed:', error)
				showError('Failed to start file warmup: ' + (error.response?.data?.message || error.message))
			} finally {
				this.isProcessing = false
			}
		},

		/**
		 * Handle dialog open/close state update
		 * @param {boolean} isOpen - The new state of the dialog
		 */
		handleDialogUpdate(isOpen) {
			console.info('🔄 FileWarmupModal: Dialog update event, isOpen:', isOpen)
			if (!isOpen) {
				this.handleClose()
			}
		},

		/**
		 * Handle dialog close
		 */
		handleClose() {
			console.info('❌ FileWarmupModal: Closing dialog...')
			this.showDialog = false
			this.$emit('update:open', false)
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.file-warmup-modal {
	padding: 20px;

	.info-box {
		margin-bottom: 20px;
	}

	.warmup-config {
		margin-bottom: 30px;

		h3 {
			font-size: 18px;
			font-weight: 600;
			margin-bottom: 15px;
			color: var(--color-main-text);
		}

		.form-group {
			margin-bottom: 20px;

			label {
				display: block;
				font-weight: 500;
				margin-bottom: 8px;
				color: var(--color-main-text);
			}

			.input-field {
				width: 100%;
				max-width: 300px;
				padding: 8px 12px;
				border: 1px solid var(--color-border);
				border-radius: var(--border-radius);
				font-size: 14px;

				&:focus {
					outline: none;
					border-color: var(--color-primary-element);
				}
			}

			.hint {
				margin-top: 5px;
				font-size: 13px;
				color: var(--color-text-maxcontrast);
			}
		}
	}

	.stats-section {
		margin-bottom: 30px;

		h3 {
			font-size: 18px;
			font-weight: 600;
			margin-bottom: 15px;
			color: var(--color-main-text);
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
			gap: 15px;

			.stat-card {
				background: var(--color-background-hover);
				padding: 15px;
				border-radius: var(--border-radius);
				text-align: center;
				border: 1px solid var(--color-border);

				.stat-value {
					font-size: 28px;
					font-weight: 700;
					color: var(--color-primary-element);
					margin-bottom: 5px;
				}

				.stat-label {
					font-size: 13px;
					color: var(--color-text-maxcontrast);
				}
			}
		}
	}

	.progress-section {
		margin-bottom: 30px;

		h3 {
			font-size: 18px;
			font-weight: 600;
			margin-bottom: 15px;
			color: var(--color-main-text);
		}

		.progress-text {
			margin-top: 10px;
			font-size: 14px;
			color: var(--color-main-text);

			.error-count {
				color: var(--color-error);
				font-weight: 500;
			}
		}
	}

	.results-section {
		margin-bottom: 30px;

		h4 {
			font-size: 16px;
			font-weight: 600;
			margin-bottom: 10px;
		}

		ul {
			list-style: none;
			padding: 0;
			margin: 10px 0;

			li {
				padding: 5px 0;
				font-size: 14px;

				&.error-item {
					color: var(--color-error);
					font-weight: 500;
				}
			}
		}

		.error-list {
			margin-top: 15px;
			padding: 10px;
			background: var(--color-background-hover);
			border-radius: var(--border-radius);

			h5 {
				font-size: 14px;
				font-weight: 600;
				margin-bottom: 8px;
				color: var(--color-error);
			}

			ul {
				li {
					font-size: 13px;
					color: var(--color-text-maxcontrast);
				}
			}

			.more-errors {
				margin-top: 8px;
				font-size: 13px;
				font-style: italic;
				color: var(--color-text-maxcontrast);
			}
		}
	}

	.option-label {
		padding: 4px 0;
	}
}
</style>
