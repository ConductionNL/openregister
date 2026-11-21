<template>
	<section class="ruleEditor">
		<h3>{{ t('openregister', 'What this rule is') }}</h3>

		<dl class="ruleFacts">
			<dt>{{ t('openregister', 'Kind') }}</dt>
			<dd>
				{{ rule.kind }}
				<span v-if="kindSentence" class="muted">{{ kindSentence }}</span>
			</dd>

			<dt>{{ t('openregister', 'Declared in') }}</dt>
			<dd>
				<code>{{ rule.source }}</code>
			</dd>

			<dt>{{ t('openregister', 'What it does when it fires') }}</dt>
			<dd>
				<ul class="actionList">
					<li v-for="action in rule.actions" :key="action">
						<code>{{ action }}</code>
						<span class="muted">{{ actionSentence(action) }}</span>
					</li>
				</ul>
			</dd>

			<dt>{{ t('openregister', 'Ceiling for one replay') }}</dt>
			<dd>
				<span v-if="rule.maxObjects">{{ rule.maxObjects }}</span>
				<span v-else class="muted">{{
					t(
						'openregister',
						'None declared. The instance-wide bulk job ceiling applies.',
					)
				}}</span>
			</dd>
		</dl>

		<h3>{{ t('openregister', 'The condition') }}</h3>
		<p class="lead">
			{{
				t(
					'openregister',
					'Edit it here to try a change before you put it in the schema. Nothing on this screen saves a condition.',
				)
			}}
		</p>

		<label for="ruleConditionDraft">{{
			t('openregister', 'Condition, as JSON')
		}}</label>
		<textarea
			id="ruleConditionDraft"
			v-model="draftText"
			rows="10"
			spellcheck="false"
			@input="validate" />

		<p v-if="parseError" class="refusal">
			{{ parseError }}
		</p>
		<p v-else-if="unknownOperator" class="refusal">
			{{
				t(
					'openregister',
					'No engine holds the operator "{op}". The schema save would refuse this.',
					{ op: unknownOperator },
				)
			}}
		</p>
		<p v-else-if="dirty" class="accepted">
			{{
				t(
					'openregister',
					'Every operator in this condition is one the engine dispatches on.',
				)
			}}
		</p>

		<NcButton v-if="dirty" variant="tertiary" @click="reset">
			{{ t('openregister', 'Back to what the schema declares') }}
		</NcButton>

		<details class="operatorReference">
			<summary>{{ t('openregister', 'Operators you can use') }}</summary>
			<div
				v-for="category in categories"
				:key="category"
				class="operatorCategory">
				<h4>{{ category }}</h4>
				<CnDataTable
					class="operatorTable"
					:columns="operatorColumns"
					:rows="operatorsIn(category)"
					rowKey="op">
					<template #column-op="{ value }">
						<code>{{ value }}</code>
					</template>
					<template #column-description="{ value }">
						{{ value }}
					</template>
				</CnDataTable>
			</div>
		</details>
	</section>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { invalidJsonText } from './ruleJson.js'

/**
 * What a rule is, and a place to draft a change to its condition.
 *
 * It says plainly that it saves nothing. The rules API offers exactly one
 * write, the switch, and a screen that looked like an editor but could not
 * save would be worse than one that says what it is.
 *
 * The operator list is the published catalogue, which is generated from the
 * evaluator's own dispatch table. A list typed here would drift, and an author
 * would be told an operator is unavailable on the day it shipped.
 */

/** What parsedDraft hands back for a draft that is not JSON. */
const UNPARSABLE = Symbol('unparsable')

