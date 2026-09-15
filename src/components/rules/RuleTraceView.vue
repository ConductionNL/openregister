<template>
	<div class="ruleTrace">
		<dl>
			<dt>{{ t('openregister', 'Verdict') }}</dt>
			<dd>
				<strong>{{ trace.verdict }}</strong>
				<span v-if="verdictSentence" class="verdictSentence">{{ verdictSentence }}</span>
			</dd>

			<template v-if="trace.operand">
				<dt>{{ t('openregister', 'What decided it') }}</dt>
				<dd>
					<code>{{ trace.operand }}</code>
					<span class="operandValue">{{ t('openregister', 'read as') }} <code>{{ trace.operandValue }}</code></span>
				</dd>
			</template>

			<template v-if="trace.message">
				<dt>{{ t('openregister', 'Message') }}</dt>
				<dd>{{ trace.message }}</dd>
			</template>
		</dl>

		<p v-if="!trace.operand && trace.verdict !== 'fired'" class="muted">
			{{ t('openregister', 'This rule names no single operand. That happens when the condition is a literal, or when the walk could not reach one.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * One trace, rendered as the three facts it carries.
 *
 * It says out loud when there is no deciding operand. An empty field beside
 * "what decided it" reads as a bug in the screen; the sentence says it is a
 * property of the rule.
 */
export default {
	name: 'RuleTraceView',

	props: {
		/** The trace: verdict, operand, operandValue, message. */
		trace: { type: Object, required: true },
		/** The published verdicts, for the sentence beside each one. */
		verdicts: { type: Array, default: () => [] },
	},

	computed: {
		/**
		 * The published sentence for this verdict.
		 *
		 * @return {string} The sentence, or an empty string when the
		 *   vocabulary does not carry this verdict.
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		verdictSentence() {
			const found = this.verdicts.find((row) => row.verdict === this.trace.verdict)
			return found ? found.description : ''
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.ruleTrace dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0 0 12px 0;
}

.ruleTrace dt {
	font-weight: bold;
}

.ruleTrace dd {
	margin: 0;
}

.verdictSentence,
.operandValue {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
