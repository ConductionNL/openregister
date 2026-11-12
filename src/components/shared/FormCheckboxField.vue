<template>
	<div class="checkboxField">
		<NcCheckboxRadioSwitch v-bind="$attrs">
			<slot />
		</NcCheckboxRadioSwitch>
		<p class="field-hint">
			{{ hint }}
		</p>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

/**
 * A switch of a tabbed edit form with its hint below it. Every attribute
 * and listener goes to NcCheckboxRadioSwitch; the slot is its label.
 *
 * @spec exclude Presentational form field shared by the edit modals.
 */
export default {
	name: 'FormCheckboxField',
	components: { NcCheckboxRadioSwitch },
	inheritAttrs: false,

	props: {
		hint: { type: String, default: '' },
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/modals.scss' as *;
@include tab-container-column;
</style>
