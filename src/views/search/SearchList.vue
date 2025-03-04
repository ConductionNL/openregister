<script setup>
import { navigationStore, objectStore, schemaStore, searchStore } from '../../store/store.js'
</script>

<template>
	<div>
		<table class="table">
			<thead>
				<tr class="table-row">
					<th>ObjectID</th>
					<th>Created</th>
					<th>Updated</th>
					<th>Amount of files</th>
					<th>Schema properties</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(result) in searchStore.searchObjectsResult" :key="result.uuid" class="table-row">
					<td>{{ result.uuid }}</td>
					<td>{{ getValidISOstring(result.created) ? new Date(result.created).toLocaleString() : 'N/A' }}</td>
					<td>{{ getValidISOstring(result.updated) ? new Date(result.updated).toLocaleString() : 'N/A' }}</td>
					<td><NcCounterBubble :count="result.files ? result.files.length : 0" /></td>
					<td><NcCounterBubble :count="schemaProperties.length" /></td>
					<td>
						<NcActions>
							<NcActionButton @click="navigationStore.setSelected('objects'); objectStore.setObjectItem(result)">
								<template #icon>
									<Eye :size="20" />
								</template>
								View
							</NcActionButton>
							<NcActionButton @click="navigationStore.setModal('editObject'); objectStore.setObjectItem(result)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								Edit
							</NcActionButton>
						</NcActions>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>
<script>
import { NcActions, NcActionButton, NcCounterBubble } from '@nextcloud/vue'
import getValidISOstring from '../../services/getValidISOstring.js'

import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'SearchList',
	components: {
		NcActions,
		NcActionButton,
	},
	computed: {
		selectedSchema() {
			return schemaStore.schemaList.find((schema) => schema.id.toString() === searchStore.searchObjectsResult?.[0]?.schema?.toString())
		},
		schemaProperties() {
			return Object.values(this.selectedSchema.properties) || []
		},
	},
	mounted() {
		// something
	},
	methods: {
		openLink(link, type = '') {
			window.open(link, type)
		},
	},
}
</script>

<style scoped>
.table {
	width: 100%;
	border-collapse: collapse;
}

.table-row {
  color: var(--color-main-text);
  border-bottom: 1px solid var(--color-border);
}

.table-row > td {
  height: 55px;
  padding: 0 10px;
}
.table-row > th {
    padding: 0 10px;
}
</style>
