<script setup>
import { navigationStore, objectStore, schemaStore, searchStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<div>
		<table class="table">
			<thead>
				<tr class="table-row">
					<th v-if="columnFilter.objectId">
						ObjectID
					</th>
					<th v-if="columnFilter.created">
						Created
					</th>
					<th v-if="columnFilter.updated">
						Updated
					</th>
					<th v-if="columnFilter.files">
						Amount of files
					</th>
					<th v-if="columnFilter.schemaProperties">
						Schema properties
					</th>
					<th v-if="columnFilter.actions">
						Actions
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(result) in searchStore.searchObjectsResult" :key="result.uuid" class="table-row">
					<td v-if="columnFilter.objectId">
						{{ result.uuid }}
					</td>
					<td v-if="columnFilter.created">
						{{ getValidISOstring(result.created) ? new Date(result.created).toLocaleString() : 'N/A' }}
					</td>
					<td v-if="columnFilter.updated">
						{{ getValidISOstring(result.updated) ? new Date(result.updated).toLocaleString() : 'N/A' }}
					</td>
					<td v-if="columnFilter.files">
						<NcCounterBubble :count="result.files ? result.files.length : 0" />
					</td>
					<td v-if="columnFilter.schemaProperties">
						<NcCounterBubble :count="schemaProperties.length" />
					</td>
					<td v-if="columnFilter.actions">
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
	data() {
		return {
			columnFilter: {
				objectId: true,
				created: true,
				updated: true,
				files: true,
				schemaProperties: true,
				actions: true,
			},
		}
	},
	computed: {
		selectedSchema() {
			return schemaStore.schemaList.find((schema) => schema.id.toString() === searchStore.searchObjectsResult?.[0]?.schema?.toString())
		},
		schemaProperties() {
			return Object.values(this.selectedSchema.properties) || []
		},
	},
	created() {
		EventBus.$on('object-search-set-column-filter', (payload) => {
			this.columnFilter = {
				...this.columnFilter,
				...payload,
			}
		})
	},
	beforeDestroy() {
		// Clean up the event listener
		EventBus.$off('object-search-set-column-filter')
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
