<!--
  The record-import dialog: confirm the column mapping, dry-run, import, and
  read the result.

  Every step goes through the import surface (services/recordImport.js); this
  dialog reads no rows itself. The mapping is the server's proposal, and the
  import stays disabled while any column is neither mapped nor ignored.

  @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('openregister', 'Import records')"
		size="large"
		@closing="close">
		<p v-if="handoff" class="recordImport__file">
			{{ t('openregister', 'File: {name}', { name: handoff.file.name }) }}
		</p>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="busy" class="recordImport__progress" role="status">
			<NcProgressBar :value="Math.round(uploaded * 100)" size="medium" />
			<p v-if="uploaded < 1">
				{{ t('openregister', 'Uploading the file: {percent}%', { percent: Math.round(uploaded * 100) }) }}
			</p>
			<p v-else-if="rowsRead > 0">
				{{ n('openregister', 'Uploaded. The server has processed {count} row so far.', 'Uploaded. The server has processed {count} rows so far.', rowsRead, { count: rowsRead }) }}
			</p>
			<p v-else>
				{{ t('openregister', 'Uploaded. The server is processing the rows; a large file can take a while.') }}
			</p>
		</div>

		<section v-if="proposal && !busy" class="recordImport__mapping">
			<h3>{{ t('openregister', 'Column mapping') }}</h3>
			<NcNoteCard v-if="undecided.length" type="warning">
				<p>
					{{ t('openregister', 'Map or ignore every column before importing. Undecided: {columns}', { columns: undecided.join(', ') }) }}
				</p>
			</NcNoteCard>
			<table class="recordImport__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('openregister', 'Column in the file') }}
						</th>
						<th scope="col">
							{{ t('openregister', 'Schema property') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(column, index) in proposal.columns" :key="column">
						<td>
							<label :for="`recordImport-column-${index}`">{{ column }}</label>
						</td>
						<td>
							<select
								:id="`recordImport-column-${index}`"
								:value="selectValue(column)"
								@change="setDecision(column, $event.target.value)">
								<option value="">
									{{ t('openregister', 'Choose a property or ignore') }}
								</option>
								<option :value="IGNORE">
									{{ t('openregister', 'Ignore this column') }}
								</option>
								<option v-for="property in proposal.properties" :key="property" :value="property">
									{{ property }}
								</option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="recordImport__identifier">
				<label for="recordImport-identifier">{{ t('openregister', 'Identifying property') }}</label>
				<select id="recordImport-identifier" v-model="identifier">
					<option value="">
						{{ t('openregister', 'None: create every row as a new object') }}
					</option>
					<option v-for="property in mappedProperties" :key="property" :value="property">
						{{ property }}
					</option>
				</select>
			</p>
			<NcNoteCard v-if="!identifier" type="info">
				<p>{{ t('openregister', 'No identifying column is nominated: every row will be created as a new object.') }}</p>
			</NcNoteCard>
		</section>

		<section v-if="result && !busy" class="recordImport__result" aria-live="polite">
			<h3 v-if="result.dryRun">
				{{ t('openregister', 'Dry run: nothing was written') }}
			</h3>
			<h3 v-else>
				{{ t('openregister', 'Import finished') }}
			</h3>
			<dl class="recordImport__counts">
				<template v-for="row in resultRows" :key="row.label">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</template>
			</dl>
			<ul v-if="result.failures?.length" class="recordImport__failures">
				<li v-for="(failure, index) in shownFailures" :key="index">
					{{ failureText(failure) }}
				</li>
			</ul>
			<p v-if="result.failures?.length > shownFailures.length">
				{{ t('openregister', 'Showing the first {shown} failures; download the failed rows for all of them.', { shown: shownFailures.length }) }}
			</p>
		</section>

		<template #actions>
			<NcButton :disabled="busy" @click="close">
				{{ t('openregister', 'Close') }}
			</NcButton>
			<NcButton v-if="failedFile" :disabled="busy" @click="downloadFailedRows">
				{{ t('openregister', 'Download failed rows') }}
			</NcButton>
			<NcButton :disabled="!canRun" @click="run(true)">
				{{ t('openregister', 'Dry run') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canRun" @click="run(false)">
				{{ t('openregister', 'Import') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcProgressBar } from '@nextcloud/vue'
import { n, t } from '../../modals/object/l10n.js'
import {
	dryRunImport,
	failedRowsFile,
	initialDecisions,
	newProgressToken,
	proposeMapping,
	runImport,
	undecidedColumns,
	watchImportProgress,
} from '../../services/recordImport.js'
import { navigationStore } from '../../store/store.js'
import { recordImportHandoff } from './openRecordImport.js'

/** The select value that ignores a column. */
const IGNORE = '\u0000ignore'

/** At most this many failures are listed in the dialog; the download has them all. */
const SHOWN_FAILURES = 50

/**
 * The error text of a failed request: the server's message when it sent one.
 *
 * @param {Error} failure The rejected request.
 * @return {string} The message.
 */
function errorText(failure) {
	const data = failure?.response?.data
	if (typeof data?.error === 'string' && data.error !== '') {
		return data.error
	}

	return failure?.message || t('openregister', 'The request failed.')
}

export default {
	name: 'RecordImportDialog',
	components: { NcButton, NcDialog, NcNoteCard, NcProgressBar },

	data() {
		return {
			IGNORE,
			handoff: recordImportHandoff(),
			proposal: null,
			decisions: {},
			identifier: '',
			busy: false,
			uploaded: 0,
			rowsRead: 0,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The columns still neither mapped nor ignored.
		 *
		 * @return {string[]} The columns.
		 */
		undecided() {
			return undecidedColumns(this.decisions)
		},

		/**
		 * The properties a column is mapped to: the ones that can identify a row.
		 *
		 * @return {string[]} The properties.
		 */
		mappedProperties() {
			const mapped = Object.values(this.decisions).filter((property) => typeof property === 'string' && property !== '')
			return [...new Set(mapped)]
		},

		/**
		 * Whether a dry run or import may start.
		 *
		 * @return {boolean} True with a confirmed mapping and nothing running.
		 */
		canRun() {
			return Boolean(this.handoff && this.proposal) && !this.busy && this.undecided.length === 0
		},

		/**
		 * The result's counts as labelled rows.
		 *
		 * @return {Array<{label: string, value: number|string}>} The rows.
		 */
		resultRows() {
			const result = this.result ?? {}
			const rows = [
				{ label: t('openregister', 'Created'), value: result.createdCount ?? 0 },
				{ label: t('openregister', 'Updated'), value: result.updatedCount ?? 0 },
				{ label: t('openregister', 'Unchanged'), value: result.unchangedCount ?? 0 },
				{ label: t('openregister', 'Failed'), value: result.failedCount ?? 0 },
				{ label: t('openregister', 'Reference cache hits'), value: result.referenceCacheHits ?? 0 },
				{ label: t('openregister', 'Reference cache misses'), value: result.referenceCacheMisses ?? 0 },
			]
			if (typeof result.durationSeconds === 'number') {
				rows.push({ label: t('openregister', 'Duration (seconds)'), value: result.durationSeconds.toFixed(2) })
			}

			return rows
		},

		/**
		 * The failures listed in the dialog.
		 *
		 * @return {Array<{line: number|null, reason: string}>} The failures.
		 */
		shownFailures() {
			return (this.result?.failures ?? []).slice(0, SHOWN_FAILURES)
		},

		/**
		 * The failed rows as a file, or null.
		 *
		 * @return {{name: string, blob: Blob}|null} The file.
		 */
		failedFile() {
			return failedRowsFile(this.result, this.handoff?.file?.name)
		},
	},

	/**
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-column-mapping-must-be-confirmed-never-inferred-silently
	 */
	mounted() {
		this.loadProposal()
	},

	methods: {
		n,
		t,

		/**
		 * Ask the server for the proposed mapping of the handed-over file.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-column-mapping-must-be-confirmed-never-inferred-silently
		 */
		async loadProposal() {
			if (!this.handoff) {
				this.error = t('openregister', 'No record file was handed to the import.')
				return
			}

			await this.request(async (onProgress) => {
				this.proposal = await proposeMapping({ ...this.handoff, onProgress })
				this.decisions = initialDecisions(this.proposal)
			})
		},

		/**
		 * Run a dry run or the import with the confirmed mapping.
		 *
		 * @param {boolean} dryRun Whether nothing is written.
		 * @return {Promise<void>}
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-a-dry-run-must-report-outcomes-without-writing
		 */
		async run(dryRun) {
			if (!this.canRun) {
				return
			}

			const send = dryRun ? dryRunImport : runImport
			const progressToken = newProgressToken()
			const stop = watchImportProgress({
				token: progressToken,
				onRows: (progress) => {
					this.rowsRead = progress.rows
				},
			})
			try {
				await this.request(async (onProgress) => {
					this.result = null
					this.result = await send({
						...this.handoff,
						decisions: this.confirmedDecisions(),
						identifier: this.identifier || null,
						onProgress,
						progressToken,
					})
				})
			} finally {
				stop()
			}
		},

		/**
		 * Run one request with the progress shown and its error caught.
		 *
		 * @param {(onProgress: (fraction: number) => void) => Promise<void>} work Receives the upload-progress callback.
		 * @return {Promise<void>}
		 * @spec openspec/changes/widget-record-import/tasks.md
		 */
		async request(work) {
			this.busy = true
			this.uploaded = 0
			this.rowsRead = 0
			this.error = ''
			try {
				await work((fraction) => {
					this.uploaded = fraction
				})
			} catch (failure) {
				this.error = errorText(failure)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The decisions as the server takes them: column => property, or null to ignore.
		 *
		 * @return {object} The decisions.
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-column-mapping-must-be-confirmed-never-inferred-silently
		 */
		confirmedDecisions() {
			const confirmed = {}
			for (const [column, property] of Object.entries(this.decisions)) {
				confirmed[column] = property ?? null
			}

			return confirmed
		},

		/**
		 * The select value for a column's decision.
		 *
		 * @param {string} column The column.
		 * @return {string} The property, IGNORE, or '' while undecided.
		 */
		selectValue(column) {
			const decision = this.decisions[column]
			if (decision === null) {
				return IGNORE
			}

			return decision ?? ''
		},

		/**
		 * Record a column's decision from its select; a property no longer mapped stops identifying.
		 *
		 * @param {string} column The column.
		 * @param {string} value The property, IGNORE, or '' for undecided.
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-column-mapping-must-be-confirmed-never-inferred-silently
		 */
		setDecision(column, value) {
			let decision = value
			if (value === IGNORE) {
				decision = null
			} else if (value === '') {
				decision = undefined
			}

			this.decisions = { ...this.decisions, [column]: decision }
			if (this.identifier && !this.mappedProperties.includes(this.identifier)) {
				this.identifier = ''
			}
		},

		/**
		 * A failure as one line of text.
		 *
		 * @param {{line: number|null, reason: string}} failure The failure.
		 * @return {string} The text.
		 */
		failureText(failure) {
			if (failure?.line === null || failure?.line === undefined) {
				return failure?.reason ?? ''
			}

			return t('openregister', 'Line {line}: {reason}', { line: failure.line, reason: failure.reason })
		},

		/**
		 * Save the failed rows as a CSV to correct and import again.
		 *
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-failed-rows-must-be-exportable-in-the-inputs-shape
		 */
		downloadFailedRows() {
			const file = this.failedFile
			if (!file) {
				return
			}

			const url = URL.createObjectURL(file.blob)
			const link = document.createElement('a')
			link.href = url
			link.download = file.name
			document.body.appendChild(link)
			link.click()
			link.remove()
			URL.revokeObjectURL(url)
		},

		/**
		 * Close the dialog and drop the handed-over file.
		 *
		 * @spec openspec/changes/widget-record-import/tasks.md
		 */
		close() {
			if (this.busy) {
				return
			}

			navigationStore.clearTransferData()
			navigationStore.setDialog(false)
		},
	},
}
</script>

<style scoped>
.recordImport__file {
	color: var(--color-text-maxcontrast);
}

.recordImport__progress {
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.recordImport__table {
	width: 100%;
	border-collapse: collapse;
}

.recordImport__table th,
.recordImport__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.recordImport__table select,
.recordImport__identifier select {
	width: 100%;
	min-height: var(--default-clickable-area);
}

.recordImport__identifier {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.recordImport__counts {
	display: grid;
	grid-template-columns: max-content auto;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 4);
}

.recordImport__counts dt {
	font-weight: bold;
}

.recordImport__counts dd {
	margin: 0;
}

.recordImport__failures {
	max-height: 240px;
	overflow-y: auto;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}
</style>
