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

		<table v-else class="ruleTable">
			<thead>
				<tr>
					<th scope="col">
						{{ t('openregister', 'Order') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Rule') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Kind') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Declared in') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Last run') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'On') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="rule in rules"
					:key="rule.id"
					:class="{
						selected: rule.id === selectedId,
						idle: rule.idle === true,
					}">
					<td>{{ rule.order }}</td>
					<td>
						<strong>{{ rule.label || rule.key }}</strong>
						<span class="ruleId">{{ rule.id }}</span>
					</td>
					<td>{{ kindLabel(rule.kind) }}</td>
					<td>
						<code>{{ rule.source }}</code>
					</td>
					<td>
						<span v-if="rule.lastRun">{{ rule.lastRun }}</span>
						<span v-else class="muted">{{
							t('openregister', 'Never')
						}}</span>
						<span v-if="rule.idle === true" class="idleFlag">
							{{
								t('openregister', 'Nothing in the reporting window')
							}}
						</span>
						<span v-if="rule.lastError" class="lastError">{{
							rule.lastError
						}}</span>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							type="switch"
							:modelValue="rule.enabled"
							:disabled="switching === rule.id"
							@update:modelValue="$emit('toggle', rule, $event)">
							<span class="hiddenLabel">{{ switchLabel(rule) }}</span>
						</NcCheckboxRadioSwitch>
					</td>
					<td>
						<NcButton variant="tertiary" @click="$emit('select', rule)">
							{{ t('openregister', 'Open') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
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

	methods: {
		t,

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
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
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
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
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

<style scoped>
.ruleTable {
	width: 100%;
	border-collapse: collapse;
}

.ruleTable th,
.ruleTable td {
	text-align: start;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.ruleTable tr.selected {
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
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
