<!--
  The Dutch API design rules (NLGov API Design Rules) report of one register:
  every rule of the pinned ruleset grouped by its result, the findings per rule,
  and, for administrators, a button that probes the instance's responses.
  Reads GET /api/registers/{id}/oas/conformance; probes through
  POST /api/registers/{id}/oas/conformance/probe (administrator only).

  @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('openregister', 'Dutch API design rules for {register}', { register: registerName })"
		size="large"
		:open="true"
		@update:open="onOpenChange">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />
		<div v-else-if="report !== null" class="or-nlgov">
			<p class="or-nlgov__meta" data-testid="nlgov-meta">
				{{ t('openregister', 'Ruleset {ruleset}, generated {moment}.', { ruleset: report.ruleset, moment: momentLabel(report.generatedAt) }) }}
				<template v-if="report.probedAt">
					{{ t('openregister', 'Responses probed {moment}.', { moment: momentLabel(report.probedAt) }) }}
				</template>
			</p>

			<section
				v-for="group in groups"
				:key="group.result"
				class="or-nlgov__group"
				:data-testid="`nlgov-group-${group.result}`">
				<h3 :class="`or-nlgov__heading or-nlgov__heading--${group.result}`">
					{{ group.label }} ({{ group.rules.length }})
				</h3>
				<ul class="or-nlgov__rules">
					<li v-for="rule in group.rules" :key="rule.id" class="or-nlgov__rule">
						<span class="or-nlgov__title">{{ rule.title }}</span>
						<code class="or-nlgov__id">{{ rule.id }}</code>
						<p v-if="rule.deviation" class="or-nlgov__deviation">
							{{ t('openregister', 'Deviation: {reason}', { reason: rule.deviation }) }}
						</p>
						<ul v-if="rule.findings.length > 0" class="or-nlgov__findings">
							<li v-for="(finding, index) in rule.findings" :key="index">
								{{ findingText(finding) }}
							</li>
						</ul>
					</li>
				</ul>
			</section>
		</div>

		<template #actions>
			<NcButton
				v-if="isAdmin"
				:disabled="loading || probing"
				data-testid="nlgov-probe"
				@click="probe">
				{{ t('openregister', 'Probe responses') }}
			</NcButton>
			<NcButton variant="primary" @click="close">
				{{ t('openregister', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * The order the groups show in: what needs work first, what passed last.
 *
 * @type {Array<string>}
 */
export const RESULT_ORDER = ['fail', 'deviation', 'manual', 'not-probed', 'pass']

/**
 * The rules of a report grouped by result, in RESULT_ORDER, empty groups left
 * out; a result outside the order is grouped after the known ones.
 *
 * @param {Array<object>} rules The report's rules
 * @return {Array<{result: string, rules: Array<object>}>} The groups
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
export function groupRules(rules) {
	const byResult = new Map()
	for (const rule of Array.isArray(rules) ? rules : []) {
		const result = String(rule?.result ?? '')
		if (!byResult.has(result)) {
			byResult.set(result, [])
		}
		byResult.get(result).push({
			...rule,
			findings: Array.isArray(rule?.findings) ? rule.findings : [],
		})
	}

	const known = RESULT_ORDER.filter((result) => byResult.has(result))
	const unknown = [...byResult.keys()].filter((result) => !RESULT_ORDER.includes(result))

	return [...known, ...unknown].map((result) => ({ result, rules: byResult.get(result) }))
}

/**
 * A register's Dutch API design rules report in a dialog.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
export default {
	name: 'NlGovConformanceDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		register: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			report: null,
			loading: false,
			probing: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the signed-in user is an administrator; only they may probe.
		 *
		 * @return {boolean} True for an administrator
		 */
		isAdmin() {
			return getCurrentUser()?.isAdmin === true
		},

		/**
		 * The register's name for the title.
		 *
		 * @return {string} The name
		 */
		registerName() {
			return String(this.register?.title || this.register?.slug || this.register?.id || '')
		},

		/**
		 * The report's rules grouped by result, each group with its label.
		 *
		 * @return {Array<{result: string, label: string, rules: Array<object>}>} The groups
		 */
		groups() {
			const labels = {
				fail: t('openregister', 'Failed'),
				deviation: t('openregister', 'Deviations'),
				manual: t('openregister', 'Check by hand'),
				'not-probed': t('openregister', 'Not probed yet'),
				pass: t('openregister', 'Passed'),
			}

			return groupRules(this.report?.rules).map((group) => ({
				...group,
				label: labels[group.result] ?? group.result,
			}))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * The report route of this register.
		 *
		 * @param {string} suffix A path after `/conformance`
		 * @return {string} The URL
		 */
		reportUrl(suffix = '') {
			return generateUrl('/apps/openregister/api/registers/{id}/oas/conformance' + suffix, { id: this.register.id })
		},

		/**
		 * Read the report.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(this.reportUrl())
				this.report = response.data ?? null
			} catch {
				this.report = null
				this.error = t('openregister', 'The conformance report could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Probe the instance's responses and show the report that includes them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
		 */
		async probe() {
			this.probing = true
			this.error = ''
			try {
				const response = await axios.post(this.reportUrl('/probe'))
				this.report = response.data ?? this.report
			} catch {
				this.error = t('openregister', 'The responses could not be probed.')
			} finally {
				this.probing = false
			}
		},

		/**
		 * One finding as a line: where, then what.
		 *
		 * @param {{path?: string, message?: string}} finding The finding
		 * @return {string} The line
		 */
		findingText(finding) {
			return [finding?.path, finding?.message].filter((part) => part).join(': ')
		},

		/**
		 * A moment in the reader's locale when it parses, as written otherwise.
		 *
		 * @param {string|null} moment The ISO moment
		 * @return {string} The label
		 */
		momentLabel(moment) {
			const text = String(moment ?? '')
			const parsed = new Date(text)
			if (text === '' || Number.isNaN(parsed.getTime())) {
				return text
			}

			return parsed.toLocaleString()
		},

		/**
		 * Close when the dialog is dismissed.
		 *
		 * @param {boolean} open Whether the dialog stays open
		 * @return {void}
		 */
		onOpenChange(open) {
			if (!open) {
				this.close()
			}
		},

		/**
		 * Tell the page to close the dialog.
		 *
		 * @return {void}
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.or-nlgov__meta {
	color: var(--color-text-maxcontrast);
}

.or-nlgov__group {
	margin-block: 12px;
}

.or-nlgov__heading--fail {
	color: var(--color-error-text);
}

.or-nlgov__heading--deviation {
	color: var(--color-warning-text);
}

.or-nlgov__heading--pass {
	color: var(--color-success-text);
}

.or-nlgov__rules {
	margin: 0;
	padding: 0;
	list-style: none;
}

.or-nlgov__rule {
	padding-block: 6px;
	border-bottom: 1px solid var(--color-border);
}

.or-nlgov__id {
	margin-inline-start: 8px;
	color: var(--color-text-maxcontrast);
}

.or-nlgov__deviation {
	margin: 4px 0 0;
	color: var(--color-warning-text);
}

.or-nlgov__findings {
	margin: 4px 0 0;
	padding-inline-start: 20px;
	list-style: disc;
}
</style>
