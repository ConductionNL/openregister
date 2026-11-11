<template>
	<div class="filterGroup">
		<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
		<NcSelect
			id="registerSelect"
			v-bind="registerOptions"
			:modelValue="selectedRegisterValue"
			:placeholder="t('openregister', 'All registers')"
			:inputLabel="t('openregister', 'Register')"
			:clearable="true"
			@update:modelValue="$emit('registerChange', $event)" />
	</div>
	<div class="filterGroup">
		<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
		<NcSelect
			id="schemaSelect"
			v-bind="schemaOptions"
			:modelValue="selectedSchemaValue"
			:placeholder="t('openregister', 'All schemas')"
			:inputLabel="t('openregister', 'Schema')"
			:disabled="!hasRegister"
			:clearable="true"
			@update:modelValue="$emit('schemaChange', $event)" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { scopePickers } from '../services/scopePickers.js'

/**
 * The register and schema filters of a list sidebar.
 *
 * @spec exclude Presentational filter fields shared by the list sidebars.
 */
export default {
	name: 'ScopeFilterFields',
	components: { NcSelect },
	mixins: [scopePickers],
	emits: ['registerChange', 'schemaChange'],
	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../styles/sidebars.scss' as *;

@include filter-group-rules;
</style>
