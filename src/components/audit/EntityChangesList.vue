<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  EntityChangesList — the edit history of one schema or register: who changed
  it, when, why (the cause), and each changed path with its old and new value
  (history-schema-and-settings-edits-audited, task 3.1). It reads
  `GET /api/{schemas|registers}/{id}/changes`, which is administrator-only, so
  the pages show it to administrators only.
-->
<template>
	<div class="entityChanges">
		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="error" class="entityChanges__error">
			{{ error }}
		</p>
		<p v-else-if="rows.length === 0" class="entityChanges__empty">
			{{ t('openregister', 'No changes recorded') }}
		</p>
		<ul v-else class="entityChanges__list">
			<li v-for="row in rows" :key="row.uuid" class="entityChanges__row">
				<div class="entityChanges__head">
					<strong class="entityChanges__who">{{ row.userName || row.user }}</strong>
					<span class="entityChanges__when">{{ when(row.created) }}</span>
					<span v-if="row.cause" class="entityChanges__cause">{{ row.cause }}</span>
					<span class="entityChanges__action">{{ verb(row.action) }}</span>
				</div>
				<table v-if="fields(row).length > 0" class="entityChanges__fields">
					<thead>
						<tr>
							<th>{{ t('openregister', 'Path') }}</th>
							<th>{{ t('openregister', 'Before') }}</th>
							<th>{{ t('openregister', 'After') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="field in fields(row)" :key="field.path">
							<td class="entityChanges__path">
								{{ field.path }}
							</td>
							<td>{{ shown(field.old) }}</td>
							<td>{{ shown(field.new) }}</td>
						</tr>
					</tbody>
				</table>
			</li>
		</ul>
		<button
			v-if="hasMore && !loading"
			class="entityChanges__more"
			@click="load(page + 1)">
			{{ t('openregister', 'Load more') }}
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'EntityChangesList',

	components: {
		NcLoadingIcon,
	},

	props: {
		/** `schemas` or `registers`. */
		kind: {
			type: String,
			required: true,
			validator: (value) => ['schemas', 'registers'].includes(value),
		},

		/** The id of the schema or register. */
		entityId: {
			type: [Number, String],
			required: true,
		},
	},

	data() {
		return {
			rows: [],
			page: 1,
			hasMore: false,
			loading: false,
			error: null,
		}
	},

	watch: {
		entityId() {
			this.rows = []
			this.load(1)
		},
	},

	mounted() {
		this.load(1)
	},

	methods: {
		t,

		/**
		 * Load one page of changes; a later page is appended.
		 *
		 * @param {number} page The page.
		 * @return {Promise<void>}
		 * @spec openspec/changes/history-schema-and-settings-edits-audited/tasks.md#task-3-1
		 */
		async load(page) {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/openregister/api/{kind}/{id}/changes', { kind: this.kind, id: this.entityId })
				const response = await axios.get(url, { params: { _page: page, _limit: 20 } })
				const data = response?.data ?? {}
				const results = Array.isArray(data.results) ? data.results : []
				this.rows = page === 1 ? results : [...this.rows, ...results]
				this.page = page
				this.hasMore = data.hasMore === true
			} catch {
				this.error = t('openregister', 'Error loading audit trails')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The changed paths of a row.
		 *
		 * @param {object} row The row.
		 * @return {Array<{path: string, old: unknown, new: unknown}>}
		 */
		fields(row) {
			const fields = row?.changed?.fields
			return Array.isArray(fields) ? fields : []
		},

		/**
		 * A value as text: JSON for structures, a dash for nothing.
		 *
		 * @param {unknown} value The value.
		 * @return {string}
		 */
		shown(value) {
			if (value === null || value === undefined) {
				return '—'
			}
			if (typeof value === 'object') {
				return JSON.stringify(value)
			}
			return String(value)
		},

		/**
		 * The moment of a row, as the browser writes it.
		 *
		 * @param {string} created The creation moment.
		 * @return {string}
		 */
		when(created) {
			const time = new Date(created)
			return Number.isNaN(time.getTime()) ? '' : time.toLocaleString()
		},

		/**
		 * The verb of an action such as `schema.updated`.
		 *
		 * @param {string} action The action.
		 * @return {string}
		 */
		verb(action) {
			const verb = String(action ?? '').split('.').pop()
			return {
				created: t('openregister', 'Created'),
				updated: t('openregister', 'Updated'),
				deleted: t('openregister', 'Deleted'),
			}[verb] ?? verb
		},
	},
}
</script>

<style scoped>
.entityChanges__list {
	list-style: none;
	padding: 0;
}

.entityChanges__row {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.entityChanges__head {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: baseline;
}

.entityChanges__when,
.entityChanges__cause,
.entityChanges__action {
	color: var(--color-text-maxcontrast);
}

.entityChanges__fields {
	margin-top: 4px;
	width: 100%;
}

.entityChanges__fields th {
	text-align: start;
	color: var(--color-text-maxcontrast);
}

.entityChanges__path {
	font-family: var(--font-face-monospace, monospace);
}

.entityChanges__error {
	color: var(--color-error-text);
}
</style>
