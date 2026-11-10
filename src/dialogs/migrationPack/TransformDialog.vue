<!--
  Edit the transform of one field mapping: every type PackDefinitionValidator
  accepts (trim, date, lookup, yes/no map, join, fixed value) or none.

  Works on a copy; Apply hands the new transform draft back, Cancel drops it.
  A lookup's "other values" choice is three-way on purpose, because the
  engine treats each differently: no default errors the row, an empty default
  leaves the field empty, a value fills it. A yes/no table offers only yes or
  no for its values and its default, because the engine casts them to a
  boolean and the text "false" would become true.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcDialog
		:name="
			t('openregister', 'Transform for row {number}', { number: rowNumber })
		"
		size="normal"
		@update:open="onOpenChange">
		<div class="transform-dialog">
			<p class="transform-dialog__intro">
				{{
					t('openregister', 'Column {source} to field {target}', {
						source: source || '-',
						target: target || '-',
					})
				}}
			</p>

			<label class="transform-dialog__label" :for="typeInputId">
				{{ t('openregister', 'Transform') }}
			</label>
			<select
				:id="typeInputId"
				:value="draft ? draft.type : ''"
				:disabled="readOnly || unknownType"
				@change="setType($event.target.value)">
				<option
					v-for="option in typeOptions"
					:key="option.value"
					:value="option.value">
					{{ option.label }}
				</option>
			</select>

			<NcNoteCard v-if="unknownType" type="info">
				{{
					t(
						'openregister',
						'This editor does not know the transform type "{type}". It is kept as stored.',
						{ type: draft.type },
					)
				}}
			</NcNoteCard>

			<template v-if="draft && draft.type === 'date'">
				<NcTextField
					:modelValue="draft.sourceFormat"
					:label="t('openregister', 'Source date format')"
					:helperText="
						t(
							'openregister',
							'A PHP date format such as d-m-Y. Start it with ! so parts the format does not name are not taken from today. Leave it empty to read any common date.',
						)
					"
					:disabled="readOnly"
					@update:modelValue="patch({ sourceFormat: $event })" />
				<NcTextField
					:modelValue="draft.targetFormat"
					:label="t('openregister', 'Stored date format')"
					placeholder="Y-m-d"
					:disabled="readOnly"
					@update:modelValue="patch({ targetFormat: $event })" />
			</template>

			<template
				v-if="
					draft && (draft.type === 'lookup' || draft.type === 'bool-map')
				">
				<KeyValueRows
					:rows="draft.mapRows"
					:keyLabel="t('openregister', 'Value in the source')"
					:valueLabel="t('openregister', 'Stored value')"
					:addLabel="t('openregister', 'Add value')"
					:newRowJson="draft.type === 'bool-map'"
					:booleanValues="draft.type === 'bool-map'"
					:disabled="readOnly"
					@update:rows="patch({ mapRows: $event })" />
				<fieldset class="transform-dialog__fieldset">
					<legend>
						{{ t('openregister', 'Values that are not in the table') }}
					</legend>
					<NcCheckboxRadioSwitch
						v-for="mode in defaultModes"
						:key="mode.value"
						:modelValue="draft.defaultMode"
						:value="mode.value"
						type="radio"
						:name="`${typeInputId}-default`"
						:disabled="readOnly"
						@update:modelValue="patch({ defaultMode: $event })">
						{{ mode.label }}
					</NcCheckboxRadioSwitch>
					<div
						v-if="
							draft.defaultMode === 'value'
							&& draft.type === 'bool-map'
						"
						class="transform-dialog__cell">
						<label :for="`${typeInputId}-default-value`">
							{{ t('openregister', 'Value for other values') }}
						</label>
						<select
							:id="`${typeInputId}-default-value`"
							:value="defaultChoice"
							:disabled="readOnly"
							@change="setDefaultChoice($event.target.value)">
							<option v-if="defaultChoice === ''" value="" disabled>
								{{ t('openregister', 'Choose yes or no') }}
							</option>
							<option value="true">
								{{ t('openregister', 'Yes') }}
							</option>
							<option value="false">
								{{ t('openregister', 'No') }}
							</option>
						</select>
					</div>
					<div
						v-else-if="draft.defaultMode === 'value'"
						class="transform-dialog__cell">
						<NcTextField
							:modelValue="draft.defaultCell.text"
							:label="t('openregister', 'Value for other values')"
							:disabled="readOnly"
							@update:modelValue="
								patch({
									defaultCell: {
										...draft.defaultCell,
										text: $event,
									},
								})
							" />
						<NcCheckboxRadioSwitch
							:modelValue="draft.defaultCell.json"
							:disabled="readOnly"
							@update:modelValue="
								patch({
									defaultCell: {
										...draft.defaultCell,
										json: $event,
									},
								})
							">
							{{ t('openregister', 'As JSON') }}
						</NcCheckboxRadioSwitch>
					</div>
				</fieldset>
			</template>

			<template v-if="draft && draft.type === 'concat'">
				<p class="transform-dialog__help">
					{{
						t(
							'openregister',
							'The value of column {source} comes first, then these columns in order.',
							{ source: source || '-' },
						)
					}}
				</p>
				<ol class="transform-dialog__fields">
					<li v-for="(field, index) in draft.fields" :key="index">
						<input
							:value="field"
							type="text"
							:disabled="readOnly"
							:aria-label="
								t('openregister', 'Column to join {number}', {
									number: index + 1,
								})
							"
							@input="setField(index, $event.target.value)" />
						<NcButton
							v-if="!readOnly"
							variant="tertiary"
							:aria-label="
								t('openregister', 'Remove column {number}', {
									number: index + 1,
								})
							"
							@click="removeField(index)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
						</NcButton>
					</li>
				</ol>
				<NcButton v-if="!readOnly" @click="addField">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Add column') }}
				</NcButton>
				<NcTextField
					:modelValue="draft.separator"
					:label="t('openregister', 'Separator')"
					:disabled="readOnly"
					@update:modelValue="patch({ separator: $event })" />
			</template>

			<template v-if="draft && draft.type === 'const'">
				<div class="transform-dialog__cell">
					<NcTextField
						:modelValue="draft.valueCell.text"
						:label="t('openregister', 'Fixed value')"
						:disabled="readOnly"
						@update:modelValue="
							patch({
								valueCell: { ...draft.valueCell, text: $event },
							})
						" />
					<NcCheckboxRadioSwitch
						:modelValue="draft.valueCell.json"
						:disabled="readOnly"
						@update:modelValue="
							patch({
								valueCell: { ...draft.valueCell, json: $event },
							})
						">
						{{ t('openregister', 'As JSON') }}
					</NcCheckboxRadioSwitch>
				</div>
				<p class="transform-dialog__help">
					{{
						t(
							'openregister',
							'A fixed value is written even when the column is empty.',
						)
					}}
				</p>
			</template>

			<NcNoteCard v-if="unknownKeys.length > 0" type="info">
				{{
					t(
						'openregister',
						'This transform also has settings this editor does not show ({keys}). They are kept when you save.',
						{ keys: unknownKeys.join(', ') },
					)
				}}
			</NcNoteCard>

			<div aria-live="polite">
				<NcNoteCard v-if="errors.length > 0" type="error">
					<ul>
						<li v-for="message in errors" :key="message">
							{{ message }}
						</li>
					</ul>
				</NcNoteCard>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{
					readOnly
						? t('openregister', 'Close')
						: t('openregister', 'Cancel')
				}}
			</NcButton>
			<NcButton v-if="!readOnly" variant="primary" @click="apply">
				{{ t('openregister', 'Apply') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import KeyValueRows from '../../components/migrationPack/KeyValueRows.vue'
import { t } from '../../views/migrationPack/l10n.js'
import {
	isBooleanCell,
	newTransform,
	TRANSFORM_TYPES,
	unknownTransformKeys,
	validateTransform,
	valueFromCell,
	withTransformType,
} from '../../views/migrationPack/packDraft.js'

let dialogCounter = 0

/**
 * The label of each transform type, for the type picker.
 *
 * @return {object} Type to label.
 */
function typeLabels() {
	return {
		'': t('openregister', 'None: copy the value as is'),
		trim: t('openregister', 'Trim spaces'),
		date: t('openregister', 'Date'),
		lookup: t('openregister', 'Lookup table'),
		'bool-map': t('openregister', 'Yes/no table'),
		concat: t('openregister', 'Join columns'),
		const: t('openregister', 'Fixed value'),
	}
}

export default {
	name: 'TransformDialog',
	components: {
		KeyValueRows,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcTextField,
		Plus,
		TrashCanOutline,
	},

	props: {
		transform: { type: Object, default: null },
		rowNumber: { type: Number, required: true },
		source: { type: String, default: '' },
		target: { type: String, default: '' },
		readOnly: { type: Boolean, default: false },
	},

	emits: ['apply', 'close'],

	data() {
		dialogCounter += 1
		return {
			draft: this.transform
				? JSON.parse(JSON.stringify(this.transform))
				: null,

			errors: [],
			typeInputId: `migration-pack-transform-${dialogCounter}`,
		}
	},

	computed: {
		/**
		 * The transform types the picker offers, with none first.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		typeOptions() {
			const labels = typeLabels()
			const options = ['', ...TRANSFORM_TYPES].map((value) => ({
				value,
				label: labels[value],
			}))
			if (this.unknownType) {
				options.push({ value: this.draft.type, label: this.draft.type })
			}
			return options
		},

		/**
		 * Whether the stored type is one this editor does not know.
		 *
		 * @return {boolean} True for an unknown type.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		unknownType() {
			return Boolean(
				this.draft
				&& this.draft.type !== ''
				&& !TRANSFORM_TYPES.includes(this.draft.type),
			)
		},

		/**
		 * The three ways to treat a value that is not in the table.
		 *
		 * A yes/no table stores every default as true or false, so its empty
		 * default is offered as storing false.
		 *
		 * @return {Array<{value: string, label: string}>} The choices.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		defaultModes() {
			return [
				{
					value: 'none',
					label: t('openregister', 'Report an error for the row'),
				},
				{
					value: 'empty',
					label:
						this.draft?.type === 'bool-map'
							? t('openregister', 'Store false')
							: t('openregister', 'Leave the field empty'),
				},
				{ value: 'value', label: t('openregister', 'Store this value') },
			]
		},

		/**
		 * The yes/no choice for values that are not in a yes/no table.
		 *
		 * @return {string} 'true', 'false', or '' while none is chosen.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		defaultChoice() {
			if (!this.draft || !isBooleanCell(this.draft.defaultCell)) {
				return ''
			}
			return valueFromCell(this.draft.defaultCell) ? 'true' : 'false'
		},

		/**
		 * Settings of the stored transform the editor does not show.
		 *
		 * @return {Array<string>} The keys.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		unknownKeys() {
			return this.draft ? unknownTransformKeys(this.draft) : []
		},
	},

	methods: {
		t,
		/**
		 * Change the transform type; none removes the transform.
		 *
		 * @param {string} type The new type, or '' for none.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		setType(type) {
			this.errors = []
			if (type === '') {
				this.draft = this.draft ? { ...this.draft, type: '' } : null
				return
			}
			this.draft = this.draft
				? withTransformType(this.draft, type)
				: newTransform(type)
		},

		/**
		 * Change fields of the transform draft.
		 *
		 * @param {object} changes The changed fields.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		patch(changes) {
			this.draft = { ...this.draft, ...changes }
		},

		/**
		 * Store yes or no for values that are not in a yes/no table.
		 *
		 * @param {string} choice 'true' or 'false'.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		setDefaultChoice(choice) {
			this.patch({ defaultCell: { text: choice, json: true } })
		},

		/**
		 * Change one column of a join.
		 *
		 * @param {number} index The column.
		 * @param {string} value The column name.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		setField(index, value) {
			this.patch({
				fields: this.draft.fields.map((f, i) => (i === index ? value : f)),
			})
		},

		/**
		 * Add a column to a join.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		addField() {
			this.patch({ fields: [...this.draft.fields, ''] })
		},

		/**
		 * Remove a column from a join.
		 *
		 * @param {number} index The column.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		removeField(index) {
			this.patch({ fields: this.draft.fields.filter((_, i) => i !== index) })
		},

		/**
		 * Hand the transform back when it can be saved; none hands back null.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		apply() {
			if (!this.draft || this.draft.type === '') {
				this.$emit('apply', null)
				return
			}
			this.errors = validateTransform(this.draft)
			if (this.errors.length === 0) {
				this.$emit('apply', this.draft)
			}
		},

		/**
		 * Close on the dialog's own close button or Escape.
		 *
		 * @param {boolean} open Whether the dialog stays open.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		onOpenChange(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.transform-dialog {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.transform-dialog__intro,
.transform-dialog__help {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.transform-dialog__label {
	font-weight: bold;
}

.transform-dialog__fieldset {
	border: none;
	padding: 0;
	margin: 0;
}

.transform-dialog__fieldset legend {
	font-weight: bold;
}

.transform-dialog__cell {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.transform-dialog__fields {
	margin: 0;
	padding-inline-start: 24px;
}

.transform-dialog__fields li {
	display: flex;
	align-items: center;
	gap: 4px;
}

.transform-dialog__fields input {
	flex: 1;
	margin: 0;
}
</style>
