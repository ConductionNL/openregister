<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnFormDialog
		ref="dialog"
		:schema="schema"
		:item="item"
		:dialogTitle="t('openregister', 'Edit Register')"
		@confirm="save"
		@close="$emit('close')">
		<template #form="form">
			<RegisterFormFields
				v-bind="form"
				:schemaOptions="schemaOptions"
				:schemasLoading="schemasLoading"
				:schemasLabel="schemasLabel">
				<RegisterLanguagesEditor
					v-if="withLanguages"
					:value="form.formData.languages || []"
					:label="t('openregister', 'Languages')"
					:helperText="
						t(
							'openregister',
							'Ordered BCP 47 language tags. The first language is the register default and drives Accept-Language fallback for translatable properties.',
						)
					"
					@input="(vals) => form.updateField('languages', vals)" />
			</RegisterFormFields>
		</template>
	</CnFormDialog>
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import RegisterLanguagesEditor from '../../components/i18n/RegisterLanguagesEditor.vue'
import RegisterFormFields from '../../components/register/RegisterFormFields.vue'
import {
	registerFormSchema,
	registerPayload,
	schemaOptionsOf,
} from '../../services/registerForm.js'
import { registerStore, schemaStore } from '../../store/store.js'

/**
 * Edits one register: loads the schema options when it opens, saves through
 * the register store and reports the outcome on the dialog.
 *
 * @spec exclude Dialog plumbing; the register contract lives in the register store.
 */
export default {
	name: 'EditRegisterFormDialog',
	components: { CnFormDialog, RegisterFormFields, RegisterLanguagesEditor },
	props: {
		item: { type: Object, default: null },
		withLanguages: { type: Boolean, default: false },
		schemasLabel: { type: String, default: '' },
		afterSave: { type: Function, default: () => {} },
	},

	emits: ['close'],
	data() {
		return { schemaOptions: [], schemasLoading: false }
	},

	computed: {
		/**
		 * @spec exclude form definition of the dialog, UI plumbing
		 * @return {object} The form definition
		 */
		schema() {
			if (!this.withLanguages) return registerFormSchema()
			return registerFormSchema({
				languages: {
					type: 'array',
					title: t('openregister', 'Languages'),
					order: 5,
				},
			})
		},
	},

	created() {
		this.loadSchemaOptions()
	},

	methods: {
		t,

		/**
		 * @spec exclude loads the options of the schema select, UI plumbing
		 * @return {Promise<void>}
		 */
		async loadSchemaOptions() {
			this.schemasLoading = true
			try {
				await schemaStore.refreshSchemaList()
				this.schemaOptions = schemaOptionsOf(schemaStore.schemaList)
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to load schemas:', error)
			} finally {
				this.schemasLoading = false
			}
		},

		/**
		 * @spec exclude store delegation and dialog result, UI plumbing
		 * @param {object} formData The edited fields
		 * @return {Promise<void>}
		 */
		async save(formData) {
			try {
				await registerStore.saveRegister(registerPayload(formData))
				this.$refs.dialog.setResult({ success: true })
				await this.afterSave()
			} catch (error) {
				this.$refs.dialog.setResult({ error: error.message })
			}
		},
	},
}
</script>
