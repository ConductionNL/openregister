<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('openregister', 'Resolve conflicts')"
		size="large"
		:can-close="!saving"
		@closing="$emit('close')">
		<div class="conflictResolution">
			<template v-if="conflicts.length === 0">
				<NcEmptyContent :name="t('openregister', 'No conflicts to resolve')" :description="t('openregister', 'Every attribute across the linked sources already agrees, or has only one source.')">
					<template #icon>
						<CheckCircleOutline :size="48" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<NcNoteCard type="info">
					{{ t('openregister', 'Choose the authoritative value for each conflicting attribute, then save.') }}
				</NcNoteCard>

				<div
					v-for="conflict in conflicts"
					:key="conflict.attribute"
					class="conflictRow"
					data-testid="conflict-row">
					<h4>{{ conflict.attribute }}</h4>

					<NcSelect
						v-model="selections[conflict.attribute]"
						data-testid="mdm-conflict-source-select"
						:options="conflict.options"
						:input-label="t('openregister', 'Winning source for {attribute}', { attribute: conflict.attribute })"
						:clearable="false"
						label="label"
						@update:modelValue="handleSelectionChange" />

					<div class="conflictRow__outcome">
						<NcCheckboxRadioSwitch
							v-model="outcomes[conflict.attribute]"
							:button-variant="true"
							value="persistent"
							:name="'outcome_' + conflict.attribute"
							type="radio"
							button-variant-grouped="horizontal">
							{{ t('openregister', 'Persistent (trust rule)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="outcomes[conflict.attribute]"
							:button-variant="true"
							value="one-off"
							:name="'outcome_' + conflict.attribute"
							type="radio"
							button-variant-grouped="horizontal">
							{{ t('openregister', 'One-off (this record only)') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<NcTextField
					v-model="rationale"
					:label="t('openregister', 'Rationale (optional)')"
					:placeholder="t('openregister', 'Why is this the authoritative value?')" />

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="conflicts.length > 0"
				variant="primary"
				data-testid="mdm-conflict-save"
				:disabled="!canSave"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('openregister', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import { qualityStore } from '../../store/store.js'

/**
 * Steward-facing conflict-resolution modal (ADR-045 #E). Computes, per
 * attribute, the set of DISAGREEING values across the master object's linked
 * source records (attributes with a single value, or full agreement, are
 * never listed). For each conflict the steward picks the authoritative
 * source/value and an outcome:
 *   - persistent: writes a `trustConfiguration` row via the generic
 *     `/api/objects` CRUD surface (no bespoke endpoint) so future
 *     resolutions for the same tuple honour the rule.
 *   - one-off: calls the survivorship override endpoint to pin just this
 *     object's attribute, leaving trust rules untouched.
 *
 * Generalises pipelinq's `MdmConflictResolutionModal.vue` conflict-detection
 * logic (group source values per attribute, keep >1 distinct value) onto
 * OpenRegister's object/source shape, and adds the one-off outcome pipelinq
 * did not have (design.md).
 */
export default {
	name: 'MdmConflictResolutionModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		CheckCircleOutline,
	},

	props: {
		/**
		 * The master object (golden record) being resolved. Must carry an id;
		 * its linked source records are read from `object.sources` (or the
		 * first array-valued field whose entries look like source records —
		 * `{sourceSystem, values|mappedAttributes}` — mirroring the
		 * resolver's own tolerance for the configurable `sourceLinkField`).
		 */
		object: {
			type: Object,
			required: true,
		},
		/**
		 * Entity type used when persisting a trust-configuration row. Falls
		 * back to `object.entityType` / `object['@self']?.schema` when omitted.
		 */
		entityType: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			selections: {},
			outcomes: {},
			rationale: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Resolve the linked source records defensively from the object
		 * payload: an explicit `sources` array, or the first array-valued
		 * field whose entries look like source records.
		 *
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#requirement-conflict-resolution-modal-surfaces-disagreeing-sources-per-attribute
		 * @return {Array<object>}
		 */
		sourceRecords() {
			if (!this.object || typeof this.object !== 'object') return []
			if (Array.isArray(this.object.sources)) return this.object.sources.filter((entry) => entry && typeof entry === 'object')

			for (const value of Object.values(this.object)) {
				if (Array.isArray(value) && value.length > 0 && value.every((entry) => entry && typeof entry === 'object' && ('sourceSystem' in entry))) {
					return value
				}
			}
			return []
		},

		/**
		 * Compute, per attribute, the set of distinct non-empty values
		 * supplied by the linked sources. Only attributes with MORE THAN ONE
		 * distinct value are conflicts.
		 *
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-only-disagreeing-attributes-are-listed
		 * @return {Array<{attribute: string, options: Array<{id: string, label: string, value: *, sourceSystem: string}>}>}
		 */
		conflicts() {
			const perAttribute = {}

			for (const record of this.sourceRecords) {
				const sourceSystem = record.sourceSystem ?? ''
				const values = record.values ?? record.mappedAttributes ?? {}
				if (!values || typeof values !== 'object') continue

				for (const [attribute, value] of Object.entries(values)) {
					if (value === null || value === undefined || value === '') continue
					if (!perAttribute[attribute]) perAttribute[attribute] = new Map()
					const key = JSON.stringify(value)
					if (!perAttribute[attribute].has(key)) {
						perAttribute[attribute].set(key, { id: `${attribute}::${sourceSystem}::${key}`, label: `${this.displayValue(value)} (${sourceSystem})`, value, sourceSystem })
					}
				}
			}

			return Object.entries(perAttribute)
				.filter(([, options]) => options.size > 1)
				.map(([attribute, options]) => ({ attribute, options: Array.from(options.values()) }))
		},

		/**
		 * Save is enabled once every listed conflict has a selected winner.
		 *
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-selecting-a-winning-source-enables-save
		 * @return {boolean}
		 */
		canSave() {
			if (this.saving) return false
			if (this.conflicts.length === 0) return false
			return this.conflicts.every((conflict) => Boolean(this.selections[conflict.attribute]))
		},
	},

	watch: {
		conflicts: {
			immediate: true,
			handler(list) {
				for (const conflict of list) {
					if (!(conflict.attribute in this.selections)) this.selections[conflict.attribute] = null
					if (!(conflict.attribute in this.outcomes)) this.outcomes[conflict.attribute] = 'persistent'
				}
			},
		},
	},

	methods: {
		/**
		 * Stringify a source value for the select option label.
		 *
		 * @param {*} value Raw attribute value.
		 * @spec exclude UI presentation helper
		 * @return {string}
		 */
		displayValue(value) {
			if (typeof value === 'object') return JSON.stringify(value)
			return String(value)
		},

		/**
		 * No-op change handler kept for template symmetry with other
		 * NcSelect usages in this store's modals; `canSave` already reacts
		 * to `selections` via Vue's reactivity.
		 *
		 * @spec exclude UI plumbing — reactivity is handled by v-model
		 * @return {void}
		 */
		handleSelectionChange() {},

		/**
		 * Resolve the entity type used for a persistent trust rule.
		 *
		 * @spec exclude UI plumbing — reads already-available props/object fields
		 * @return {string}
		 */
		resolveEntityType() {
			return this.entityType || this.object?.entityType || this.object?.['@self']?.schema || ''
		},

		/**
		 * Save every resolved conflict. A persistent outcome writes a
		 * `trustConfiguration` row for the winning `(entityType, attribute,
		 * sourceSystem)` tuple — no per-object override is set, so the
		 * golden record recomputes from tier resolution alone once the new
		 * trust row is honoured. A one-off outcome sets a per-object
		 * override, pinning just this object's attribute without touching
		 * any trust rule. Once every conflict is applied, the master object
		 * is touch-saved (only needed when at least one persistent outcome
		 * was chosen — an override save already recomputes on its own) so
		 * `SurvivorshipRecomputeListener` recomputes against the
		 * newly-persisted trust rows, then the golden record is refreshed
		 * and `saved` is emitted. On failure, shows an error and keeps the
		 * modal open.
		 *
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-persistent-choice-writes-a-trust-configuration-row
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-one-off-choice-sets-a-per-object-override
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-save-failure-surfaces-an-error-and-keeps-the-modal-open
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.canSave) return
			this.saving = true
			this.error = ''
			try {
				let wrotePersistentRule = false

				for (const conflict of this.conflicts) {
					const winner = this.selections[conflict.attribute]
					const outcome = this.outcomes[conflict.attribute] ?? 'persistent'

					if (outcome === 'one-off') {
						await qualityStore.setAttributeOverride(this.object.id, conflict.attribute, winner.value, this.rationale || undefined)
						continue
					}

					await qualityStore.persistTrustRule({
						entityType: this.resolveEntityType(),
						attribute: conflict.attribute,
						sourceSystem: winner.sourceSystem,
						trustTier: 'gold',
						rationale: this.rationale || undefined,
					})
					wrotePersistentRule = true
				}

				if (wrotePersistentRule) {
					const register = this.object?.['@self']?.register ?? this.object?.register
					const schema = this.object?.['@self']?.schema ?? this.object?.schema ?? this.resolveEntityType()
					if (register && schema) {
						await qualityStore.touchObject(register, schema, this.object.id)
					}
				}

				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				this.error = e.response?.data?.error || t('openregister', 'The conflict resolution could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.conflictResolution {
	min-height: 120px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.conflictRow {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.conflictRow h4 {
	margin: 0 0 8px 0;
}

.conflictRow__outcome {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>
