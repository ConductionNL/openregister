/**
 * The record-import surface over `/api/record-import/{register}/{schema}`:
 * propose a column mapping, dry-run, import, and hand back the failed rows.
 *
 * This is the only frontend code that talks to the import routes. A drop
 * target hands it the file and nothing else: the server reads the rows, maps
 * the columns and writes the objects. The routes are administrator-only; the
 * server refuses anyone else whatever the screens offer.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The file extensions the server reads as record files (RecordFileReader::formatOf). */
export const RECORD_FILE_EXTENSIONS = ['csv', 'tsv', 'tab', 'xlsx', 'jsonl', 'ndjson']

/**
 * Whether a file is one the record import reads, by its extension only.
 *
 * Nothing is opened or parsed here: it only decides whether a drop belongs
 * to the record import or to file upload.
 *
 * @param {{name?: string}|null} file The dropped file.
 * @return {boolean} True for a CSV, TSV, XLSX or JSON-lines file.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
export function isRecordFile(file) {
	const name = String(file?.name ?? '')
	const dot = name.lastIndexOf('.')
	if (dot < 0) {
		return false
	}

	return RECORD_FILE_EXTENSIONS.includes(name.slice(dot + 1).toLowerCase())
}

/**
 * The URL of a record-import route.
 *
 * @param {string|number} register The register id, uuid or slug.
 * @param {string|number} schema The schema id, uuid or slug.
 * @param {string} [suffix] `/mapping`, `/dry-run` or nothing for the import.
 * @return {string} The URL.
 */
function importUrl(register, schema, suffix = '') {
	return generateUrl('/apps/openregister/api/record-import/{register}/{schema}' + suffix, { register, schema })
}

/**
 * The multipart body: the file, and for a dry run or an import the decisions and identifier.
 *
 * @param {File|Blob} file The record file.
 * @param {object} [decisions] Column => property name, or null to ignore the column.
 * @param {string|null} [identifier] The identifying property, or null to create only.
 * @param {string} [progressToken] The token to report the rows read under, so they can be polled.
 * @return {FormData} The body.
 */
function body(file, decisions, identifier, progressToken) {
	const form = new FormData()
	form.append('file', file, file.name)
	if (decisions !== undefined) {
		form.append('decisions', JSON.stringify(decisions))
		form.append('identifier', identifier ?? '')
	}

	if (typeof progressToken === 'string' && progressToken !== '') {
		form.append('progress', progressToken)
	}

	return form
}

/**
 * The upload-progress option for axios, as a 0..1 fraction, or none.
 *
 * @param {(fraction: number) => void} [onProgress] Called with the fraction uploaded.
 * @return {object} The axios config.
 */
function progress(onProgress) {
	if (typeof onProgress !== 'function') {
		return {}
	}

	return {
		onUploadProgress: (event) => {
			if (event?.total > 0) {
				onProgress(Math.min(1, event.loaded / event.total))
			}
		},
	}
}

/**
 * The proposed mapping of a file's columns onto the schema's properties. Writes nothing.
 *
 * @param {{register: string|number, schema: string|number, file: File, onProgress?: (fraction: number) => void}} request The file and target.
 * @return {Promise<{format: string, columns: string[], properties: string[], mapped: object, unmatched: string[]}>} The proposal.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-column-mapping-must-be-confirmed-never-inferred-silently
 */
export async function proposeMapping({ register, schema, file, onProgress }) {
	const response = await axios.post(importUrl(register, schema, '/mapping'), body(file), progress(onProgress))
	return response.data
}

/**
 * What the import would do with the confirmed mapping, writing nothing.
 *
 * @param {{register: string|number, schema: string|number, file: File, decisions: object, identifier: string|null, onProgress?: (fraction: number) => void, progressToken?: string}} request The import.
 * @return {Promise<object>} The report, `dryRun: true`.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-a-dry-run-must-report-outcomes-without-writing
 */
export async function dryRunImport({ register, schema, file, decisions, identifier, onProgress, progressToken }) {
	const response = await axios.post(importUrl(register, schema, '/dry-run'), body(file, decisions, identifier, progressToken), progress(onProgress))
	return response.data
}

