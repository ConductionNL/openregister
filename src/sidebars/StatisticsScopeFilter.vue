<template>
	<div class="filterSection">
		<h3>{{ t('openregister', 'Filter Statistics') }}</h3>
		<div class="filterGroup">
			<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
			<NcSelect
				v-bind="registerOptions"
				id="registerSelect"
				:modelValue="selectedRegisterValue"
				:loading="registerLoading"
				:disabled="registerLoading"
				:inputLabel="
					comboboxLabels ? undefined : t('openregister', 'Register')
				"
				:aria-label-combobox="
					comboboxLabels
						? t('openregister', 'Select a register')
						: undefined
				"
				:placeholder="t('openregister', 'Select a register')"
				@update:modelValue="handleRegisterChange" />
		</div>
		<div class="filterGroup">
			<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
			<NcSelect
				v-bind="schemaOptions"
				id="schemaSelect"
				:modelValue="selectedSchemaValue"
				:loading="schemaLoading"
				:disabled="!hasRegister || schemaLoading"
				:inputLabel="
					comboboxLabels ? undefined : t('openregister', 'Schema')
				"
				:aria-label-combobox="
					comboboxLabels ? t('openregister', 'Select a schema') : undefined
				"
				:placeholder="t('openregister', 'Select a schema')"
				@update:modelValue="handleSchemaChange" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { scopePickers } from '../services/scopePickers.js'
import { dashboardScope } from './dashboardScope.js'

/**
 * The register and schema pickers above the dashboard's statistics, in the
 * dashboard and registers sidebars.
 *
 * @spec exclude Presentational scope pickers; the stores hold the scope.
 */
export default {
	name: 'StatisticsScopeFilter',
	components: { NcSelect },
	mixins: [scopePickers, dashboardScope],

	props: {
		registerLoading: {
			type: Boolean,
			default: false,
		},

		schemaLoading: {
			type: Boolean,
			default: false,
		},

		// Label the pickers through their combobox (its aria label is the
		// placeholder) instead of through an input label.
		comboboxLabels: {
			type: Boolean,
			default: false,
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../styles/sidebars.scss' as *;

@include filter-section-block;
</style>
