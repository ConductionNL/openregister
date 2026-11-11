<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<table class="statisticsTable">
		<thead>
			<tr>
				<th scope="col">
					{{ t('openregister', 'Name') }}
				</th>
				<th scope="col">
					{{ t('openregister', 'Count') }}
				</th>
				<th scope="col">
					{{ t('openregister', 'Size') }}
				</th>
			</tr>
		</thead>
		<tbody>
			<tr v-for="row in leadingRows" :key="row.label">
				<th scope="row">
					{{ row.label }}
				</th>
				<td>{{ row.count }}</td>
				<td>-</td>
			</tr>
			<tr>
				<th scope="row">
					{{ t('openregister', 'Objects') }}
				</th>
				<td>
					{{ stats?.objects?.total || 0 }}
				</td>
				<td>
					{{ formatBytes(stats?.objects?.size || 0) }}
				</td>
			</tr>
			<tr v-for="row in objectRows" :key="row.key" class="subRow">
				<th scope="row" class="indented">
					{{ row.label }}
				</th>
				<td>
					{{ stats?.objects?.[row.key] || 0 }}
				</td>
				<td>-</td>
			</tr>
			<tr v-for="row in sizedRows" :key="row.key">
				<th scope="row">
					{{ row.label }}
				</th>
				<td>{{ stats?.[row.key]?.total || 0 }}</td>
				<td>
					{{ formatBytes(stats?.[row.key]?.size || 0) }}
				</td>
			</tr>
		</tbody>
	</table>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import formatBytes from '../../services/formatBytes.js'

/**
 * The dashboard's totals table: optional count-only rows, then the objects
 * (with their invalid, deleted and locked counts), logs and files of a
 * statistics record.
 *
 * @spec exclude Presentational table; the dashboard sidebar owns the data.
 */
export default {
	name: 'StatisticsTotalsTable',

	props: {
		/** The `stats` of a totals record: objects, logs and files. */
		stats: {
			type: Object,
			default: null,
		},

		/** Count-only rows shown first: `{ label, count }`. */
		leadingRows: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		/**
		 * The object sub-rows, by the stats key they read.
		 *
		 * @return {Array<object>} The rows
		 */
		objectRows() {
			return [
				{ key: 'invalid', label: t('openregister', 'Invalid') },
				{ key: 'deleted', label: t('openregister', 'Deleted') },
				{ key: 'locked', label: t('openregister', 'Locked') },
			]
		},

		/**
		 * The rows with a count and a size, by the stats key they read.
		 *
		 * @return {Array<object>} The rows
		 */
		sizedRows() {
			return [
				{ key: 'logs', label: t('openregister', 'Logs') },
				{ key: 'files', label: t('openregister', 'Files') },
			]
		},
	},

	methods: {
		t,
		formatBytes,
	},
}
</script>

<style lang="scss" scoped>
.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;

	td,
	th {
		padding: 4px 8px;
		border-bottom: 1px solid var(--color-border);
		text-align: left;

		&:nth-child(2),
		&:nth-child(3) {
			text-align: right;
		}
	}

	/* Row headers are labels, not emphasis — keep the original visual weight. */
	tbody th {
		font-weight: normal;
	}

	.subRow td,
	.subRow th {
		color: var(--color-text-maxcontrast);
	}

	.indented {
		padding-left: 24px;
	}

	tr:last-child td,
	tr:last-child th {
		border-bottom: none;
	}
}
</style>
