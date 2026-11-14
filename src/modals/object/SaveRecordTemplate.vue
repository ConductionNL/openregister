<template>
	<NcDialog
		v-if="navigationStore.dialog === 'saveRecordTemplate'"
		:name="t('openregister', 'Save as template')"
		size="normal"
		noClose>
		<div v-if="!saved" class="recordTemplateForm">
			<NcTextField
				v-model="name"
				:label="t('openregister', 'Template name')"
				:disabled="saving"
				data-testid="record-template-name" />
			<NcTextField
				v-model="description"
				:label="t('openregister', 'Description')"
				:disabled="saving" />

			<fieldset class="recordTemplateForm__fields">
				<legend>{{ t('openregister', 'Fields to keep') }}</legend>
				<p v-if="!choices.length" class="recordTemplateForm__hint">
					{{ t('openregister', 'This record has no values to keep.') }}
				</p>
				<NcCheckboxRadioSwitch
					v-for="choice in choices"
					:key="choice.field"
					v-model="choice.keep"
					:disabled="saving"
					:data-testid="'record-template-field-' + choice.field">
					{{ choice.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="recordTemplateForm__fields">
				<legend>{{ t('openregister', 'Who can use it') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="visibility"
					value="private"
					name="recordTemplateVisibility"
					type="radio"
					:disabled="saving">
					{{ t('openregister', 'Only me') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="visibility"
					value="group"
					name="recordTemplateVisibility"
					type="radio"
					:disabled="saving">
					{{ t('openregister', 'Members of a group') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-if="visibility === 'group'"
					v-model="group"
					:label="t('openregister', 'Group')"
					:disabled="saving" />
				<NcCheckboxRadioSwitch
					v-model="visibility"
					value="public"
					name="recordTemplateVisibility"
					type="radio"
					:disabled="saving">
					{{ t('openregister', 'Everyone') }}
				</NcCheckboxRadioSwitch>
				<p class="recordTemplateForm__hint">
					{{ t('openregister', 'Only people who may create these records can use the template.') }}
				</p>
			</fieldset>
		</div>

		<NcNoteCard v-if="saved" type="success">
			<p>{{ t('openregister', 'Template saved') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<CloseCancelButton :label="saved ? t('openregister', 'Close') : t('openregister', 'Cancel')" @click="closeDialog" />
			<NcButton
				v-if="!saved"
				:disabled="!canSave"
				variant="primary"
				data-testid="record-template-save"
				@click="save()">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('openregister', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import {
	saveRecordTemplate,
	templateFieldChoices,
	templateSharing,
	templateValues,
} from '../../services/recordTemplates.js'
import { navigationStore, objectStore, schemaStore } from '../../store/store.js'

/**
 * The register or schema id a record names in its metadata, or on itself.
 *
 * @param {object} record The record.
 * @param {string} key `register` or `schema`.
 * @return {string|number|null}
 */
function recordScope(record, key) {
	const value = record?.['@self']?.[key] ?? record?.[key] ?? null
	return value && typeof value === 'object' ? (value.id ?? null) : value
}

/**
 * "Save as template" on a record (records-saved-templates, task 2.1, design
 * D-3): lists the record's fields with its identity, dates, relations and
 * files unticked, takes a name, a description and who may use it, and stores
 * the ticked values through `POST /api/record-templates`. The server checks
 * that the user may create in the schema.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-caseworker-saves-a-melding-as-a-template
 */
export default {
	name: 'SaveRecordTemplate',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		ContentSaveOutline,
		CloseCancelButton,
	},

	data() {
		return {
			name: '',
			description: '',
			choices: [],
			visibility: 'private',
			group: '',
			saving: false,
			saved: false,
			error: '',
			closeTimeout: null,
		}
	},

	computed: {
		// The template and the watch path read the store through `this`.
		navigationStore: () => navigationStore,

		/**
		 * Whether the form can be sent: a name, at least one field, and a
		 * group when the template is shared with one.
		 *
		 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
		 * @return {boolean}
		 */
		canSave() {
			if (this.saving || this.name.trim() === '') return false
			if (!this.choices.some((choice) => choice.keep)) return false
			return this.visibility !== 'group' || this.group.trim() !== ''
		},
	},

	watch: {
		'navigationStore.dialog': {
			immediate: true,
			/**
			 * @param {string|false} dialog The open dialog.
			 * @spec exclude watcher resetting the form when the dialog opens
			 */
			handler(dialog) {
				if (dialog === 'saveRecordTemplate') {
					this.reset()
				}
			},
		},
	},

	methods: {
		t: translate,

		/**
		 * The schema of the record, with its properties, from the schema store.
		 *
		 * @return {object|null}
		 */
		recordSchema() {
			const wanted = String(recordScope(objectStore.objectItem, 'schema') ?? '')
			const matches = (schema) => schema && (String(schema.id) === wanted || schema.uuid === wanted || schema.slug === wanted)
			if (matches(schemaStore.schemaItem)) return schemaStore.schemaItem
			return (schemaStore.schemaList || []).find(matches) || null
		},

		/**
		 * Start the form over for the selected record.
		 *
		 * @spec exclude form reset
		 */
		reset() {
			clearTimeout(this.closeTimeout)
			this.name = ''
			this.description = ''
			this.visibility = 'private'
			this.group = ''
			this.saving = false
			this.saved = false
			this.error = ''
			this.choices = templateFieldChoices(objectStore.objectItem || {}, this.recordSchema())
		},

		/**
		 * Store the template with the ticked fields.
		 *
		 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-caseworker-saves-a-melding-as-a-template
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.canSave) return
			const record = objectStore.objectItem || {}
			this.saving = true
			this.error = ''
			try {
				await saveRecordTemplate({
					name: this.name.trim(),
					description: this.description.trim(),
					register: String(recordScope(record, 'register') ?? ''),
					schema: String(recordScope(record, 'schema') ?? ''),
					values: templateValues(record, this.choices.filter((choice) => choice.keep).map((choice) => choice.field)),
					...templateSharing(this.visibility, this.group),
				})
				this.saved = true
				this.closeTimeout = setTimeout(this.closeDialog, 2000)
			} catch (e) {
				this.error = e?.message || translate('openregister', 'The template could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * @spec exclude dialog close
		 */
		closeDialog() {
			clearTimeout(this.closeTimeout)
			navigationStore.setDialog(false)
		},
	},
}
</script>

<style scoped>
.recordTemplateForm {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.recordTemplateForm__fields {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.recordTemplateForm__fields legend {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline);
}

.recordTemplateForm__hint {
	color: var(--color-text-maxcontrast);
}
</style>
