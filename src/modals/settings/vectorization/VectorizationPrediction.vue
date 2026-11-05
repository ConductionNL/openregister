<template>
	<div class="prediction-box">
		<h4>📊 {{ title }}</h4>
		<div class="prediction-stats">
			<slot />
			<div class="stat">
				<span class="label">{{
					t('openregister', 'Estimated Batches:')
				}}</span>
				<span class="value">{{ batches }}</span>
			</div>
			<div class="stat">
				<span class="label">{{
					t('openregister', 'Estimated Duration:')
				}}</span>
				<span class="value">{{ duration }}</span>
			</div>
			<div class="stat">
				<span class="label">{{ t('openregister', 'Estimated Cost:') }}</span>
				<span class="value">{{ cost }}</span>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * What a vectorization run is expected to take: the counts the slot shows,
 * then the estimated batches, duration and cost.
 *
 * @spec exclude Presentational summary shared by the vectorization modals.
 */
export default {
	name: 'VectorizationPrediction',

	props: {
		batches: { type: Number, required: true },
		duration: { type: String, required: true },
		cost: { type: String, required: true },
		title: { type: String, required: true },
	},
}
</script>
