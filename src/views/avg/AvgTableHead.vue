<!--
	SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
	SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<thead>
		<tr>
			<th v-for="label in labels" :key="label" scope="col">
				{{ label }}
			</th>
		</tr>
	</thead>
</template>

<script>
/**
 * The header row of an AVG table: one column header per label, in order.
 * Styled by the `.avgTable` rules of the screen that draws it.
 *
 * @spec exclude Presentational table header; no behaviour of its own.
 */
export default {
	name: 'AvgTableHead',

	props: {
		/**
		 * The translated column labels, in order.
		 */
		labels: {
			type: Array,
			required: true,
		},
	},
}
</script>
