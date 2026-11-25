<!--
  The query console on the operations page: a read-only GraphQL query run as
  the administrator, with its first list shown as a table and downloadable as
  CSV or JSON. Runs POST /api/operations/query and
  POST /api/operations/query/export (administrator only, each run audited).

  @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<section v-if="isAdmin" class="consoleSection queryConsole">
		<h2>{{ t('openregister', 'Query console') }}</h2>

		<div class="queryConsoleEditor">
			<p>{{ t('openregister', 'Query') }}</p>
			<CodeMirror
				v-model="query"
				:basic="true" />
		</div>

		<div class="queryConsoleEditor">
			<p>{{ t('openregister', 'Variables (JSON)') }}</p>
			<CodeMirror
				v-model="variablesText"
				:basic="true"
				:lang="jsonLang" />
		</div>

		<p class="queryConsoleNote">
			{{ t('openregister', 'Runs as you, in your active organisation. Read only. At most 1,000 rows and 30 seconds. Each run is recorded.') }}
		</p>

		<div class="queryConsoleActions">
			<NcButton
				variant="primary"
				:disabled="running || query.trim() === ''"
				@click="run">
				{{ running ? t('openregister', 'Running…') : t('openregister', 'Run') }}
			</NcButton>
			<NcButton :disabled="running || query.trim() === ''" @click="download('csv')">
				{{ t('openregister', 'Download CSV') }}
			</NcButton>
			<NcButton :disabled="running || query.trim() === ''" @click="download('json')">
				{{ t('openregister', 'Download JSON') }}
			</NcButton>
		</div>

		<p v-if="error" class="queryConsoleError" role="alert">
			{{ error }}
		</p>

		<template v-if="result !== null">
			<ul v-if="resultErrors.length > 0" class="queryConsoleErrors">
				<li v-for="(message, index) in resultErrors" :key="index">
					{{ message }}
				</li>
			</ul>

			<p class="queryConsoleSummary">
				{{
					t('openregister', 'Rows: {count}. Took {ms} ms.', {
						count: rowCount,
						ms: durationMs,
					})
				}}
			</p>
			<NcNoteCard v-if="truncated" type="warning">
				{{ t('openregister', 'The result was cut off at 1,000 rows. Narrow the query to see the rest.') }}
			</NcNoteCard>

			<NcCheckboxRadioSwitch v-model="showRaw" type="switch">
				{{ t('openregister', 'Show the raw JSON') }}
			</NcCheckboxRadioSwitch>

			<pre v-if="showRaw" class="queryConsoleRaw">{{ rawJson }}</pre>
			<p v-else-if="table === null" class="queryConsoleEmpty">
				{{ t('openregister', 'The result has no list to show as a table.') }}
			</p>
			<div v-else class="queryConsoleTableWrapper">
				<table class="queryConsoleTable">
					<thead>
						<tr>
							<th v-for="column in table.columns" :key="column" scope="col">
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
		</template>
	</section>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { defineAsyncComponent } from 'vue'

// The editor is loaded only when an administrator opens the page: it is
// large, and nobody else ever sees this section.
const CodeMirror = defineAsyncComponent(() => import('vue-codemirror6'))

const RUN = '/apps/openregister/api/operations/query'
const EXPORT = '/apps/openregister/api/operations/query/export'

/**
 * The first list in a GraphQL `data` block, the same one the export
 * downloads: a connection's `edges[].node`, or a plain list.
 *
 * @param {object|null} data The result's `data`.
 * @return {Array|null} The rows, or null when the result has no list.
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
			return value.edges.map((edge) => (edge !== null && typeof edge === 'object' && 'node' in edge ? edge.node : edge))
		}
		if (Array.isArray(value)) {
			return value
		}
	}
	return null
}

/**
 * Flatten one row to dotted columns, as the export does: nested objects
 * become `a.b`, a list stays one JSON cell.
 *
 * @param {unknown} value The row or a value inside it.
 * @param {string} prefix The path so far.
 * @return {{[column: string]: unknown}} The flat row.
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
		flat = { ...flat, ...flatten(nested, prefix === '' ? key : `${prefix}.${key}`) }
	}
	return flat
}

/**
 * The first list of a result as columns and flat rows.
 *
 * @param {object} result The GraphQL result.
 * @return {{columns: Array<string>, rows: Array<object>}|null} The table, or null without a list.
 */
export function tableOf(result) {
	const list = firstList(result?.data ?? null)
	if (list === null) {
		return null
	}
	const rows = list.map((row) => flatten(row))
	const columns = []
	for (const row of rows) {
		for (const column of Object.keys(row)) {
			if (!columns.includes(column)) {
				columns.push(column)
			}
		}
	}
	return { columns, rows }
}

/**
 * The text of a Blob, through FileReader so it also works where Blob has no text().
 *
 * @param {Blob} blob The blob.
 * @return {Promise<string>} Its text.
 */
function blobText(blob) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(String(reader.result))
		reader.onerror = () => reject(reader.error)
		reader.readAsText(blob)
	})
}

