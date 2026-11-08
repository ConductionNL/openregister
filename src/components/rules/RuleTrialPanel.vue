<template>
	<section class="ruleTrial">
		<h3>{{ t('openregister', 'Try this rule') }}</h3>
		<p class="lead">
			{{
				t(
					'openregister',
					'Nothing is written. You get the verdict, and the writes the rule would have made.',
				)
			}}
		</p>

		<fieldset class="trialTarget">
			<legend>{{ t('openregister', 'What to run it against') }}</legend>
			<NcCheckboxRadioSwitch
				v-model="target"
				value="sample"
				name="ruleTrialTarget"
				type="radio">
				{{ t('openregister', 'A sample object I paste here') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="target"
				value="stored"
				name="ruleTrialTarget"
				type="radio">
				{{ t('openregister', 'An object that already exists') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<div v-if="target === 'sample'" class="trialField">
			<label for="ruleTrialSample">{{
				t('openregister', 'Sample object, as JSON')
			}}</label>
			<textarea
				id="ruleTrialSample"
				v-model="sample"
				rows="8"
				spellcheck="false"
				:placeholder="samplePlaceholder" />
			<p v-if="sampleError" class="refusal">
				{{ sampleError }}
			</p>
		</div>

		<div v-else class="trialField">
			<NcTextField
				id="ruleTrialRegister"
				v-model="register"
				:label="t('openregister', 'Register')"
				:placeholder="t('openregister', 'Id, uuid or slug')" />
			<NcTextField
				id="ruleTrialObject"
				v-model="objectId"
				:label="t('openregister', 'Object')"
				:placeholder="t('openregister', 'Id, uuid, slug or uri')" />
		</div>

		<NcButton variant="primary" :disabled="running" @click="run">
			<span v-if="draft === null">{{ t('openregister', 'Run it') }}</span>
			<span v-else>{{ t('openregister', 'Run the draft') }}</span>
		</NcButton>
		<p v-if="draft !== null" class="draftNotice">
			{{
				t(
					'openregister',
					'This runs the condition you edited, not the one the schema declares.',
				)
			}}
		</p>

		<div v-if="result" class="trialResult">
			<h4>{{ t('openregister', 'What happened') }}</h4>
			<RuleTraceView :trace="result.trace" :verdicts="verdicts" />

			<h4>{{ t('openregister', 'What it would have written') }}</h4>
			<p v-if="!hasWrites" class="muted">
				{{ t('openregister', 'Nothing. This rule writes no value.') }}
			</p>
			<CnDataTable
				v-else
				class="writeTable"
				:columns="writeColumns"
				:rows="writeRows">
				<template #column-id="{ value }">
					<code>{{ value }}</code>
				</template>
				<template #column-value="{ value }">
					<code>{{ renderValue(value) }}</code>
				</template>
			</CnDataTable>
		</div>

		<p v-if="error" class="refusal">
			{{ error }}
		</p>
	</section>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import RuleTraceView from './RuleTraceView.vue'
import { evaluateRule, messageFor } from '../../services/rules.js'

/**
 * The dry run, with the trace shown rather than summarised.
 *
 * The operand that decided is the whole reason this panel exists. A panel that
 * showed only "did not match" would answer the question an administrator can
 * already answer by looking at the object.
 */
export default {
	name: 'RuleTrialPanel',

	components: {
		CnDataTable,
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
		RuleTraceView,
	},

	props: {
		/** Schema id, uuid or slug. */
		schema: { type: [String, Number], required: true },
		/** The derived rule id. */
		ruleId: { type: String, required: true },
		/** The published verdicts, for the sentence beside each one. */
		verdicts: { type: Array, default: () => [] },
		/**
		 * An unsaved condition to try in the declared one's place.
		 *
		 * Null means "try the rule as the schema declares it". The editor
		 * emits null the moment the draft matches the declaration again, so a
		 * trial can never silently be about something other than what is on
		 * screen.
		 */
		draft: { type: [Object, Array], default: null },
	},

	data() {
		return {
			target: 'sample',
			sample: '',
			register: '',
			objectId: '',
			running: false,
			result: null,
			error: '',
			sampleError: '',
		}
	},

	computed: {
		/**
		 * A placeholder that shows the shape rather than describing it.
		 *
		 * @return {string} The placeholder.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		samplePlaceholder() {
			return '{\n  "ontvangstdatum": "2026-01-01",\n  "bedrag": 900\n}'
		},

		/**
		 * Whether the trial reported any write at all.
		 *
		 * @return {boolean} True when there is a write to show.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		hasWrites() {
			return this.result?.writes && Object.keys(this.result.writes).length > 0
		},

		/**
		 * The write table's columns: the property and the value written.
		 *
		 * @return {Array<object>} The CnDataTable columns
		 */
		writeColumns() {
			return [
				{ key: 'id', label: t('openregister', 'Property') },
				{ key: 'value', label: t('openregister', 'Value') },
			]
		},

		/**
		 * The writes as table rows, keyed by property.
		 *
		 * @return {Array<object>} One `{ id, value }` per write
		 */
		writeRows() {
			return Object.entries(this.result?.writes || {}).map(([id, value]) => ({ id, value }))
		},
	},

	watch: {
		/**
		 * A different rule is being read, so the previous result is not about it.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		ruleId() {
			this.result = null
			this.error = ''
		},

		/**
		 * The condition changed, so a result left on screen is about the old one.
		 *
		 * That is the worst thing this panel could show, so it is cleared.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		draft() {
			this.result = null
		},
	},

	methods: {
		t,

		/**
		 * Render one value for the table without hiding its type.
		 *
		 * @param {object|Array|string|number|boolean|null} value The value the rule would write.
		 *
		 * @return {string} The rendered value.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		renderValue(value) {
			if (value === null || value === undefined) {
				return 'null'
			}
			if (typeof value === 'object') {
				return JSON.stringify(value)
			}
			return String(value)
		},

		/**
		 * Evaluate the rule and show what it would have done.
		 *
		 * The sample is parsed here rather than sent as a string, so a typo in
		 * the JSON is named as a typo instead of arriving at the server as an
		 * empty object and coming back as "it did not match".
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		async run() {
			this.error = ''
			this.sampleError = ''
			this.result = null

			const payload = this.trialPayload()
			if (payload === null) {
				return
			}

			this.running = true
			try {
				this.result = await evaluateRule(this.schema, this.ruleId, payload)
				if (this.result.ok === false) {
					this.error =
						this.result.error?.message
						|| t('openregister', 'The rule could not be tried.')
					this.result = null
				}
			} catch (failure) {
				this.error = messageFor(
					failure,
					t('openregister', 'The rule could not be tried.'),
				)
			} finally {
				this.running = false
			}
		},

		/**
		 * The payload for a trial: the parsed sample or the stored object, plus
		 * the unsaved draft when there is one.
		 *
		 * @return {object|null} The payload, or null when the sample is not valid JSON
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		trialPayload() {
			const payload =
				this.target === 'sample'
					? this.samplePayload()
					: { register: this.register, objectId: this.objectId }
			if (payload !== null && this.draft !== null) {
				payload.rule = { expression: this.draft }
			}
			return payload
		},

		/**
		 * The sample as an object to try the rule on. An empty sample is an
		 * empty object; a sample that does not parse sets the sample error.
		 *
		 * @return {object|null} The payload, or null when the sample is not valid JSON
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		samplePayload() {
			try {
				return {
					object: this.sample.trim() === '' ? {} : JSON.parse(this.sample),
				}
			} catch (parseFailure) {
				this.sampleError = t(
					'openregister',
					'That is not valid JSON: {reason}',
					{
						reason: parseFailure.message,
					},
				)
				return null
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

.ruleTrial {
	margin-bottom: 24px;
}

@include lead;

.trialTarget {
	border: none;
	padding: 0;
	margin-bottom: 12px;
}

.trialField {
	margin-bottom: 12px;
}

.trialField textarea {
	width: 100%;
	font-family: monospace;
}

.refusal {
	color: var(--color-error-text, var(--color-error));
}

.draftNotice {
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
