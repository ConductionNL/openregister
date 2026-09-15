<template>
	<div class="state-field-rules-panel">
		<h3>{{ t('openregister', 'Field rules per status') }}</h3>

		<NcEmptyContent
			v-if="stateEntries.length === 0"
			:name="t('openregister', 'No field rules declared')"
			:description="
				t(
					'openregister',
					'Declare x-openregister-lifecycle.states on this schema to hide, freeze or demand a field while an object sits in a status.',
				)
			">
			<template #icon>
				<FormatListChecks :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<p class="state-field-rules-panel__hint">
				{{
					t(
						'openregister',
						'A form reads these rules from @self.fieldRules and renders them. A write that breaks one is refused on save, whichever door it came through.',
					)
				}}
			</p>

			<div
				v-for="entry in stateEntries"
				:key="entry.state"
				class="state-field-rules-panel__state">
				<h4>
					{{ entry.state }}
					<span v-if="entry.enabled === false" class="state-field-rules-panel__off">
						{{ t('openregister', 'switched off') }}
					</span>
				</h4>

				<ul class="state-field-rules-panel__meta">
					<li v-if="entry.entry">
						{{ t('openregister', 'Entry condition: every path into this status is checked.') }}
					</li>
					<li v-if="entry.exit">
						{{ t('openregister', 'Exit condition: every path out of this status is checked.') }}
					</li>
					<li v-if="entry.condition">
						{{ t('openregister', 'The whole block applies only when its condition holds.') }}
					</li>
				</ul>

				<ul v-if="entry.rules.length > 0" class="state-field-rules-panel__rules">
					<li v-for="rule in entry.rules" :key="`${entry.state}-${rule.id}`">
						<span class="state-field-rules-panel__kind">{{ kindLabel(rule.kind) }}</span>
						{{ rule.fields.join(', ') }}
						<span v-if="rule.groups.length > 0" class="state-field-rules-panel__scope">
							{{ t('openregister', 'for {groups}', { groups: rule.groups.join(', ') }) }}
						</span>
						<span v-else class="state-field-rules-panel__scope">
							{{ t('openregister', 'for everyone') }}
						</span>
						<span v-if="rule.conditional" class="state-field-rules-panel__scope">
							{{ t('openregister', 'when its condition holds') }}
						</span>
					</li>
				</ul>
			</div>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'

/**
 * Read-only view of a schema's per-status field rules.
 *
 * There is no CRUD here on purpose, the same call TaskSequencePanel makes: the
 * declaration on the schema is the one authoring surface, and OpenRegister
 * refuses a rule naming a field or status that does not exist at schema save.
 * What this panel adds is the reading nobody could do before: which status
 * freezes what, for whom, and whether a condition narrows it.
 *
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */
export default {
	name: 'StateFieldRulesPanel',
	components: {
		NcEmptyContent,
		FormatListChecks,
	},

	props: {
		schema: { type: Object, required: true },
	},

	computed: {
		/**
		 * The declared statuses that carry rules, normalised for the template.
		 *
		 * @return {Array} One entry per status declaring a rule or a condition.
		 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
		 */
		stateEntries() {
			const states =
				this.schema?.configuration?.['x-openregister-lifecycle']?.states || {}

			return Object.entries(states)
				.filter(([, block]) => block && typeof block === 'object')
				.map(([state, block]) => ({
					state,
					enabled: block.enabled,
					entry: block.entry || null,
					exit: block.exit || null,
					condition: block.condition || null,
					rules: this.rulesOf(block.fields),
				}))
				.filter(
					(entry) =>
						entry.rules.length > 0
						|| entry.entry
						|| entry.exit
						|| entry.condition,
				)
		},
	},

	methods: {
		/**
		 * Flatten a status `fields` block into one list of rules.
		 *
		 * @param {object} fields The declared fields block.
		 * @return {Array} One entry per declared rule.
		 */
		rulesOf(fields) {
			if (!fields || typeof fields !== 'object') {
				return []
			}

			const rules = []
			for (const kind of ['hidden', 'readOnly', 'required']) {
				const entries = fields[kind]
				if (!Array.isArray(entries)) {
					continue
				}

				entries.forEach((declared, index) => {
					if (!declared || typeof declared !== 'object') {
						return
					}

					const names = Array.isArray(declared.fields)
						? declared.fields
						: [declared.fields || declared.field].filter(Boolean)
					if (names.length === 0) {
						return
					}

					const groups = Array.isArray(declared.groups)
						? declared.groups
						: [declared.groups].filter(Boolean)

					rules.push({
						id: `${kind}-${index}`,
						kind,
						fields: names,
						groups,
						conditional: Boolean(declared.when || declared.condition),
					})
				})
			}

			return rules
		},

		/**
		 * The sentence a rule kind is shown as.
		 *
		 * @param {string} kind One of hidden, readOnly, required.
		 * @return {string} The translated label.
		 */
		kindLabel(kind) {
			if (kind === 'hidden') {
				return this.t('openregister', 'Hidden')
			}

			if (kind === 'readOnly') {
				return this.t('openregister', 'Read only')
			}

			return this.t('openregister', 'Required')
		},
	},
}
</script>

<style scoped>
.state-field-rules-panel__hint {
	color: var(--color-text-maxcontrast);
}

.state-field-rules-panel__state {
	margin-bottom: 16px;
}

.state-field-rules-panel__off {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.state-field-rules-panel__meta {
	color: var(--color-text-maxcontrast);
	list-style: none;
	padding: 0;
}

.state-field-rules-panel__rules {
	margin-left: 20px;
}

.state-field-rules-panel__kind {
	font-weight: bold;
}

.state-field-rules-panel__scope {
	color: var(--color-text-maxcontrast);
}
</style>
