<template>
	<div class="form-group">
		<NcCheckboxRadioSwitch v-model="checked" type="switch">
			{{ label }}
		</NcCheckboxRadioSwitch>
		<small>{{ hint }}</small>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

/**
 * A vectorization setting that is on or off, with a hint below the switch.
 *
 * @spec exclude Presentational form field shared by the vectorization settings modals.
 */
export default {
	name: 'VectorizationSwitchField',
	components: { NcCheckboxRadioSwitch },

	props: {
		modelValue: { type: Boolean, required: true },
		label: { type: String, required: true },
		hint: { type: String, required: true },
	},

	emits: ['update:modelValue'],

	computed: {
		checked: {
			/**
			 * @spec exclude Two-way binding of the switch.
			 * @return {boolean} Whether the setting is on
			 */
			get() {
				return this.modelValue
			},

			/**
			 * @param {boolean} value Whether the setting is on
			 * @spec exclude Two-way binding of the switch.
			 */
			set(value) {
				this.$emit('update:modelValue', value)
			},
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/modals.scss' as *;

@include form-group;
</style>