export default {
	name: 'RuleConditionEditor',

	components: {
		CnDataTable,
		NcButton,
	},

	props: {
		/** The inventory row for the rule being read. */
		rule: { type: Object, required: true },
		/** The published kinds. */
		kinds: { type: Array, default: () => [] },
		/** The published actions. */
		actions: { type: Array, default: () => [] },
		/** The JSON-AST operator catalogue. */
		operators: { type: Array, default: () => [] },
	},

	emits: ['update:draft'],

	data() {
		return {
			draftText: '',
			parseError: '',
			unknownOperator: '',
		}
	},

	computed: {
		/**
		 * The operator reference columns.
		 *
		 * @return {Array<object>} The CnDataTable columns
		 */
		operatorColumns() {
			return [
				{ key: 'op', label: t('openregister', 'Operator') },
				{ key: 'arity', label: t('openregister', 'Takes') },
				{ key: 'description', label: t('openregister', 'What it does'), cellClass: 'cn-cell--wrap' },
			]
		},

		/**
		 * The published sentence for this rule's kind.
		 *
		 * @return {string} The sentence, or an empty string.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		kindSentence() {
			const found = this.kinds.find((row) => row.kind === this.rule.kind)
			return found ? found.description : ''
		},

		/**
		 * The categories the operator catalogue groups itself by.
		 *
		 * @return {Array<string>} The categories, in the catalogue's order.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		categories() {
			const seen = []
			this.operators.forEach((row) => {
				if (!seen.includes(row.category)) {
					seen.push(row.category)
				}
			})
			return seen
		},

		/**
		 * Whether the draft differs from what the schema declares.
		 *
		 * @return {boolean} True when the author has changed something.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		dirty() {
			return this.draftText.trim() !== this.declaredText.trim()
		},

		/**
		 * The declared condition, rendered.
		 *
		 * @return {string} The JSON.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		declaredText() {
			if (this.rule.condition === null || this.rule.condition === undefined) {
				return ''
			}
			return JSON.stringify(this.rule.condition, null, 2)
		},
	},

	watch: {
		rule: {
			handler: 'reset',
			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * The published sentence for one action.
		 *
		 * @param {string} action The action key.
		 *
		 * @return {string} The sentence, or an empty string.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		actionSentence(action) {
			const found = this.actions.find((row) => row.action === action)
			return found ? found.description : ''
		},

		/**
		 * The operators in one category.
		 *
		 * @param {string} category The category name.
		 *
		 * @return {Array<object>} The rows.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		operatorsIn(category) {
			return this.operators.filter((row) => row.category === category)
		},

		/**
		 * Put the draft back to what the schema declares.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		reset() {
			this.draftText = this.declaredText
			this.parseError = ''
			this.unknownOperator = ''
			this.$emit('update:draft', null)
		},

		/**
		 * Parse the draft and check every operator against the catalogue.
		 *
		 * Checking here saves a round trip, and it names the operator. The
		 * schema save checks the same tree against the same table, so this
		 * cannot accept something the save would refuse.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		validate() {
			this.parseError = ''
			this.unknownOperator = ''
			this.$emit('update:draft', this.checkedDraft())
		},

		/**
		 * The draft to hand on: the parsed tree when it is filled in, parses,
		 * names only known operators and differs from the declared condition;
		 * null otherwise.
		 *
		 * @return {object|Array|string|number|boolean|null} The draft, or null.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		checkedDraft() {
			if (this.draftText.trim() === '') {
				return null
			}
			const parsed = this.parsedDraft()
			if (parsed === UNPARSABLE) {
				return null
			}
			this.unknownOperator = this.firstUnknownOperator(parsed)
			if (this.unknownOperator !== '') {
				return null
			}
			return this.dirty ? parsed : null
		},

		/**
		 * The draft text as JSON; on a parse failure the reason is shown and
		 * UNPARSABLE comes back.
		 *
		 * @return {object|Array|string|number|boolean|null|symbol} The parsed draft.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		parsedDraft() {
			try {
				return JSON.parse(this.draftText)
			} catch (failure) {
				this.parseError = invalidJsonText(failure)
				return UNPARSABLE
			}
		},

		/**
		 * The first operator in the tree that the catalogue does not hold.
		 *
		 * Only JSON-AST nodes are checked. A JSONLogic condition uses
		 * spellings this catalogue does not carry, and flagging those would
		 * tell an author their working legacy rule is broken.
		 *
		 * @param {object|Array|string|number|boolean|null} node The node to walk.
		 *
		 * @return {string} The operator, or an empty string when all are known.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		firstUnknownOperator(node) {
			if (node === null || typeof node !== 'object') {
				return ''
			}
			if (Array.isArray(node)) {
				return this.firstUnknownInList(node)
			}
			return this.firstUnknownInNode(node)
		},

		/**
		 * The first unknown operator in any item of a list.
		 *
		 * @param {Array} items The items to walk.
		 *
		 * @return {string} The operator, or an empty string when all are known.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		firstUnknownInList(items) {
			for (const item of items) {
				const found = this.firstUnknownOperator(item)
				if (found !== '') {
					return found
				}
			}
			return ''
		},

		/**
		 * The first unknown operator under a single-key JSON-AST node. A node
		 * with other than one key, a JSONLogic node and a literal are not
		 * walked.
		 *
		 * @param {object} node The node to walk.
		 *
		 * @return {string} The operator, or an empty string when all are known.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		firstUnknownInNode(node) {
			const keys = Object.keys(node)
			if (keys.length !== 1) {
				return ''
			}
			const op = keys[0]
			if (!this.isAstSpelling(op)) {
				// A JSONLogic node. Its own dialect validates it.
				return ''
			}
			if (op === 'lit') {
				return ''
			}
			return this.firstUnknownOperator(node[op])
		},

		/**
		 * Whether a key is spelled the way the JSON AST spells its operators.
		 *
		 * @param {string} op The operator key.
		 *
		 * @return {boolean} True when the catalogue holds it.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		isAstSpelling(op) {
			return this.operators.some((row) => row.op === op)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

.ruleEditor {
	margin-bottom: 24px;
}

.ruleFacts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0 0 20px 0;
}

.ruleFacts dt {
	font-weight: bold;
}

.ruleFacts dd {
	margin: 0;
}

.actionList {
	list-style: none;
	padding: 0;
	margin: 0;
}

@include lead;

textarea {
	width: 100%;
	font-family: monospace;
}

.operatorReference {
	margin-top: 16px;
}

.operatorCategory {
	margin-bottom: 16px;
}

.muted {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.refusal {
	color: var(--color-error-text, var(--color-error));
}

.accepted {
	color: var(--color-success-text, var(--color-text-maxcontrast));
}
</style>
