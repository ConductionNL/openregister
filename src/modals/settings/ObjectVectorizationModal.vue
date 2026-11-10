<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'Object Vectorization')"
		size="large"
		@closing="$emit('closing')">
		<div class="vectorization-config-content">
			<p class="description">
				{{
					t(
						'openregister',
						'Configure parameters for object vectorization. This process will generate vector embeddings for all objects matching your view filters.',
					)
				}}
			</p>

			<VectorizationModeChoice
				v-model="executionMode"
				:serialText="
					t('openregister', 'Processes objects sequentially (safest).')
				"
				:parallelText="
					t(
						'openregister',
						'Processes objects in chunks with simulated parallelism.',
					)
				" />

			<VectorizationPrediction
				:title="t('openregister', 'Object Count Prediction')"
				:batches="estimatedBatches"
				:duration="estimatedDuration"
				:cost="estimatedCost">
				<div class="stat">
					<span class="label">{{
						t('openregister', 'Total Objects in Database:')
					}}</span>
					<span class="value">{{
						stats.totalObjects.toLocaleString()
					}}</span>
				</div>
				<div class="stat">
					<span class="label">{{
						t('openregister', 'Objects to Process:')
					}}</span>
					<span class="value">{{
						stats.objectsToProcess.toLocaleString()
					}}</span>
				</div>
			</VectorizationPrediction>

			<h3>{{ t('openregister', 'Processing Limits') }}</h3>
			<VectorizationNumberField
				id="max-objects"
				v-model="maxObjects"
				min="0"
				:label="t('openregister', 'Max Objects (0 = all)')"
				:hint="
					t(
						'openregister',
						'Maximum number of objects to process. Set to 0 to process all objects.',
					)
				" />
			<VectorizationNumberField
				id="batch-size"
				v-model="batchSize"
				max="5000"
				min="1"
				:label="t('openregister', 'Batch Size')"
				:hint="
					t(
						'openregister',
						'Number of objects to process in each batch (1-5000).',
					)
				" />

			<VectorizationScope
				v-model="vectorizeAllViews"
				:title="t('openregister', 'View Selection')"
				:info="
					t(
						'openregister',
						'Choose which views to include in the vectorization process. Leave empty to process all views based on your configuration.',
					)
				"
				:allLabel="t('openregister', 'Vectorize all views')">
				<div
					v-if="!vectorizeAllViews && views.length > 0"
					class="view-selection">
					<label>{{
						t('openregister', 'Select Views to Vectorize:')
					}}</label>
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
			</VectorizationScope>

			<VectorizationProgress
				:total="stats.objectsToProcess"
				:run="$data"
				:unitLabel="t('openregister', 'objects processed')" />
		</div>

		<template #actions>
			<VectorizationActions
				:processing="processing"
				:empty="stats.objectsToProcess === 0"
				@start="startVectorization"
				@cancel="$emit('closing')" />
		</template>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	countObjects,
	readVectorizeSettings,
	readViews,
	vectorizeObjects,
} from './vectorization/objectVectorizationApi.js'
import { vectorizationModalComponents } from './vectorization/vectorizationModalComponents.js'
import { beginRun, idleRun } from './vectorization/vectorizationRun.js'

/**
 * The counts of one batch response, 0 where one is missing.
 *
 * @param {object} response The batch response
 * @return {{processed: number, vectorized: number, failed: number}} The counts
 */
function batchCounts(response) {
	const result = response.data?.data || {}
	return {
		processed: result.processed || 0,
		vectorized: result.vectorized || 0,
		failed: result.failed || 0,
	}
}

