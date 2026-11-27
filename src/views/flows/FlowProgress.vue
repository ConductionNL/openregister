<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  Workflow progress: /apps/openregister/flows/{id}/progress.

  How a flow's work is going over the last 90 days: its runs by status, how
  long a completed run takes, and a row per step with open, overdue and
  completed tasks and their average duration (executions and failures for an
  automatic step). Every number comes from GET /api/flows/{id}/progress, so
  this page and a leaf app's card cannot disagree, and each task count links
  to the task inbox filtered to that flow and step.

  @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-person-sees-the-report-on-the-flow

  @visual exclude a baseline belongs to the live pass (task 4.2's e2e journey);
  the page reuses the flow page styles, which carry their own baselines.
-->
<template>
	<!-- eslint-disable vue/no-undef-components -- registered through pageShellComponents, which the rule cannot follow -->
	<NcAppContent>
		<div class="flowPage">
			<NcLoadingIcon v-if="loading" :size="32" />

			<NcNoteCard v-else-if="error" type="error" data-testid="flow-progress-error">
				{{ t('openregister', 'Could not load the workflow progress.') }}
			</NcNoteCard>

			<template v-else>
				<nav
					class="flowPage__crumbs"
					:aria-label="t('openregister', 'Breadcrumb')">
					<router-link to="/flows">
						{{ t('openregister', 'Flows') }}
					</router-link>
					<span aria-hidden="true"> / </span>
					<router-link :to="{ name: 'flow-overview', params: { id } }">
						{{ flowName }}
					</router-link>
					<span aria-hidden="true"> / </span>
					<span aria-current="page">{{ t('openregister', 'Workflow progress') }}</span>
				</nav>

				<header class="flowPage__header">
					<div class="flowPage__title">
						<h1 data-testid="flow-progress-title">
							{{ t('openregister', 'Workflow progress') }}
						</h1>
						<p class="flowProgress__period">
							{{ t('openregister', 'Period') }}: {{ period }}
						</p>
					</div>
				</header>

				<section class="flowCard" aria-labelledby="flow-progress-runs">
					<div class="flowCard__head">
						<h2 id="flow-progress-runs">
							{{ t('openregister', 'Runs') }}: {{ report.runs.total }}
						</h2>
					</div>
					<ul class="flowProgress__statuses">
						<li v-for="row in statusRows" :key="row.status">
							<CnStatusBadge
								:label="row.label + ': ' + row.count"
								:variant="row.variant"
								size="small" />
						</li>
					</ul>
					<p data-testid="flow-progress-duration">
						{{ t('openregister', 'Average duration') }}:
						{{ runDuration || t('openregister', 'Unknown') }}
					</p>
				</section>

				<section class="flowCard" aria-labelledby="flow-progress-steps">
					<div class="flowCard__head">
						<h2 id="flow-progress-steps">
							{{ t('openregister', 'Steps') }}
						</h2>
					</div>
					<p v-if="report.steps.length === 0">
						{{ t('openregister', 'This flow has no steps yet.') }}
					</p>
					<CnDataTable
						v-else
						:columns="stepColumns"
						:rows="report.steps"
						rowKey="nodeId">
						<template #column-open="{ row }">
							<router-link
								v-if="row.open > 0"
								:to="inbox(row.openTasksHref)"
								:data-testid="'flow-progress-open-' + row.nodeId">
								{{ row.open }}
							</router-link>
							<span v-else>{{ row.open }}</span>
						</template>
						<template #column-overdue="{ row }">
							<router-link
								v-if="row.overdue > 0"
								class="flowProgress__overdue"
								:to="inbox(row.tasksHref)"
								:data-testid="'flow-progress-overdue-' + row.nodeId">
								{{ row.overdue }}
							</router-link>
							<span v-else>{{ row.overdue }}</span>
						</template>
						<template #column-done="{ row }">
							<router-link
								v-if="row.done > 0"
								:to="inbox(row.doneTasksHref)">
								{{ row.done }}
							</router-link>
							<span v-else>{{ row.done }}</span>
						</template>
						<template #column-duration="{ row }">
							{{ duration(row) }}
						</template>
					</CnDataTable>
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { CnDataTable, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { pageShellComponents } from '../pageShell.js'
import { formatDateTime } from './flowDetail.js'
import { inboxRoute, longDuration, runStatusRows, stepDuration } from './flowProgress.js'

export default {
	name: 'FlowProgress',

	components: {
		CnDataTable,
		CnStatusBadge,
		...pageShellComponents,
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
			error: false,
			flowName: '',
			report: { runs: { total: 0, byStatus: {} }, duration: {}, steps: [], window: {} },
		}
	},

	computed: {
		/**
		 * The step table columns.
		 *
		 * @return {Array<object>} The CnDataTable columns.
		 */
		stepColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Step') },
				{ key: 'open', label: t('openregister', 'Open') },
				{ key: 'overdue', label: t('openregister', 'Overdue') },
				{ key: 'done', label: t('openregister', 'Completed') },
				{ key: 'duration', label: t('openregister', 'Average duration') },
				{ key: 'executions', label: t('openregister', 'Runs') },
				{ key: 'failed', label: t('openregister', 'Failed') },
			]
		},

		/**
		 * The runs by status.
		 *
		 * @return {Array<object>} The rows.
		 */
		statusRows() {
			return runStatusRows(this.report.runs.byStatus)
		},

		/**
		 * The average duration of a completed run, or '' when no run could be measured.
		 *
		 * @return {string} The duration.
		 */
		runDuration() {
			return longDuration(this.report.duration.averageSeconds)
		},

		/**
		 * The window the numbers count over.
		 *
		 * @return {string} From and to.
		 */
		period() {
			return formatDateTime(this.report.window.from) + ' – ' + formatDateTime(this.report.window.to)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the flow's name and its progress; either failing shows the error note.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				const base = '/apps/openregister/api/flows/' + encodeURIComponent(this.id)
				const [flow, progress] = await Promise.all([
					axios.get(generateUrl(base)),
					axios.get(generateUrl(base + '/progress')),
				])
				this.flowName = (flow.data && flow.data.name) || this.id
				this.report = { ...this.report, ...(progress.data || {}) }
			} catch {
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * The inbox route behind one of a step's counts.
		 *
		 * @param {string} href The report's link.
		 * @return {object} The router location.
		 */
		inbox(href) {
			return inboxRoute(href)
		},

		/**
		 * A step's average duration.
		 *
		 * @param {object} row The step row.
		 * @return {string} The duration.
		 */
		duration(row) {
			return stepDuration(row)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;
@use './flowPage.scss' as page;

@include page.flow-page('flowPage');
@include page.flow-card('flowCard');

.flowProgress__period {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.flowProgress__statuses {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
}

.flowProgress__overdue {
	color: var(--color-error-text);
	font-weight: bold;
}
</style>
