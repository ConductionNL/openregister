<template>
	<div class="ruleInventory">
		<NcEmptyContent
			v-if="rules.length === 0"
			:name="t('openregister', 'No rules on this schema yet')"
			:description="
				t(
					'openregister',
					'A calculation, a state field block, a transition condition or a triggering flow appears here the moment you declare it.',
				)
			">
			<template #icon>
				<GavelIcon :size="44" />
			</template>
		</NcEmptyContent>

		<CnDataTable
			v-else
			class="ruleTable"
			:columns="columns"
			:rows="rules"
			:rowClass="rowClass">
			<template #column-label="{ row: rule }">
				<strong>{{ rule.label || rule.key }}</strong>
				<span class="ruleId">{{ rule.id }}</span>
			</template>
			<template #column-kind="{ row: rule }">
				{{ kindLabel(rule.kind) }}
			</template>
			<template #column-source="{ value }">
				<code>{{ value }}</code>
			</template>
			<template #column-lastRun="{ row: rule }">
				<span v-if="rule.lastRun">{{ rule.lastRun }}</span>
				<span v-else class="muted">{{ t('openregister', 'Never') }}</span>
				<span v-if="rule.idle === true" class="idleFlag">
					{{ t('openregister', 'Nothing in the reporting window') }}
				</span>
				<span v-if="rule.lastError" class="lastError">{{ rule.lastError }}</span>
			</template>
			<template #column-enabled="{ row: rule }">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="rule.enabled"
					:disabled="switching === rule.id"
					@update:modelValue="$emit('toggle', rule, $event)">
					<span class="hiddenLabel">{{ switchLabel(rule) }}</span>
				</NcCheckboxRadioSwitch>
			</template>
			<template #actions-header>
				{{ t('openregister', 'Actions') }}
			</template>
			<template #row-actions="{ row: rule }">
				<NcButton variant="tertiary" @click="$emit('select', rule)">
					{{ t('openregister', 'Open') }}
				</NcButton>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcEmptyContent } from '@nextcloud/vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'

/**
 * The rules a schema declares, in the order the save pipeline runs them.
 *
 * It renders what the inventory returns and nothing else. The order column is
 * the pipeline's, not a sort the user chose, because reordering the list would
 * show an evaluation order that is not the one the engine uses.
 */
export default {
	name: 'RuleInventoryTable',

	components: {
		CnDataTable,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		GavelIcon,
	},

	props: {
		/** The inventory rows, already in evaluation order. */
		rules: { type: Array, required: true },
		/** The published kinds, so an unknown one still renders a label. */
		kinds: { type: Array, default: () => [] },
		/** The rule currently open, by id. */
		selectedId: { type: String, default: '' },
		/** The rule whose switch is mid-flight, by id. */
		switching: { type: String, default: '' },
	},

	emits: ['select', 'toggle'],

	computed: {
		/**
		 * The inventory columns. None sorts: the order is the pipeline's.
		 *
		 * @return {Array<object>} The CnDataTable columns
		 */
		columns() {
			return [
				{ key: 'order', label: t('openregister', 'Order') },
				{ key: 'label', label: t('openregister', 'Rule') },
				{ key: 'kind', label: t('openregister', 'Kind') },
				{ key: 'source', label: t('openregister', 'Declared in') },
				{ key: 'lastRun', label: t('openregister', 'Last run') },
				{ key: 'enabled', label: t('openregister', 'On') },
			]
		},
	},

	methods: {
		t,

		/**
		 * The row classes: the open rule and an idle one.
		 *
		 * @param {object} rule The inventory row.
		 *
		 * @return {string} The classes
		 */
		rowClass(rule) {
			return [
				rule.id === this.selectedId ? 'selected' : '',
				rule.idle === true ? 'idle' : '',
			].join(' ').trim()
		},

		/**
		 * The label for a kind, from the published vocabulary.
		 *
		 * Falls back to the raw kind rather than to a blank: a kind a later
		 * release adds must still be readable here.
		 *
		 * @param {string} kind The rule kind.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		kindLabel(kind) {
			const found = this.kinds.find((row) => row.kind === kind)
			return found ? found.kind : kind
		},

		/**
		 * What the switch does, said out loud for a screen reader.
		 *
		 * @param {object} rule The inventory row.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		switchLabel(rule) {
			if (rule.enabled) {
				return t('openregister', 'Switch off {rule}', {
					rule: rule.label || rule.key,
				})
			}
			return t('openregister', 'Switch on {rule}', {
				rule: rule.label || rule.key,
			})
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/common.scss' as *;

.ruleTable :deep(td) {
	vertical-align: top;
}

.ruleTable :deep(tr.selected) {
	background-color: var(--color-primary-element-light);
}

.ruleId {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.muted {
	color: var(--color-text-maxcontrast);
}

.idleFlag {
	display: block;
	font-size: 0.85em;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.lastError {
	display: block;
	font-size: 0.85em;
	color: var(--color-error-text, var(--color-error));
}

.hiddenLabel {
	@include visually-hidden;
}
</style>
