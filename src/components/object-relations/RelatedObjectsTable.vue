<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div v-if="objects.length > 0" class="search-list-table">
		<table class="table">
			<thead>
				<tr class="table-row">
					<th scope="col">ID</th>
					<th scope="col">URI</th>
					<th scope="col">Schema</th>
					<th scope="col">Register</th>
					<th scope="col">Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="object in objects"
					:key="object['@self'].id"
					class="table-row">
					<td>{{ object['@self'].id }}</td>
					<td>{{ object['@self'].uri }}</td>
					<td>{{ object['@self'].schema }}</td>
					<td>{{ object['@self'].register }}</td>
					<td>
						<NcButton @click="$emit('view', object)">
							<template #icon>
								<Eye :size="20" />
							</template>
							View Object
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
	<NcNoteCard v-else type="info">
		<p>{{ emptyText }}</p>
	</NcNoteCard>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import Eye from 'vue-material-design-icons/Eye.vue'

/**
 * A table of objects related to the one being viewed (its uses, what uses
 * it, its contracts), each with a button to view that object.
 *
 * @spec exclude Presentational table; the object modal owns the data and the navigation.
 */
export default {
	name: 'RelatedObjectsTable',
	components: {
		NcButton,
		NcNoteCard,
		Eye,
	},

	props: {
		/** The related objects, each with its `@self` metadata. */
		objects: {
			type: Array,
			required: true,
		},

		/** The note shown when there are no related objects. */
		emptyText: {
			type: String,
			required: true,
		},
	},

	emits: ['view'],
}
</script>
