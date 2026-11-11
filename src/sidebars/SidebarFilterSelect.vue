<template>
	<div class="filterGroup">
		<label :for="id">{{ label }}</label>
		<NcSelect
			:id="id"
			:modelValue="modelValue"
			:options="options"
			:placeholder="placeholder"
			:inputLabel="label"
			v-bind="multiple ? { multiple: true } : {}"
			:clearable="true"
			@update:modelValue="$emit('update:modelValue', $event)">
			<template #option="option">
				<slot name="option" v-bind="option">
					{{ option.label }}
				</slot>
			</template>
		</NcSelect>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'

/**
 * One labelled, clearable select of a filter sidebar. The option slot
 * receives the option and shows its label unless the sidebar fills it.
 *
 * @spec exclude Presentational filter field; the sidebars own the filter state.
 */
export default {
	name: 'SidebarFilterSelect',
	components: {
		NcSelect,
	},

	props: {
		id: {
			type: String,
			required: true,
		},

		label: {
			type: String,
			required: true,
		},

		modelValue: {
			type: [Array, Object, String],
			default: null,
		},

		options: {
			type: Array,
			default: () => [],
		},

		placeholder: {
			type: String,
			default: '',
		},

		multiple: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],
}
</script>

<style scoped lang="scss">
@use '../styles/sidebars.scss' as *;

@include filter-group-rules;
</style>
