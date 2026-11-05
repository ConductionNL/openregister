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
		size: {
			/**
			 * @spec exclude Two-way binding of the batch size.
			 * @return {number|string} The batch size
			 */
			get() {
				return this.batchSize
			},

			/**
			 * @param {number|string} value The batch size
			 * @spec exclude Two-way binding of the batch size.
			 */
			set(value) {
				this.$emit('update:batchSize', value)
			},
		},

		retry: {
			/**
			 * @spec exclude Two-way binding of the auto-retry switch.
			 * @return {boolean} Whether failed runs are retried
			 */
			get() {
				return this.autoRetry
			},

			/**
			 * @param {boolean} value Whether failed runs are retried
			 * @spec exclude Two-way binding of the auto-retry switch.
			 */
			set(value) {
				this.$emit('update:autoRetry', value)
			},
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/modals.scss' as *;

@include config-section;
@include form-group;
</style>
