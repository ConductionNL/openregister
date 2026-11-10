<!--
  The API key field of an LLM provider: a password input and a hint that
  links to where the key is issued.

  @spec exclude Presentational form field of the LLM configuration modal.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="form-group">
		<label :for="id">{{ t('openregister', 'API Key') }}</label>
		<input
			:id="id"
			v-model="model"
			type="password"
			:placeholder="placeholder"
			class="input-field" />
		<small>{{ hint }}
			<a :href="href" target="_blank">{{ linkText }}</a></small>
	</div>
</template>

<script>
import modelValueProxy from './modelValueProxy.js'

/**
 * The API key field of an LLM provider.
 *
 * @spec exclude Presentational form field of the LLM configuration modal.
 */
export default {
	name: 'LlmApiKeyField',
	mixins: [modelValueProxy],

	props: {
		id: { type: String, required: true },
		modelValue: { type: String, default: '' },
		placeholder: { type: String, default: '' },
		/** The sentence before the link, already translated. */
		hint: { type: String, required: true },
		href: { type: String, required: true },
		linkText: { type: String, required: true },
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/modals.scss' as *;
@include form-group;

.form-group small a {
	color: var(--color-primary-element);
	text-decoration: none;

	&:hover {
		text-decoration: underline;
	}
}
</style>
