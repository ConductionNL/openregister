<!--
  One provider column of the LLM configuration modal: its heading, what the
  provider is for, and the provider select.

  @spec exclude Presentational form field of the LLM configuration modal.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="provider-column">
		<h3>{{ title }}</h3>
		<p class="section-description">
			{{ description }}
		</p>

		<NcSelect
			v-model="model"
			:options="options"
			label="name"
			:inputLabel="inputLabel"
			:placeholder="t('openregister', 'Select provider')">
			<template #option="option">
				<div class="provider-option">
					<strong>{{ option.name }}</strong>
					<small>{{ option.description }}</small>
				</div>
			</template>
		</NcSelect>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import modelValueProxy from './modelValueProxy.js'

/**
 * One provider column of the LLM configuration modal.
 *
 * @spec exclude Presentational form field of the LLM configuration modal.
 */
export default {
	name: 'LlmProviderSelect',
	components: { NcSelect },
	mixins: [modelValueProxy],

	props: {
		modelValue: { type: Object, default: null },
		options: { type: Array, required: true },
		/** The column heading, already translated. */
		title: { type: String, required: true },
		/** What the provider is used for, already translated. */
		description: { type: String, required: true },
		inputLabel: { type: String, required: true },
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/modals.scss' as *;

.provider-column {
	h3 {
		margin: 0 0 8px 0;
		font-size: 16px;
		font-weight: 600;
	}

	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}

.provider-option {
	@include stacked-select-option;
}
</style>