export default {
	name: 'QueryConsoleSection',

	components: {
		CodeMirror,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	data() {
		return {
			query: '',
			variablesText: '{}',
			running: false,
			error: '',
			result: null,
			showRaw: false,
			// JSON mode for the variables editor, loaded with the editor.
			jsonLang: undefined,
		}
	},

	computed: {
		/**
		 * Whether the viewer is an administrator; the console's routes refuse anyone else.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {boolean} True for an administrator.
		 */
		isAdmin() {
			return getCurrentUser()?.isAdmin === true
		},

		/**
		 * The result's first list as a table.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {{columns: Array<string>, rows: Array<object>}|null} The table.
		 */
		table() {
			return this.result === null ? null : tableOf(this.result)
		},

		/**
		 * The row count the server reported, else the rows shown.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {number} The rows.
		 */
		rowCount() {
			const rows = this.result?.extensions?.rows
			if (typeof rows === 'number') {
				return rows
			}
			return this.table?.rows.length ?? 0
		},

		/**
		 * Whether the server cut the result off at its row cap.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {boolean} True when truncated.
		 */
		truncated() {
			return this.result?.extensions?.truncated === true
		},

		/**
		 * How long the run took on the server.
		 *
		 * @spec exclude Display of a server-reported number.
		 * @return {number} Milliseconds.
		 */
		durationMs() {
			return Number(this.result?.extensions?.durationMs ?? 0)
		},

		/**
		 * The messages of the result's GraphQL errors.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {Array<string>} The messages.
		 */
		resultErrors() {
			const errors = this.result?.errors
			return Array.isArray(errors) ? errors.map((error) => String(error?.message ?? error)) : []
		},

		/**
		 * The whole result, indented.
		 *
		 * @spec exclude Display formatting.
		 * @return {string} The JSON.
		 */
		rawJson() {
			return JSON.stringify(this.result, null, 2)
		},
	},

	async mounted() {
		if (this.isAdmin) {
			const { json } = await import('@codemirror/lang-json')
			this.jsonLang = json()
		}
	},

	methods: {
		/**
		 * The request body: the query and the parsed variables.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {{query: string, variables: object}|null} The body, or null when the variables are not a JSON object.
		 */
		body() {
			const text = this.variablesText.trim()
			let variables = {}
			if (text !== '') {
				try {
					variables = JSON.parse(text)
				} catch {
					variables = null
				}
			}
			if (variables === null || typeof variables !== 'object' || Array.isArray(variables)) {
				this.error = t('openregister', 'The variables must be a JSON object.')
				return null
			}
			return { query: this.query, variables }
		},

		/**
		 * Run the query and show its result.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @return {Promise<void>}
		 */
		async run() {
			this.error = ''
			const body = this.body()
			if (body === null) {
				return
			}
			this.running = true
			try {
				const response = await axios.post(generateUrl(RUN), body)
				this.result = response.data
			} catch (error) {
				this.result = null
				this.error = this.messageOf(error)
			}
			this.running = false
		},

		/**
		 * Run the query again as a download of its first list.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-the-result-downloads-as-csv-or-json
		 * @param {string} format `csv` or `json`.
		 * @return {Promise<void>}
		 */
		async download(format) {
			this.error = ''
			const body = this.body()
			if (body === null) {
				return
			}
			this.running = true
			try {
				const response = await axios.post(generateUrl(EXPORT), { ...body, format }, { responseType: 'blob' })
				this.save(response.data, `query.${format}`)
			} catch (error) {
				this.error = await this.downloadMessageOf(error)
			}
			this.running = false
		},

		/**
		 * Hand a downloaded file to the browser.
		 *
		 * @spec exclude Browser download plumbing.
		 * @param {Blob} blob The file.
		 * @param {string} name The file name.
		 * @return {void}
		 */
		save(blob, name) {
			if (typeof URL.createObjectURL !== 'function') {
				return
			}
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = name
			link.click()
			URL.revokeObjectURL(url)
		},

		/**
		 * The message of a refused run: the server's own when it gave one.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
		 * @param {object} error The axios error.
		 * @return {string} The message.
		 */
		messageOf(error) {
			return error?.response?.data?.message
				|| t('openregister', 'The query could not be run.')
		},

		/**
		 * The message of a refused download; a blob body is read as JSON first.
		 *
		 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-the-result-downloads-as-csv-or-json
		 * @param {object} error The axios error.
		 * @return {Promise<string>} The message.
		 */
		async downloadMessageOf(error) {
			const data = error?.response?.data
			if (typeof Blob !== 'undefined' && data instanceof Blob) {
				try {
					const parsed = JSON.parse(await blobText(data))
					if (parsed?.message) {
						return parsed.message
					}
				} catch {
					// Not JSON: fall through to the generic message.
				}
			}
			return this.messageOf(error)
		},

		/**
		 * A table cell as text.
		 *
		 * @spec exclude Display formatting.
		 * @param {unknown} value The cell.
		 * @return {string} The text.
		 */
		cellText(value) {
			if (value === null || value === undefined) {
				return ''
			}
			return String(value)
		},

		t,
	},
}
</script>

<style scoped>
.queryConsoleEditor {
	margin-block-end: 12px;
}

.queryConsoleNote,
.queryConsoleSummary,
.queryConsoleEmpty {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 12px;
}

.queryConsoleActions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block-end: 12px;
}

.queryConsoleError,
.queryConsoleErrors {
	color: var(--color-error-text);
}

.queryConsoleRaw {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	max-height: 400px;
	overflow: auto;
	padding: 8px;
}

.queryConsoleTableWrapper {
	max-height: 480px;
	overflow: auto;
}

.queryConsoleTable {
	border-collapse: collapse;
	width: 100%;
}

.queryConsoleTable th,
.queryConsoleTable td {
	border-bottom: 1px solid var(--color-border);
	padding: 4px 8px;
	text-align: start;
}
</style>
