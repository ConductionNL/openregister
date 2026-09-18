<template>
	<div class="schemaRulesTab">
		<NcAppContentDetails>
			<h2>{{ t('openregister', 'Rules') }}</h2>
			<p class="lead">
				{{
					t(
						'openregister',
						'Everything that runs when an object of this schema is saved, in the order it runs.',
					)
				}}
			</p>

			<NcLoadingIcon v-if="loading" :size="44" />

			<template v-else>
				<p v-if="error" class="refusal">
					{{ error }}
				</p>

				<RuleInventoryTable
					:rules="rules"
					:kinds="vocabulary.kinds"
					:selectedId="selected ? selected.id : ''"
					:switching="switching"
					@select="open"
					@toggle="toggle" />

				<div v-if="selected" class="ruleDetail">
					<div class="ruleDetailHeader">
						<h2>{{ selected.label || selected.key }}</h2>
						<NcButton variant="tertiary" @click="selected = null">
							{{ t('openregister', 'Close') }}
						</NcButton>
					</div>

					<RuleConditionEditor
						:rule="selected"
						:kinds="vocabulary.kinds"
						:actions="vocabulary.actions"
						:operators="operators"
						@update:draft="draft = $event" />

					<RuleTrialPanel
						:schema="schemaReference"
						:ruleId="selected.id"
						:draft="draft"
						:verdicts="vocabulary.verdicts" />

					<RuleReplayPanel
						v-if="selected.kind === 'calculation'"
						:schema="schemaReference"
						:ruleId="selected.id" />

					<RuleRunsPanel
						:ruleId="selected.id"
						:verdicts="vocabulary.verdicts" />
				</div>
			</template>
		</NcAppContentDetails>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContentDetails, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import RuleConditionEditor from '../../components/rules/RuleConditionEditor.vue'
import RuleInventoryTable from '../../components/rules/RuleInventoryTable.vue'
import RuleReplayPanel from '../../components/rules/RuleReplayPanel.vue'
import RuleRunsPanel from '../../components/rules/RuleRunsPanel.vue'
import RuleTrialPanel from '../../components/rules/RuleTrialPanel.vue'
import {
	fetchInventory,
	fetchOperators,
	fetchVocabulary,
	messageFor,
	setRuleEnabled,
} from '../../services/rules.js'

/**
 * The administrator's rule surface for one schema.
 *
 * It is a tab on the schema, not a page of its own, because a rule has no
 * meaning away from the schema it acts on and a separate page would make an
 * administrator navigate to find out what a schema does.
 *
 * Everything it shows is a read. The one write is the switch, which is the one
 * write the API offers, and it is audited.
 *
 * @visual exclude {Covered by tests/e2e/ci/rules-engine-admin-ui.spec.ts, which drives the tab against a live instance.}
 */
export default {
	name: 'SchemaRulesTab',

	components: {
		NcAppContentDetails,
		NcButton,
		NcLoadingIcon,
		RuleConditionEditor,
		RuleInventoryTable,
		RuleReplayPanel,
		RuleRunsPanel,
		RuleTrialPanel,
	},

	props: {
		/** The schema being read. */
		schema: { type: Object, required: true },
	},

	data() {
		return {
			rules: [],
			vocabulary: { kinds: [], verdicts: [], actions: [] },
			operators: [],
			selected: null,
			draft: null,
			switching: '',
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * How the API should be asked for this schema.
		 *
		 * The slug when there is one, because it is what the rule ids are
		 * derived from and it makes the request readable in a network log.
		 *
		 * @return {string|number} The reference.
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		schemaReference() {
			return this.schema.slug || this.schema.uuid || this.schema.id
		},
	},

	watch: {
		schemaReference: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Load the vocabulary, the operator catalogue and the inventory.
		 *
		 * The vocabulary and the catalogue are asked for beside the inventory
		 * rather than hardcoded, so a kind or an operator a later release adds
		 * renders here without this file changing.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.selected = null
			this.draft = null

			try {
				const [vocabulary, inventory, operators] = await Promise.all([
					fetchVocabulary(),
					fetchInventory(this.schemaReference),
					fetchOperators(),
				])

				this.vocabulary = {
					kinds: vocabulary.kinds ?? [],
					verdicts: vocabulary.verdicts ?? [],
					actions: vocabulary.actions ?? [],
				}
				this.rules = inventory.rules ?? []
				this.operators = operators
			} catch (failure) {
				this.error = messageFor(
					failure,
					t(
						'openregister',
						'The rules for this schema could not be read.',
					),
				)
				this.rules = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open one rule.
		 *
		 * @param {object} rule The inventory row.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		open(rule) {
			this.selected = rule
			this.draft = null
		},

		/**
		 * Switch one rule off or on, and show what the server now says.
		 *
		 * The row is replaced with the server's answer rather than flipped
		 * locally: the switch writes into the declaration that owns the rule,
		 * and a row that flipped optimistically would report a state the
		 * schema does not have if that write was refused.
		 *
		 * @param {object} rule The inventory row.
		 * @param {boolean} enabled What the operator asked for.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		async toggle(rule, enabled) {
			this.switching = rule.id
			this.error = ''

			try {
				await setRuleEnabled(this.schemaReference, rule.id, enabled)
				const inventory = await fetchInventory(this.schemaReference)
				this.rules = inventory.rules ?? []
				if (this.selected) {
					this.selected =
						this.rules.find((row) => row.id === this.selected.id) ?? null
				}
			} catch (failure) {
				this.error = messageFor(
					failure,
					t('openregister', 'The rule could not be switched.'),
				)
			} finally {
				this.switching = ''
			}
		},
	},
}
</script>

<style scoped>
.schemaRulesTab {
	padding: 20px;
}

.lead {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.ruleDetail {
	margin-top: 28px;
	padding-top: 20px;
	border-top: 2px solid var(--color-border);
}

.ruleDetailHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}

.refusal {
	color: var(--color-error-text, var(--color-error));
}
</style>
