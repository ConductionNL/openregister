<template>
	<div class="config-section">
		<h3>{{ t('openregister', '⚡ Batch Processing') }}</h3>

		<div class="form-group">
			<label for="batch-size">{{ t('openregister', 'Batch Size') }}</label>
			<input
				id="batch-size"
				v-model.number="size"
				type="number"
				min="1"
				max="100"
				step="1"
				class="input-field" />
			<small>{{ hint }}</small>
		</div>

		<VectorizationSwitchField
			v-model="retry"
			:label="t('openregister', 'Auto-retry failed vectorizations')"
			:hint="
				t(
					'openregister',
					'Automatically retry failed vectorization attempts (max 3 retries)',
				)
			" />
	</div>
</template>

<script>
import VectorizationSwitchField from './VectorizationSwitchField.vue'
import { modelProxy } from './modelProxy.js'

/**
 * The batch size and auto-retry settings of automatic vectorization.
 *
 * @spec exclude Presentational settings section shared by the vectorization settings modals.
 */
export default {
	name: 'VectorizationBatchSection',
	components: { VectorizationSwitchField },

	props: {
		batchSize: { type: [Number, String], required: true },
		autoRetry: { type: Boolean, required: true },
		hint: { type: String, required: true },
	},

	emits: ['update:batchSize', 'update:autoRetry'],

	computed: {
		size: modelProxy('batchSize'),
		retry: modelProxy('autoRetry'),
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/modals.scss' as *;

@include config-section;
@include form-group;
</style>
