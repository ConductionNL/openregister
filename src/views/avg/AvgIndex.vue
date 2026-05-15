<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'AVG / Verwerkingsregister') }}
					</h1>
				</div>
				<p>
					{{ t('openregister', 'Manage processing activities, run data-subject access requests, and audit compliance under the EU GDPR / Dutch AVG.') }}
				</p>
			</div>

			<!-- Tab Bar -->
			<div class="avgTabBar">
				<NcButton
					v-for="tab in tabs"
					:key="tab.id"
					:type="activeTab === tab.id ? 'primary' : 'tertiary'"
					@click="activeTab = tab.id">
					<template #icon>
						<component :is="tab.icon" :size="20" />
					</template>
					{{ tab.label }}
				</NcButton>
			</div>

			<!-- Verwerkingsactiviteiten -->
			<section v-if="activeTab === 'activities'">
				<div class="viewActionsBar">
					<div class="viewInfo">
						<span v-if="activities.length" class="viewTotalCount">
							{{ t('openregister', 'Showing {count} processing activities', { count: activities.length }) }}
						</span>
					</div>
					<div class="viewActions">
						<NcButton type="primary" @click="openCreateDialog">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('openregister', 'New activity') }}
						</NcButton>
						<NcButton type="tertiary" :disabled="loading" @click="refreshActivities">
							<template #icon>
								<NcLoadingIcon v-if="loading" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcButton>
					</div>
				</div>

				<div class="tableContainer">
					<NcEmptyContent
						v-if="!activities.length && !loading"
						:name="t('openregister', 'No processing activities yet')"
						:description="t('openregister', 'Create the first verwerkingsactiviteit to start tagging audit-trail rows with their AVG Art 30 attribution.')">
						<template #icon>
							<ShieldLockOutline :size="64" />
						</template>
					</NcEmptyContent>

					<table v-else class="avgTable">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Naam') }}</th>
								<th>{{ t('openregister', 'Code') }}</th>
								<th>{{ t('openregister', 'Rechtsgrond') }}</th>
								<th>{{ t('openregister', 'Bewaartermijn') }}</th>
								<th>{{ t('openregister', 'Status') }}</th>
								<th>{{ t('openregister', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="a in activities" :key="a.uuid">
								<td>{{ a.naam }}</td>
								<td><code v-if="a.code">{{ a.code }}</code></td>
								<td>
									<span class="badge">{{ a.rechtsgrond }}</span>
								</td>
								<td>{{ a.bewaartermijn || '—' }}</td>
								<td>
									<span :class="'badge badge-status-' + a.status">{{ a.status }}</span>
								</td>
								<td>
									<NcActions :force-name="false">
										<NcActionButton @click="openEditDialog(a)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											{{ t('openregister', 'Edit') }}
										</NcActionButton>
										<NcActionButton @click="archiveActivity(a)">
											<template #icon>
												<Archive :size="20" />
											</template>
											{{ t('openregister', 'Archive') }}
										</NcActionButton>
									</NcActions>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<!-- Verantwoording -->
			<section v-else-if="activeTab === 'verantwoording'">
				<div class="viewActionsBar">
					<div class="viewInfo">
						<span v-if="verantwoording" class="viewTotalCount">
							{{ t('openregister', 'Generated: {time}', { time: formatTime(verantwoording.generated) }) }}
						</span>
					</div>
					<div class="viewActions">
						<NcButton type="primary" :disabled="loading" @click="loadVerantwoording">
							<template #icon>
								<NcLoadingIcon v-if="loading" :size="20" />
								<FileDocumentOutline v-else :size="20" />
							</template>
							{{ t('openregister', 'Generate report') }}
						</NcButton>
					</div>
				</div>

				<div class="tableContainer">
					<NcEmptyContent
						v-if="!verantwoording"
						:name="t('openregister', 'Generate the verantwoordingsdocument')"
						:description="t('openregister', 'AVG Art 30 §4: this report joins each processing activity with the lifetime audit-trail counts attributed to it. Auditors and the Autoriteit Persoonsgegevens use this for supervisory review.')">
						<template #icon>
							<FileDocumentOutline :size="64" />
						</template>
					</NcEmptyContent>

					<table v-else class="avgTable">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Activity') }}</th>
								<th>{{ t('openregister', 'Rechtsgrond') }}</th>
								<th>{{ t('openregister', 'Bewaartermijn') }}</th>
								<th>{{ t('openregister', 'Total events') }}</th>
								<th>{{ t('openregister', 'Create') }}</th>
								<th>{{ t('openregister', 'Update') }}</th>
								<th>{{ t('openregister', 'Delete') }}</th>
								<th>{{ t('openregister', 'Read') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in verantwoording.activities" :key="row.uuid">
								<td>{{ row.naam }}</td>
								<td><span class="badge">{{ row.rechtsgrond }}</span></td>
								<td>{{ row.bewaartermijn || '—' }}</td>
								<td><strong>{{ row.activity.totalEvents }}</strong></td>
								<td>{{ row.activity.byAction?.create ?? 0 }}</td>
								<td>{{ row.activity.byAction?.update ?? 0 }}</td>
								<td>{{ row.activity.byAction?.delete ?? 0 }}</td>
								<td>{{ row.activity.byAction?.read ?? 0 }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<!-- DSAR -->
			<section v-else-if="activeTab === 'dsar'">
				<div class="dsarPanel">
					<h2>{{ t('openregister', 'Data-subject access request') }}</h2>
					<p>
						{{ t('openregister', 'Locate every object referencing a data subject (Art 15 inzage), preview an erasure (Art 17 vergetelheid), or export their data (Art 20 portabiliteit).') }}
					</p>

					<div class="dsarForm">
						<NcTextField
							:value.sync="dsar.subject"
							:label="t('openregister', 'Subject identifier (email, BSN, name, etc.)')"
							required />
						<NcTextField
							:value.sync="dsar.type"
							:label="t('openregister', 'Type filter (optional, e.g. email)')" />
						<div class="dsarActions">
							<NcButton type="primary" :disabled="!dsar.subject || loading" @click="runInzage">
								<template #icon>
									<Magnify :size="20" />
								</template>
								{{ t('openregister', 'Inzage (Art 15)') }}
							</NcButton>
							<NcButton type="secondary" :disabled="!dsar.subject || loading" @click="runVergetelheidDryRun">
								<template #icon>
									<EyeOutline :size="20" />
								</template>
								{{ t('openregister', 'Preview erasure') }}
							</NcButton>
							<NcButton type="error" :disabled="!dsar.subject || !dsarSummary || !dsarSummary.matchedCount || loading" @click="confirmVergetelheid">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openregister', 'Erase (Art 17)') }}
							</NcButton>
							<NcButton type="tertiary" :disabled="!dsar.subject || loading" @click="downloadPortabiliteit">
								<template #icon>
									<Download :size="20" />
								</template>
								{{ t('openregister', 'Portabiliteit (Art 20)') }}
							</NcButton>
						</div>
					</div>

					<div v-if="dsarResults" class="dsarResults">
						<h3>{{ t('openregister', 'Inzage results') }} ({{ dsarResults.count }})</h3>
						<NcEmptyContent
							v-if="!dsarResults.count"
							:name="t('openregister', 'No matches')"
							:description="t('openregister', 'No personal data was found for this subject identifier.')">
							<template #icon>
								<MagnifyClose :size="64" />
							</template>
						</NcEmptyContent>
						<ul v-else class="dsarResultsList">
							<li v-for="(entry, i) in dsarResults.results" :key="i">
								<details>
									<summary>
										<code>{{ entry.object?.id ?? entry.object?.uuid }}</code>
										<span class="badge">{{ entry.gdprEntities?.length ?? 0 }} {{ t('openregister', 'PII hits') }}</span>
									</summary>
									<pre class="dsarObjectPayload">{{ JSON.stringify(entry, null, 2) }}</pre>
								</details>
							</li>
						</ul>
					</div>

					<div v-if="dsarSummary" class="dsarSummary">
						<h3>
							<template v-if="dsarSummary.dryRun">
								{{ t('openregister', 'Erasure preview') }}
							</template>
							<template v-else>
								{{ t('openregister', 'Erasure complete') }}
							</template>
						</h3>
						<p>
							{{ t('openregister', '{matched} object(s) matched. {erased} erased.', {
								matched: dsarSummary.matchedCount,
								erased: dsarSummary.erased?.length ?? 0,
							}) }}
						</p>
					</div>
				</div>
			</section>

			<!-- Compliance -->
			<section v-else-if="activeTab === 'compliance'">
				<div class="viewActionsBar">
					<div class="viewInfo">
						<span v-if="complianceReport" class="viewTotalCount">
							{{ t('openregister', '{count} compliance issue(s) detected', {
								count: complianceReport.totals?.unannotatedSchemasWithPii ?? 0,
							}) }}
						</span>
					</div>
					<div class="viewActions">
						<NcButton type="primary" :disabled="loading" @click="loadCompliance">
							<template #icon>
								<NcLoadingIcon v-if="loading" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							{{ t('openregister', 'Run compliance scan') }}
						</NcButton>
					</div>
				</div>

				<div class="tableContainer">
					<NcEmptyContent
						v-if="!complianceReport"
						:name="t('openregister', 'No scan run yet')"
						:description="t('openregister', 'Click Run compliance scan to find schemas where PII has been detected but no processing-activity annotation exists.')">
						<template #icon>
							<ShieldAlertOutline :size="64" />
						</template>
					</NcEmptyContent>

					<NcEmptyContent
						v-else-if="!complianceReport.totals?.unannotatedSchemasWithPii"
						:name="t('openregister', 'All clear')"
						:description="t('openregister', 'Every schema with detected PII has a processing-activity annotation.')">
						<template #icon>
							<CheckCircleOutline :size="64" />
						</template>
					</NcEmptyContent>

					<table v-else class="avgTable">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Schema') }}</th>
								<th>{{ t('openregister', 'Register') }}</th>
								<th>{{ t('openregister', 'PII rows') }}</th>
								<th>{{ t('openregister', 'Schema annotation') }}</th>
								<th>{{ t('openregister', 'Register annotation') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="issue in complianceReport.issues.unannotatedSchemasWithPii" :key="issue.schemaId">
								<td>{{ issue.schemaTitle || issue.schemaId }}</td>
								<td><code>{{ issue.registerId }}</code></td>
								<td><strong>{{ issue.piiCount }}</strong></td>
								<td>
									<span :class="'badge badge-status-' + (issue.schemaHasAnnotation ? 'ok' : 'missing')">
										{{ issue.schemaHasAnnotation ? t('openregister', 'set') : t('openregister', 'missing') }}
									</span>
								</td>
								<td>
									<span :class="'badge badge-status-' + (issue.registerHasAnnotation ? 'ok' : 'missing')">
										{{ issue.registerHasAnnotation ? t('openregister', 'set') : t('openregister', 'missing') }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<EditActivityDialog
				v-if="dialogOpen"
				:activity="dialogActivity"
				@close="closeDialog"
				@saved="onActivitySaved" />
		</div>
	</NcAppContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcAppContent,
	NcButton,
	NcActions,
	NcActionButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'

import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import ShieldAlertOutline from 'vue-material-design-icons/ShieldAlertOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import MagnifyClose from 'vue-material-design-icons/MagnifyClose.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import AccountSearch from 'vue-material-design-icons/AccountSearch.vue'

import { avgStore } from '../../store/store.js'
import EditActivityDialog from '../../dialogs/avg/EditActivityDialog.vue'

export default {
	name: 'AvgIndex',

	components: {
		NcAppContent,
		NcButton,
		NcActions,
		NcActionButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		Plus,
		Pencil,
		Archive,
		Refresh,
		ShieldLockOutline,
		ShieldAlertOutline,
		FileDocumentOutline,
		Magnify,
		MagnifyClose,
		EyeOutline,
		TrashCanOutline,
		Download,
		CheckCircleOutline,
		AccountSearch,
		EditActivityDialog,
	},

	data() {
		return {
			activeTab: 'activities',
			dialogOpen: false,
			dialogActivity: null,
			dsar: {
				subject: '',
				type: '',
			},
		}
	},

	computed: {
		t() {
			return t
		},
		tabs() {
			return [
				{ id: 'activities', label: t('openregister', 'Activities'), icon: 'ShieldLockOutline' },
				{ id: 'verantwoording', label: t('openregister', 'Verantwoording'), icon: 'FileDocumentOutline' },
				{ id: 'dsar', label: t('openregister', 'DSAR'), icon: 'AccountSearch' },
				{ id: 'compliance', label: t('openregister', 'Compliance'), icon: 'ShieldAlertOutline' },
			]
		},
		activities() {
			return avgStore.getActivities ?? []
		},
		verantwoording() {
			return avgStore.getVerantwoording
		},
		dsarResults() {
			return avgStore.getDsarResults
		},
		dsarSummary() {
			return avgStore.getDsarSummary
		},
		complianceReport() {
			return avgStore.getComplianceReport
		},
		loading() {
			return avgStore.isLoading
		},
	},

	mounted() {
		this.refreshActivities()
	},

	methods: {
		formatTime(value) {
			if (!value) return ''
			try {
				return new Date(value).toLocaleString()
			} catch (e) {
				return value
			}
		},

		async refreshActivities() {
			try {
				await avgStore.fetchActivities()
			} catch (e) {
				// surfaced via avgStore.error
			}
		},

		openCreateDialog() {
			this.dialogActivity = null
			this.dialogOpen = true
		},

		openEditDialog(activity) {
			this.dialogActivity = { ...activity }
			this.dialogOpen = true
		},

		closeDialog() {
			this.dialogOpen = false
			this.dialogActivity = null
		},

		onActivitySaved() {
			this.closeDialog()
			this.refreshActivities()
		},

		async archiveActivity(activity) {
			// eslint-disable-next-line no-alert
			if (!confirm(t('openregister', 'Archive this verwerkingsactiviteit? Audit-trail rows will keep referring to it.'))) {
				return
			}
			try {
				await avgStore.archiveActivity(activity.uuid)
				await this.refreshActivities()
			} catch (e) {
				// surfaced via store error
			}
		},

		async loadVerantwoording() {
			try {
				await avgStore.fetchVerantwoording()
			} catch (e) {
				// surfaced via store error
			}
		},

		async runInzage() {
			try {
				await avgStore.runInzage({
					subject: this.dsar.subject,
					type: this.dsar.type || undefined,
				})
			} catch (e) {
				// surfaced via store error
			}
		},

		async runVergetelheidDryRun() {
			try {
				await avgStore.runVergetelheid({
					subject: this.dsar.subject,
					type: this.dsar.type || undefined,
					dryRun: true,
				})
			} catch (e) {
				// surfaced via store error
			}
		},

		async confirmVergetelheid() {
			// eslint-disable-next-line no-alert
			if (!confirm(t('openregister', 'Erase {count} object(s) for this subject? This action is logged in the audit trail.', {
				count: this.dsarSummary?.matchedCount ?? 0,
			}))) {
				return
			}
			try {
				await avgStore.runVergetelheid({
					subject: this.dsar.subject,
					type: this.dsar.type || undefined,
					dryRun: false,
				})
			} catch (e) {
				// surfaced via store error
			}
		},

		async downloadPortabiliteit() {
			try {
				const data = await avgStore.runPortabiliteit({
					subject: this.dsar.subject,
					type: this.dsar.type || undefined,
				})
				if (!data) return
				const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
				const url = URL.createObjectURL(blob)
				const a = document.createElement('a')
				a.href = url
				a.download = `avg-portabiliteit-${this.dsar.subject.replace(/[^a-z0-9]/gi, '-')}.json`
				a.click()
				URL.revokeObjectURL(url)
			} catch (e) {
				// surfaced via store error
			}
		},

		async loadCompliance() {
			try {
				await avgStore.fetchCompliance()
			} catch (e) {
				// surfaced via store error
			}
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
.viewHeader {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}
.viewHeaderTitle {
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.viewHeaderTitleIndented {
	margin: 0;
}
.avgTabBar {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
.viewActionsBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 0;
}
.viewInfo {
	color: var(--color-text-maxcontrast);
}
.viewActions {
	display: flex;
	gap: 8px;
}
.tableContainer {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}
.avgTable {
	width: 100%;
	border-collapse: collapse;
}
.avgTable th,
.avgTable td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
	vertical-align: middle;
}
.avgTable thead {
	background: var(--color-background-hover);
}
.avgTable tbody tr:hover {
	background: var(--color-background-hover);
}
.badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-darker);
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}
.badge-status-published {
	background: var(--color-success);
	color: var(--color-primary-text);
}
.badge-status-archived {
	background: var(--color-warning);
	color: var(--color-primary-text);
}
.badge-status-concept {
	background: var(--color-background-darker);
}
.badge-status-ok {
	background: var(--color-success);
	color: var(--color-primary-text);
}
.badge-status-missing {
	background: var(--color-error);
	color: var(--color-primary-text);
}
.dsarPanel {
	padding: 16px;
}
.dsarForm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;
	margin-bottom: 16px;
}
.dsarActions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
.dsarResults,
.dsarSummary {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
.dsarResultsList {
	list-style: none;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.dsarResultsList details {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}
.dsarResultsList summary {
	cursor: pointer;
	display: flex;
	gap: 8px;
	align-items: center;
}
.dsarObjectPayload {
	white-space: pre-wrap;
	word-break: break-word;
	background: var(--color-main-background);
	padding: 8px;
	border-radius: var(--border-radius);
	max-height: 400px;
	overflow: auto;
}
</style>
