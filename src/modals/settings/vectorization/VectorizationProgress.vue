<template>
	<div v-if="run.processing" class="progress-section">
		<NcProgressBar :value="progress" :error="run.failed > 0">
			{{ run.processed }} / {{ total }}
			{{ unitLabel }}
		</NcProgressBar>
		<div class="progress-stats">
			<span class="success"
				>✓ {{ run.vectorized }} {{ t('openregister', 'vectorized') }}</span
			>
			<span v-if="run.failed > 0" class="error"
				>✗ {{ run.failed }} {{ t('openregister', 'failed') }}</span
			>
		</div>
	</div>
</template>

<script>
import { NcProgressBar } from '@nextcloud/vue'

/**
 * The progress of a running vectorization: processed of total, and how
 * many were vectorized or failed.
 *
 * @spec exclude Presentational progress section shared by the vectorization modals.
 */
export default {
	name: 'VectorizationProgress',
	components: { NcProgressBar },

	props: {
		run: { type: Object, required: true },
		total: { type: Number, required: true },
		unitLabel: { type: String, required: true },
	},

	computed: {
		/**
		 * @spec exclude Computed progress percentage for the progress bar.
		 * @return {number} The percentage done
		 */
		progress() {
			if (this.total === 0) return 0
			return Math.round((this.run.processed / this.total) * 100)
		},
	},
}
</script>

<style scoped lang="scss">
.progress-section {
	margin-top: 24px;
	padding: 16px;
	border-radius: 8px;
}

.progress-stats {
	display: flex;
	margin-top: 12px;
	font-size: 14px;

	.success {
		color: var(--color-success);
	}

	.error {
		color: var(--color-error);
	}
}
</style>
