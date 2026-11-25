<!--
  The query console on the operations page: an administrator runs a read-only
  GraphQL query, sees the first list of the result as a table with its row
  count, truncation and duration, the raw JSON behind a toggle, and downloads
  the list as CSV or JSON.

  Reads POST /api/operations/query and POST /api/operations/query/export
  (administrator only; the server refuses mutations, caps the rows and the
  time, and records every run and download).

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
-->
<template>
	<section class="consoleSection queryConsole">
		<h2>{{ t('openregister', 'Query console') }}</h2>
		<p class="queryConsole__note">
			{{
				t(
					'openregister',
					'Runs as the signed-in administrator, in the active organisation. Read only. At most 1,000 rows and 30 seconds. Each run is recorded.',
				)
			}}
		</p>

		<label class="queryConsole__label">{{
			t('openregister', 'GraphQL query')
		}}</label>
		<CodeMirror
			v-model="query"
			class="queryConsole__editor"
			:basic="true"
			:dark="dark" />

		<label class="queryConsole__label">{{
			t('openregister', 'Variables (JSON)')
		}}</label>
		<CodeMirror
			v-model="variables"
			class="queryConsole__editor queryConsole__editor--small"
			:basic="true"
			:dark="dark"
			:lang="jsonLang" />

		<div class="queryConsole__actions">
			<NcButton
				variant="primary"
				:disabled="busy || query.trim() === ''"
				@click="run">
				{{ t('openregister', 'Run query') }}
			</NcButton>
			<NcButton
				:disabled="busy || query.trim() === ''"
				@click="download('csv')">
				{{ t('openregister', 'Download CSV') }}
			</NcButton>
			<NcButton
				:disabled="busy || query.trim() === ''"
				@click="download('json')">
				{{ t('openregister', 'Download JSON') }}
			</NcButton>
		</div>

		<p v-if="error" class="queryConsole__error" role="alert">
			{{ error }}
		</p>
		<ul v-if="queryErrors.length > 0" class="queryConsole__error" role="alert">
			<li v-for="(message, index) in queryErrors" :key="index">
				{{ message }}
			</li>
		</ul>

		<template v-if="result">
			<p class="queryConsole__stats">
				{{ t('openregister', 'Rows: {count}', { count: stats.rows }) }}
				·
				{{
					t('openregister', 'Duration: {duration} ms', {
						duration: stats.durationMs,
					})
				}}
			</p>
			<p v-if="stats.truncated" class="queryConsole__truncated" role="status">
				{{ t('openregister', 'The result was cut off at 1,000 rows.') }}
			</p>

			<div v-if="table" class="queryConsole__tableWrap">
				<table class="queryConsole__table">
					<thead>
						<tr>
							<th
								v-for="column in table.columns"
								:key="column"
								scope="col">
								{{ column }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in table.rows" :key="index">
							<td v-for="column in table.columns" :key="column">
								{{ cellText(row[column]) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p v-else class="queryConsole__note">
				{{ t('openregister', 'The result has no list to show as a table.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="showRaw" type="switch">
				{{ t('openregister', 'Raw JSON') }}
			</NcCheckboxRadioSwitch>
			<pre v-if="showRaw" class="queryConsole__raw">{{ rawJson }}</pre>
		</template>
	</section>
</template>

<script>
import { json } from '@codemirror/lang-json'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import { getTheme } from '../../services/getTheme.js'

/** The query a new console starts with. */
export const STARTER_QUERY = '{\n  __typename\n}\n'

/**
 * The first list in a GraphQL result's data: a connection's nodes, or a
 * plain list; null when the data holds none. Mirrors
 * AdminQueryExport::firstList(), so the table shows what a download holds.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
 * @param {object|null} data The result's `data`.
 * @return {Array|null} The rows.
 */
export function firstList(data) {
	if (data === null || typeof data !== 'object') {
		return null
	}
	for (const value of Object.values(data)) {
		if (value === null || typeof value !== 'object') {
			continue
		}
		if (Array.isArray(value.edges)) {
			return value.edges.map((edge) =>
				edge && typeof edge === 'object' && 'node' in edge
					? edge.node
					: edge,
			)
		}
		if (Array.isArray(value)) {
			return value
		}
	}
	return null
}

/**
 * A row flattened to dotted column names, with nested lists as JSON text.
 * Mirrors AdminQueryExport::flatten().
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
 * @param {*} value The row or a nested value.
 * @param {string} [prefix] The column name so far.
 * @return {object} The flat row.
 */
export function flatten(value, prefix = '') {
	if (value === null || typeof value !== 'object') {
		return { [prefix]: value }
	}
	if (Array.isArray(value)) {
		return { [prefix]: JSON.stringify(value) }
	}
	let flat = {}
	for (const [key, nested] of Object.entries(value)) {
		flat = {
			...flat,
			...flatten(nested, prefix === '' ? key : prefix + '.' + key),
		}
	}
	return flat
}

/**
 * The table of a result: its columns in first-seen order and its flat rows;
 * null when the result has no list.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
 * @param {object} result The GraphQL result.
 * @return {{columns: Array<string>, rows: Array<object>}|null} The table.
 */
export function tableOf(result) {
	const rows = firstList(result?.data ?? null)
	if (rows === null) {
		return null
	}
	const columns = []
	const flatRows = rows.map((row) => {
		const flat = flatten(row)
		for (const column of Object.keys(flat)) {
			if (!columns.includes(column)) {
				columns.push(column)
			}
		}
		return flat
	})
	return { columns, rows: flatRows }
}

/**
 * The variables typed, parsed; an empty field is no variables.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
 * @param {string} text The typed JSON.
 * @return {{ok: boolean, value: object}} The variables, or ok false when they are not a JSON object.
 */
export function parseVariables(text) {
	if (String(text ?? '').trim() === '') {
		return { ok: true, value: {} }
	}
	try {
		const value = JSON.parse(text)
		if (value === null || typeof value !== 'object' || Array.isArray(value)) {
			return { ok: false, value: {} }
		}
		return { ok: true, value }
	} catch {
		return { ok: false, value: {} }
	}
}

export default {
	name: 'QueryConsoleSection',

	components: {
		CodeMirror,
		NcButton,
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			query: STARTER_QUERY,
			variables: '',
			busy: false,
			error: null,
			result: null,
			showRaw: false,
		}
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — the editor theme.
		 * @return {boolean} Whether the dark theme is active.
		 */
		dark() {
			return getTheme() === 'dark'
		},

		/**
		 * @spec exclude UI plumbing — the variables editor's language.
		 * @return {object} The JSON language support.
		 */
		jsonLang() {
			return json()
		},

		/**
		 * The row count, truncation and duration the server reported.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {{rows: number, truncated: boolean, durationMs: number}} The figures.
		 */
		stats() {
			const extensions = this.result?.extensions ?? {}
			return {
				rows: Number(extensions.rows ?? 0),
				truncated: extensions.truncated === true,
				durationMs: Number(extensions.durationMs ?? 0),
			}
		},

		/**
		 * @spec exclude UI plumbing — the result's table for the template.
		 * @return {object|null} The table.
		 */
		table() {
			return this.result ? tableOf(this.result) : null
		},

		/**
		 * The messages of the errors the query answered with.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {Array<string>} The messages.
		 */
		queryErrors() {
			const errors = this.result?.errors
			return Array.isArray(errors)
				? errors.map((error) => String(error?.message ?? ''))
				: []
		},

		/**
		 * @spec exclude UI plumbing — the raw result as text.
		 * @return {string} The JSON.
		 */
		rawJson() {
			return JSON.stringify(this.result, null, 2)
		},
	},

	methods: {
		/**
		 * The request body, or null after naming invalid variables.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {object|null} The body.
		 */
		requestBody() {
			const variables = parseVariables(this.variables)
			if (!variables.ok) {
				this.error = t('openregister', 'The variables are not valid JSON.')
				return null
			}
			return { query: this.query, variables: variables.value }
		},

		/**
		 * Run the query and show its result; a refusal shows the server's message.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {Promise<void>}
		 */
		async run() {
			this.error = null
			const body = this.requestBody()
			if (body === null) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(
					generateUrl('/apps/openregister/api/operations/query'),
					body,
				)
				this.result = data
			} catch (error) {
				this.result = null
				this.error =
					error?.response?.data?.message
					|| t('openregister', 'The query could not be run.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Download the result's list as CSV or JSON through the export route.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @param {string} format `csv` or `json`.
		 * @return {Promise<void>}
		 */
		async download(format) {
			this.error = null
			const body = this.requestBody()
			if (body === null) {
				return
			}
			this.busy = true
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/operations/query/export'),
					{ ...body, format },
					{ responseType: 'blob' },
				)
				this.save(response.data, fileNameOf(response.headers, format))
			} catch (error) {
				this.error =
					(await refusalMessage(error))
					|| t('openregister', 'The download failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Hand a downloaded file to the browser.
		 *
		 * @spec exclude UI plumbing — browser download of a blob.
		 * @param {Blob} blob The file.
		 * @param {string} name Its name.
		 * @return {void}
		 */
		save(blob, name) {
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = name
			document.body.appendChild(link)
			link.click()
			link.remove()
			URL.revokeObjectURL(url)
		},

		/**
		 * @spec exclude UI plumbing — a table cell's text.
		 * @param {*} value The cell value.
		 * @return {string} The text.
		 */
		cellText(value) {
			return value === null || value === undefined ? '' : String(value)
		},
	},
}

/**
 * The file name a download response names, or `query.<format>`.
 *
 * @param {object} headers The response headers.
 * @param {string} format `csv` or `json`.
 * @return {string} The name.
 */
function fileNameOf(headers, format) {
	const disposition = String(headers?.['content-disposition'] ?? '')
	const match = disposition.match(/filename="?([^";]+)"?/)
	return match ? match[1] : 'query.' + format
}

/**
 * The message of a refused download, whose body arrives as a blob.
 *
 * @param {object} error The axios error.
 * @return {Promise<string|null>} The message, if the body carried one.
 */
async function refusalMessage(error) {
	const data = error?.response?.data
	if (!data) {
		return null
	}
	try {
		const text =
			typeof Blob !== 'undefined' && data instanceof Blob
				? await blobText(data)
				: JSON.stringify(data)
		return JSON.parse(text)?.message ?? null
	} catch {
		return null
	}
}

/**
 * A blob's text, through `Blob.text()` where the browser has it, else a FileReader.
 *
 * @param {Blob} blob The blob.
 * @return {Promise<string>} The text.
 */
function blobText(blob) {
	if (typeof blob.text === 'function') {
		return blob.text()
	}
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(String(reader.result))
		reader.onerror = () => reject(reader.error)
		reader.readAsText(blob)
	})
}
</script>

<style scoped>
.queryConsole__note,
.queryConsole__stats {
	color: var(--color-text-maxcontrast);
}

.queryConsole__label {
	display: block;
	font-weight: bold;
	margin-block: 12px 4px;
}

.queryConsole__editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	min-height: 120px;
}

.queryConsole__editor--small {
	min-height: 60px;
}

.queryConsole__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block: 12px;
}

.queryConsole__error {
	color: var(--color-error-text);
}

.queryConsole__truncated {
	color: var(--color-warning-text);
}

.queryConsole__tableWrap {
	overflow-x: auto;
	margin-block: 8px;
}

.queryConsole__table {
	border-collapse: collapse;
}

.queryConsole__table th,
.queryConsole__table td {
	border: 1px solid var(--color-border);
	padding: 4px 8px;
	text-align: start;
}

.queryConsole__raw {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 8px;
	border-radius: var(--border-radius);
	overflow-x: auto;
}
</style>