/**
 * Import the file with the confirmed mapping.
 *
 * @param {{register: string|number, schema: string|number, file: File, decisions: object, identifier: string|null, onProgress?: (fraction: number) => void, progressToken?: string}} request The import.
 * @return {Promise<object>} The import status.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-a-record-import-must-write-through-the-streaming-path
 */
export async function runImport({ register, schema, file, decisions, identifier, onProgress, progressToken }) {
	const response = await axios.post(importUrl(register, schema), body(file, decisions, identifier, progressToken), progress(onProgress))
	return response.data
}

/**
 * The initial decisions from a proposal: each matched column to its property, every other column undecided.
 *
 * @param {{columns: string[], mapped: object}} proposal The proposal.
 * @return {object} Column => property name, or undefined while undecided.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-column-mapping-must-be-confirmed-never-inferred-silently
 */
export function initialDecisions(proposal) {
	const decisions = {}
	for (const column of proposal?.columns ?? []) {
		decisions[column] = proposal?.mapped?.[column]
	}

	return decisions
}

/**
 * The columns still neither mapped nor ignored; the import is refused while any remain.
 *
 * @param {object} decisions Column => property name, null (ignored) or undefined (undecided).
 * @return {string[]} The undecided columns.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-column-mapping-must-be-confirmed-never-inferred-silently
 */
export function undecidedColumns(decisions) {
	return Object.keys(decisions ?? {}).filter((column) => decisions[column] === undefined || decisions[column] === '')
}

/**
 * The failed rows of a result as a CSV file named after the imported one, or null when none failed.
 *
 * @param {{failedRows?: string}} result The dry-run or import result.
 * @param {string} fileName The imported file's name.
 * @return {{name: string, blob: Blob}|null} The file.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-failed-rows-must-be-exportable-in-the-inputs-shape
 */
export function failedRowsFile(result, fileName) {
	if (typeof result?.failedRows !== 'string' || result.failedRows === '') {
		return null
	}

	const base = String(fileName ?? 'records').replace(/\.[^.]*$/, '')
	return { name: `${base}-failed.csv`, blob: new Blob([result.failedRows], { type: 'text/csv;charset=utf-8' }) }
}

/**
 * A fresh token that names one running import, for its progress to be read under.
 *
 * @return {string} 32 hexadecimal characters.
 * @spec openspec/changes/widget-record-import/tasks.md#5.2
 */
export function newProgressToken() {
	const bytes = new Uint8Array(16)
	globalThis.crypto.getRandomValues(bytes)
	return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/**
 * The rows a running import has read so far, or null while the server has reported none.
 *
 * @param {string} token The import's progress token.
 * @return {Promise<{rows: number, done: boolean}|null>} The progress.
 * @spec openspec/changes/widget-record-import/tasks.md#5.2
 */
export async function fetchImportProgress(token) {
	try {
		const response = await axios.get(generateUrl('/apps/openregister/api/record-import/progress/{token}', { token }))
		return response.data
	} catch (failure) {
		if (failure?.response?.status === 404) {
			return null
		}

		throw failure
	}
}

/**
 * Poll an import's progress until stopped, handing each answer to `onRows`.
 *
 * A failed poll is skipped: the import request itself reports any real failure.
 *
 * @param {object} options The options.
 * @param {string} options.token The import's progress token.
 * @param {(progress: {rows: number, done: boolean}) => void} options.onRows Receives each reported progress.
 * @param {number} [options.interval] Milliseconds between polls.
 * @return {() => void} Stops the polling.
 * @spec openspec/changes/widget-record-import/tasks.md#5.2
 */
export function watchImportProgress({ token, onRows, interval = 1000 }) {
	let stopped = false
	const timer = setInterval(async () => {
		try {
			const progress = await fetchImportProgress(token)
			if (!stopped && progress !== null) {
				onRows(progress)
			}
		} catch {
			// Skipped: the next poll or the import's own answer will tell.
		}
	}, interval)

	return () => {
		stopped = true
		clearInterval(timer)
	}
}
