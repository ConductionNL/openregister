<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  The one stable task address: /apps/openregister/flow-tasks/{uuid}.

  The VTODO URL, every notification button and the inbox row click all
  resolve this path (flow-task-inbox-projections). The server serves the
  SPA shell on it (TaskController::open), the router mounts this page, and
  this page reads GET /api/flow-tasks/{uuid}: the visibility-checked read
  that answers 404 for a task the caller may not see.

  Verbs follow the widget's offer heuristic (CnTasksWidget, cn-tasks-entity-
  source D-7): claim on a pooled row, complete on the viewer's own open row,
  one entry per declared outcome. The offer is a heuristic; the server still
  authorizes, and a refusal is shown in the server's own words. A watcher
  gets the task and no buttons.
-->
<template>
	<NcAppContent>
		<div class="viewContainer">
			<template v-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<template v-else-if="notFound">
				<NcEmptyContent
					:name="t('openregister', 'No such task')"
					:description="
						t(
							'openregister',
							'The task does not exist, or it is not yours to see.',
						)
					">
					<template #icon>
						<ClipboardListOutline :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else-if="task">
				<div class="viewHeader">
					<h1 data-testid="task-title">
						{{ task.displayTitle || task.title || task.uuid }}
					</h1>
					<p v-if="task.description">{{ task.description }}</p>
				</div>

				<section class="taskFacts">
					<h2>{{ t('openregister', 'Details') }}</h2>
					<table class="taskFactsTable">
						<tbody>
							<tr>
								<th scope="row">{{ t('openregister', 'State') }}</th>
								<td data-testid="task-state">{{ stateLabel }}</td>
							</tr>
							<tr>
								<th scope="row">{{ t('openregister', 'Priority') }}</th>
								<td>{{ priorityLabel }}</td>
							</tr>
							<tr v-if="task.dueAt">
								<th scope="row">{{ t('openregister', 'Due') }}</th>
								<td :class="{ overdue: task.overdue === true }">
									{{ dueLabel }}
								</td>
							</tr>
							<tr>
								<th scope="row">{{ t('openregister', 'Assignee') }}</th>
								<td>{{ assigneeLabel }}</td>
							</tr>
							<tr v-if="task.requester">
								<th scope="row">{{ t('openregister', 'Requested by') }}</th>
								<td>{{ task.requester }}</td>
							</tr>
							<tr v-if="task.appId">
								<th scope="row">{{ t('openregister', 'App') }}</th>
								<td>{{ task.appId }}</td>
							</tr>
							<tr v-if="task.outcome">
								<th scope="row">{{ t('openregister', 'Outcome') }}</th>
								<td>{{ task.outcome }}</td>
							</tr>
							<tr v-if="subjectTitle">
								<th scope="row">{{ t('openregister', 'Subject') }}</th>
								<td>
									<router-link
										v-if="subjectRoute"
										:to="subjectRoute"
										data-testid="task-subject-link">
										{{ subjectTitle }}
									</router-link>
									<template v-else>{{ subjectTitle }}</template>
								</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section v-if="canClaim || canComplete" class="taskActions">
					<NcButton
						v-if="canClaim"
						type="primary"
						data-testid="task-claim"
						:disabled="acting"
						@click="claim">
						{{ t('openregister', 'Claim') }}
					</NcButton>
					<NcButton
						v-for="outcome in completeOutcomes"
						:key="outcomeId(outcome)"
						type="primary"
						data-testid="task-complete"
						:disabled="acting"
						@click="complete(outcomeId(outcome))">
						{{ completeLabel(outcome) }}
					</NcButton>
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'

