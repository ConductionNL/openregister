<!--
  A settings select whose options are drawn by LabeledOption: the label and
  description stacked, with the option's icon in front when `withIcon` is set.

  Every other attribute and listener (v-model, inputId, inputLabel, options,
  disabled, @update:modelValue) goes to the NcSelect unchanged.

  @spec exclude Presentational select of the file settings.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcSelect v-bind="$attrs">
		<template #option="option">
			<LabeledOption
				:label="option.label"
				:description="option.description"
				:icon="withIcon ? option.icon : ''"
				:withIcon="withIcon" />
		</template>
	</NcSelect>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import LabeledOption from '../../../components/shared/LabeledOption.vue'

/**
 * A settings select whose options are drawn by LabeledOption.
 *
 * @spec exclude Presentational select of the file settings.
 */
export default {
	name: 'LabeledSelect',

	components: {
		LabeledOption,
		NcSelect,
	},

	inheritAttrs: false,

	props: {
		/** Draw each option's icon in front of its label. */
		withIcon: { type: Boolean, default: false },
	},
}
</script>
