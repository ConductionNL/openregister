<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  The run page: /apps/openregister/flow-runs/{uuid}.

  This address used to be a resolver that replaced itself with the flow
  editor. The editor's sidebar shows a run in a narrow column and does not
  mark the failed step, so a failure was hard to read. The run now has its
  own page: what happened, where it stopped, step by step, with the
  objects, tasks, context and log. "Show on the canvas" still opens the
  editor at /flows/{flowId}?run={uuid}, the replay the resolver led to.

  Retry and resume are offered in exactly the states the API accepts them:
  retry on a finished run, resume on a suspended one. The server still
  decides, and a refusal is shown in its own words.

  A run uuid that does not resolve says so here rather than bouncing to
  the dashboard: a stale link in a ticket is easier to diagnose at its own
  address.

  @spec openspec/changes/flow-and-run-detail-pages/specs/flow-and-run-detail-pages/spec.md
-->
<template>
	<NcAppContent>
		<div class="runPage">
			<NcLoadingIcon v-if="loading" :size="32" />

			<NcEmptyContent
				v-else-if="!run"
				:name="t('openregister', 'No such run')"
				:description="
					t(
						'openregister',
						'The run does not exist, or it is not yours to see. Deleting a flow deletes its runs.',
					)
				">
				<template #icon>
					<PlayCircleOutline :size="64" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<nav
					class="runPage__crumbs"
					:aria-label="t('openregister', 'Breadcrumb')">
					<router-link to="/flows">
						{{ t('openregister', 'Flows') }}
					</router-link>
					<span aria-hidden="true"> / </span>
					<template v-if="run.flowId">
						<router-link :to="overviewRoute">
							{{ flowName }}
						</router-link>
						<span aria-hidden="true"> / </span>
					</template>
					<span aria-current="page">{{
						t('openregister', 'Run {id}', { id: shortUuid(run.uuid) })
					}}</span>
				</nav>

				<header class="runPage__header">
					<div class="runPage__title">
						<h1 data-testid="run-detail-title">
							{{
								t('openregister', 'Run of {flow}', {
									flow: flowName,
								})
							}}
						</h1>
						<div class="runPage__badges">
							<CnStatusBadge
								data-testid="run-detail-status"
								:label="statusLabel(run.status)"
								:variant="statusVariant(run.status)"
								size="small" />
							<CnStatusBadge
								v-if="versionLabel"
								:label="versionLabel"
								variant="info"
								size="small" />
							<span class="runPage__muted">{{
								formatDateTime(run.created)
							}}</span>
						</div>
					</div>
					<div class="runPage__actions">
						<NcButton
							v-if="run.flowId"
							data-testid="run-detail-canvas"
							:to="canvasRoute">
							{{ t('openregister', 'Show on the canvas') }}
						</NcButton>
						<NcButton
							v-if="canResume"
							data-testid="run-detail-resume"
							:disabled="busy"
							@click="resume">
							{{ t('openregister', 'Resume') }}
						</NcButton>
						<NcButton
							v-if="canRetry"
							variant="primary"
							data-testid="run-detail-retry"
							:disabled="busy"
							@click="retry">
							{{ t('openregister', 'Retry') }}
						</NcButton>
					</div>
				</header>

				<NcNoteCard v-if="actionError" type="error">
					{{ actionError }}
				</NcNoteCard>

				<NcNoteCard
					v-if="failure"
					type="error"
					data-testid="run-detail-failure">
					<p class="runPage__alertTitle">
						{{ failure.title }}
					</p>
					<p v-if="failure.error" class="runPage__alertText">
						{{ failure.error }}
					</p>
				</NcNoteCard>

				<section class="runCard" aria-labelledby="run-summary-heading">
					<div class="runCard__head">
						<h2 id="run-summary-heading">
							{{ t('openregister', 'About this run') }}
						</h2>
					</div>
					<dl class="runCard__body runFacts">
						<div>
							<dt>{{ t('openregister', 'Started') }}</dt>
							<dd>{{ formatDateTime(run.created) }}</dd>
						</div>
						<div v-if="finished && run.updated">
							<dt>{{ t('openregister', 'Ended') }}</dt>
							<dd>{{ formatDateTime(run.updated) }}</dd>
						</div>
						<div v-if="durationMs !== null">
							<dt>{{ t('openregister', 'Duration') }}</dt>
							<dd>{{ formatDuration(durationMs) }}</dd>
						</div>
						<div v-if="run.trigger">
							<dt>{{ t('openregister', 'How it started') }}</dt>
							<dd>{{ triggerLabel(run.trigger) }}</dd>
						</div>
						<div v-if="run.triggeredBy">
							<dt>{{ t('openregister', 'Started by') }}</dt>
							<dd>{{ run.triggeredBy }}</dd>
						</div>
						<div v-if="run.runAs">
							<dt>{{ t('openregister', 'Ran as') }}</dt>
							<dd>{{ run.runAs }}</dd>
						</div>
						<div v-if="run.subjectUuid">
							<dt>{{ t('openregister', 'About') }}</dt>
							<dd class="mono">
								{{ run.subjectUuid }}
							</dd>
						</div>
						<div v-if="run.parentRunUuid">
							<dt>{{ t('openregister', 'Part of run') }}</dt>
							<dd class="mono">
								<router-link
									:to="{
										name: 'flow-run-detail',
										params: { uuid: String(run.parentRunUuid) },
									}">
									{{ shortUuid(run.parentRunUuid) }}
								</router-link>
							</dd>
						</div>
						<div v-if="run.resumeAt">
							<dt>{{ t('openregister', 'Wakes up at') }}</dt>
							<dd>{{ formatDateTime(run.resumeAt) }}</dd>
						</div>
						<div v-if="run.correlationKey">
							<dt>{{ t('openregister', 'Correlation key') }}</dt>
							<dd class="mono">
								{{ run.correlationKey }}
							</dd>
						</div>
					</dl>
				</section>

				<section class="runCard">
					<div
						class="runTabs"
						role="tablist"
						:aria-label="t('openregister', 'Run details')">
						<button
							v-for="tab in tabs"
							:id="'run-tab-' + tab.id"
							:key="tab.id"
							type="button"
							role="tab"
							class="runTabs__tab"
							:aria-selected="activeTab === tab.id ? 'true' : 'false'"
							:aria-controls="'run-panel-' + tab.id"
							:tabindex="activeTab === tab.id ? 0 : -1"
							:data-testid="'run-tab-' + tab.id"
							@click="activeTab = tab.id"
							@keydown.right.prevent="moveTab(1)"
							@keydown.left.prevent="moveTab(-1)">
							{{ tab.label }}
						</button>
					</div>

					<div
						:id="'run-panel-' + activeTab"
						role="tabpanel"
						:aria-labelledby="'run-tab-' + activeTab"
						class="runPanel">
						<ol
							v-if="activeTab === 'steps'"
							class="runSteps"
							data-testid="run-detail-steps">
							<li
								v-if="steps.length === 0"
								class="runSteps__row runSteps__row--empty">
								{{
									t('openregister', 'This run recorded no steps.')
								}}
							</li>
							<li
								v-for="step in steps"
								:key="step.nodeId"
								class="runSteps__row"
								:class="{
									'runSteps__row--failed':
										step.status === 'failed',
									'runSteps__row--skipped': !step.reached,
								}">
								<span
									class="runSteps__dot"
									:class="'runSteps__dot--' + dotKind(step)"
									aria-hidden="true">
									{{ dotSymbol(step) }}
								</span>
								<div class="runSteps__main">
									<div>
										<strong>{{ step.label }}</strong>
										<CnStatusBadge
											v-if="
												step.reached
												&& step.status
												&& step.status !== 'completed'
											"
											class="runSteps__badge"
											:label="statusLabel(step.status)"
											:variant="statusVariant(step.status)"
											size="small" />
										<div class="runSteps__meta">
											<template v-if="!step.reached">
												{{
													t('openregister', 'Not reached')
												}}
											</template>
											<template v-else>
												<span v-if="step.itemsIn !== null">{{
													t(
														'openregister',
														'Items in: {count}',
														{ count: step.itemsIn },
													)
												}}</span>
												<span
													v-if="step.itemsOut !== null"
													>{{
														t(
															'openregister',
															'Items out: {count}',
															{ count: step.itemsOut },
														)
													}}</span
												>
												<span v-if="step.times > 1">{{
													t(
														'openregister',
														'Times run: {count}',
														{ count: step.times },
													)
												}}</span>
											</template>
										</div>
									</div>
									<div
										v-if="step.status === 'failed'"
										class="runSteps__io">
										<div v-if="step.input">
											<p class="runSteps__term">
												{{ t('openregister', 'Input') }}
											</p>
											<pre>{{ pretty(step.input) }}</pre>
										</div>
										<div>
											<p class="runSteps__term">
												{{ t('openregister', 'Error') }}
											</p>
											<pre>{{ step.error }}</pre>
										</div>
									</div>
									<details
										v-else-if="
											step.input || step.output || step.error
										"
										class="runSteps__more">
										<summary>
											{{
												t(
													'openregister',
													'Show input and output',
												)
											}}
										</summary>
										<div class="runSteps__io">
											<div v-if="step.input">
												<p class="runSteps__term">
													{{ t('openregister', 'Input') }}
												</p>
												<pre>{{ pretty(step.input) }}</pre>
											</div>
											<div v-if="step.output">
												<p class="runSteps__term">
													{{ t('openregister', 'Output') }}
												</p>
												<pre>{{ pretty(step.output) }}</pre>
											</div>
											<div v-if="step.error">
												<p class="runSteps__term">
													{{ t('openregister', 'Note') }}
												</p>
												<pre>{{ step.error }}</pre>
											</div>
										</div>
									</details>
								</div>
								<span class="runSteps__duration">{{
									formatDuration(step.durationMs)
								}}</span>
							</li>
						</ol>

						<div
							v-else-if="activeTab === 'objects'"
							class="runCard__body">
							<p v-if="subjects.length === 0" class="runPage__muted">
								{{
									t(
										'openregister',
										'This run is not about a particular object.',
									)
								}}
							</p>
							<ul v-else class="runList">
								<li
									v-for="subject in subjects"
									:key="subject.key + subject.uuid">
									<span class="runPage__muted">{{
										subject.where
									}}</span>
									<span class="mono">{{ subject.uuid }}</span>
								</li>
							</ul>
						</div>

						<div v-else-if="activeTab === 'tasks'" class="runCard__body">
							<p v-if="tasks.length === 0" class="runPage__muted">
								{{ t('openregister', 'This run raised no tasks.') }}
							</p>
							<ul v-else class="runList">
								<li v-for="task in tasks" :key="task.uuid">
									<router-link
										:to="{
											name: 'flow-task-detail',
											params: { uuid: String(task.uuid) },
										}">
										{{ task.title || task.uuid }}
									</router-link>
									<span class="runPage__muted">{{
										task.state
									}}</span>
									<span
										v-if="task.assignee"
										class="runPage__muted"
										>{{ task.assignee }}</span
									>
								</li>
							</ul>
						</div>

						<div
							v-else-if="activeTab === 'context'"
							class="runCard__body">
							<pre>{{ pretty(run.context) }}</pre>
						</div>

						<div v-else class="runCard__body">
							<pre>{{ pretty(run.log) }}</pre>
						</div>
					</div>
				</section>

				<section class="runCard" aria-labelledby="run-changed-heading">
					<div class="runCard__head">
						<h2 id="run-changed-heading">
							{{ t('openregister', 'Changed by this run') }}
						</h2>
					</div>
					<div class="runCard__body">
						<p v-if="changedCount === 0" class="runPage__muted">
							{{ t('openregister', 'This run changed no objects.') }}
						</p>
						<div
							v-for="group in changed"
							v-else
							:key="group.node"
							class="runChanged">
							<h3>{{ stepNameFor(group.node) }}</h3>
							<ul class="runList">
								<li
									v-for="object in group.objects"
									:key="object.auditUuid || object.objectUuid">
									<span>{{ object.action }}</span>
									<span class="runPage__muted"
										>{{ object.register }} ›
										{{ object.schema }}</span
									>
									<span class="mono">{{ object.objectUuid }}</span>
								</li>
							</ul>
						</div>
					</div>
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import {
	catalogNames,
	failedStep,
	formatDateTime,
	formatDuration,
	nodeLabel,
	orderedNodes,
	RUN_FAILED_STATUSES,
	RUN_TERMINAL_STATUSES,
	runDuration,
	shortUuid,
	statusLabel,
	statusVariant,
	stepRows,
	triggerLabel,
} from './flowDetail.js'

