<template>
	<h3>{{ title }}</h3>
	<div class="info-box">
		<InformationOutline :size="20" />
		<p>{{ info }}</p>
	</div>

	<div class="form-group">
		<NcCheckboxRadioSwitch v-model="all" type="switch">
			{{ allLabel }}
		</NcCheckboxRadioSwitch>
	</div>

	<slot />
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

/**
 * What a vectorization run covers: everything, or the selection the slot
 * offers.
 *
 * @spec exclude Presentational form section shared by the vectorization modals.
 */
export default {
	name: 'VectorizationScope',
	components: { NcCheckboxRadioSwitch, InformationOutline },

	props: {
		modelValue: { type: Boolean, required: true },
		title: { type: String, required: true },
		info: { type: String, required: true },
		allLabel: { type: String, required: true },
	},

	emits: ['update:modelValue'],

	computed: {
		all: {
			/**
			 * @spec exclude Two-way binding of the everything switch.
			 * @return {boolean} Whether the run covers everything
			 */
			get() {
				return this.modelValue
			},

			/**
			 * @param {boolean} value Whether the run covers everything
			 * @spec exclude Two-way binding of the everything switch.
			 */
			set(value) {
				this.$emit('update:modelValue', value)
			},
		},
	},
}
</script>

<style scoped lang="scss">
.info-box {
	display: flex;
	gap: 12px;
	border-radius: 8px;
	margin-bottom: 16px;

	p {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}
}

.form-group {
	margin-bottom: 20px;
}
</style>