export default {
	name: 'FlowTaskDetail',

	components: {
		ClipboardListOutline,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			task: null,
			loading: true,
			notFound: false,
			acting: false,
		}
	},

	computed: {
		/**
		 * The lifecycle state, in words. The six CMMN states of
		 * flow-task-entity; an unknown value renders as itself rather than
		 * disappearing.
		 *
		 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-task-surfaces-speak-the-task-vocabulary
		 * @return {string} The state label.
		 */
		stateLabel() {
			const labels = {
				available: this.t('openregister', 'Available'),
				enabled: this.t('openregister', 'Ready to claim'),
				active: this.t('openregister', 'In progress'),
				completed: this.t('openregister', 'Completed'),
				terminated: this.t('openregister', 'Terminated'),
				disabled: this.t('openregister', 'Disabled'),
			}
			return labels[this.task?.state] || String(this.task?.state || '')
		},

		/**
		 * @spec exclude UI wording — maps the stored priority value to a label
		 * @return {string} The priority label.
		 */
		priorityLabel() {
			const labels = {
				low: this.t('openregister', 'Low'),
				normal: this.t('openregister', 'Normal'),
				high: this.t('openregister', 'High'),
				urgent: this.t('openregister', 'Urgent'),
			}
			return labels[this.task?.priority] || String(this.task?.priority || '')
		},

		/**
		 * The due wording, from the SERVER'S derived fields (overdue,
		 * daysOverdue, daysUntilDue), so this cell and any overdue filter can
		 * never disagree about what overdue means. Wording, never colour
		 * alone: the signal survives monochrome and a screen reader.
		 *
		 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
		 * @return {string} The due label.
		 */
		dueLabel() {
			const task = this.task || {}
			if (task.overdue === true) {
				const days = Number(task.daysOverdue)
				if (Number.isFinite(days) && days > 0) {
					return days === 1
						? this.t('openregister', 'Overdue by 1 day')
						: this.t('openregister', 'Overdue by {days} days', { days })
				}
				return this.t('openregister', 'Overdue')
			}
			const until = Number(task.daysUntilDue)
			if (Number.isFinite(until) === false) {
				return this.formatDate(task.dueAt)
			}
			if (until <= 0) {
				return this.t('openregister', 'Due today')
			}
			return until === 1
				? this.t('openregister', 'Due tomorrow')
				: this.t('openregister', 'Due in {days} days', { days: until })
		},

		/**
		 * The assignee as an identity, or the pool wording. Never parsed out
		 * of prose: the row carries the uid.
		 *
		 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-projection-carries-a-real-assignee-not-prose
		 * @return {string} The assignee label.
		 */
		assigneeLabel() {
			if (this.task?.assignee) {
				return String(this.task.assignee)
			}
			return this.t('openregister', 'Unassigned, open for claiming')
		},

		/**
		 * @spec exclude UI plumbing — reads the subject block the row carries
		 * @return {string} The subject's display title, or ''.
		 */
		subjectTitle() {
			const subject = this.task?.subject
			return (subject && (subject.title || subject.uuid)) || ''
		},

		/**
		 * The subject's object-detail route, when the row carries enough to
		 * address it (register, schema, object uuid).
		 *
		 * @spec exclude UI plumbing — composes the objectDetail route from row fields
		 * @return {object|null} A router location, or null.
		 */
		subjectRoute() {
			const subject = this.task?.subject
			if (!subject || !subject.register || !subject.schema || !subject.uuid) {
				return null
			}
			return {
				name: 'objectDetail',
				params: {
					register: String(subject.register),
					schema: String(subject.schema),
					id: String(subject.uuid),
				},
			}
		},

		/**
		 * Whether claim is offered: a pooled, non-terminal row. The same
		 * heuristic CnTasksWidget uses; the server still authorizes.
		 *
		 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-task-surfaces-speak-the-task-vocabulary
		 * @return {boolean} True when claim is offered.
		 */
		canClaim() {
			return !!this.task && !this.task.assignee && this.task.isTerminal !== true
		},

		/**
		 * Whether complete is offered: the viewer's own open row. A watcher
		 * fails this check and sees the task with no buttons.
		 *
		 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-task-surfaces-speak-the-task-vocabulary
		 * @return {boolean} True when complete is offered.
		 */
		canComplete() {
			const uid = getCurrentUser()?.uid || ''
			return uid !== ''
				&& !!this.task
				&& this.task.assignee === uid
				&& this.task.isTerminal !== true
		},

		/**
		 * The outcomes to offer on complete: the row's declared list, else
		 * one entry for the server's default outcome.
		 *
		 * @spec exclude UI plumbing — mirrors CnTasksWidget's outcome fallback
		 * @return {Array<object|string>} The outcome entries.
		 */
		completeOutcomes() {
			if (this.canComplete === false) {
				return []
			}
			const outcomes = this.task?.outcomes
			if (Array.isArray(outcomes) && outcomes.length > 0) {
				return outcomes
			}
			return ['done']
		},
	},

	watch: {
		/**
		 * Reload when the router moves this page to another uuid without
		 * remounting (an in-app navigation between two task links).
		 *
		 * @spec exclude UI plumbing — param-watch reload, no contract of its own
		 */
		'$route.params.uuid'() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the task through the visibility-checked detail route. A 404
		 * renders the not-found state: a caller with no relationship to the
		 * task learns nothing, not even that the uuid exists, and this page
		 * keeps it that way.
		 *
		 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
		 * @return {Promise<void>} Resolves when the read settled.
		 */
		async load() {
			const uuid = String(this.$route.params.uuid || '')
			this.loading = true
			this.notFound = false
			this.task = null
			try {
				const response = await axios.get(
					generateUrl(`/apps/openregister/api/flow-tasks/${uuid}`),
				)
				this.task = response.data
			} catch (error) {
				if (error?.response?.status === 404) {
					this.notFound = true
				} else {
					console.error('[FlowTaskDetail] task read failed', error)
					showError(this.t('openregister', 'Could not load the task'))
					this.notFound = true
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Claim the pooled task, then reload: on success the row moved, and
		 * on a refusal the reload shows the state that refusal proves.
		 *
		 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
		 * @return {Promise<void>} Resolves when the verb settled.
		 */
		claim() {
			return this.postVerb('claim', {})
		},

		/**
		 * Complete the viewer's own task with an outcome.
		 *
		 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
		 * @param {string} outcome The chosen outcome id.
		 * @return {Promise<void>} Resolves when the verb settled.
		 */
		complete(outcome) {
			return this.postVerb('complete', { outcome })
		},

		/**
		 * POST one lifecycle verb and reload either way. The refusal is
		 * shown in the server's own words: the endpoint's error string is
		 * written for people, the raw status line is not.
		 *
		 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
		 * @param {string} verb The lifecycle verb.
		 * @param {object} body The verb body.
		 * @return {Promise<void>} Resolves when the verb settled.
		 */
		async postVerb(verb, body) {
			const uuid = String(this.task?.uuid || '')
			if (uuid === '') {
				return
			}
			this.acting = true
			try {
				await axios.post(
					generateUrl(`/apps/openregister/api/flow-tasks/${uuid}/${verb}`),
					body,
				)
			} catch (error) {
				const message = error?.response?.data?.error
				showError(
					typeof message === 'string' && message !== ''
						? message
						: this.t('openregister', 'The task refused that action'),
				)
			} finally {
				this.acting = false
				await this.load()
			}
		},

		/**
		 * The wire value of one outcome entry (a string, or {id, label}).
		 *
		 * @spec exclude UI plumbing — mirrors CnTasksWidget's outcome shape
		 * @param {object|string} outcome The outcome entry.
		 * @return {string} The outcome id.
		 */
		outcomeId(outcome) {
			if (outcome && typeof outcome === 'object') {
				return String(outcome.id ?? outcome.value ?? outcome.label ?? '')
			}
			return String(outcome ?? '')
		},

		/**
		 * The button label for one complete entry.
		 *
		 * @spec exclude UI wording — labels the complete button per outcome
		 * @param {object|string} outcome The outcome entry.
		 * @return {string} The label.
		 */
		completeLabel(outcome) {
			const id = this.outcomeId(outcome)
			if (id === 'done') {
				return this.t('openregister', 'Complete')
			}
			const label = (outcome && typeof outcome === 'object' && outcome.label)
				? String(outcome.label)
				: id
			return this.t('openregister', 'Complete: {outcome}', { outcome: label })
		},

		/**
		 * A locale date for a due value the temporal projection could not
		 * turn into day counts.
		 *
		 * @spec exclude UI wording — fallback date formatting only
		 * @param {string|null} value The ISO timestamp.
		 * @return {string} The formatted date, or ''.
		 */
		formatDate(value) {
			if (!value) {
				return ''
			}
			const parsed = new Date(value)
			return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 800px;
}

.viewHeader h1 {
	margin-bottom: 4px;
}

.taskFacts {
	margin-top: 24px;
}

.taskFactsTable {
	border-collapse: collapse;
}

.taskFactsTable th {
	text-align: start;
	padding: 6px 24px 6px 0;
	font-weight: 600;
	vertical-align: top;
}

.taskFactsTable td {
	padding: 6px 0;
}

.taskFactsTable td.overdue {
	color: var(--color-error-text);
	font-weight: 600;
}

.taskActions {
	margin-top: 24px;
	display: flex;
	gap: 12px;
}
</style>