export default {
	name: 'FlowRunDetail',

	components: {
		CnStatusBadge,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		PlayCircleOutline,
	},

	props: {
		/**
		 * The run uuid, from the route.
		 */
		uuid: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			busy: false,
			run: null,
			flow: null,
			versions: [],
			names: {},
			changed: [],
			tasks: [],
			activeTab: 'steps',
			actionError: '',
		}
	},

	computed: {
		ordered() {
			return orderedNodes(this.flow?.nodes, this.flow?.edges)
		},

		steps() {
			return stepRows(this.run, this.ordered, this.names)
		},

		flowName() {
			return this.flow?.name || shortUuid(this.run?.flowId)
		},

		overviewRoute() {
			return {
				name: 'flow-overview',
				params: { id: String(this.run?.flowId) },
			}
		},

		canvasRoute() {
			return { path: `/flows/${this.run?.flowId}`, query: { run: this.uuid } }
		},

		finished() {
			return RUN_TERMINAL_STATUSES.includes(this.run?.status)
		},

		canRetry() {
			return this.finished
		},

		canResume() {
			return this.run?.status === 'suspended'
		},

		durationMs() {
			return runDuration(this.run)
		},

		versionLabel() {
			const version = this.run?.flowVersion
			if (version === null || version === undefined) {
				return ''
			}
			const match = this.versions.find((row) => row.version === version)
			return 'v' + (match?.semver || version)
		},

		/**
		 * The failure alert: the step a failed run stopped at, or the run's
		 * own error when its log names no failed step.
		 *
		 * @spec openspec/changes/flow-and-run-detail-pages/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
		 * @return {{title: string, error: string}|null} The alert, or null.
		 */
		failure() {
			if (!this.run) {
				return null
			}
			const failed = RUN_FAILED_STATUSES.includes(this.run.status)
			const step = failedStep(this.run, this.ordered, this.names)
			if (step) {
				const title =
					step.position !== null
						? t('openregister', 'Stopped at step {position}, {step}', {
								position: step.position,
								step: step.label,
							})
						: t('openregister', 'Stopped at {step}', {
								step: step.label,
							})
				return { title, error: step.error }
			}
			if (failed || this.run.error) {
				return {
					title: t('openregister', 'This run failed'),
					error: this.run.error || '',
				}
			}
			return null
		},

		/**
		 * The objects the run is about: its trigger subject and any subjects
		 * it declared by role.
		 *
		 * @return {Array<{key: string, uuid: string, where: string}>} The subjects.
		 */
		subjects() {
			const rows = []
			const seen = new Set()
			const add = (key, uuid, register, schema) => {
				if (!uuid || seen.has(uuid)) {
					return
				}
				seen.add(uuid)
				rows.push({
					key,
					uuid,
					where: register && schema ? `${register} › ${schema}` : key,
				})
			}
			add(
				'subject',
				this.run?.subjectUuid,
				this.run?.subjectRegister,
				this.run?.subjectSchema,
			)
			for (const [role, subject] of Object.entries(this.run?.subjects || {})) {
				add(role, subject?.uuid, subject?.register, subject?.schema)
			}
			return rows
		},

		changedCount() {
			return this.changed.reduce(
				(sum, group) => sum + (group.objects?.length || 0),
				0,
			)
		},

		tabs() {
			return [
				{ id: 'steps', label: t('openregister', 'Steps') },
				{ id: 'objects', label: t('openregister', 'Objects') },
				{ id: 'tasks', label: t('openregister', 'Tasks') },
				{ id: 'context', label: t('openregister', 'Context') },
				{ id: 'log', label: t('openregister', 'Raw log') },
			]
		},
	},

	watch: {
		// A link between two runs reuses this component rather than
		// remounting it, so loading only in `created` would leave the second
		// link showing the first run.
		uuid: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		formatDateTime,
		formatDuration,
		shortUuid,
		statusLabel,
		statusVariant,
		triggerLabel,

		/**
		 * Read the run, then its flow, version names, objects and tasks.
		 *
		 * Only the run is required. The rest fill panels; a panel that cannot
		 * load stays empty rather than hiding the run.
		 *
		 * @spec openspec/changes/flow-and-run-detail-pages/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.actionError = ''
			this.activeTab = 'steps'
			try {
				const response = await axios.get(
					generateUrl(`/apps/openregister/api/flow-runs/${this.uuid}`),
				)
				this.run = response?.data || null
			} catch {
				// 404 for a run that is gone, 401 without a session; both mean
				// the same thing to the person holding the link.
				this.run = null
				this.loading = false
				return
			}

			const flowId = this.run?.flowId
			const [flow, versions, catalog, objects, tasks] = await Promise.all([
				flowId
					? this.quietGet(`/apps/openregister/api/flows/${flowId}`)
					: null,
				flowId
					? this.quietGet(
							`/apps/openregister/api/flows/${flowId}/versions`,
						)
					: null,
				this.quietGet('/apps/openregister/api/flow/node-catalog'),
				this.quietGet(
					`/apps/openregister/api/flow-runs/${this.uuid}/objects`,
				),
				this.quietGet('/apps/openregister/api/flow-tasks', {
					runUuid: this.uuid,
					scope: 'all',
				}),
			])
			this.flow = flow
			this.versions = versions?.results || []
			this.names = catalogNames(catalog?.results)
			this.changed = objects?.nodes || []
			this.tasks = (tasks?.results || []).filter(
				(task) => String(task?.runUuid || '') === String(this.uuid),
			)
			this.loading = false
		},

		/**
		 * A GET whose failure leaves its panel empty.
		 *
		 * @param {string} path The app path.
		 * @param {object} params Query parameters.
		 * @return {Promise<object|null>} The response body, or null.
		 */
		async quietGet(path, params = undefined) {
			try {
				const response = await axios.get(
					generateUrl(path),
					params ? { params } : undefined,
				)
				return response?.data ?? null
			} catch {
				return null
			}
		},

		/**
		 * Start the run again from the beginning and open the new run.
		 *
		 * @spec openspec/changes/flow-and-run-detail-pages/specs/flow-and-run-detail-pages/spec.md#requirement-run-actions-follow-what-the-api-accepts
		 * @return {Promise<void>}
		 */
		async retry() {
			await this.act(async () => {
				const response = await axios.post(
					generateUrl(
						`/apps/openregister/api/flow-runs/${this.uuid}/retry`,
					),
					{},
				)
				const next = response?.data?.uuid
				if (next) {
					this.$router.push({
						name: 'flow-run-detail',
						params: { uuid: String(next) },
					})
				}
			})
		},

		/**
		 * Wake a suspended run, then read it again.
		 *
		 * @spec openspec/changes/flow-and-run-detail-pages/specs/flow-and-run-detail-pages/spec.md#requirement-run-actions-follow-what-the-api-accepts
		 * @return {Promise<void>}
		 */
		async resume() {
			await this.act(async () => {
				await axios.post(
					generateUrl(
						`/apps/openregister/api/flow-runs/${this.uuid}/resume`,
					),
					{},
				)
				await this.load()
			})
		},

		/**
		 * Run one action, and show the server's refusal in its own words.
		 *
		 * @param {() => Promise<void>} action The action.
		 * @return {Promise<void>}
		 */
		async act(action) {
			this.busy = true
			this.actionError = ''
			try {
				await action()
			} catch (error) {
				this.actionError =
					error?.response?.data?.error
					|| t(
						'openregister',
						'That did not work. Try again, or open the run on the canvas.',
					)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Move the selected tab with the arrow keys, wrapping at the ends.
		 *
		 * @param {number} step 1 for the next tab, -1 for the previous.
		 * @return {void}
		 */
		moveTab(step) {
			const ids = this.tabs.map((tab) => tab.id)
			const index =
				(ids.indexOf(this.activeTab) + step + ids.length) % ids.length
			this.activeTab = ids[index]
			this.$nextTick(() =>
				document.getElementById('run-tab-' + this.activeTab)?.focus(),
			)
		},

		/**
		 * The name of the step that touched an object, for the changed list.
		 *
		 * @param {string} nodeId The node id.
		 * @return {string} The step label.
		 */
		stepNameFor(nodeId) {
			const node = this.ordered.find(
				(candidate) => candidate.id === nodeId,
			) || { id: nodeId }
			return nodeLabel(node, this.names)
		},

		/**
		 * @param {object} step A step row.
		 * @return {string} ok, failed, waiting or skipped.
		 */
		dotKind(step) {
			if (!step.reached) {
				return 'skipped'
			}
			if (step.status === 'failed') {
				return 'failed'
			}
			if (step.status === 'completed') {
				return 'ok'
			}
			return 'waiting'
		},

		/**
		 * @param {object} step A step row.
		 * @return {string} The symbol inside the step's dot.
		 */
		dotSymbol(step) {
			return { ok: '✓', failed: '!', waiting: '…', skipped: '·' }[
				this.dotKind(step)
			]
		},

		/**
		 * @param {unknown} value Anything JSON can hold.
		 * @return {string} Indented JSON.
		 */
		pretty(value) {
			try {
				return JSON.stringify(value ?? null, null, 2)
			} catch {
				return String(value)
			}
		},
	},
}
</script>

<style scoped>
.runPage {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 5);
	max-width: 1200px;
	padding: calc(var(--default-grid-baseline, 4px) * 6)
		calc(var(--default-grid-baseline, 4px) * 8)
		calc(var(--default-grid-baseline, 4px) * 12);
	box-sizing: border-box;
}

