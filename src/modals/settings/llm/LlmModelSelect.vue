<!--
  A model select of a hosted LLM provider: the label, one of the provider's
  models, and the model's details (the `detail` slot) under its name.

  @spec exclude Presentational form field of the LLM configuration modal.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="form-group">
		<label :for="labelFor">{{ label }}</label>
		<NcSelect
			v-model="model"
			:inputLabel="inputLabel"
			:options="options"
			label="name"
			:placeholder="placeholder"
			:labelOutside="true">
			<template #option="option">
				<div class="model-option">
					<strong>{{ option.name }}</strong>
					<small><slot name="detail" v-bind="option" /></small>
				</div>
			</template>
		</NcSelect>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import modelValueProxy from './modelValueProxy.js'

/**
 * A model select of a hosted LLM provider.
 *
 * @spec exclude Presentational form field of the LLM configuration modal.
 */
export default {
	name: 'LlmModelSelect',
	components: { NcSelect },
	mixins: [modelValueProxy],

	props: {
		/** The id the label points at. */
		labelFor: { type: String, required: true },
		modelValue: { type: [Object, String], default: null },
		options: { type: Array, required: true },
		/** The visible label, already translated. */
		label: { type: String, required: true },
		inputLabel: { type: String, required: true },
		/** The placeholder, already translated. */
		placeholder: { type: String, required: true },
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/modals.scss' as *;
@include form-group;

.model-option {
	@include stacked-select-option;
}
</style>
