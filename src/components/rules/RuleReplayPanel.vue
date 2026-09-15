<template>
	<section class="ruleReplay">
		<h3>{{ t('openregister', 'Replay over objects that already exist') }}</h3>
		<p class="lead">
			{{ t('openregister', 'This previews the run. It writes nothing until you commit the job it creates.') }}
		</p>

		<div class="replayField">
			<NcTextField
id="ruleReplayRegister"
				v-model="register"
				:label="t('openregister', 'Register id')"
				:placeholder="t('openregister', 'Leave empty to search every register')" />
			<label for="ruleReplayQuery">{{ t('openregister', 'Which objects, as a JSON query') }}</label>
			<textarea
id="ruleReplayQuery"
				v-model="query"
				rows="5"
				spellcheck="false"
				:placeholder="queryPlaceholder" />
			<NcTextField
id="ruleReplayReason"
				v-model="justification"
				:label="t('openregister', 'Why you are doing this')"
				:placeholder="t('openregister', 'The termijn changed, so every open case needs the new date')" />
		</div>

		<NcButton variant="primary" :disabled="running" @click="run">
			{{ t('openregister', 'Preview the replay') }}
		</NcButton>

		<div v-if="result" class="replayResult">
			<p>
				{{ t('openregister', 'This would touch {count} objects.', { count: result.count }) }}
				<span v-if="result.maxObjects">{{ t('openregister', 'The ceiling on this rule is {max}.', { max: result.maxObjects }) }}</span>
				<span v-else class="muted">{{ t('openregister', 'This rule declares no ceiling.') }}</span>
			</p>
			<p>
				{{ t('openregister', 'Job {id} is waiting for you to commit it, on the bulk jobs page.', { id: result.job.id }) }}
			</p>
		</div>

		<div v-if="refusal" class="refusalBox">
			<p class="refusal">
				{{ refusal.message }}
			</p>
			<p v-if="refusal.count" class="muted">
				{{ t('openregister', 'Nothing was written and no job was created.') }}
			</p>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { messageFor, replayRule } from '../../services/rules.js'

/**
 * The replay, and the one place an operator sees the ceiling refuse.
 *
 * The refusal is rendered as its own block with the two numbers in it, rather
 * than as a red line beside the button. A ceiling that can only be seen in a
 * network log is a ceiling nobody trusts, which is the failure the whole
 * change is about.
 */
export default {
	name: 'RuleReplayPanel',

	components: {
		NcButton,
		NcTextField,
	},

	props: {
		/** Schema id, uuid or slug. */
		schema: { type: [String, Number], required: true },
		/** The derived rule id. */
		ruleId: { type: String, required: true },
	},

	data() {
		return {
			register: '',
			query: '',
			justification: '',
			running: false,
			result: null,
			refusal: null,
		}
	},

	computed: {
		/**
		 * A placeholder showing the query shape rather than describing it.
		 *
		 * @return {string} The placeholder.
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		queryPlaceholder() {
			return '{\n  "status": "open"\n}'
		},
	},

	watch: {
		/**
		 * A different rule is being read, so the previous preview is not about it.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		ruleId() {
			this.result = null
			this.refusal = null
		},
	},

	methods: {
		t,

		/**
		 * Ask for the preview, and show the refusal when there is one.
		 *
		 * A refused run is not an error the screen should swallow: it carries
		 * the count and the ceiling, which are exactly what the operator needs
		 * to decide whether to narrow the filter or raise the ceiling.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
		 */
		async run() {
			this.result = null
			this.refusal = null
			this.running = true

			try {
				let selection = {}
				if (this.query.trim() !== '') {
					selection = { query: JSON.parse(this.query) }
				}

				const payload = { selection, justification: this.justification }
				if (this.register !== '') {
					payload.registerId = this.register
				}

				const data = await replayRule(this.schema, this.ruleId, payload)
				if (data.ok === false) {
					this.refusal = data.error
					return
				}
				this.result = data
			} catch (failure) {
				this.refusal = failure?.response?.data?.error ?? {
					message: messageFor(failure, t('openregister', 'The replay could not be previewed.')),
				}
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.ruleReplay {
	margin-bottom: 24px;
}

.lead {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.replayField {
	margin-bottom: 12px;
}

.replayField textarea {
	width: 100%;
	font-family: monospace;
}

.refusalBox {
	margin-top: 12px;
	padding: 12px;
	border: 2px solid var(--color-error, #c00);
	border-radius: var(--border-radius-large, 8px);
}

.refusal {
	color: var(--color-error-text, var(--color-error));
	margin: 0;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
