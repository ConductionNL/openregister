<!--
  An editable list of key/value rows: a lookup table, or a pack's defaults.

  A value is text, or JSON when the stored value was not a string (a list, a
  number, true/false, null). The "JSON" switch per row keeps that type on
  save, so `"Saas": ["SaaS"]` stays a list. With `booleanValues` (a yes/no
  table) every value is a yes/no choice instead, stored as true or false.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="key-value-rows">
		<table v-if="rows.length > 0" class="key-value-rows__table">
			<thead>
				<tr>
					<th scope="col">
						{{ keyLabel }}
					</th>
					<th scope="col">
						{{ valueLabel }}
					</th>
					<th v-if="!booleanValues" scope="col">
						{{ t('openregister', 'As JSON') }}
					</th>
					<th v-if="!disabled" scope="col">
						<span class="hidden-visually">{{
							t('openregister', 'Actions')
						}}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(row, index) in rows" :key="row.uid">
					<td>
						<input
							:value="row.key"
							type="text"
							:disabled="disabled"
							:aria-label="rowLabel(keyLabel, index)"
							@input="update(index, { key: $event.target.value })" />
					</td>
					<td v-if="booleanValues">
						<select
							:value="booleanChoice(row)"
							:disabled="disabled"
							:aria-label="rowLabel(valueLabel, index)"
							@change="
								update(index, {
									text: $event.target.value,
									json: true,
								})
							">
							<option
								v-if="booleanChoice(row) === ''"
								value=""
								disabled>
								{{ t('openregister', 'Choose yes or no') }}
							</option>
							<option value="true">
								{{ t('openregister', 'Yes') }}
							</option>
							<option value="false">
								{{ t('openregister', 'No') }}
							</option>
						</select>
					</td>
					<td v-else>
						<input
							:value="row.text"
							type="text"
							:class="{ 'key-value-rows__json': row.json }"
							:disabled="disabled"
							:aria-label="rowLabel(valueLabel, index)"
							@input="update(index, { text: $event.target.value })" />
					</td>
					<td v-if="!booleanValues">
						<input
							:checked="row.json"
							type="checkbox"
							:disabled="disabled"
							:aria-label="
								t('openregister', 'Value {number} is JSON', {
									number: index + 1,
								})
							"
							@change="
								update(index, { json: $event.target.checked })
							" />
					</td>
					<td v-if="!disabled">
						<NcButton
							variant="tertiary"
							:aria-label="
								t('openregister', 'Remove row {number}', {
									number: index + 1,
								})
							"
							@click="remove(index)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else class="key-value-rows__empty">
			{{ emptyText }}
		</p>
		<NcButton v-if="!disabled" @click="add">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ addLabel }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { t } from '../../views/migrationPack/l10n.js'
import {
	isBooleanCell,
	newEntryRow,
	valueFromCell,
} from '../../views/migrationPack/packDraft.js'

export default {
	name: 'KeyValueRows',
	components: { NcButton, Plus, TrashCanOutline },
	props: {
		rows: { type: Array, required: true },
		keyLabel: { type: String, required: true },
		valueLabel: { type: String, required: true },
		addLabel: { type: String, required: true },
		emptyText: { type: String, default: '' },
		newRowJson: { type: Boolean, default: false },
		/** Every value is true or false: a yes/no table. */
		booleanValues: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:rows'],

	methods: {
		t,
		/**
		 * The accessible name of one row's key or value input.
		 *
		 * @param {string} label The column label.
		 * @param {number} index The row.
		 * @return {string} The label, with the row number.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		rowLabel(label, index) {
			return t('openregister', '{label} of row {number}', {
				label,
				number: index + 1,
			})
		},

		/**
		 * The yes/no choice a row holds.
		 *
		 * @param {{text: string, json: boolean}} row The row.
		 * @return {string} 'true', 'false', or '' when the row holds something else.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		booleanChoice(row) {
			if (!isBooleanCell(row)) {
				return ''
			}
			return valueFromCell(row) ? 'true' : 'false'
		},

		/**
		 * Change one row.
		 *
		 * @param {number} index The row.
		 * @param {object} patch The changed fields.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		update(index, patch) {
			this.$emit(
				'update:rows',
				this.rows.map((row, i) =>
					i === index ? { ...row, ...patch } : row,
				),
			)
		},

		/**
		 * Add an empty row at the end.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		add() {
			this.$emit('update:rows', [...this.rows, newEntryRow(this.newRowJson)])
		},

		/**
		 * Remove one row.
		 *
		 * @param {number} index The row.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		remove(index) {
			this.$emit(
				'update:rows',
				this.rows.filter((_, i) => i !== index),
			)
		},
	},
}
</script>

<style scoped>
.key-value-rows__table {
	width: 100%;
	border-collapse: collapse;
	margin-block-end: 8px;
}

.key-value-rows__table th,
.key-value-rows__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.key-value-rows__table input[type='text'] {
	width: 100%;
	margin: 0;
}

.key-value-rows__json {
	font-family: var(--font-face-monospace, monospace);
}

.key-value-rows__empty {
	color: var(--color-text-maxcontrast);
	margin-block-end: 8px;
}
</style>
