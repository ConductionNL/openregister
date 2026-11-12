<template>
	<div class="viewModeSwitchContainer">
		<NcCheckboxRadioSwitch
			:modelValue="modelValue"
			:title="cardsTitle"
			:buttonVariant="true"
			value="cards"
			name="view_mode_radio"
			type="radio"
			buttonVariantGrouped="horizontal"
			@update:modelValue="$emit('update:modelValue', $event)">
			Cards
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			:modelValue="modelValue"
			:title="tableTitle"
			:buttonVariant="true"
			value="table"
			name="view_mode_radio"
			type="radio"
			buttonVariantGrouped="horizontal"
			@update:modelValue="$emit('update:modelValue', $event)">
			Table
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

/**
 * Switches a list between its cards and its table.
 *
 * @spec exclude Presentational switch shared by the list pages.
 */
export default {
	name: 'ViewModeSwitch',
	components: {
		NcCheckboxRadioSwitch,
	},

	props: {
		modelValue: {
			type: String,
			required: true,
		},

		cardsTitle: {
			type: String,
			required: true,
		},

		tableTitle: {
			type: String,
			required: true,
		},
	},

	emits: ['update:modelValue'],
}
</script>
