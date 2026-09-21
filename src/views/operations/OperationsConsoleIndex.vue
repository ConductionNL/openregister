<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Operations') }}
				</h1>
				<p>
					{{
						t(
							'openregister',
							'See what this instance is doing right now. Jobs, notifications and rules, with the failures first.',
						)
					}}
				</p>
			</div>

			<NcLoadingIcon v-if="loading" :size="32" />

			<template v-else>
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

				<section class="paneRow">
					<article
						v-for="pane in panes"
						:key="pane.id"
						class="paneCard"
						:class="{ paneCardAttention: pane.attention > 0 }">
						<h2>{{ paneLabel(pane.id) }}</h2>
						<p class="paneTotal">
							{{ pane.total }}
						</p>
						<p class="paneAttention">
							{{ attentionLine(pane) }}
						</p>
					</article>
				</section>

				<p class="windowNote">
					{{
						n(
							'openregister',
							'Counted over the last hour.',
							'Counted over the last %n hours.',
							windowHours,
						)
					}}
				</p>

				<section class="consoleSection">
					<h2>{{ t('openregister', 'Jobs') }}</h2>

					<NcEmptyContent
						v-if="bulkJobs.length === 0"
						:name="t('openregister', 'No jobs have run yet')"
						:description="
							t(
								'openregister',
								'Start a bulk action and it appears here, with its outcome.',
							)
						">
						<template #icon>
							<CogOutline :size="64" />
						</template>
					</NcEmptyContent>

					<table v-else class="consoleTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Action') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'State') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Progress') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Started by') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Failure') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Do') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="job in bulkJobs" :key="job.id">
								<td>{{ job.action }}</td>
								<td>{{ job.state }}</td>
								<td>{{ job.processed }} / {{ job.total }}</td>
								<td>{{ job.startedBy }}</td>
								<td class="failureCell">
									{{ failureOf(job) }}
								</td>
								<td class="actionCell">
									<NcButton
										v-if="job.actions && job.actions.pause"
										variant="secondary"
										:disabled="acting === job.id"
										@click="act(job, 'pause')">
										{{ t('openregister', 'Pause') }}
									</NcButton>
									<NcButton
										v-if="job.actions && job.actions.resume"
										variant="secondary"
										:disabled="acting === job.id"
										@click="act(job, 'resume')">
										{{ t('openregister', 'Resume') }}
									</NcButton>
									<NcButton
										v-if="job.actions && job.actions.retry"
										variant="secondary"
										:disabled="acting === job.id"
										@click="act(job, 'retry')">
										{{ t('openregister', 'Retry') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>

					<NcNoteCard v-if="unobserved.length > 0" type="warning">
						{{
							n(
								'openregister',
								'%n background job records no outcome, so this list cannot show how it went.',
								'%n background jobs record no outcome, so this list cannot show how they went.',
								unobserved.length,
							)
						}}
						<span class="unobservedNames">{{ unobservedNames }}</span>
					</NcNoteCard>
				</section>

				<section class="consoleSection">
					<h2>{{ t('openregister', 'Notifications') }}</h2>
					<p v-if="notificationPane">
						{{
							t('openregister', 'Sent: {delivered} of {total}.', {
								delivered: notificationPane.delivered,
								total: notificationPane.total,
							})
						}}
						{{
							t('openregister', 'Waiting to go out: {queued}.', {
								queued: notificationPane.queued,
							})
						}}
					</p>
					<NcNoteCard
						v-if="notificationPane && notificationPane.templateGaps > 0"
						type="warning">
						{{
							n(
								'openregister',
								'%n platform event has no text. It fires with nothing to say.',
								'%n platform events have no text. They fire with nothing to say.',
								notificationPane.templateGaps,
							)
						}}
					</NcNoteCard>
					<table
						v-if="notificationOutcomes.length > 0"
						class="consoleTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Outcome') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Dispatches') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="outcome in notificationOutcomes"
								:key="outcome.status">
								<td>{{ outcome.status }}</td>
								<td>{{ outcome.count }}</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="consoleSection">
					<h2>{{ t('openregister', 'Rule runs') }}</h2>

					<NcEmptyContent
						v-if="rulesHoldingAnError.length === 0"
						:name="t('openregister', 'No rule is holding an error')"
						:description="
							t(
								'openregister',
								'A rule that errors shows up here with its message.',
							)
						">
						<template #icon>
							<CogOutline :size="48" />
						</template>
					</NcEmptyContent>

					<table v-else class="consoleTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Rule') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Schema') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Last error') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'When') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="rule in rulesHoldingAnError"
								:key="rule.ruleId">
								<td>{{ rule.ruleId }}</td>
								<td>{{ rule.schemaSlug }}</td>
								<td class="failureCell">
									{{ rule.lastError }}
								</td>
								<td>{{ rule.lastErrorAt }}</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="consoleSection">
					<h2>{{ t('openregister', 'Run history') }}</h2>

					<div class="runFilters">
						<label class="runFilter">
							<span>{{ t('openregister', 'Outcome') }}</span>
							<select v-model="runOutcome" @change="loadRuns">
								<option value="">
									{{ t('openregister', 'Every outcome') }}
								</option>
								<option value="running">
									{{ t('openregister', 'Still running') }}
								</option>
								<option value="completed">
									{{ t('openregister', 'Completed') }}
								</option>
								<option value="failed">
									{{ t('openregister', 'Failed') }}
								</option>
							</select>
						</label>

						<label class="runFilter">
							<span>{{ t('openregister', 'Period') }}</span>
							<select
								v-model.number="runWindowHours"
								@change="loadRuns">
								<option :value="24">
									{{ t('openregister', 'Last day') }}
								</option>
								<option :value="168">
									{{ t('openregister', 'Last week') }}
								</option>
								<option :value="720">
									{{ t('openregister', 'Last month') }}
								</option>
							</select>
						</label>
					</div>

					<NcEmptyContent
						v-if="runs.length === 0"
						:name="t('openregister', 'No run in this period')"
						:description="
							t(
								'openregister',
								'Every recorded run shows up here with how it came out.',
							)
						">
						<template #icon>
							<CogOutline :size="48" />
						</template>
					</NcEmptyContent>

					<table v-else class="consoleTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Job') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Started') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Took') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Outcome') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Reason') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Started by') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="run in runs" :key="run.id">
								<td>{{ run.name }}</td>
								<td>{{ run.started }}</td>
								<td>{{ durationOf(run) }}</td>
								<td>{{ outcomeWords(run.outcome) }}</td>
								<td class="failureCell">
									{{ run.message || '' }}
								</td>
								<td>
									{{
										run.actor
										|| t('openregister', 'The schedule')
									}}
								</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="consoleSection">
					<h2>{{ t('openregister', 'Maintenance') }}</h2>

					<NcNoteCard v-if="maintenance.holds" type="warning">
						{{
							t(
								'openregister',
								'This register is closed. Readers are told: {message}',
								{ message: maintenance.message },
							)
						}}
					</NcNoteCard>

					<div class="maintenanceActions">
						<NcButton
							v-for="action in maintenanceActions"
							:key="action.slug"
							:disabled="acting === action.slug"
							@click="runNow(action.slug)">
							{{ action.label }}
						</NcButton>

						<NcButton
							v-if="maintenance.holds"
							variant="primary"
							:disabled="acting === 'maintenance'"
							@click="leaveMaintenance">
							{{ t('openregister', 'Open the register again') }}
						</NcButton>
						<NcButton
							v-else
							:disabled="acting === 'maintenance'"
							@click="enterMaintenance">
							{{ t('openregister', 'Close for maintenance') }}
						</NcButton>
					</div>

					<p v-if="facts.version" class="factsLine">
						{{
							t(
								'openregister',
								'Version {version}, build {build}, licence {licence}.',
								{
									version: facts.version,
									build: facts.build || '-',
									licence: facts.licence,
								},
							)
						}}
					</p>
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'

/**
 * The operations console.
 *
 * Reads the console's panes and its job rows, and drives the verbs each row
 * says it allows. The verbs are the bulk job resource's own endpoints, not a
 * second copy on the console: one ownership rule, in one place.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */
export default {
	name: 'OperationsConsoleIndex',

	components: {
		CogOutline,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			error: '',
			acting: null,
			panes: [],
			windowHours: 24,
			bulkJobs: [],
			unobserved: [],
			rulesHoldingAnError: [],
			runs: [],
			runOutcome: '',
			runWindowHours: 24,
			maintenance: { holds: false, message: '', actor: null },
			facts: {},
		}
	},

	computed: {
		/**
		 * The notification pane, when the console reported one.
		 *
		 * @return {object|null} The pane.
		 * @spec exclude UI plumbing, picks one pane out of the list the console returned
		 */
		notificationPane() {
			return this.panes.find((pane) => pane.id === 'notifications') || null
		},

		/**
		 * The dispatch outcomes, biggest first.
		 *
		 * @return {Array<object>} Outcome rows.
		 * @spec exclude UI plumbing, reshapes the pane's counts map into table rows
		 */
		notificationOutcomes() {
			const counts = (this.notificationPane || {}).counts || {}

			return Object.keys(counts)
				.map((status) => ({ status, count: counts[status] }))
				.sort((left, right) => right.count - left.count)
		},

		/**
		 * The unobserved jobs, named rather than counted.
		 *
		 * @return {string} The job names.
		 * @spec exclude UI plumbing, joins the names the console already returned
		 */
		unobservedNames() {
			return this.unobserved.map((job) => job.name).join(', ')
		},

		/**
		 * The maintenance actions, with the words a reader sees.
		 *
		 * The slugs are the server's; the words are here, where they can be
		 * translated, and nowhere else.
		 *
		 * @return {Array<object>} The actions.
		 * @spec exclude UI plumbing, pairs the shipped slugs with their labels
		 */
		maintenanceActions() {
			return [
				{
					slug: 'search-index-rebuild',
					label: t('openregister', 'Rebuild the search index'),
				},
				{
					slug: 'cache-clear-and-warm',
					label: t('openregister', 'Clear and warm the cache'),
				},
				{
					slug: 'consistency-check',
					label: t('openregister', 'Check the data'),
				},
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * The words for a pane the backend names by id.
		 *
		 * The backend has no locale, so it returns ids and numbers and the
		 * words live here, where they can be translated.
		 *
		 * @param {string} id Pane id.
		 * @return {string} The label.
		 * @spec exclude UI plumbing, maps a pane id to its translated label
		 */
		paneLabel(id) {
			const labels = {
				jobs: t('openregister', 'Jobs'),
				notifications: t('openregister', 'Notifications'),
				'rule-runs': t('openregister', 'Rule runs'),
			}

			return labels[id] || id
		},

		/**
		 * What a pane wants the reader to do about it.
		 *
		 * @param {object} pane The pane.
		 * @return {string} The line under the number.
		 * @spec exclude UI plumbing, turns a pane's attention count into its line
		 */
		attentionLine(pane) {
			if (pane.attention > 0) {
				return n(
					'openregister',
					'%n needs a look.',
					'%n need a look.',
					pane.attention,
				)
			}

			return t('openregister', 'Nothing to act on.')
		},

		/**
		 * A failed job's reason, or a dash when it did not fail.
		 *
		 * @param {object} job The job row.
		 * @return {string} The reason.
		 * @spec exclude UI plumbing, reads the reason the job record already carries
		 */
		failureOf(job) {
			return (job.report || {}).fatal || '-'
		},

		/**
		 * Load the panes and the job rows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
		 */
		async load() {
			this.loading = true
			this.error = ''

			try {
				const [console_, jobs, ruleRuns] = await Promise.all([
					axios.get(
						generateUrl('/apps/openregister/api/operations/console'),
					),
					axios.get(generateUrl('/apps/openregister/api/operations/jobs')),
					axios.get(
						generateUrl('/apps/openregister/api/operations/rule-runs'),
					),
				])

				this.panes = console_.data.panes || []
				this.windowHours = (console_.data.window || {}).hours || 24
				this.bulkJobs = jobs.data.results || []
				this.unobserved = jobs.data.unobserved || []
				this.rulesHoldingAnError = ruleRuns.data.holdingAnError || []

				await Promise.all([
					this.loadRuns(),
					this.loadMaintenance(),
					this.loadFacts(),
				])
			} catch {
				this.error = t(
					'openregister',
					'The console could not be read. Try again, or check the server log.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run one verb on one job, then read the console again.
		 *
		 * The endpoint is the bulk job resource's own, so the refusal a
		 * caller sees here is the same one the resource gives anybody else.
		 *
		 * @param {object} job The job row.
		 * @param {string} verb One of pause, resume, retry.
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
		 */
		async act(job, verb) {
			this.acting = job.id
			this.error = ''

			try {
				await axios.post(
					generateUrl(
						`/apps/openregister/api/bulk-jobs/${job.id}/${verb}`,
					),
				)
				await this.load()
			} catch (exception) {
				this.error =
					(exception.response || {}).data?.error
					|| t('openregister', 'That did not go through.')
			} finally {
				this.acting = null
			}
		},

		/**
		 * The run history, under the filters the reader chose.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
		 */
		async loadRuns() {
			const query = new URLSearchParams({
				hours: String(this.runWindowHours),
				limit: '50',
			})

			if (this.runOutcome !== '') {
				query.set('outcome', this.runOutcome)
			}

			const answer = await axios.get(
				generateUrl(
					`/apps/openregister/api/operations/runs?${query.toString()}`,
				),
			)

			this.runs = answer.data.results || []
		},

		/**
		 * Whether the instance is closed, and what readers are told.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
		 */
		async loadMaintenance() {
			const answer = await axios.get(
				generateUrl('/apps/openregister/api/operations/maintenance'),
			)

			this.maintenance = answer.data || { holds: false, message: '' }
		},

		/**
		 * The version, build and licence a support call opens with.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
		 */
		async loadFacts() {
			const answer = await axios.get(
				generateUrl('/apps/openregister/api/operations/facts'),
			)

			this.facts = answer.data || {}
		},

		/**
		 * Start a job by hand.
		 *
		 * A refusal is shown as the server worded it, including the run that
		 * holds the job: replacing it with a generic sentence here would take
		 * away the one thing the reader can act on.
		 *
		 * @param {string} slug The job or maintenance action.
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
		 */
		async runNow(slug) {
			this.acting = slug
			this.error = ''

			try {
				await axios.post(
					generateUrl('/apps/openregister/api/operations/run-now'),
					{ job: slug },
				)
				await this.loadRuns()
			} catch (exception) {
				this.error =
					(exception.response || {}).data?.message
					|| t('openregister', 'That did not go through.')
			} finally {
				this.acting = null
			}
		},

		/**
		 * Close the instance, with the message readers are given.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
		 */
		async enterMaintenance() {
			this.acting = 'maintenance'
			this.error = ''

			try {
				const answer = await axios.post(
					generateUrl('/apps/openregister/api/operations/maintenance'),
					{ message: this.maintenance.message || undefined },
				)
				this.maintenance = answer.data
			} catch (exception) {
				this.error =
					(exception.response || {}).data?.message
					|| t('openregister', 'That did not go through.')
			} finally {
				this.acting = null
			}
		},

		/**
		 * Open the instance again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
		 */
		async leaveMaintenance() {
			this.acting = 'maintenance'
			this.error = ''

			try {
				const answer = await axios.delete(
					generateUrl('/apps/openregister/api/operations/maintenance'),
				)
				this.maintenance = answer.data
			} catch (exception) {
				this.error =
					(exception.response || {}).data?.message
					|| t('openregister', 'That did not go through.')
			} finally {
				this.acting = null
			}
		},

		/**
		 * How long a run took, in words.
		 *
		 * @param {object} run The run row.
		 * @return {string} The duration.
		 * @spec exclude UI plumbing, renders a number of milliseconds
		 */
		durationOf(run) {
			if (run.durationMs === null || run.durationMs === undefined) {
				return t('openregister', 'Still running')
			}

			if (run.durationMs < 1000) {
				return `${run.durationMs} ms`
			}

			return `${Math.round(run.durationMs / 100) / 10} s`
		},

		/**
		 * The words for an outcome the backend names by id.
		 *
		 * @param {string} outcome The outcome id.
		 * @return {string} The words.
		 * @spec exclude UI plumbing, translates one of three known ids
		 */
		outcomeWords(outcome) {
			const words = {
				running: t('openregister', 'Still running'),
				completed: t('openregister', 'Completed'),
				failed: t('openregister', 'Failed'),
			}

			return words[outcome] || outcome
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 1200px;
}

.viewHeader h1 {
	margin-bottom: 4px;
}

.paneRow {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-top: 24px;
}

.paneCard {
	flex: 1 1 220px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.paneCardAttention {
	border-color: var(--color-warning);
}

.paneCard h2 {
	margin: 0;
	font-size: 1rem;
}

.paneTotal {
	margin: 8px 0 0;
	font-size: 2rem;
	font-weight: bold;
}

.paneAttention {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.windowNote {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.consoleSection {
	margin-top: 32px;
}

.consoleTable {
	width: 100%;
	border-collapse: collapse;
}

.consoleTable th,
.consoleTable td {
	text-align: start;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.failureCell {
	max-width: 320px;
	overflow-wrap: anywhere;
}

.actionCell {
	display: flex;
	gap: 8px;
}

.unobservedNames {
	display: block;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
}

.runFilters {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-bottom: 12px;
}

.runFilter {
	display: flex;
	flex-direction: column;
	gap: 4px;
	color: var(--color-text-maxcontrast);
}

.maintenanceActions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.factsLine {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
