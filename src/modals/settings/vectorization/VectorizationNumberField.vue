<template>
	<div class="form-group">
		<label :for="id">{{ label }}</label>
		<input
			:id="id"
			v-model.number="number"
			type="number"
			:min="min"
			:max="max"
			:placeholder="placeholder || hint" />
		<small>{{ hint }}</small>
	</div>
</template>

<script>
import { modelProxy } from './modelProxy.js'
/**
 * A labelled number input of a vectorization run, with a hint below it
 * that is also the placeholder unless one is given.
 *
 * @spec exclude Presentational form field shared by the vectorization modals.
 */
export default {
	name: 'VectorizationNumberField',

	props: {
		modelValue: { type: [Number, String], required: true },
		id: { type: String, required: true },
		label: { type: String, required: true },
		hint: { type: String, required: true },
		placeholder: { type: String, default: '' },
		min: { type: String, required: true },
		max: { type: String, default: undefined },
	},

	emits: ['update:modelValue'],

	computed: {
		number: modelProxy(),
	},
}
</script>

<style scoped lang="scss">
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
		font-size: 14px;

		&:focus {
			outline: none;
			border-color: var(--color-primary-element);
		}
	}

	small {
		display: block;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
