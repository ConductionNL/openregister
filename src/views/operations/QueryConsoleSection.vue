<!--
  The administrator's read-only query console on the operations page.
  Runs POST /api/operations/query and downloads through POST /api/operations/query/export
  (both administrator only; the middleware refuses anyone else before the controller).

  @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<section class="consoleSection queryConsole">
		<h2>{{ t('openregister', 'Query console') }}</h2>
		<p class="queryConsoleNote">
			{{ t('openregister', 'Runs as the signed-in administrator, in the active organisation. Read only. At most 1,000 rows and 30 seconds. Every run is recorded.') }}
		</p>

		<p class="queryConsoleLabel">
			{{ t('openregister', 'GraphQL query') }}
		</p>
		<div class="queryConsoleEditor" data-test="query-editor">
			<CodeMirror
				v-model="query"
				:basic="true"
				:dark="dark" />
		</div>

		<p class="queryConsoleLabel">
			{{ t('openregister', 'Variables (JSON)') }}
		</p>
		<div class="queryConsoleEditor" data-test="variables-editor">
			<CodeMirror
				v-model="variablesText"
				:basic="true"
				:dark="dark"
				:lang="jsonLanguage" />
		</div>

		<div class="queryConsoleActions">
			<NcButton
				variant="primary"
				:disabled="running || query.trim() === ''"
				@click="run">
				{{ t('openregister', 'Run query') }}
			</NcButton>
			<NcButton :disabled="running || query.trim() === ''" @click="download('csv')">
				{{ t('openregister', 'Download CSV') }}
			</NcButton>
			<NcButton :disabled="running || query.trim() === ''" @click="download('json')">
				{{ t('openregister', 'Download JSON') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error !== ''" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-if="result !== null">
			<p class="queryConsoleFacts">
				{{ t('openregister', 'Rows: {count}. Duration: {duration} ms.', { count: rowCount, duration: durationMs }) }}
			</p>
			<NcNoteCard v-if="truncated" type="warning">
				{{ t('openregister', 'The result was cut off at 1,000 rows.') }}
			</NcNoteCard>

			<table v-if="table !== null" class="queryConsoleTable">
				<thead>
					<tr>
						<th v-for="column in table.columns" :key="column">
							{{ column }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, index) in table.rows" :key="index">
						<td v-for="column in table.columns" :key="column">
							{{ cell(row, column) }}
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="queryConsoleNoList">
				{{ t('openregister', 'The result has no list to show as a table.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="showRaw">
				{{ t('openregister', 'Show the raw result') }}
			</NcCheckboxRadioSwitch>
			<pre v-if="showRaw" class="queryConsoleRaw">{{ rawResult }}</pre>
		</template>
	</section>
</template>

<script>
import { json } from '@codemirror/lang-json'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import { getTheme } from '../../services/getTheme.js'
import { tableOf } from './queryConsoleTable.js'

/** The route a run posts to. */
export const RUN_ROUTE = '/apps/openregister/api/operations/query'

/** The route a download posts to. */
export const EXPORT_ROUTE = '/apps/openregister/api/operations/query/export'

/**
 * The query console: a query, its variables, a run, the first list as a table and two downloads.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
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
			result: null,
			error: '',
			showRaw: false,
		}
	},

	computed: {
		/**
		 * The JSON language for the variables editor.
		 *
		 * @return {object} The language extension
		 */
		jsonLanguage() {
			return json()
		},

		/**
		 * Whether the editors follow the dark theme.
		 *
		 * @return {boolean} True in the dark theme
		 */
		dark() {
			return getTheme() === 'dark'
		},

		/**
		 * The first list of the result as a table, or null when the result has none.
		 *
		 * @return {{columns: Array<string>, rows: Array<object>}|null} The table
		 */
		table() {
			return this.result === null ? null : tableOf(this.result)
		},

		/**
		 * The rows the run returned, as the server counted them.
		 *
		 * @return {number} The count
		 */
		rowCount() {
			return Number(this.result?.extensions?.rows ?? this.table?.rows.length ?? 0)
		},

		/**
		 * How long the run took, in milliseconds.
		 *
		 * @return {number} The duration
		 */
		durationMs() {
			return Number(this.result?.extensions?.durationMs ?? 0)
		},

		/**
		 * Whether the server cut the result at its row cap.
		 *
		 * @return {boolean} True when cut
		 */
		truncated() {
			return this.result?.extensions?.truncated === true
		},

		/**
		 * The whole result as indented JSON.
		 *
		 * @return {string} The JSON
		 */
		rawResult() {
			return JSON.stringify(this.result, null, 2)
		},
	},

	methods: {
		t,

		/**
		 * One cell as text.
		 *
		 * @param {object} row The flattened row
		 * @param {string} column The column
		 * @return {string} The text
		 */
		cell(row, column) {
			const value = row[column]
			return value === undefined || value === null ? '' : String(value)
		},

		/**
		 * The request body: the query and its parsed variables, or null after naming a parse error.
		 *
		 * @return {object|null} The body
		 */
		body() {
			const text = this.variablesText.trim()
			let variables = {}
			if (text !== '') {
				try {
					variables = JSON.parse(text)
				} catch {
					variables = undefined
				}
			}

			if (variables === undefined || variables === null || typeof variables !== 'object' || Array.isArray(variables)) {
				this.error = t('openregister', 'The variables are not valid JSON.')
				return null
			}

			return { query: this.query, variables }
		},

		/**
		 * The message a failed request or a result carries.
		 *
		 * @param {object|null} payload The response body or GraphQL result
		 * @return {string} The message, empty when there is none
		 */
		messageOf(payload) {
			if (Array.isArray(payload?.errors) && payload.errors.length > 0) {
				return String(payload.errors[0]?.message ?? '')
			}

			return String(payload?.message ?? payload?.error ?? '')
		},

		/**
		 * Run the query and show its result.
		 *
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
				const response = await axios.post(generateUrl(RUN_ROUTE), body)
				this.result = response.data
				const message = this.messageOf(response.data)
				if (message !== '') {
					this.error = t('openregister', 'The query could not run: {message}', { message })
				}
			} catch (failure) {
				this.result = null
				this.error = t('openregister', 'The query could not run: {message}', {
					message: this.messageOf(failure?.response?.data) || String(failure?.message ?? ''),
				})
			} finally {
				this.running = false
			}
		},

		/**
		 * Run the query through the export route and save the first list as a file.
		 *
		 * @param {string} format `csv` or `json`
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
				const response = await axios.post(generateUrl(EXPORT_ROUTE), { ...body, format }, { responseType: 'blob' })
				const disposition = String(response.headers?.['content-disposition'] ?? '')
				const named = disposition.match(/filename="?([^";]+)"?/)
				this.save(response.data, named !== null ? named[1] : `query.${format}`)
			} catch (failure) {
				let payload = failure?.response?.data
				if (typeof payload?.text === 'function') {
					try {
						payload = JSON.parse(await payload.text())
					} catch {
						payload = null
					}
				}
				this.error = t('openregister', 'The query could not run: {message}', {
					message: this.messageOf(payload) || String(failure?.message ?? ''),
				})
			} finally {
				this.running = false
			}
		},

		/**
		 * Hand a downloaded file to the browser.
		 *
		 * @param {Blob} blob The file
		 * @param {string} filename Its name
		 * @return {void}
		 */
		save(blob, filename) {
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = filename
			document.body.appendChild(link)
			link.click()
			link.remove()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.queryConsoleNote {
	color: var(--color-text-maxcontrast);
}

.queryConsoleLabel {
	margin-top: 8px;
	font-weight: bold;
}

.queryConsoleEditor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	min-height: 80px;
}

.queryConsoleActions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 12px 0;
}

.queryConsoleTable {
	display: block;
	max-width: 100%;
	overflow-x: auto;
	border-collapse: collapse;
}

.queryConsoleTable th,
.queryConsoleTable td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	white-space: nowrap;
}

.queryConsoleRaw {
	max-height: 400px;
	overflow: auto;
	white-space: pre-wrap;
}
</style>
