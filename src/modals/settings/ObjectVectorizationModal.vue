<template>
	<NcDialog v-if="show"
		:name="t('openregister', 'Object Vectorization')"
		size="large"
		@closing="$emit('closing')">
		<div class="vectorization-config-content">
			<p class="description">
				{{ t('openregister', 'Configure parameters for object vectorization. This process will generate vector embeddings for all objects matching your view filters.') }}
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
				<p><strong>{{ t('openregister', 'Serial:') }}</strong> {{ t('openregister', 'Processes objects sequentially (safest).') }}</p>
				<p><strong>{{ t('openregister', 'Parallel:') }}</strong> {{ t('openregister', 'Processes objects in chunks with simulated parallelism.') }}</p>
			</div>

			<!-- Object Count Prediction -->
			<div class="prediction-box">
				<h4>📊 {{ t('openregister', 'Object Count Prediction') }}</h4>
				<div class="prediction-stats">
					<div class="stat">
						<span class="label">{{ t('openregister', 'Total Objects in Database:') }}</span>
						<span class="value">{{ stats.totalObjects.toLocaleString() }}</span>
					</div>
					<div class="stat">
						<span class="label">{{ t('openregister', 'Objects to Process:') }}</span>
						<span class="value">{{ stats.objectsToProcess.toLocaleString() }}</span>
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
				<label for="max-objects">{{ t('openregister', 'Max Objects (0 = all)') }}</label>
				<input
					id="max-objects"
					v-model.number="maxObjects"
					type="number"
					min="0"
					:placeholder="t('openregister', 'Maximum number of objects to process. Set to 0 to process all objects.')">
				<small>{{ t('openregister', 'Maximum number of objects to process. Set to 0 to process all objects.') }}</small>
			</div>

			<div class="form-group">
				<label for="batch-size">{{ t('openregister', 'Batch Size') }}</label>
				<input
					id="batch-size"
					v-model.number="batchSize"
					type="number"
					min="1"
					max="5000"
					:placeholder="t('openregister', 'Number of objects to process in each batch (1-5000).')">
				<small>{{ t('openregister', 'Number of objects to process in each batch (1-5000).') }}</small>
			</div>

			<!-- View Selection -->
			<h3>{{ t('openregister', 'View Selection') }}</h3>
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>{{ t('openregister', 'Choose which views to include in the vectorization process. Leave empty to process all views based on your configuration.') }}</p>
			</div>

			<div class="form-group">
				<NcCheckboxRadioSwitch
					v-model="vectorizeAllViews"
					type="switch">
					{{ t('openregister', 'Vectorize all views') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="!vectorizeAllViews && views.length > 0" class="view-selection">
				<label>{{ t('openregister', 'Select Views to Vectorize:') }}</label>
				<div class="view-list">
					<NcCheckboxRadioSwitch
						v-for="view in views"
						:key="view.id"
						v-model="selectedViews"
						:value="view.id"
						type="checkbox">
						{{ view.name }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Progress (shown during processing) -->
			<div v-if="processing" class="progress-section">
				<NcProgressBar :value="progress" :error="failed > 0">
					{{ processed }} / {{ stats.objectsToProcess }} {{ t('openregister', 'objects processed') }}
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
				:disabled="processing || (stats.objectsToProcess === 0)"
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
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'ObjectVectorizationModal',

	components: {
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcProgressBar,
		NcLoadingIcon,
		InformationOutline,
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
			executionMode: 'serial',
			maxObjects: 0,
			batchSize: 25,
			vectorizeAllViews: true,
			selectedViews: [],
			views: [],
			stats: {
				totalObjects: 0,
				objectsToProcess: 0,
			},
			processing: false,
			processed: 0,
			vectorized: 0,
			failed: 0,
		}
	},

	computed: {
		estimatedBatches() {
			if (this.stats.objectsToProcess === 0) return 0
			const objectCount = this.maxObjects > 0 ? Math.min(this.maxObjects, this.stats.objectsToProcess) : this.stats.objectsToProcess
			return Math.ceil(objectCount / this.batchSize)
		},

		estimatedDuration() {
			const batches = this.estimatedBatches
			if (batches === 0) return '~0 seconds'

			// Estimate ~2 seconds per object for embedding generation
			const totalSeconds = this.stats.objectsToProcess * 2
			
			if (totalSeconds < 60) return `~${totalSeconds} seconds`
			if (totalSeconds < 3600) return `~${Math.ceil(totalSeconds / 60)} minutes`
			return `~${Math.ceil(totalSeconds / 3600)} hours`
		},

		estimatedCost() {
			// Rough estimate: $0.008 per 1M tokens, ~500 tokens per object
			const tokens = this.stats.objectsToProcess * 500
			const cost = (tokens / 1000000) * 0.008
			return `$${cost.toFixed(4)}`
		},

		progress() {
			if (this.stats.objectsToProcess === 0) return 0
			return Math.round((this.processed / this.stats.objectsToProcess) * 100)
		},
	},

	watch: {
		// Reload stats when view selection changes
		vectorizeAllViews() {
			this.loadStats()
		},
		selectedViews: {
			handler() {
				this.loadStats()
			},
			deep: true,
		},
	},

	mounted() {
		this.loadConfiguration()
		this.loadViews()
		this.loadStats()
	},

	methods: {
		async loadConfiguration() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/objects/vectorize'))
				const config = response.data?.data || {}
				
				this.vectorizeAllViews = config.vectorizeAllViews ?? true
				this.selectedViews = config.enabledViews || []
				this.batchSize = config.batchSize || 25
			} catch (error) {
				console.error('Failed to load configuration:', error)
			}
		},

		async loadViews() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/views'))
				this.views = response.data.results || []
			} catch (error) {
				console.error('Failed to load views:', error)
			}
		},

		async loadStats() {
			try {
				// Determine which views to use for stats
				const viewsToCount = this.vectorizeAllViews ? null : this.selectedViews

				// Build query params
				const params = {}
				if (viewsToCount !== null && viewsToCount.length > 0) {
					params.views = JSON.stringify(viewsToCount)
				}

				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/vectorize/stats'),
					{ params }
				)
				
				this.stats = {
					totalObjects: response.data.total_objects || 0,
					objectsToProcess: response.data.total_objects || 0,
				}
			} catch (error) {
				console.error('Failed to load stats:', error)
				this.stats = {
					totalObjects: 0,
					objectsToProcess: 0,
				}
			}
		},

		async startVectorization() {
			this.processing = true
			this.processed = 0
			this.vectorized = 0
			this.failed = 0

			try {
				const viewsToProcess = this.vectorizeAllViews ? null : this.selectedViews
				const objectCount = this.maxObjects > 0 ? this.maxObjects : this.stats.objectsToProcess
				
				// Process in batches until complete
				let remaining = objectCount
				while (remaining > 0 && this.processing) {
					const currentBatchSize = Math.min(this.batchSize, remaining)
					
					const response = await axios.post(
						generateUrl('/apps/openregister/api/objects/vectorize/batch'),
						{
							views: viewsToProcess,
							batchSize: currentBatchSize,
						}
					)

					const result = response.data?.data || {}
					this.processed += result.processed || 0
					this.vectorized += result.vectorized || 0
					this.failed += result.failed || 0
					remaining -= result.processed || 0

					// Stop if no more objects were processed
					if ((result.processed || 0) === 0) {
						break
					}
				}

				showSuccess(this.t('openregister', 'Vectorization completed: {vectorized} objects vectorized, {failed} failed', {
					vectorized: this.vectorized,
					failed: this.failed,
				}))

				this.$emit('completed')
				this.$emit('closing')
			} catch (error) {
				console.error('Failed to vectorize objects:', error)
				showError(this.t('openregister', 'Vectorization failed: {error}', {
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

	.description {
		margin-bottom: 20px;
		color: var(--color-text-maxcontrast);
	}

	h3 {
		margin-top: 24px;
		margin-bottom: 12px;
		font-weight: 600;
	}

	h4 {
		margin: 0 0 12px 0;
		font-weight: 600;
	}

	.execution-modes {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin-bottom: 12px;
	}

	.mode-descriptions {
		background: var(--color-background-dark);
		padding: 12px;
		border-radius: 8px;
		margin-bottom: 20px;

		p {
			margin: 4px 0;
			font-size: 13px;
			color: var(--color-text-maxcontrast);
		}
	}

	.prediction-box {
		background: var(--color-primary-element-light);
		padding: 16px;
		border-radius: 8px;
		margin: 20px 0;

		.prediction-stats {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 12px;
			margin-top: 12px;

			.stat {
				display: flex;
				flex-direction: column;
				gap: 4px;

				.label {
					font-size: 12px;
					color: var(--color-text-maxcontrast);
				}

				.value {
					font-size: 18px;
					font-weight: 600;
					color: var(--color-main-text);
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
			border: 1px solid var(--color-border);
			border-radius: 4px;
			font-size: 14px;

			&:focus {
				outline: none;
				border-color: var(--color-primary-element);
			}
		}

		small {
			display: block;
			margin-top: 4px;
			font-size: 12px;
			color: var(--color-text-maxcontrast);
		}
	}

	.info-box {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 12px;
		background: var(--color-background-dark);
		border-radius: 8px;
		margin-bottom: 16px;

		p {
			margin: 0;
			font-size: 13px;
			color: var(--color-text-maxcontrast);
		}
	}

	.view-selection {
		margin-top: 16px;

		label {
			display: block;
			margin-bottom: 8px;
			font-weight: 500;
		}

		.view-list {
			display: flex;
			flex-direction: column;
			gap: 8px;
			padding: 12px;
			background: var(--color-background-dark);
			border-radius: 8px;
		}
	}

	.progress-section {
		margin-top: 24px;
		padding: 16px;
		background: var(--color-background-dark);
		border-radius: 8px;

		.progress-stats {
			display: flex;
			gap: 16px;
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
}
</style>

