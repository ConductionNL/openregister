<!--
  The model select of an Ollama provider: one of the known models, or a
  custom model name typed in.

  @spec exclude Presentational form field of the LLM configuration modal.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="form-group">
		<label :for="id">{{ label }}</label>
		<NcSelect
			:id="id"
			v-model="model"
			:inputLabel="inputLabel"
			:options="options"
			label="name"
			:placeholder="t('openregister', 'Select model')"
			:labelOutside="true"
			:taggable="true"
			:createOption="(option) => ({ id: option, name: option })">
			<template #option="{ name, description }">
				<div class="model-option">
					<strong>{{ name }}</strong>
					<small v-if="description">{{ description }}</small>
				</div>
			</template>
		</NcSelect>
		<small>{{
			t('openregister', 'Select a model or type a custom model name')
		}}</small>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import modelValueProxy from './modelValueProxy.js'

/**
 * The model select of an Ollama provider.
 *
 * @spec exclude Presentational form field of the LLM configuration modal.
 */
export default {
	name: 'LlmOllamaModelSelect',
	components: { NcSelect },
	mixins: [modelValueProxy],

	props: {
		id: { type: String, required: true },
		modelValue: { type: [Object, String], default: null },
		options: { type: Array, required: true },
		/** The visible label, already translated. */
		label: { type: String, required: true },
		inputLabel: { type: String, required: true },
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
