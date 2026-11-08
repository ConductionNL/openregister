<!--
  The URL field of an Ollama provider.

  @spec exclude Presentational form field of the LLM configuration modal.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="form-group">
		<label :for="id">{{ t('openregister', 'Ollama URL') }}</label>
		<input
			:id="id"
			v-model="model"
			type="text"
			placeholder="http://localhost:11434"
			class="input-field" />
		<small>{{ t('openregister', 'URL where Ollama is running') }}</small>
	</div>
</template>

<script>
import modelValueProxy from './modelValueProxy.js'

/**
 * The URL field of an Ollama provider.
 *
 * @spec exclude Presentational form field of the LLM configuration modal.
 */
export default {
	name: 'LlmOllamaUrlField',
	mixins: [modelValueProxy],

	props: {
		id: { type: String, required: true },
		modelValue: { type: String, default: '' },
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/modals.scss' as *;
@include form-group;
</style>
