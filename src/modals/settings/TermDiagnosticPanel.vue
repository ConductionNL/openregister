<!--
  "Try a date": works out, through the timer engine's own code path, when a
  term started at a chosen moment ends under the calendar being edited, and
  lists the days it walked: which counted, and which rule skipped the others.

  Calls POST /api/flow-timers/diagnostic (administrator only) with the
  calendar definition as it stands in the form, so it explains unsaved rules
  too, and stores nothing.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
-->
<template>
	<section
		class="calendar-form__block term-diagnostic"
		data-testid="term-diagnostic">
		<h4>{{ t('openregister', 'Try a date') }}</h4>
		<p class="calendar-form__hint">
			{{
				t(
					'openregister',
					'Works out, without saving or arming anything, when a term that starts at a chosen moment ends under the rules above.',
				)
			}}
		</p>

		<div class="term-diagnostic__fields">
			<NcTextField
				v-model="anchorAt"
				type="datetime-local"
				:label="t('openregister', 'Start moment')"
				data-testid="term-diagnostic-anchor" />
			<NcTextField
				v-model="value"
				type="number"
				min="0"
				:label="t('openregister', 'Length of the term')"
				data-testid="term-diagnostic-value" />
			<NcSelect
				v-model="unit"
				:options="unitOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				:inputLabel="t('openregister', 'Unit')" />
			<NcSelect
				v-model="roll"
				:options="rollOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				:inputLabel="
					t('openregister', 'If it ends on a day nobody works')
				" />
		</div>

		<NcButton
			variant="secondary"
			:disabled="working"
			data-testid="term-diagnostic-run"
			@click="explain">
			{{ t('openregister', 'Calculate') }}
		</NcButton>

		<p
			v-if="error"
			class="calendar-form__hint calendar-form__hint--bad"
			role="status">
			{{ error }}
		</p>

		<div
			v-else-if="result"
			class="term-diagnostic__result"
			data-testid="term-diagnostic-result">
			<p class="term-diagnostic__fires">
				{{
					t('openregister', 'Ends at {moment}', { moment: result.firesAt })
				}}
			</p>
			<p v-if="result.unrolledAt" class="calendar-form__hint">
				{{
					t('openregister', 'Moved from {moment} by {rule}', {
						moment: result.unrolledAt,
						rule: result.rolledBy || kindLabel('weekend'),
					})
				}}
			</p>
			<ul class="calendar-form__preview" data-testid="term-diagnostic-walk">
				<li
					v-for="day in result.walk || []"
					:key="day.date"
					:class="{ 'term-diagnostic__skipped': !day.counted }">
					<span class="calendar-form__date">{{ day.date }}</span>
					<span>{{ kindLabel(day.kind) }}</span>
				</li>
			</ul>
			<p v-if="result.walkTruncated" class="calendar-form__hint">
				{{
					t(
						'openregister',
						'The list shows the first days only; the end moment counts every day.',
					)
				}}
			</p>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'

/**
 * The request the diagnostic takes for a calendar definition and the typed term;
 * null when the start moment or the length is missing.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
 * @param {object} calendar The calendar definition, as the form would save it.
 * @param {{anchorAt: string, value: string|number, unit: string, roll: string}} term The typed term.
 * @return {object|null} The request body.
 */
export function diagnosticRequest(calendar, term) {
	const value = Number(term.value)
	if (
		String(term.anchorAt ?? '').trim() === ''
		|| String(term.value ?? '').trim() === ''
		|| !Number.isFinite(value)
		|| value <= 0
	) {
		return null
	}
	return {
		calendar,
		anchorAt: term.anchorAt,
		sla: { value, unit: term.unit, rollToWorkingDay: term.roll },
	}
}

export default {
	name: 'TermDiagnosticPanel',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * Returns the calendar definition as it stands in the form.
		 */
		definitionOf: {
			type: Function,
			required: true,
		},

		/**
		 * A term to start from, e.g. from a deep link: `{value, unit, rollToWorkingDay}`.
		 */
		initialSla: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			anchorAt: '',
			value: this.initialSla ? String(this.initialSla.value) : '',
			unit: this.initialSla?.unit ?? 'businessDays',
			roll: this.initialSla?.rollToWorkingDay ?? 'none',
			working: false,
			error: '',
			result: null,
		}
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — the unit options.
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		unitOptions() {
			return [
				{ id: 'businessDays', label: t('openregister', 'Business days') },
				{ id: 'calendarDays', label: t('openregister', 'Calendar days') },
				{ id: 'hours', label: t('openregister', 'Hours') },
			]
		},

		/**
		 * @spec exclude UI plumbing — the roll options.
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		rollOptions() {
			return [
				{ id: 'none', label: t('openregister', 'No change') },
				{ id: 'next', label: t('openregister', 'The next working day') },
				{
					id: 'previous',
					label: t('openregister', 'The previous working day'),
				},
			]
		},
	},

	methods: {
		/**
		 * What a walked day was: a working day, the weekend, or the rule's own name.
		 *
		 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
		 * @param {string} kind `working`, `weekend` or a rule name.
		 * @return {string} The label.
		 */
		kindLabel(kind) {
			if (kind === 'working') {
				return t('openregister', 'Working day')
			}
			if (kind === 'weekend') {
				return t('openregister', 'Weekend')
			}
			return String(kind)
		},

		/**
		 * Ask the diagnostic when the term ends and show its walk.
		 *
		 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
		 * @return {Promise<void>}
		 */
		async explain() {
			this.error = ''
			this.result = null
			const body = diagnosticRequest(this.definitionOf(), {
				anchorAt: this.anchorAt,
				value: this.value,
				unit: this.unit,
				roll: this.roll,
			})
			if (body === null) {
				this.error = t(
					'openregister',
					'A start moment and a length are needed.',
				)
				return
			}
			this.working = true
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/flow-timers/diagnostic'),
					body,
				)
				this.result = response.data
			} catch (error) {
				this.error =
					error?.response?.data?.errors?.message
					|| error?.response?.data?.error
					|| t('openregister', 'The term could not be worked out.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.term-diagnostic__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 8px;
	margin-block: 8px;
}

.term-diagnostic__fires {
	font-weight: bold;
}

.term-diagnostic__skipped {
	color: var(--color-text-maxcontrast);
}
</style>
