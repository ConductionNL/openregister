<template>
	<div class="filterGroup">
		<label for="userSelect">{{ t('openregister', 'Users') }}</label>
		<NcSelect
			id="userSelect"
			:modelValue="users"
			:options="userOptions"
			:placeholder="t('openregister', 'All users')"
			:inputLabel="t('openregister', 'Users')"
			:multiple="true"
			:clearable="true"
			@update:modelValue="update('users', $event)">
			<template #option="{ label }">
				{{ label }}
			</template>
		</NcSelect>
	</div>
	<div class="filterGroup">
		<label>{{ t('openregister', 'Date Range') }}</label>
		<NcDateTimePickerNative
			:modelValue="dateFrom"
			:label="t('openregister', 'From date')"
			type="datetime-local"
			@update:modelValue="update('dateFrom', $event)" />
		<NcDateTimePickerNative
			:modelValue="dateTo"
			:label="t('openregister', 'To date')"
			type="datetime-local"
			@update:modelValue="update('dateTo', $event)" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcDateTimePickerNative, NcSelect } from '@nextcloud/vue'

/**
 * The user and date range filters the audit and search trail sidebars share.
 *
 * @spec exclude Presentational filter fields; the sidebars own the filter state.
 */
export default {
	name: 'TrailUserDateFields',
	components: {
		NcDateTimePickerNative,
		NcSelect,
	},

	props: {
		users: {
			type: Array,
			default: () => [],
		},

		userOptions: {
			type: Array,
			default: () => [],
		},

		dateFrom: {
			type: [Date, String],
			default: null,
		},

		dateTo: {
			type: [Date, String],
			default: null,
		},
	},

	emits: ['update:users', 'update:dateFrom', 'update:dateTo', 'change'],

	methods: {
		t,

		/**
		 * Pass a new field value up, then announce the change.
		 *
		 * @param {string} field The prop that changed
		 * @param {Array|Date|string|null} value Its new value
		 * @return {void}
		 */
		update(field, value) {
			this.$emit(`update:${field}`, value)
			this.$emit('change')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/sidebars.scss' as *;

@include filter-group-rules;
</style>