export default {
	name: 'ObjectVectorizationModal',

	components: vectorizationModalComponents,

	props: {
		show: { type: Boolean, required: true },
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

			...idleRun(),
		}
	},

	computed: {
		/**
		 * @spec exclude Computed batch-count estimate for display; UI presentation helper.
		 */
		estimatedBatches() {
			if (this.stats.objectsToProcess === 0) return 0
			const objectCount =
				this.maxObjects > 0
					? Math.min(this.maxObjects, this.stats.objectsToProcess)
					: this.stats.objectsToProcess
			return Math.ceil(objectCount / this.batchSize)
		},

		/**
		 * @spec exclude Computed duration estimate for display; UI presentation helper.
		 */
		estimatedDuration() {
			const batches = this.estimatedBatches
			if (batches === 0) return '~0 seconds'

			// Estimate ~2 seconds per object for embedding generation
			const totalSeconds = this.stats.objectsToProcess * 2

			if (totalSeconds < 60) return `~${totalSeconds} seconds`
			if (totalSeconds < 3600)
				return `~${Math.ceil(totalSeconds / 60)} minutes`
			return `~${Math.ceil(totalSeconds / 3600)} hours`
		},

		/**
		 * @spec exclude Computed rough cost estimate for display; UI presentation helper.
		 */
		estimatedCost() {
			// Rough estimate: $0.008 per 1M tokens, ~500 tokens per object
			const tokens = this.stats.objectsToProcess * 500
			const cost = (tokens / 1000000) * 0.008
			return `$${cost.toFixed(4)}`
		},
	},

	watch: {
		/**
		 * @spec exclude Watcher reloading stats when the all-views toggle changes; UI reactivity plumbing.
		 */
		vectorizeAllViews() {
			this.loadStats()
		},

		selectedViews: {
			/**
			 * @spec exclude Watcher reloading stats when the view selection changes; UI reactivity plumbing.
			 */
			handler() {
				this.loadStats()
			},

			deep: true,
		},
	},

	/**
	 * @spec exclude Vue mounted() hook loading config/views/stats on open; modal init plumbing.
	 */
	mounted() {
		this.loadConfiguration()
		this.loadViews()
		this.loadStats()
	},

	methods: {
		/**
		 * @spec openspec/specs/platform-administration-modals/spec.md
		 */
		async loadConfiguration() {
			try {
				const settings = await readVectorizeSettings()
				this.applyConfiguration(settings?.data || {})
			} catch (error) {
				console.error('Failed to load configuration:', error)
			}
		},

		/**
		 * Take the view choice and batch size from the stored settings.
		 *
		 * @param {object} config The stored settings
		 * @spec exclude UI plumbing — fills the form from the stored settings
		 * @return {void}
		 */
		applyConfiguration(config) {
			this.vectorizeAllViews = config.vectorizeAllViews ?? true
			this.selectedViews = config.enabledViews || []
			this.batchSize = config.batchSize || 25
		},

		/**
		 * @spec exclude Loads views to populate the view selector; UI form-loading plumbing.
		 */
		async loadViews() {
			this.views = await readViews()
		},

		/**
		 * @spec exclude Loads object-count stats for the chosen views; UI form-loading plumbing.
		 */
		async loadStats() {
			try {
				const total = await countObjects(this.statsParams())
				this.stats = { totalObjects: total, objectsToProcess: total }
			} catch (error) {
				console.error('Failed to load stats:', error)
				this.stats = { totalObjects: 0, objectsToProcess: 0 }
			}
		},

		/**
		 * The views a run covers, null for all of them.
		 *
		 * @spec exclude UI plumbing — reads the view choice of the form
		 * @return {Array|null} The chosen views
		 */
		chosenViews() {
			return this.vectorizeAllViews ? null : this.selectedViews
		},

		/**
		 * The stats query for the chosen views, empty for all of them.
		 *
		 * @spec exclude UI plumbing — builds the stats query from the form
		 * @return {object} The query parameters
		 */
		statsParams() {
			const views = this.chosenViews()
			if (views !== null && views.length > 0) {
				return { views: JSON.stringify(views) }
			}
			return {}
		},

		/**
		 * @spec exclude Start-button handler driving the vectorization job over the chosen views; platform-admin orchestration plumbing.
		 */
		async startVectorization() {
			beginRun(this)
			try {
				await this.runBatches(this.chosenViews())
				showSuccess(this.completedText())
				this.$emit('completed')
				this.$emit('closing')
			} catch (error) {
				console.error('Failed to vectorize objects:', error)
				showError(this.failureText(error))
			} finally {
				this.processing = false
			}
		},

		/**
		 * What a finished run did, in words.
		 *
		 * @spec exclude UI plumbing — words a finished run
		 * @return {string} The translated message
		 */
		completedText() {
			return this.t(
				'openregister',
				'Vectorization completed: {vectorized} objects vectorized, {failed} failed',
				{ vectorized: this.vectorized, failed: this.failed },
			)
		},

		/**
		 * Why a run failed, in words.
		 *
		 * @param {object} error The request error
		 * @spec exclude UI plumbing — words a failed run
		 * @return {string} The translated message
		 */
		failureText(error) {
			return this.t('openregister', 'Vectorization failed: {error}', {
				error: error.response?.data?.error || error.message,
			})
		},

		/**
		 * Send batches until the objects are done, one comes back empty, or processing stops.
		 *
		 * @param {Array|null} views The views to vectorize, null for all
		 * @spec exclude UI plumbing — drives the batch loop of the modal
		 * @return {Promise<void>}
		 */
		async runBatches(views) {
			let remaining =
				this.maxObjects > 0 ? this.maxObjects : this.stats.objectsToProcess
			while (remaining > 0 && this.processing) {
				const processed = await this.vectorizeBatch(
					views,
					Math.min(this.batchSize, remaining),
				)
				remaining -= processed
				if (processed === 0) {
					break
				}
			}
		},

		/**
		 * Vectorize one batch and add its counts to the totals.
		 *
		 * @param {Array|null} views The views to vectorize, null for all
		 * @param {number} batchSize The number of objects in this batch
		 * @spec exclude UI plumbing — one batch request of the modal
		 * @return {Promise<number>} The number of objects the batch processed
		 */
		async vectorizeBatch(views, batchSize) {
			const response = await vectorizeObjects({ views, batchSize })
			const counts = batchCounts(response)
			this.processed += counts.processed
			this.vectorized += counts.vectorized
			this.failed += counts.failed
			return counts.processed
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

	:deep(h3) {
		margin-top: 24px;
		margin-bottom: 12px;
		font-weight: 600;
	}

	:deep(h4) {
		margin: 0 0 12px 0;
		font-weight: 600;
	}

	:deep(.execution-modes) {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin-bottom: 12px;
	}

	:deep(.mode-descriptions) {
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

	:deep(.prediction-box) {
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

	:deep(.form-group > small) {
		margin-top: 4px;
	}

	:deep(.form-group > input) {
		border: 1px solid var(--color-border);
		border-radius: 4px;
	}

	:deep(.info-box) {
		align-items: center;
		padding: 12px;
		background: var(--color-background-dark);
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

	:deep(.progress-section) {
		background: var(--color-background-dark);

		.progress-stats {
			gap: 16px;
		}
	}
}
</style>
