<!--
  The relation fields of a schema property: object handling, the register and
  schema it points to, query parameters and the inversed relation with its
  write-back switches. EditSchemaProperty renders it for an object property and
  for the items of an array of objects; the parent owns the data and writes each
  change through the `set` event.

  @spec exclude Presentational part of the EditSchemaProperty dialog.
  @license EUPL-1.2
  @copyright 2026 Conduction B.V.
-->
<template>
	<NcSelect
		:modelValue="handlingConfig.handling"
		inputLabel="Properties Object Configuration Handling"
		v-bind="handlingOptions"
		@update:modelValue="$emit('set', handlingConfig, 'handling', $event)" />
	<NcSelect
		:disabled="registerDisabled"
		:inputLabel="t('openregister', 'Register')"
		:label="t('openregister', 'Register')"
		:placeholder="
			// eslint-disable-next-line @nextcloud/l10n-enforce-ellipsis -- the catalogue key has three dots
			t('openregister', 'Select a register...')
		"
		:options="registers"
		:modelValue="relation.register"
		@update:modelValue="$emit('registerChange', $event)" />
	<NcSelect
		:disabled="loading || !relation.register"
		:inputLabel="t('openregister', 'Schema reference ($ref)')"
		:label="t('openregister', 'Schema reference ($ref)')"
		:placeholder="
			// eslint-disable-next-line @nextcloud/l10n-enforce-ellipsis -- the catalogue key has three dots
			t('openregister', 'Select a schema...')
		"
		:options="schemas"
		:modelValue="relation.$ref"
		@update:modelValue="$emit('schemaChange', $event)" />
	<NcTextField
		:modelValue="queryConfig.queryParams"
		:disabled="loading || !relation.$ref"
		:label="t('openregister', 'Extra Query Parameters')"
		placeholder="key1=value1&key2=value2"
		:helperText="
			t(
				'openregister',
				'Optional: Add query parameters to filter the referenced schema (e.g., status=active&type=public)',
			)
		"
		@update:modelValue="$emit('set', queryConfig, 'queryParams', $event)" />
	<NcSelect
		:disabled="loading || !relation.$ref"
		v-bind="inversedByOptions"
		:inputLabel="t('openregister', 'Property name of inversed relation')"
		:label="t('openregister', 'Property name of inversed relation')"
		:modelValue="relation.inversedBy"
		@update:modelValue="$emit('inversedByChange', $event)" />
	<NcCheckboxRadioSwitch
		v-if="relation.inversedBy"
		:modelValue="relation.writeBack"
		:disabled="loading"
		@update:modelValue="$emit('set', relation, 'writeBack', $event)">
		{{ t('openregister', 'Enable write-back to target objects') }}
	</NcCheckboxRadioSwitch>
	<div
		v-if="relation.inversedBy && !relation.writeBack"
		class="helper-text">
		{{
			t(
				'openregister',
				'When enabled, saving this object will also update the target objects to include a reference back to this object.',
			)
		}}
	</div>
	<NcCheckboxRadioSwitch
		v-if="relation.inversedBy && relation.writeBack"
		:modelValue="relation.removeAfterWriteBack"
		:disabled="loading"
		@update:modelValue="$emit('set', relation, 'removeAfterWriteBack', $event)">
		{{ t('openregister', 'Remove property after write-back') }}
	</NcCheckboxRadioSwitch>
	<div
		v-if="
			relation.inversedBy
			&& relation.writeBack
			&& !relation.removeAfterWriteBack
		"
		class="helper-text">
		{{
			t(
				'openregister',
				'When enabled, this property will be removed from the source object after updating the target objects.',
			)
		}}
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'SchemaRelationFields',
	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The object holding `handling` (the property's objectConfiguration). */
		handlingConfig: { type: Object, required: true },
		/** The select options for the handling field. */
		handlingOptions: { type: Object, required: true },
		/** The object holding register, $ref, inversedBy and the write-back flags. */
		relation: { type: Object, required: true },
		/** The object holding `queryParams`. */
		queryConfig: { type: Object, required: true },
		/** Whether the register select is disabled. */
		registerDisabled: { type: Boolean, default: false },
		loading: { type: Boolean, default: false },
		registers: { type: Array, default: () => [] },
		schemas: { type: Array, default: () => [] },
		/** The select options for the inversed relation field. */
		inversedByOptions: { type: Object, required: true },
	},

	emits: ['set', 'registerChange', 'schemaChange', 'inversedByChange'],

	methods: {
		t,
	},
}
</script>
