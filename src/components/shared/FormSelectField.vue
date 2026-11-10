<template>
	<div class="selectField">
		<label :for="$attrs.id">{{ title }}</label>
		<NcSelect v-bind="$attrs">
			<template #option="option">
				<slot name="option" v-bind="option">
					<SelectOption
						:title="option[$attrs.label]"
						:description="option.description" />
				</slot>
			</template>
			<template v-if="$slots.noOptions" #no-options="scope">
				<slot name="noOptions" v-bind="scope || {}" />
			</template>
		</NcSelect>
		<p class="field-hint">
			<slot name="hint">
				{{ hint }}
			</slot>
		</p>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import SelectOption from './SelectOption.vue'

/**
 * A labelled select of a tabbed edit form: the label above it, the select
 * itself (every attribute and listener goes to NcSelect, so a caller passes
 * `:labelOutside="true"` as it did) and the hint below.
 * An option shows its `label`-keyed title and its description unless the
 * `option` slot says otherwise.
 *
 * @spec exclude Presentational form field shared by the edit modals.
 */
export default {
	name: 'FormSelectField',
	components: { NcSelect, SelectOption },
	inheritAttrs: false,

	props: {
		title: { type: String, default: '' },
		hint: { type: String, default: '' },
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/modals.scss' as *;
@include tab-container-column;
</style>
