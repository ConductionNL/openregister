<template>
	<div class="groups-select-container">
		<label class="groups-label">{{ label }}</label>
		<NcSelect
			:modelValue="modelValue"
			inputLabel="Selected Groups"
			:disabled="disabled || loading"
			:options="options"
			label="name"
			trackBy="id"
			:multiple="true"
			:labelOutside="true"
			:filterable="false"
			v-bind="$attrs"
			@update:modelValue="$emit('update:modelValue', $event)"
			@searchChange="$emit('searchChange', $event)">
			<template #option="{ name }">
				<div class="group-option">
					<span class="group-name">{{ name }}</span>
				</div>
			</template>
			<template #no-options>
				<span v-if="loading">{{
					t('openregister', 'Loading groups...')
				}}</span>
				<span v-else>{{ emptyText }}</span>
			</template>
		</NcSelect>
		<p class="field-hint">{{ hint }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'

/**
 * Picks Nextcloud groups; the search itself belongs to the parent. Other
 * attributes go to the select.
 *
 * @spec exclude Presentational picker shared by the edit modals.
 */
export default {
	name: 'GroupSelect',
	components: {
		NcSelect,
	},

	inheritAttrs: false,

	props: {
		modelValue: {
			type: Array,
			required: true,
		},

		options: {
			type: Array,
			required: true,
		},

		label: {
			type: String,
			required: true,
		},

		hint: {
			type: String,
			required: true,
		},

		/** Shown when the search has no groups to offer. */
		emptyText: {
			type: String,
			required: true,
		},

		disabled: {
			type: Boolean,
			default: false,
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['searchChange', 'update:modelValue'],

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include groups-select-container-rules-2;

.group-name {
	font-weight: 500;
}
</style>
