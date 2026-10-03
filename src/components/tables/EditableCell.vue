<script>
import { CnCellRenderer } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect, NcTextField } from '@nextcloud/vue'
import { enumOptions, isInlineEditable } from '../../services/propertyEditor.js'

/**
 * A typed draft: a number field sends a number, not the text typed into it.
 *
 * @param {object} property The schema property.
 * @param {*} draft The edited value.
 * @return {*}
 */
function toPropertyType(property, draft) {
	const type = property?.type
	if ((type === 'integer' || type === 'number') && typeof draft === 'string') {
		if (draft.trim() === '') return null
		const parsed = type === 'integer' ? parseInt(draft, 10) : parseFloat(draft)
		return Number.isNaN(parsed) ? draft : parsed
	}
	return draft
}

/**
 * A records-list cell that a reader with update rights edits in place.
 *
 * Double click edits, Enter saves, Escape cancels. The save is the same PATCH
 * the record form sends, for this one field. A refused save shows the server's
 * message under the cell and keeps the old value. Without update rights (the
 * row's `@self.can.update`), a double click opens the record as before.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
 */
export default {
	name: 'EditableCell',
	components: { CnCellRenderer, NcSelect, NcTextField },

	props: {
		row: { type: Object, required: true },
		field: { type: String, required: true },
		value: { type: [Boolean, String, Number, Object, Array], default: null },
		property: { type: Object, default: () => ({}) },
	},

	emits: ['open', 'saved'],

	data() {
		return {
			editing: false,
			draft: null,
			shown: this.value,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
		 * @return {boolean}
		 */
		canEdit() {
			return (
				this.row?.['@self']?.can?.update === true
				&& isInlineEditable(this.property, this.shown)
			)
		},

		/**
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
		 * @return {Array<{value: *, label: string}>}
		 */
		options() {
			return enumOptions(this.property)
		},

		/**
		 * @spec exclude display helper: the select's current option
		 * @return {object|null}
		 */
		selectedOption() {
			return this.options.find((option) => option.value === this.draft) || null
		},

		/**
		 * @spec exclude display helper: accessible name of the inline editor
		 * @return {string}
		 */
		editorLabel() {
			return t('openregister', 'Edit {field}', {
				field: this.property?.title || this.field,
			})
		},
	},

	watch: {
		/**
		 * @spec exclude UI plumbing: follow a refreshed list value
		 * @param {*} next The new value.
		 */
		value(next) {
			if (!this.editing) this.shown = next
		},
	},

	methods: {
		/**
		 * Edit in place for an editor; open the record for everyone else.
		 *
		 * @param {MouseEvent} event The double click.
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
		 */
		onDoubleClick(event) {
			event?.stopPropagation?.()
			if (!this.canEdit) {
				this.$emit('open', this.row)
				return
			}
			this.error = ''
			this.draft = this.shown
			this.editing = true
		},

		/**
		 * Save this one field through PATCH; on a refusal show why and keep the old value.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
		 */
		async save() {
			if (this.saving) return
			const next = toPropertyType(this.property, this.draft)
			if (next === this.shown) {
				this.cancel()
				return
			}
			const self = this.row?.['@self'] || {}
			const url = generateUrl(
				`/apps/openregister/api/objects/${self.register}/${self.schema}/${self.id ?? this.row.id}`,
			)
			this.saving = true
			try {
				await axios.patch(url, { [this.field]: next })
				this.shown = next
				this.error = ''
				this.$emit('saved', {
					row: this.row,
					field: this.field,
					value: next,
				})
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.response?.data?.error
					|| t('openregister', 'The value could not be saved')
			} finally {
				this.saving = false
				this.editing = false
				this.draft = null
			}
		},

		/**
		 * Keep a single click on an editable cell from opening the record, so
		 * the double click that edits it is reachable. A cell the reader may
		 * not edit lets the click through, and the row opens as before.
		 *
		 * @param {MouseEvent} event The click.
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
		 */
		onClick(event) {
			if (this.canEdit || this.editing) {
				event?.stopPropagation?.()
			}
		},

		/**
		 * Leave the editor without saving.
		 *
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
		 */
		cancel() {
			this.editing = false
			this.draft = null
		},

		/**
		 * @param {object|null} option The chosen option.
		 * @spec exclude UI plumbing: a choice saves straight away
		 */
		onSelect(option) {
			this.draft = option ? option.value : null
			this.save()
		},
	},
}
</script>

<template>
	<div
		class="editable-cell"
		:class="{ 'editable-cell--editable': canEdit }"
		:role="canEdit && !editing ? 'button' : undefined"
		:tabindex="canEdit && !editing ? 0 : undefined"
		:aria-label="canEdit && !editing ? editorLabel : undefined"
		@click="onClick"
		@dblclick="onDoubleClick"
		@keydown.enter.self.prevent="onDoubleClick">
		<template v-if="editing">
			<NcSelect
				v-if="options.length > 0"
				:modelValue="selectedOption"
				:options="options"
				label="label"
				:inputLabel="editorLabel"
				:labelOutside="true"
				:disabled="saving"
				@click.stop
				@update:modelValue="onSelect" />
			<NcTextField
				v-else
				v-model="draft"
				:label="editorLabel"
				:labelOutside="true"
				:type="
					property.type === 'integer' || property.type === 'number'
						? 'number'
						: 'text'
				"
				:disabled="saving"
				@click.stop
				@keydown.enter.prevent="save"
				@keydown.esc.prevent="cancel"
				@blur="save" />
		</template>
		<CnCellRenderer
			v-else
			:value="shown"
			:property="property"
			:row="row"
			rowKey="id" />
		<p v-if="error" class="editable-cell__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<style scoped>
.editable-cell--editable {
	cursor: text;
}

.editable-cell__error {
	color: var(--color-error-text);
	font-size: var(--font-size-small, 13px);
	margin: 4px 0 0;
}
</style>
