<!--
	SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
	SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<table :style="{ '--entity-stats-label-width': labelWidth }">
		<tbody>
			<tr v-for="row in rows" :key="row.key">
				<th scope="row">
					<strong>{{ row.label }}</strong>
				</th>
				<td>
					<span v-if="row.badge" class="badge" :class="row.badge">{{
						row.value
					}}</span>
					<template v-else>
						{{ row.value }}
					</template>
				</td>
			</tr>
		</tbody>
	</table>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { displayDateTime } from '../../services/displayDateTime.js'

/**
 * A two-column label/value table for one entity: a row header per label and
 * the value beside it, drawn as a badge when the row names a badge class.
 *
 * @spec exclude Presentational entity statistics table; no behaviour of its own.
 */
/**
 * Every row the table can draw for an entity, by field key; the source row
 * only when the entity has a source.
 *
 * @param {object} entity The entity
 * @return {Object<string, object|null>} The rows by key
 */
function statRows(entity) {
	return {
		value: { label: t('openregister', 'Value'), value: entity.value },
		type: { label: t('openregister', 'Type'), value: entity.type, badge: 'badge-type' },
		category: { label: t('openregister', 'Category'), value: entity.category, badge: 'badge-category' },
		detectedAt: { label: t('openregister', 'Detected At'), value: displayDateTime(entity.detectedAt) },
		confidence: {
			label: t('openregister', 'Confidence Score'),
			value: entity.confidence ? (entity.confidence * 100).toFixed(2) + '%' : '-',
		},

		source: entity.source ? { label: t('openregister', 'Source'), value: entity.source } : null,
		relations: { label: t('openregister', 'Relations'), value: entity.relationCount || 0 },
	}
}

export default {
	name: 'EntityStatsTable',

	props: {
		/**
		 * The entity whose statistics are shown.
		 */
		entity: {
			type: Object,
			required: true,
		},

		/**
		 * Which rows to show, in order: value, type, category, detectedAt,
		 * confidence, source (only when the entity has one) and relations.
		 */
		fields: {
			type: Array,
			required: true,
		},

		/**
		 * Width of the label column.
		 */
		labelWidth: {
			type: String,
			default: '40%',
		},
	},

	computed: {
		/**
		 * The rows to draw: `{ key, label, value, badge? }`.
		 *
		 * @return {Array} The rows
		 */
		rows() {
			const all = statRows(this.entity)
			return this.fields
				.filter((key) => all[key])
				.map((key) => ({ key, ...all[key] }))
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

table {
	width: 100%;
	border-collapse: collapse;
}

tbody tr {
	border-bottom: 1px solid var(--color-border);
}

tbody tr:last-child {
	border-bottom: none;
}

td,
th {
	padding: 12px 16px;
	text-align: left;
	font-weight: normal;
}

td:first-child,
th:first-child {
	width: var(--entity-stats-label-width);
	color: var(--color-text-maxcontrast);
}

@include badge-rules-2;
</style>