.runPage__crumbs,
.runPage__muted {
	color: var(--color-text-maxcontrast);
}

.runPage__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.runPage__title {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.runPage__title h1 {
	margin: 0;
	font-size: 30px;
	font-weight: 700;
	line-height: 1.2;
}

.runPage__badges,
.runPage__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.runPage__alertTitle {
	margin: 0 0 4px;
	font-weight: 600;
}

.runPage__alertText {
	margin: 0;
	overflow-wrap: anywhere;
}

.runCard {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	min-width: 0;
}

.runCard__head {
	padding: 12px 20px;
	border-bottom: 1px solid var(--color-border);
}

.runCard__head h2 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.runCard__body {
	margin: 0;
	padding: 20px;
}

.runFacts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px 24px;
}

.runFacts dt,
.runSteps__term {
	margin: 0 0 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.runFacts dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.runTabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	padding: 0 12px;
	border-bottom: 1px solid var(--color-border);
}

.runTabs__tab {
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
	padding: 0 16px;
	border: 0;
	border-bottom: 3px solid transparent;
	border-radius: 0;
	background: none;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	cursor: pointer;
}

.runTabs__tab[aria-selected='true'] {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
}

.runSteps {
	margin: 0;
	padding: 0;
	list-style: none;
}

.runSteps__row {
	display: grid;
	grid-template-columns: 28px minmax(0, 1fr) auto;
	gap: 14px;
	align-items: start;
	padding: 14px 20px;
	border-bottom: 1px solid var(--color-border);
}

