<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  The flow overview: /apps/openregister/flows/{id}/overview.

  What a flow does, whether it works and what it ran, without opening the
  canvas. The /flows index rows land here (manifest `rowRoute`); the editor
  stays at /flows/{id}, so /flows/new, the editor's save redirect and every
  `?run=` link keep their meaning.

  Everything shown comes from reads that already exist: the flow, its
  versions, the node catalogue for step names, and the run history. The
  health cards describe the runs this page loaded, and say so.

  @spec openspec/specs/flow-and-run-detail-pages/spec.md

  @visual exclude the baseline is task 5.2 of flow-and-run-detail-pages. This
  page was built in a design round, where Playwright runs once at the end of
  the round, after the styling settles; a baseline taken now would be
  re-recorded with every styling pass.
-->
<template>
	<NcAppContent>
		<div class="flowPage">
			<NcLoadingIcon v-if="loading" :size="32" />

			<NcEmptyContent
				v-else-if="!flow"
				:name="t('openregister', 'No such flow')"
				:description="
					t(
						'openregister',
						'The flow does not exist, or it is not yours to see.',
					)
				">
				<template #icon>
					<SitemapOutline :size="64" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<nav
					class="flowPage__crumbs"
					:aria-label="t('openregister', 'Breadcrumb')">
					<router-link to="/flows">
						{{ t('openregister', 'Flows') }}
					</router-link>
					<span aria-hidden="true"> / </span>
					<span aria-current="page">{{ flow.name }}</span>
				</nav>

				<header class="flowPage__header">
					<div class="flowPage__title">
						<h1 data-testid="flow-overview-name">
							{{ flow.name }}
						</h1>
						<div class="flowPage__badges">
							<CnStatusBadge
								v-if="flow.semver"
								:label="'v' + flow.semver"
								variant="info"
								size="small" />
							<CnStatusBadge
								v-if="flow.lifecycleStatus"
								:label="statusLabel(flow.lifecycleStatus)"
								:variant="statusVariant(flow.lifecycleStatus)"
								size="small" />
							<CnStatusBadge
								:label="
									flow.enabled
										? t('openregister', 'Enabled')
										: t('openregister', 'Disabled')
								"
								:variant="flow.enabled ? 'success' : 'default'"
								size="small" />
							<CnStatusBadge
								v-if="flow.app"
								:label="flow.app"
								size="small" />
						</div>
						<p v-if="flow.description" class="flowPage__description">
							{{ flow.description }}
						</p>
					</div>
					<div class="flowPage__actions">
						<NcButton
							v-if="!flow.owner"
							data-testid="flow-overview-adopt"
							:disabled="busy"
							@click="adopt">
							{{ t('openregister', 'Adopt') }}
						</NcButton>
						<NcButton
							data-testid="flow-overview-run"
							:disabled="busy"
							@click="runNow">
							{{ t('openregister', 'Run now') }}
						</NcButton>
						<NcButton
							data-testid="flow-overview-toggle"
							:disabled="busy"
							@click="toggleEnabled">
							{{
								flow.enabled
									? t('openregister', 'Disable')
									: t('openregister', 'Enable')
							}}
						</NcButton>
						<NcButton
							variant="primary"
							data-testid="flow-overview-editor"
							:to="editorRoute">
							{{ t('openregister', 'Open in editor') }}
						</NcButton>
					</div>
				</header>

				<NcNoteCard v-if="actionError" type="error">
					{{ actionError }}
				</NcNoteCard>

				<section
					class="flowPage__health"
					:aria-label="t('openregister', 'Health')">
					<div class="flowCard flowCard--stat">
						<p class="flowCard__term">
							{{ t('openregister', 'Last run') }}
						</p>
						<template v-if="lastRun">
							<p class="flowCard__value flowCard__value--row">
								<CnStatusBadge
									:label="statusLabel(lastRun.status)"
									:variant="statusVariant(lastRun.status)"
									size="small" />
								<router-link :to="runRoute(lastRun)">
									{{ formatDateTime(lastRun.created) }}
								</router-link>
							</p>
							<p
								v-if="lastRun.error"
								class="flowCard__note flowCard__note--error">
								{{ lastRun.error }}
							</p>
						</template>
						<p v-else class="flowCard__value">
							{{ t('openregister', 'Not run yet') }}
						</p>
					</div>
					<div v-if="counts.total > 0" class="flowCard flowCard--stat">
						<p class="flowCard__term">
							{{ t('openregister', 'Recent runs') }}
						</p>
						<p class="flowCard__value flowCard__value--big">
							{{
								t(
									'openregister',
									'{completed} of {total} completed',
									{
										completed: counts.completed,
										total: counts.total,
									},
								)
							}}
						</p>
						<p class="flowCard__note">
							{{
								t(
									'openregister',
									'{failed} failed, {stopped} stopped',
									{
										failed: counts.failed,
										stopped: counts.stopped,
									},
								)
							}}
						</p>
					</div>
					<div v-if="averageMs !== null" class="flowCard flowCard--stat">
						<p class="flowCard__term">
							{{ t('openregister', 'Average duration') }}
						</p>
						<p class="flowCard__value flowCard__value--big">
							{{ formatDuration(averageMs) }}
						</p>
						<p class="flowCard__note">
							{{ t('openregister', 'Sum of the step durations') }}
						</p>
					</div>
				</section>

				<section class="flowCard" aria-labelledby="flow-steps-heading">
					<div class="flowCard__head">
						<h2 id="flow-steps-heading">
							{{ t('openregister', 'Steps') }}
						</h2>
						<router-link :to="editorRoute">
							{{ t('openregister', 'Open in editor') }}
						</router-link>
					</div>
					<div class="flowCard__body flowSteps">
						<p v-if="steps.length === 0" class="flowCard__note">
							{{ t('openregister', 'This flow has no steps yet.') }}
						</p>
						<ol
							v-else
							class="flowSteps__list"
							data-testid="flow-overview-steps">
							<li
								v-for="(node, index) in steps"
								:key="node.id"
								class="flowSteps__item">
								<span
									v-if="index > 0"
									class="flowSteps__arrow"
									aria-hidden="true"
									>→</span
								>
								<span
									class="flowSteps__step"
									:class="{
										'flowSteps__step--trigger': index === 0,
									}">
									<strong>{{ nodeLabel(node, names) }}</strong>
									<span>{{ nodeDetail(node) }}</span>
								</span>
							</li>
						</ol>
					</div>
				</section>

				<div class="flowPage__pair">
					<section class="flowCard" aria-labelledby="flow-setup-heading">
						<div class="flowCard__head">
							<h2 id="flow-setup-heading">
								{{ t('openregister', 'How it runs') }}
							</h2>
							<router-link :to="editorRoute">
								{{ t('openregister', 'Edit settings') }}
							</router-link>
						</div>
						<dl class="flowCard__body flowFacts">
							<div>
								<dt>{{ t('openregister', 'Starts when') }}</dt>
								<dd>{{ triggerLabel(flow.trigger) }}</dd>
							</div>
							<div v-if="watching">
								<dt>{{ t('openregister', 'Watching') }}</dt>
								<dd>{{ watching }}</dd>
							</div>
							<div v-if="flow.cron">
								<dt>{{ t('openregister', 'Schedule') }}</dt>
								<dd class="mono">
									{{ flow.cron }}
								</dd>
							</div>
							<div v-if="flow.executionMode">
								<dt>{{ t('openregister', 'Runs') }}</dt>
								<dd>
									{{
										flow.executionMode === 'sync'
											? t(
													'openregister',
													'Straight away, while the change is saved',
												)
											: t('openregister', 'In the background')
									}}
								</dd>
							</div>
							<div v-if="flow.owner">
								<dt>{{ t('openregister', 'Owner') }}</dt>
								<dd>{{ flow.owner }}</dd>
							</div>
							<div
								v-if="
									flow.retentionDays !== null
									&& flow.retentionDays !== undefined
								">
								<dt>
									{{
										t(
											'openregister',
											'Run history kept, in days',
										)
									}}
								</dt>
								<dd>{{ flow.retentionDays }}</dd>
							</div>
							<div
								v-if="
									flow.auditEnabled !== null
									&& flow.auditEnabled !== undefined
								">
								<dt>{{ t('openregister', 'Audit trail') }}</dt>
								<dd>
									{{
										flow.auditEnabled
											? t('openregister', 'On')
											: t('openregister', 'Off')
									}}
								</dd>
							</div>
						</dl>
					</section>

					<section
						class="flowCard"
						aria-labelledby="flow-versions-heading">
						<div class="flowCard__head">
							<h2 id="flow-versions-heading">
								{{ t('openregister', 'Versions') }}
							</h2>
							<NcButton
								v-if="flow.lifecycleStatus === 'published'"
								data-testid="flow-overview-draft"
								:disabled="busy"
								@click="createDraft">
								{{ t('openregister', 'Create draft') }}
							</NcButton>
						</div>
						<p
							v-if="versions.length === 0"
							class="flowCard__body flowCard__note">
							{{
								t(
									'openregister',
									'No version has been published yet.',
								)
							}}
						</p>
						<table v-else class="flowTable">
							<thead>
								<tr>
									<th scope="col">
										{{ t('openregister', 'Version') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Status') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Published') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr
									v-for="version in versions"
									:key="version.id || version.version">
									<td>v{{ version.semver || version.version }}</td>
									<td>
										<CnStatusBadge
											:label="statusLabel(version.status)"
											:variant="statusVariant(version.status)"
											size="small" />
									</td>
									<td>
										<template
											v-if="
												version.publishedAt
												&& version.publishedBy
											">
											{{
												t(
													'openregister',
													'{date} by {person}',
													{
														date: formatDateTime(
															version.publishedAt,
														),
														person: version.publishedBy,
													},
												)
											}}
										</template>
										<template v-else>
											{{ formatDateTime(version.publishedAt) }}
										</template>
									</td>
								</tr>
							</tbody>
						</table>
					</section>
				</div>

				<section class="flowCard" aria-labelledby="flow-runs-heading">
					<div class="flowCard__head flowCard__head--wrap">
						<h2 id="flow-runs-heading">
							{{ t('openregister', 'Runs') }}
						</h2>
						<div
							class="flowPage__filters"
							role="group"
							:aria-label="t('openregister', 'Filter runs by status')">
							<NcButton
								v-for="option in filterOptions"
								:key="option.id"
								:pressed="runFilter === option.id"
								:data-testid="'flow-runs-filter-' + option.id"
								@update:pressed="setFilter(option.id)">
								{{ option.label }}
							</NcButton>
						</div>
					</div>
					<p
						v-if="pagedRuns.length === 0"
						class="flowCard__body flowCard__note">
						{{ t('openregister', 'No runs match this filter.') }}
					</p>
					<div v-else class="flowTable__scroll">
						<table class="flowTable" data-testid="flow-overview-runs">
							<thead>
								<tr>
									<th scope="col">
										{{ t('openregister', 'Status') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Started') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Duration') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'About') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Stopped at') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Version') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="run in pagedRuns" :key="run.uuid">
									<td>
										<CnStatusBadge
											:label="statusLabel(run.status)"
											:variant="statusVariant(run.status)"
											size="small" />
									</td>
									<td>
										<router-link :to="runRoute(run)">
											{{ formatDateTime(run.created) }}
										</router-link>
									</td>
									<td>{{ formatDuration(runDuration(run)) }}</td>
									<td class="mono" :title="run.subjectUuid || ''">
										{{ shortUuid(run.subjectUuid) }}
									</td>
									<td>{{ stoppedAt(run) }}</td>
									<td>{{ versionLabel(run.flowVersion) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div class="flowCard__foot">
						<span>
							{{
								t('openregister', 'Showing {shown} of {total}', {
									shown: pagedRuns.length,
									total: filteredRuns.length,
								})
							}}
						</span>
						<span class="flowCard__paging">
							<NcButton :disabled="page === 0" @click="page--">
								{{ t('openregister', 'Previous page') }}
							</NcButton>
							<NcButton
								:disabled="
									(page + 1) * pageSize >= filteredRuns.length
								"
								@click="page++">
								{{ t('openregister', 'Next page') }}
							</NcButton>
						</span>
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
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import {
	averageDuration,
	catalogNames,
	failedStep,
	formatDateTime,
	formatDuration,
	matchesRunFilter,
	nodeDetail,
	nodeLabel,
	orderedNodes,
	runCounts,
	runDuration,
	shortUuid,
	statusLabel,
	statusVariant,
	triggerLabel,
} from './flowDetail.js'

/**
 * How many runs the overview reads. The history endpoint caps a page at 200;
 * a run carries its whole log, so the overview stays well under that.
 */
export const RUN_HISTORY_LIMIT = 100

export default {
	name: 'FlowOverview',

	components: {
		CnStatusBadge,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		SitemapOutline,
	},

	props: {
		/**
		 * The flow id, from the route.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			busy: false,
			flow: null,
			versions: [],
			names: {},
			runs: [],
			runFilter: 'all',
			page: 0,
			pageSize: 10,
			actionError: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 */
		steps() {
			return orderedNodes(this.flow?.nodes, this.flow?.edges)
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		counts() {
			return runCounts(this.runs)
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		averageMs() {
			return averageDuration(this.runs)
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		lastRun() {
			return this.runs[0] || null
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 */
		watching() {
			const register = this.flow?.triggerRegister
			const schema = this.flow?.triggerSchema
			return register && schema ? `${register} › ${schema}` : ''
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 */
		editorRoute() {
			return `/flows/${this.id}`
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		filterOptions() {
			return [
				{ id: 'all', label: t('openregister', 'All') },
				{ id: 'failed', label: t('openregister', 'Failed') },
				{ id: 'completed', label: t('openregister', 'Completed') },
				{ id: 'running', label: t('openregister', 'Running') },
			]
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		filteredRuns() {
			return this.runs.filter((run) => matchesRunFilter(run, this.runFilter))
		},

		/**
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		pagedRuns() {
			const start = this.page * this.pageSize
			return this.filteredRuns.slice(start, start + this.pageSize)
		},
	},

	watch: {
		id: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		formatDateTime,
		formatDuration,
		nodeDetail,
		nodeLabel,
		runDuration,
		shortUuid,
		statusLabel,
		statusVariant,
		triggerLabel,

		/**
		 * Read the flow, its versions, the node names and its runs.
		 *
		 * Only the flow itself is required. The other three fill panels, and a
		 * panel that cannot load shows empty rather than hiding the flow.
		 *
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.actionError = ''
			try {
				const response = await axios.get(
					generateUrl(`/apps/openregister/api/flows/${this.id}`),
				)
				this.flow = response?.data || null
			} catch {
				this.flow = null
				this.loading = false
				return
			}

			const [versions, catalog] = await Promise.all([
				this.quietGet(`/apps/openregister/api/flows/${this.id}/versions`),
				this.quietGet('/apps/openregister/api/flow/node-catalog'),
				this.loadRuns(),
			])
			this.versions = versions?.results || []
			this.names = catalogNames(catalog?.results)
			this.loading = false
		},

		/**
		 * Read this flow's run history, newest first.
		 *
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 * @return {Promise<void>}
		 */
		async loadRuns() {
			const data = await this.quietGet('/apps/openregister/api/flow-runs', {
				flowId: this.flow?.uuid || this.id,
				limit: RUN_HISTORY_LIMIT,
			})
			this.runs = data?.results || []
			this.page = 0
		},

		/**
		 * A GET whose failure leaves its panel empty.
		 *
		 * @param {string} path The app path.
		 * @param {object} params Query parameters.
		 * @return {Promise<object|null>} The response body, or null.
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
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
		 * Where a run's own page lives.
		 *
		 * @param {object} run The run.
		 * @return {object} The route.
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		runRoute(run) {
			return { name: 'flow-run-detail', params: { uuid: String(run.uuid) } }
		},

		/**
		 * The step a run stopped at, in a few words, for the runs table.
		 *
		 * @param {object} run The run.
		 * @return {string} "Step: error", the run's error, or an empty string.
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		stoppedAt(run) {
			const step = failedStep(run, this.steps, this.names)
			if (step) {
				return step.error ? `${step.label}: ${step.error}` : step.label
			}
			return run.error || ''
		},

		/**
		 * The semantic version a run executed, read from the version list.
		 *
		 * @param {number|null} version The run's numeric flow version.
		 * @return {string} "v1.0.0", or "v1" when the version is not listed.
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		versionLabel(version) {
			if (version === null || version === undefined) {
				return ''
			}
			const match = this.versions.find((row) => row.version === version)
			return 'v' + (match?.semver || version)
		},

		/**
		 * Pick a runs-table filter and go back to the first page.
		 *
		 * @param {string} filter The filter id.
		 * @return {void}
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
		 */
		setFilter(filter) {
			this.runFilter = filter
			this.page = 0
		},

		/**
		 * Start the flow by hand and open the run it started.
		 *
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 * @return {Promise<void>}
		 */
		async runNow() {
			await this.act(async () => {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/flows/${this.id}/run`),
					{},
				)
				const uuid = response?.data?.uuid
				if (uuid) {
					this.$router.push({
						name: 'flow-run-detail',
						params: { uuid: String(uuid) },
					})
					return
				}
				await this.loadRuns()
			})
		},

		/**
		 * Make the signed-in user the owner of a flow that has none. A shipped
		 * flow arrives without an owner and cannot run until somebody adopts
		 * it; the run refusal says so, and this is where it can be done.
		 *
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 * @return {Promise<void>}
		 */
		async adopt() {
			await this.act(async () => {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/flows/${this.id}/adopt`),
					{},
				)
				this.flow = { ...this.flow, ...(response?.data || {}) }
			})
		},

		/**
		 * Switch the flow on or off. Only `enabled` is sent: the server treats
		 * it as a setting, not a change to the published definition.
		 *
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 * @return {Promise<void>}
		 */
		async toggleEnabled() {
			await this.act(async () => {
				const response = await axios.put(
					generateUrl(`/apps/openregister/api/flows/${this.id}`),
					{
						enabled: !this.flow.enabled,
					},
				)
				this.flow = {
					...this.flow,
					...(response?.data || {}),
					enabled: response?.data?.enabled ?? !this.flow.enabled,
				}
			})
		},

		/**
		 * Open a draft of the published version and take the author to it.
		 *
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 * @return {Promise<void>}
		 */
		async createDraft() {
			await this.act(async () => {
				await axios.post(
					generateUrl(`/apps/openregister/api/flows/${this.id}/draft`),
					{},
				)
				this.$router.push(this.editorRoute)
			})
		},

		/**
		 * Run one action, and show the server's refusal in its own words.
		 *
		 * @param {() => Promise<void>} action The action.
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
		 */
		async act(action) {
			this.busy = true
			this.actionError = ''
			try {
				await action()
			} catch (error) {
				this.actionError =
					error?.response?.data?.error
					|| t('openregister', 'That did not work. Try again.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.flowPage {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 5);
	max-width: 1200px;
	padding: calc(var(--default-grid-baseline, 4px) * 6)
		calc(var(--default-grid-baseline, 4px) * 8)
		calc(var(--default-grid-baseline, 4px) * 12);
	box-sizing: border-box;
}

.flowPage__crumbs {
	color: var(--color-text-maxcontrast);
}

.flowPage__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.flowPage__title {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.flowPage__title h1 {
	margin: 0;
	font-size: 30px;
	font-weight: 700;
	line-height: 1.2;
}

.flowPage__badges,
.flowPage__actions,
.flowPage__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.flowPage__description {
	margin: 0;
	max-width: 640px;
	color: var(--color-text-maxcontrast);
}

.flowPage__health {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	gap: 16px;
}

.flowPage__pair {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 20px;
}

.flowCard {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	min-width: 0;
}

.flowCard--stat {
	padding: 16px 20px;
}

.flowCard__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 20px;
	border-bottom: 1px solid var(--color-border);
}

.flowCard__head--wrap {
	flex-wrap: wrap;
}

.flowCard__head h2 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.flowCard__body {
	margin: 0;
	padding: 20px;
}

.flowCard__term,
.flowFacts dt {
	margin: 0 0 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.flowCard__value,
.flowFacts dd {
	margin: 0;
}

.flowCard__value--row {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
}

.flowCard__value--big {
	font-size: 24px;
	font-weight: 600;
}

.flowCard__note {
	margin: 8px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.flowCard__note--error {
	color: var(--color-error-text);
}

.flowCard__foot {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 12px 20px;
	color: var(--color-text-maxcontrast);
}

.flowCard__paging {
	display: flex;
	gap: 8px;
}

.flowSteps {
	overflow-x: auto;
}

.flowSteps__list {
	display: flex;
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
	min-width: max-content;
}

.flowSteps__item {
	display: flex;
	align-items: center;
	gap: 10px;
}

.flowSteps__arrow {
	color: var(--color-text-maxcontrast);
	font-size: 18px;
}

.flowSteps__step {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 150px;
	padding: 12px 14px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.flowSteps__step--trigger {
	border-color: var(--color-success);
}

.flowSteps__step span {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.flowFacts {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px 24px;
}

.flowTable__scroll {
	overflow-x: auto;
}

.flowTable {
	width: 100%;
	border-collapse: collapse;
}

.flowTable th {
	text-align: start;
	font-weight: 600;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
	white-space: nowrap;
}

.flowTable td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.mono {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

@media (max-width: 600px) {
	.flowPage {
		padding: 16px;
	}

	.flowFacts {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
