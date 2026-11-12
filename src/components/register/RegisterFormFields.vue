<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="formContainer">
		<NcTextField
			:label="t('openregister', 'Title') + ' *'"
			:modelValue="formData.title || ''"
			:error="!!errors.title"
			:helperText="errors.title"
			@update:modelValue="(v) => updateField('title', v)" />
		<NcTextField
			:label="t('openregister', 'Slug') + ' *'"
			:modelValue="formData.slug || ''"
			:error="!!errors.slug"
			:helperText="errors.slug"
			@update:modelValue="(v) => updateField('slug', v)" />
		<NcTextArea
			:label="t('openregister', 'Description')"
			:modelValue="formData.description || ''"
			@update:modelValue="(v) => updateField('description', v)" />
		<NcSelect
			:inputLabel="schemasLabel || t('openregister', 'Schemas')"
			:options="schemaOptions"
			:modelValue="selectedSchemas"
			:multiple="true"
			:keepOpen="true"
			:loading="schemasLoading"
			@update:modelValue="(vals) => updateField('schemas', vals)" />
		<slot />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'

/**
 * The fields of a register form.
 *
 * @spec exclude Presentational form fields; the register contract lives in the register store.
 */
export default {
	name: 'RegisterFormFields',
	components: { NcSelect, NcTextArea, NcTextField },
	inheritAttrs: false,
	props: {
		formData: { type: Object, required: true },
		errors: { type: Object, required: true },
		updateField: { type: Function, required: true },
		schemaOptions: { type: Array, required: true },
		schemasLoading: { type: Boolean, default: false },
		schemasLabel: { type: String, default: '' },
	},

	computed: {
		/**
		 * @spec exclude select-value normalizer for the form, UI plumbing
		 * @return {Array} The option of each schema in the form data
		 */
		selectedSchemas() {
			if (!Array.isArray(this.formData.schemas)) return []
			return this.formData.schemas.map((schema) => {
				const id = typeof schema === 'object' ? schema.id : schema
				const known = this.schemaOptions.find(
					(option) => String(option.id) === String(id),
				)
				return known || { id, label: String(id) }
			})
		},
	},

	methods: { t },
}
</script>
