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
					:variant="activeTab === tab.id ? 'primary' : 'tertiary'"
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
						<NcButton variant="primary" @click="openCreateDialog">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('openregister', 'New activity') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="loading" @click="refreshActivities">
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
						<NcButton variant="primary" :disabled="loading" @click="loadVerantwoording">
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
							v-model="dsar.subject"
							:label="t('openregister', 'Subject identifier (email, BSN, name, etc.)')"
							required />
						<NcTextField
							v-model="dsar.type"
							:label="t('openregister', 'Type filter (optional, e.g. email)')" />
						<div class="dsarActions">
							<NcButton variant="primary" :disabled="!dsar.subject || loading" @click="runInzage">
								<template #icon>
									<Magnify :size="20" />
								</template>
								{{ t('openregister', 'Inzage (Art 15)') }}
							</NcButton>
							<NcButton variant="secondary" :disabled="!dsar.subject || loading" @click="runVergetelheidDryRun">
								<template #icon>
									<EyeOutline :size="20" />
								</template>
								{{ t('openregister', 'Preview erasure') }}
							</NcButton>
							<NcButton variant="error" :disabled="!dsar.subject || !dsarSummary || !dsarSummary.matchedCount || loading" @click="confirmVergetelheid">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openregister', 'Erase (Art 17)') }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="!dsar.subject || loading" @click="downloadPortabiliteit">
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
						<NcButton variant="primary" :disabled="loading" @click="loadCompliance">
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

			<!-- Cases (DSAR case management) -->
			<section v-else-if="activeTab === 'cases'">
				<!-- LIST -->
				<template v-if="!selectedCase">
					<div class="viewActionsBar">
						<div class="viewInfo">
							<span v-if="cases.length" class="viewTotalCount">
								{{ t('openregister', 'Showing {shown} of {total} case(s)', { shown: filteredCases.length, total: cases.length }) }}
							</span>
						</div>
						<div class="viewActions">
							<NcButton variant="tertiary" :disabled="loading" @click="refreshCases">
								<template #icon>
									<NcLoadingIcon v-if="loading" :size="20" />
									<Refresh v-else :size="20" />
								</template>
								{{ t('openregister', 'Refresh') }}
							</NcButton>
						</div>
					</div>

					<div class="caseFilters">
						<NcSelect
							v-model="caseFilters.status"
							class="caseFilterSelect"
							:options="statusFilterOptions"
							:label-outside="false"
							:input-label="t('openregister', 'Filter by status')"
							:aria-label-combobox="t('openregister', 'Filter by status')"
							:reduce="(o) => o.value" />
						<NcSelect
							v-model="caseFilters.handler"
							class="caseFilterSelect"
							:options="handlerFilterOptions"
							:label-outside="false"
							:input-label="t('openregister', 'Filter by handler')"
							:aria-label-combobox="t('openregister', 'Filter by handler')"
							:reduce="(o) => o.value" />
						<NcCheckboxRadioSwitch
							v-model="caseFilters.overdue"
							type="switch">
							{{ t('openregister', 'Overdue only') }}
						</NcCheckboxRadioSwitch>
					</div>

					<div class="tableContainer">
						<NcEmptyContent
							v-if="!filteredCases.length && !loading"
							:name="t('openregister', 'No cases')"
							:description="cases.length
								? t('openregister', 'No cases match the current filters. Clear the filters to see the full authorised set.')
								: t('openregister', 'No data-subject-request cases are tracked for you yet.')">
							<template #icon>
								<ClipboardTextClockOutline :size="64" />
							</template>
						</NcEmptyContent>

						<table v-else class="avgTable">
							<thead>
								<tr>
									<th>{{ t('openregister', 'Subject') }}</th>
									<th>{{ t('openregister', 'Type') }}</th>
									<th>{{ t('openregister', 'Status') }}</th>
									<th>{{ t('openregister', 'Handler') }}</th>
									<th>{{ t('openregister', 'Deadline') }}</th>
									<th>{{ t('openregister', 'Escalation') }}</th>
									<th>{{ t('openregister', 'Actions') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="c in filteredCases" :key="caseId(c)">
									<td>{{ c.subjectId || '—' }}</td>
									<td>{{ c.type || '—' }}</td>
									<td><span class="badge">{{ statusLabel(c.status) }}</span></td>
									<td>{{ c.handler || t('openregister', 'Unassigned') }}</td>
									<td>{{ deadlineText(c) }}</td>
									<td>
										<span :class="tierClass(c.escalationTier)">
											<component :is="tierIcon(c.escalationTier)" :size="16" />
											{{ tierLabel(c.escalationTier) }}
										</span>
									</td>
									<td>
										<NcButton variant="tertiary" @click="selectCase(c)">
											<template #icon>
												<OpenInNew :size="20" />
											</template>
											{{ t('openregister', 'Open') }}
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>

				<!-- DETAIL -->
				<template v-else>
					<div class="viewActionsBar">
						<div class="viewInfo">
							<NcButton variant="tertiary" @click="backToList">
								<template #icon>
									<ArrowLeft :size="20" />
								</template>
								{{ t('openregister', 'Back to cases') }}
							</NcButton>
						</div>
						<div class="viewActions">
							<NcButton variant="tertiary" :disabled="loading" @click="reloadSelectedCase">
								<template #icon>
									<NcLoadingIcon v-if="loading" :size="20" />
									<Refresh v-else :size="20" />
								</template>
								{{ t('openregister', 'Refresh') }}
							</NcButton>
						</div>
					</div>

					<NcNoteCard v-if="error" type="error">
						{{ error }}
					</NcNoteCard>

					<div class="caseDetail">
						<div class="caseDetailHeader">
							<h2>{{ selectedCase.subjectId || t('openregister', 'Data-subject request') }}</h2>
							<span class="badge">{{ statusLabel(selectedCase.status) }}</span>
							<span :class="tierClass(selectedCase.escalationTier)">
								<component :is="tierIcon(selectedCase.escalationTier)" :size="16" />
								{{ tierLabel(selectedCase.escalationTier) }}
							</span>
						</div>

						<!-- Deadline / escalation -->
						<div class="caseCard">
							<h3>{{ t('openregister', 'Deadline tracking') }}</h3>
							<dl class="caseFacts">
								<div>
									<dt>{{ t('openregister', 'Type') }}</dt>
									<dd>{{ selectedCase.type || '—' }}</dd>
								</div>
								<div>
									<dt>{{ t('openregister', 'Handler') }}</dt>
									<dd>{{ selectedCase.handler || t('openregister', 'Unassigned') }}</dd>
								</div>
								<div>
									<dt>{{ t('openregister', 'Days remaining') }}</dt>
									<dd>{{ daysRemainingText(selectedCase) }}</dd>
								</div>
								<div>
									<dt>{{ t('openregister', 'Effective deadline') }}</dt>
									<dd>{{ formatTime(selectedCase.extendedUntil || selectedCase.dueAt) || '—' }}</dd>
								</div>
								<div>
									<dt>{{ t('openregister', 'Escalation tier') }}</dt>
									<dd>
										<span :class="tierClass(selectedCase.escalationTier)">
											<component :is="tierIcon(selectedCase.escalationTier)" :size="16" />
											{{ tierLabel(selectedCase.escalationTier) }}
										</span>
									</dd>
								</div>
							</dl>
						</div>

						<!-- Lifecycle transitions -->
						<div class="caseCard">
							<h3>{{ t('openregister', 'Lifecycle') }}</h3>
							<p v-if="!availableTransitions.length" class="caseHint">
								{{ t('openregister', 'No transitions are available from the current state.') }}
							</p>
							<div v-else class="caseActionsRow">
								<NcButton
									v-for="tr in availableTransitions"
									:key="tr.action"
									:variant="tr.action === 'finaliseDenial' ? 'warning' : 'secondary'"
									:disabled="loading || (tr.action === 'finaliseDenial' && !hasRegulatorReference)"
									@click="onTransition(tr)">
									{{ transitionLabel(tr.action) }}
								</NcButton>
							</div>
							<p v-if="finaliseVisible && !hasRegulatorReference" class="caseHint caseHint-warning">
								<AlertCircleOutline :size="16" />
								{{ t('openregister', 'Finalising a denial is blocked until a regulator reference is recorded on the case.') }}
							</p>
						</div>

						<!-- Denial workflow -->
						<div class="caseCard">
							<h3>{{ t('openregister', 'Denial') }}</h3>
							<dl class="caseFacts">
								<div>
									<dt>{{ t('openregister', 'Recorded ground') }}</dt>
									<dd>{{ selectedCase.denialGround ? groundLabel(selectedCase.denialGround) : '—' }}</dd>
								</div>
								<div>
									<dt>{{ t('openregister', 'Regulator reference') }}</dt>
									<dd>{{ selectedCase.regulatorReference || '—' }}</dd>
								</div>
							</dl>
							<div class="caseInlineForm">
								<NcTextField
									v-model="regulatorReferenceDraft"
									:label="t('openregister', 'Record a regulator reference')" />
								<NcButton
									variant="secondary"
									:disabled="loading || !regulatorReferenceDraft"
									@click="recordRegulatorReference">
									{{ t('openregister', 'Save reference') }}
								</NcButton>
							</div>
							<div class="caseActionsRow">
								<NcButton variant="secondary" :disabled="loading" @click="openDenialDialog">
									<template #icon>
										<FileDocumentEditOutline :size="20" />
									</template>
									{{ t('openregister', 'Compose denial') }}
								</NcButton>
							</div>
						</div>

						<!-- Evidence -->
						<div class="caseCard">
							<div class="caseCardHead">
								<h3>{{ t('openregister', 'Evidence') }}</h3>
								<NcButton variant="secondary" :disabled="loading" @click="harvestEvidence">
									<template #icon>
										<NcLoadingIcon v-if="loading" :size="20" />
										<DatabaseSearchOutline v-else :size="20" />
									</template>
									{{ t('openregister', 'Collect evidence') }}
								</NcButton>
							</div>
							<p v-if="!caseEvidence.length" class="caseHint">
								{{ t('openregister', 'No evidence collected yet.') }}
							</p>
							<table v-else class="avgTable">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Source') }}</th>
										<th>{{ t('openregister', 'Status') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(ev, i) in caseEvidence" :key="i">
										<td><code>{{ ev.sourceId || '—' }}</code></td>
										<td><span class="badge">{{ ev.status || '—' }}</span></td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Redactions -->
						<div class="caseCard">
							<div class="caseCardHead">
								<h3>{{ t('openregister', 'Redactions') }}</h3>
								<NcButton variant="secondary" :disabled="loading" @click="openRedactionDialog">
									<template #icon>
										<MarkerIcon :size="20" />
									</template>
									{{ t('openregister', 'Apply redaction') }}
								</NcButton>
							</div>
							<p v-if="!caseRedactions.length" class="caseHint">
								{{ t('openregister', 'No redactions applied.') }}
							</p>
							<table v-else class="avgTable">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Field') }}</th>
										<th>{{ t('openregister', 'Ground') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(r, i) in caseRedactions" :key="i">
										<td><code>{{ r.field }}</code></td>
										<td>{{ groundLabel(r.ground) }}</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Export + seams -->
						<div class="caseCard">
							<h3>{{ t('openregister', 'Export & escalation') }}</h3>
							<div class="caseActionsRow">
								<NcButton variant="primary" :disabled="loading" @click="generateAndDownloadBundle">
									<template #icon>
										<NcLoadingIcon v-if="loading" :size="20" />
										<Download v-else :size="20" />
									</template>
									{{ t('openregister', 'Generate export bundle') }}
								</NcButton>
								<NcButton variant="secondary" :disabled="loading" @click="runVerifyIdentity">
									<template #icon>
										<AccountCheckOutline :size="20" />
									</template>
									{{ t('openregister', 'Verify identity') }}
								</NcButton>
								<NcButton variant="secondary" :disabled="loading" @click="runEscalate">
									<template #icon>
										<BankOutline :size="20" />
									</template>
									{{ t('openregister', 'Escalate to regulator') }}
								</NcButton>
							</div>

							<NcNoteCard v-if="identityResult" :type="seamNoteType(identityResult.status, 'verified')">
								{{ t('openregister', 'Identity verification: {status}', { status: identityResult.status }) }}
								<template v-if="identityResult.message">
									— {{ identityResult.message }}
								</template>
							</NcNoteCard>
							<NcNoteCard v-if="escalateResult" :type="seamNoteType(escalateResult.status, 'escalated')">
								{{ t('openregister', 'Regulator escalation: {status}', { status: escalateResult.status }) }}
								<template v-if="escalateResult.reference">
									— {{ escalateResult.reference }}
								</template>
								<template v-else-if="escalateResult.message">
									— {{ escalateResult.message }}
								</template>
							</NcNoteCard>
						</div>

						<!-- Notes / history -->
						<div v-if="selectedCase.notes" class="caseCard">
							<h3>{{ t('openregister', 'Notes') }}</h3>
							<p class="caseNotes">
								{{ selectedCase.notes }}
							</p>
						</div>
					</div>
				</template>
			</section>

			<EditActivityDialog
				v-if="dialogOpen"
				:activity="dialogActivity"
				@close="closeDialog"
				@saved="onActivitySaved" />

			<DenialComposerDialog
				v-if="denialDialogOpen"
				:case-id="selectedCaseId"
				:pack="activePolicyPack"
				@close="denialDialogOpen = false"
				@drafted="onCaseMutated" />

			<RedactionDialog
				v-if="redactionDialogOpen"
				:case-id="selectedCaseId"
				:pack="activePolicyPack"
				@close="redactionDialogOpen = false"
				@redacted="onCaseMutated" />
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
	NcSelect,
	NcCheckboxRadioSwitch,
	NcNoteCard,
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
import ClipboardTextClockOutline from 'vue-material-design-icons/ClipboardTextClockOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import DatabaseSearchOutline from 'vue-material-design-icons/DatabaseSearchOutline.vue'
import MarkerIcon from 'vue-material-design-icons/Marker.vue'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import BankOutline from 'vue-material-design-icons/BankOutline.vue'

import { avgStore } from '../../store/store.js'
import {
	CASE_LIFECYCLE_TRANSITIONS,
	CASE_STATUS_VOCABULARY,
	resolveStatusLabel,
	resolveTierLabel,
	resolveGroundOptions,
} from '../../store/modules/avg.js'
import EditActivityDialog from '../../dialogs/avg/EditActivityDialog.vue'
import DenialComposerDialog from '../../dialogs/avg/DenialComposerDialog.vue'
import RedactionDialog from '../../dialogs/avg/RedactionDialog.vue'

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
		NcSelect,
		NcCheckboxRadioSwitch,
		NcNoteCard,
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
		ClipboardTextClockOutline,
		OpenInNew,
		ArrowLeft,
		AlertCircleOutline,
		AlertOutline,
		ClockOutline,
		CheckboxMarkedCircleOutline,
		FileDocumentEditOutline,
		DatabaseSearchOutline,
		MarkerIcon,
		AccountCheckOutline,
		BankOutline,
		EditActivityDialog,
		DenialComposerDialog,
		RedactionDialog,
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
			// Case-management surface state.
			selectedCaseId: null,
			caseFilters: {
				status: null,
				handler: null,
				overdue: false,
			},
			regulatorReferenceDraft: '',
			denialDialogOpen: false,
			redactionDialogOpen: false,
			identityResult: null,
			escalateResult: null,
		}
	},

	computed: {
		/**
		 * Expose the l10n translate helper to the template.
		 *
		 * @spec exclude UI plumbing — template translation helper
		 * @return {Function}
		 */
		t() {
			return t
		},
		/**
		 * Tab definitions for the AVG view header.
		 *
		 * @spec exclude UI plumbing — static tab list for display
		 * @return {Array<object>}
		 */
		tabs() {
			return [
				{ id: 'activities', label: t('openregister', 'Activities'), icon: 'ShieldLockOutline' },
				{ id: 'verantwoording', label: t('openregister', 'Verantwoording'), icon: 'FileDocumentOutline' },
				{ id: 'dsar', label: t('openregister', 'DSAR'), icon: 'AccountSearch' },
				{ id: 'cases', label: t('openregister', 'Cases'), icon: 'ClipboardTextClockOutline' },
				{ id: 'compliance', label: t('openregister', 'Compliance'), icon: 'ShieldAlertOutline' },
			]
		},
		/**
		 * Activities list from the AVG store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {Array<object>}
		 */
		activities() {
			return avgStore.getActivities ?? []
		},
		/**
		 * Verantwoording report from the AVG store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object}
		 */
		verantwoording() {
			return avgStore.getVerantwoording
		},
		/**
		 * DSAR result rows from the AVG store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object}
		 */
		dsarResults() {
			return avgStore.getDsarResults
		},
		/**
		 * DSAR summary from the AVG store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object}
		 */
		dsarSummary() {
			return avgStore.getDsarSummary
		},
		/**
		 * Compliance report from the AVG store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object}
		 */
		complianceReport() {
			return avgStore.getComplianceReport
		},
		/**
		 * Whether the AVG store is currently loading, for spinner display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {boolean}
		 */
		loading() {
			return avgStore.isLoading
		},
		/**
		 * Store error string, for inline note display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {string}
		 */
		error() {
			return avgStore.getError
		},
		/**
		 * Tracked DSAR cases from the store, for display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {Array<object>}
		 */
		cases() {
			return avgStore.getCases ?? []
		},
		/**
		 * The active policy pack from the store (pack-driven labels/grounds).
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object|null}
		 */
		activePolicyPack() {
			return avgStore.getActivePolicyPack
		},
		/**
		 * The currently selected case object (detail view), matched by id.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {object|null}
		 */
		selectedCase() {
			if (!this.selectedCaseId) return null
			return avgStore.getActiveCase
				?? this.cases.find((c) => this.caseId(c) === this.selectedCaseId)
				?? null
		},
		/**
		 * Client-side filtered case list (status / handler / overdue),
		 * applied on top of the RBAC/tenant-scoped set from the server.
		 *
		 * @spec exclude UI plumbing — presentation filter over store data
		 * @return {Array<object>}
		 */
		filteredCases() {
			return this.cases.filter((c) => {
				if (this.caseFilters.status && c.status !== this.caseFilters.status) return false
				if (this.caseFilters.handler && c.handler !== this.caseFilters.handler) return false
				if (this.caseFilters.overdue && !c.isOverdue) return false
				return true
			})
		},
		/**
		 * Status filter options, with an "all" clear entry.
		 *
		 * @spec exclude UI plumbing — pack-labelled status filter options
		 * @return {Array<object>}
		 */
		statusFilterOptions() {
			return [
				{ value: null, label: t('openregister', 'All statuses') },
				...CASE_STATUS_VOCABULARY.map((s) => ({ value: s, label: this.statusLabel(s) })),
			]
		},
		/**
		 * Handler filter options, derived from the loaded case set.
		 *
		 * @spec exclude UI plumbing — derived handler filter options
		 * @return {Array<object>}
		 */
		handlerFilterOptions() {
			const handlers = [...new Set(this.cases.map((c) => c.handler).filter(Boolean))]
			return [
				{ value: null, label: t('openregister', 'All handlers') },
				...handlers.map((h) => ({ value: h, label: h })),
			]
		},
		/**
		 * Declared lifecycle transitions available from the selected case's
		 * current state (mirror of the register graph; server authoritative).
		 *
		 * @spec exclude UI plumbing — filters the declared transition mirror by state
		 * @return {Array<object>}
		 */
		availableTransitions() {
			const status = this.selectedCase?.status
			if (!status) return []
			return CASE_LIFECYCLE_TRANSITIONS.filter((tr) => tr.from.includes(status))
		},
		/**
		 * Whether a finaliseDenial control is offered from the current state.
		 *
		 * @spec exclude UI plumbing — derived display flag
		 * @return {boolean}
		 */
		finaliseVisible() {
			return this.availableTransitions.some((tr) => tr.action === 'finaliseDenial')
		},
		/**
		 * Whether the selected case carries a regulator reference (gates finalise).
		 *
		 * @spec exclude UI plumbing — reflects the server-side denial-finalise guard
		 * @return {boolean}
		 */
		hasRegulatorReference() {
			return !!(this.selectedCase?.regulatorReference)
		},
		/**
		 * Evidence sub-collection of the selected case.
		 *
		 * @spec exclude UI plumbing — derived view state from the case
		 * @return {Array<object>}
		 */
		caseEvidence() {
			return Array.isArray(this.selectedCase?.evidence) ? this.selectedCase.evidence : []
		},
		/**
		 * Redactions sub-collection of the selected case.
		 *
		 * @spec exclude UI plumbing — derived view state from the case
		 * @return {Array<object>}
		 */
		caseRedactions() {
			return Array.isArray(this.selectedCase?.redactions) ? this.selectedCase.redactions : []
		},
	},

	watch: {
		/**
		 * Load cases + the active policy pack the first time the Cases tab
		 * is opened.
		 *
		 * @param {string} tab The newly active tab id.
		 * @spec exclude UI plumbing — lazy data load on tab activation
		 * @return {void}
		 */
		activeTab(tab) {
			if (tab === 'cases' && !this.cases.length) {
				this.refreshCases()
			}
		},
	},

	/**
	 * Lifecycle hook: load activities when the view mounts.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch for display only
	 * @return {void}
	 */
	mounted() {
		this.refreshActivities()
	},

	methods: {
		/**
		 * Format an ISO timestamp as a locale string for display.
		 *
		 * @param {string} value The timestamp to format.
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {string}
		 */
		formatTime(value) {
			if (!value) return ''
			try {
				return new Date(value).toLocaleString()
			} catch (e) {
				return value
			}
		},

		/**
		 * Reload the activities list from the store.
		 *
		 * @spec exclude UI plumbing — delegates to the AVG store fetch
		 * @return {Promise<void>}
		 */
		async refreshActivities() {
			try {
				await avgStore.fetchActivities()
			} catch (e) {
				// surfaced via avgStore.error
			}
		},

		/**
		 * Open the create-activity dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle
		 * @return {void}
		 */
		openCreateDialog() {
			this.dialogActivity = null
			this.dialogOpen = true
		},

		/**
		 * Open the edit-activity dialog for a given activity.
		 *
		 * @param {object} activity The activity to edit.
		 * @spec exclude UI plumbing — dialog visibility toggle
		 * @return {void}
		 */
		openEditDialog(activity) {
			this.dialogActivity = { ...activity }
			this.dialogOpen = true
		},

		/**
		 * Close the activity dialog and clear its state.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle
		 * @return {void}
		 */
		closeDialog() {
			this.dialogOpen = false
			this.dialogActivity = null
		},

		/**
		 * Handle the dialog's saved event by closing it and refreshing the list.
		 *
		 * @spec exclude UI plumbing — dialog callback wiring
		 * @return {void}
		 */
		onActivitySaved() {
			this.closeDialog()
			this.refreshActivities()
		},

		/**
		 * Archive an activity after user confirmation.
		 *
		 * @param {object} activity The activity to archive.
		 * @spec exclude UI plumbing — confirm dialog plus store delegation
		 * @return {Promise<void>}
		 */
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

		/**
		 * Load the verantwoording report from the store.
		 *
		 * @spec exclude UI plumbing — delegates to the AVG store fetch
		 * @return {Promise<void>}
		 */
		async loadVerantwoording() {
			try {
				await avgStore.fetchVerantwoording()
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Run a DSAR inzage (subject-access) request via the store.
		 *
		 * @spec exclude UI plumbing — delegates to the AVG store action
		 * @return {Promise<void>}
		 */
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

		/**
		 * Run a vergetelheid (erasure) dry-run via the store.
		 *
		 * @spec exclude UI plumbing — delegates to the AVG store action
		 * @return {Promise<void>}
		 */
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

		/**
		 * Confirm and run a vergetelheid (erasure) after user confirmation.
		 *
		 * @spec exclude UI plumbing — confirm dialog plus store delegation
		 * @return {Promise<void>}
		 */
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

		/**
		 * Run a portabiliteit export and download the result as JSON.
		 *
		 * @spec exclude UI plumbing — store delegation plus browser download
		 * @return {Promise<void>}
		 */
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

		/**
		 * Load the compliance report from the store.
		 *
		 * @spec exclude UI plumbing — delegates to the AVG store fetch
		 * @return {Promise<void>}
		 */
		async loadCompliance() {
			try {
				await avgStore.fetchCompliance()
			} catch (e) {
				// surfaced via store error
			}
		},

		// ---- Case management --------------------------------------

		/**
		 * Stable identifier for a case object (uuid, else id).
		 *
		 * @param {object} c The case object.
		 * @spec exclude UI plumbing — presentation id resolver
		 * @return {string}
		 */
		caseId(c) {
			return c?.uuid ?? c?.id ?? c?.['@self']?.id ?? ''
		},

		/**
		 * Reload the case list + the active policy pack from the store.
		 *
		 * @spec exclude UI plumbing — delegates to the AVG store fetches
		 * @return {Promise<void>}
		 */
		async refreshCases() {
			const params = {}
			if (this.caseFilters.status) params.status = this.caseFilters.status
			if (this.caseFilters.handler) params.handler = this.caseFilters.handler
			if (this.caseFilters.overdue) params.isOverdue = 'true'
			try {
				await Promise.all([
					avgStore.fetchCases(params),
					this.activePolicyPack ? Promise.resolve() : avgStore.fetchActivePolicyPack(),
				])
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Open a case's detail surface, loading its full object and the
		 * pack for its jurisdiction.
		 *
		 * @param {object} c The case row.
		 * @spec exclude UI plumbing — selects a case and loads detail
		 * @return {Promise<void>}
		 */
		async selectCase(c) {
			this.selectedCaseId = this.caseId(c)
			this.identityResult = null
			this.escalateResult = null
			this.regulatorReferenceDraft = ''
			try {
				await avgStore.fetchCase(this.selectedCaseId)
				await avgStore.fetchActivePolicyPack({ jurisdiction: this.selectedCase?.jurisdiction })
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Reload the selected case from the store.
		 *
		 * @spec exclude UI plumbing — delegates to the AVG store fetch
		 * @return {Promise<void>}
		 */
		async reloadSelectedCase() {
			if (!this.selectedCaseId) return
			try {
				await avgStore.fetchCase(this.selectedCaseId)
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Return to the case list.
		 *
		 * @spec exclude UI plumbing — clears the detail selection
		 * @return {void}
		 */
		backToList() {
			this.selectedCaseId = null
			this.identityResult = null
			this.escalateResult = null
		},

		/**
		 * Resolve a status label from the active pack.
		 *
		 * @param {string} status The generic status key.
		 * @spec exclude UI plumbing — pack-driven label resolver
		 * @return {string}
		 */
		statusLabel(status) {
			return resolveStatusLabel(this.activePolicyPack, status)
		},

		/**
		 * Resolve an escalation-tier label from the active pack.
		 *
		 * @param {string} tier The tier key.
		 * @spec exclude UI plumbing — pack-driven label resolver
		 * @return {string}
		 */
		tierLabel(tier) {
			return resolveTierLabel(this.activePolicyPack, tier)
		},

		/**
		 * Resolve a denial-ground label from the active pack.
		 *
		 * @param {string} key The ground key.
		 * @spec exclude UI plumbing — pack-driven label resolver
		 * @return {string}
		 */
		groundLabel(key) {
			const found = resolveGroundOptions(this.activePolicyPack).find((o) => o.value === key)
			return found?.label ?? key
		},

		/**
		 * Resolve a transition label from the declared action name.
		 *
		 * @param {string} action The transition action.
		 * @spec exclude UI plumbing — presentation label for a transition
		 * @return {string}
		 */
		transitionLabel(action) {
			return action.replace(/([A-Z])/g, ' $1').replace(/^\w/, (c) => c.toUpperCase())
		},

		/**
		 * CSS class for an escalation tier (colour + text/icon, WCAG AA).
		 *
		 * @param {string} tier The tier key.
		 * @spec exclude UI plumbing — presentation class resolver
		 * @return {string}
		 */
		tierClass(tier) {
			return 'tier tier-' + (tier || 'on-track')
		},

		/**
		 * Icon component name for an escalation tier (state by icon, not colour alone).
		 *
		 * @param {string} tier The tier key.
		 * @spec exclude UI plumbing — presentation icon resolver
		 * @return {string}
		 */
		tierIcon(tier) {
			switch (tier) {
			case 'breached': return 'AlertCircleOutline'
			case 'escalation': return 'AlertOutline'
			case 'reminder': return 'ClockOutline'
			default: return 'CheckboxMarkedCircleOutline'
			}
		},

		/**
		 * Human deadline summary for a case row.
		 *
		 * @param {object} c The case.
		 * @spec exclude UI plumbing — presentation deadline text
		 * @return {string}
		 */
		deadlineText(c) {
			if (c.isOverdue) {
				return t('openregister', 'Overdue')
			}
			if (typeof c.daysRemaining === 'number') {
				return t('openregister', '{days} day(s) left', { days: c.daysRemaining })
			}
			return '—'
		},

		/**
		 * Days-remaining detail text for the detail view.
		 *
		 * @param {object} c The case.
		 * @spec exclude UI plumbing — presentation days-remaining text
		 * @return {string}
		 */
		daysRemainingText(c) {
			if (typeof c.daysRemaining !== 'number') return '—'
			if (c.isOverdue || c.daysRemaining < 0) {
				return t('openregister', 'Overdue by {days} day(s)', { days: Math.abs(c.daysRemaining) })
			}
			return t('openregister', '{days} day(s) remaining', { days: c.daysRemaining })
		},

		/**
		 * NoteCard type for a seam result, given the "success" status value.
		 *
		 * @param {string} status  The seam-returned status.
		 * @param {string} success The status value that counts as success.
		 * @spec exclude UI plumbing — presentation note-type resolver (fail-closed faithful)
		 * @return {string}
		 */
		seamNoteType(status, success) {
			return status === success ? 'success' : 'warning'
		},

		/**
		 * Run a lifecycle transition. draftDenial / redact open their
		 * dialogs; everything else posts directly.
		 *
		 * @param {object} tr The transition descriptor.
		 * @spec exclude UI plumbing — delegates to a dialog or avgStore.transitionCase
		 * @return {Promise<void>}
		 */
		async onTransition(tr) {
			if (tr.action === 'draftDenial') {
				this.openDenialDialog()
				return
			}
			if (tr.action === 'redact') {
				this.openRedactionDialog()
				return
			}
			try {
				await avgStore.transitionCase(this.selectedCaseId, tr.action)
				await this.reloadSelectedCase()
			} catch (e) {
				// server refusal (e.g. guard) surfaced via store error
			}
		},

		/**
		 * Persist a regulator reference on the case (unblocks finaliseDenial).
		 *
		 * @spec exclude UI plumbing — delegates to avgStore.updateCase
		 * @return {Promise<void>}
		 */
		async recordRegulatorReference() {
			if (!this.regulatorReferenceDraft) return
			try {
				await avgStore.updateCase(this.selectedCaseId, { regulatorReference: this.regulatorReferenceDraft })
				this.regulatorReferenceDraft = ''
				await this.reloadSelectedCase()
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Open the denial-composer dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle
		 * @return {void}
		 */
		openDenialDialog() {
			this.denialDialogOpen = true
		},

		/**
		 * Open the redaction dialog.
		 *
		 * @spec exclude UI plumbing — dialog visibility toggle
		 * @return {void}
		 */
		openRedactionDialog() {
			this.redactionDialogOpen = true
		},

		/**
		 * Refresh the case after a dialog mutates it.
		 *
		 * @spec exclude UI plumbing — dialog callback wiring
		 * @return {Promise<void>}
		 */
		async onCaseMutated() {
			this.denialDialogOpen = false
			this.redactionDialogOpen = false
			await this.reloadSelectedCase()
		},

		/**
		 * Trigger an evidence harvest and refresh the case.
		 *
		 * @spec exclude UI plumbing — delegates to avgStore.collectEvidence
		 * @return {Promise<void>}
		 */
		async harvestEvidence() {
			try {
				await avgStore.collectEvidence(this.selectedCaseId)
				await this.reloadSelectedCase()
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Generate the export bundle and offer exactly one download of the
		 * returned one-time token.
		 *
		 * @spec exclude UI plumbing — delegates to avgStore.generateBundle/downloadBundle
		 * @return {Promise<void>}
		 */
		async generateAndDownloadBundle() {
			try {
				const meta = await avgStore.generateBundle(this.selectedCaseId)
				const token = meta?.downloadToken
				if (!token) return
				const blob = await avgStore.downloadBundle(this.selectedCaseId, token)
				if (!blob) return
				const url = URL.createObjectURL(blob)
				const a = document.createElement('a')
				a.href = url
				a.download = `dsar-case-${this.selectedCaseId}.pdf`
				a.click()
				URL.revokeObjectURL(url)
				await this.reloadSelectedCase()
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Trigger the identity-verify seam and render its fail-closed result.
		 *
		 * @spec exclude UI plumbing — delegates to avgStore.verifyIdentity
		 * @return {Promise<void>}
		 */
		async runVerifyIdentity() {
			try {
				this.identityResult = await avgStore.verifyIdentity(this.selectedCaseId)
				await this.reloadSelectedCase()
			} catch (e) {
				// surfaced via store error
			}
		},

		/**
		 * Trigger the regulator-escalate seam and render its fail-closed result.
		 *
		 * @spec exclude UI plumbing — delegates to avgStore.escalateRegulator
		 * @return {Promise<void>}
		 */
		async runEscalate() {
			try {
				this.escalateResult = await avgStore.escalateRegulator(this.selectedCaseId)
				await this.reloadSelectedCase()
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

.caseFilters {
	display: flex;
	gap: 16px;
	align-items: center;
	flex-wrap: wrap;
	padding: 8px 0;
}

.caseFilterSelect {
	min-width: 220px;
}

.caseDetail {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.caseDetailHeader {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.caseDetailHeader h2 {
	margin: 0;
}

.caseCard {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.caseCard h3 {
	margin-top: 0;
}

.caseCardHead {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.caseFacts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	margin: 0;
}

.caseFacts dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.caseFacts dd {
	margin: 2px 0 0;
}

.caseActionsRow {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.caseInlineForm {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
	margin: 12px 0;
}

.caseHint {
	color: var(--color-text-maxcontrast);
}

.caseHint-warning {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-warning-text, var(--color-warning));
}

.caseNotes {
	white-space: pre-wrap;
}

.tier {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-background-darker);
}

.tier-reminder {
	background: var(--color-background-dark);
}

.tier-escalation {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.tier-breached {
	background: var(--color-error);
	color: var(--color-primary-text);
}
</style>
