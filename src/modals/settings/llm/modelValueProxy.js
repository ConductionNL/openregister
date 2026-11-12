/**
 * A writable `model` computed over the `modelValue` prop, so a field
 * component can pass its v-model straight on to the input it wraps.
 *
 * @spec exclude Presentational helper of the LLM configuration fields.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
export default {
	emits: ['update:modelValue'],

	computed: {
		model: {
			get() {
				return this.modelValue
			},
			set(value) {
				this.$emit('update:modelValue', value)
			},
		},
	},
}