.runSteps__row:last-child {
	border-bottom: 0;
}

.runSteps__row--empty {
	display: block;
	color: var(--color-text-maxcontrast);
}

.runSteps__row--failed {
	background: var(--color-error-hover, var(--color-background-hover));
}

.runSteps__row--skipped {
	color: var(--color-text-maxcontrast);
}

.runSteps__dot {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	font-size: 13px;
	font-weight: 700;
	color: var(--color-primary-element-text);
}

.runSteps__dot--ok {
	background: var(--color-success);
}

.runSteps__dot--failed {
	background: var(--color-error);
}

.runSteps__dot--waiting {
	background: var(--color-warning);
}

.runSteps__dot--skipped {
	background: var(--color-border-dark);
}

.runSteps__main {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 0;
}

.runSteps__badge {
	margin-inline-start: 6px;
}

.runSteps__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.runSteps__io {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
	gap: 12px;
	margin-top: 8px;
}

.runSteps__duration {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.runList {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.runList li {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: baseline;
}

.runChanged + .runChanged {
	margin-top: 16px;
}

.runChanged h3 {
	margin: 0 0 8px;
	font-size: 15px;
	font-weight: 600;
}

pre {
	margin: 0;
	padding: 12px 14px;
	max-height: 480px;
	overflow: auto;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	line-height: 1.5;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.mono {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

@media (max-width: 600px) {
	.runPage {
		padding: 16px;
	}
}
</style>
