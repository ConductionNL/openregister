<!--
  A text field of the settings sections: its label, the NcTextField and the
  hint under it, with an optional icon in the field's trailing button.

  Every other attribute and listener (v-model, placeholder, type,
  autocomplete, @update:modelValue, @blur) goes to the NcTextField unchanged.

  @spec exclude Presentational text field of the file settings.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="field-group">
		<label :for="id">{{ label }}</label>
		<NcTextField :id="id" v-bind="$attrs">
			<template v-if="$slots.icon" #trailing-button-icon>
				<slot name="icon" />
			</template>
		</NcTextField>
		<p class="field-hint">
			{{ hint }}
		</p>
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'

/**
 * A text field of the settings sections: label, field and hint.
 *
 * @spec exclude Presentational text field of the file settings.
 */
export default {
	name: 'SettingsTextField',

	components: {
		NcTextField,
	},

	inheritAttrs: false,

	props: {
		/** The field id, which the label points at. */
		id: { type: String, required: true },
		/** The label, already translated. */
		label: { type: String, required: true },
		/** The hint under the field, already translated. */
		hint: { type: String, required: true },
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/common.scss' as *;

@include field-group;

.field-group label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-size: 14px;
}

.field-hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
