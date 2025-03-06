<script setup>
import { navigationStore, objectStore, schemaStore, searchStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<div>
		<VueDraggable v-model="activeHeaders"
			target=".sort-target"
			animation="150"
			draggable="> *:not(.static-column)">
			<table class="table">
				<thead>
					<tr class="table-row sort-target">
						<th class="static-column">
							<input v-model="selectAllObjects"
								type="checkbox"
								class="cursor-pointer"
								@change="toggleSelectAllObjects()">
						</th>
						<template v-for="header in activeHeaders">
							<th v-if="header.enabled" :key="header.id">
								<span>
									{{ header.label }}
								</span>
							</th>
						</template>
						<th class="static-column">
							Actions
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(result) in searchStore.searchObjectsResult" :key="result.uuid" class="table-row">
						<td class="static-column">
							<input v-model="selectedObjects"
								:value="result.id"
								type="checkbox"
								class="cursor-pointer"
								@change="() => selectAllObjects = false">
						</td>
						<template v-for="header in activeHeaders">
							<td v-if="header.enabled" :key="header.id">
								<span v-if="header.id === 'files'">
									<NcCounterBubble :count="result.files ? result.files.length : 0" />
								</span>
								<span v-else-if="header.id === 'schemaProperties'">
									<NcCounterBubble :count="schemaProperties.length" />
								</span>
								<span v-else-if="header.id === 'created' || header.id === 'updated'">
									{{ getValidISOstring(result[header.key]) ? new Date(result[header.key]).toLocaleString() : 'N/A' }}
								</span>
								<span v-else>
									{{ result[header.key] }}
								</span>
							</td>
						</template>
						<td class="static-column">
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
		</VueDraggable>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcCounterBubble } from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'
import getValidISOstring from '../../services/getValidISOstring.js'
import _ from 'lodash'

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
			headers: [
				{
					id: 'objectId',
					label: 'ObjectID',
					key: 'uuid',
					enabled: true,
				},
				{
					id: 'created',
					label: 'Created',
					key: 'created',
					enabled: true,
				},
				{
					id: 'updated',
					label: 'Updated',
					key: 'updated',
					enabled: true,
				},
				{
					id: 'files',
					label: 'Amount of files',
					key: 'files',
					enabled: true,
				},
				{
					id: 'schemaProperties',
					label: 'Schema properties',
					key: null,
					enabled: true,
				},
			],
			/**
			 * To ensure complete compatibility between the toggle and the drag function,
			 * We need a working headers array which gets updated when a header gets toggled.
			 *
			 * This array is a copy of the headers array but with the disabled headers filtered out.
			 */
			activeHeaders: [],
			// select boxes
			selectAllObjects: false,
			selectedObjects: [],
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
	watch: {
		headers: {
			handler() {
				this.setActiveHeaders()
			},
			deep: true,
		},
	},
	created() {
		EventBus.$on('object-search-set-column-filter', (payload) => {
			this.headers.find((header) => header.id === payload.id).enabled = payload.enabled
		})
	},
	beforeDestroy() {
		// Clean up the event listener
		EventBus.$off('object-search-set-column-filter')
	},
	mounted() {
		this.setActiveHeaders()
	},
	methods: {
		setActiveHeaders() {
			this.activeHeaders = _.cloneDeep(this.headers.filter((header) => header.enabled))
		},
		openLink(link, type = '') {
			window.open(link, type)
		},
		toggleSelectAllObjects() {
			if (this.selectAllObjects) {
				this.selectedObjects = searchStore.searchObjectsResult.map((result) => result.id)
			} else {
				this.selectedObjects = []
			}
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

.sort-target > th {
    cursor: move;
}

.cursor-pointer {
    cursor: pointer !important;
}
</style>
