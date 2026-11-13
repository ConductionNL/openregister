<script>
/**
 * "Start from a template" on the create dialog: lists the templates the user
 * may start a record from in this register and schema, and hands the chosen
 * template's values, as they still fit the schema, to the dialog. Values that
 * no longer fit are dropped and named.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import { applyTemplate, fetchRecordTemplates } from '../../services/recordTemplates.js'

export default {
	name: 'RecordTemplatePicker',

	components: {
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The register the record is created in. */
		register: {
			type: [String, Number],
			default: null,
		},

		/** The schema the record is created in, with its properties. */
		schema: {
			type: Object,
			default: null,
		},
	},

	emits: ['apply'],

	data() {
		return {
			templates: [],
			chosen: null,
			dropped: [],
		}
	},

	computed: {
		/**
		 * The register and schema the templates are listed for.
		 *
		 * @return {string}
		 */
		scopeKey() {
			return `${this.register ?? ''}:${this.schema?.id ?? ''}`
		},

		/**
		 * The dropped fields as one readable list.
		 *
		 * @return {string}
		 */
		droppedFields() {
			return this.dropped.map((entry) => entry.field).join(', ')
		},
	},

	watch: {
		/**
		 * A different register or schema has other templates.
		 *
		 * @spec exclude UI plumbing — refetch on scope change
		 */
		scopeKey: {
			handler() {
				this.load()
			},

			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Fetch the templates for the current register and schema.
		 *
		 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
		 * @return {Promise<void>}
		 */
		async load() {
			this.chosen = null
			this.dropped = []
			this.templates = await fetchRecordTemplates({ register: this.register, schema: this.schema?.id })
		},

		/**
		 * Apply a chosen template: emit the values that still fit, keep the dropped ones to say which.
		 *
		 * @param {object|null} template The chosen template.
		 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
		 * @return {void}
		 */
		choose(template) {
			this.chosen = template
			if (!template) {
				this.dropped = []
				return
			}
			const { values, dropped } = applyTemplate(template, this.schema)
			this.dropped = dropped
			this.$emit('apply', { values, dropped })
		},
	},
}
</script>

<template>
	<div v-if="templates.length > 0" class="record-template-picker">
		<NcSelect
			:modelValue="chosen"
			:inputLabel="t('openregister', 'Start from a template')"
			:options="templates"
			label="name"
			trackBy="id"
			:clearable="true"
			@update:modelValue="choose" />
		<NcNoteCard v-if="dropped.length > 0" type="warning">
			{{ t('openregister', 'Not taken from the template, because the field no longer fits: {fields}', { fields: droppedFields }) }}
		</NcNoteCard>
	</div>
</template>

<style scoped>
.record-template-picker {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 3);
}
</style>
