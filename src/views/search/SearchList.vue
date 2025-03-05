<script setup>
import { navigationStore, objectStore, schemaStore, searchStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<div class="search-list">
		<div class="search-list-table">
			<VueDraggable v-model="activeHeaders"
				target=".sort-target"
				animation="150"
				draggable="> *:not(:last-child)">
				<table class="table">
					<thead>
						<tr class="table-row sort-target">
							<template v-for="header in activeHeaders">
								<th v-if="header.enabled" :key="header.id">
									<span class="sticky-header">
										{{ header.label }}
									</span>
								</th>
							</template>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(result) in searchStore.searchObjectsResult?.results" :key="result.uuid" class="table-row">
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
									<span v-else-if="header.id === 'actions'">
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
									</span>
									<span v-else>
										{{ result[header.key] }}
									</span>
								</td>
							</template>
						</tr>
					</tbody>
				</table>
			</VueDraggable>
		</div>
		<NcButton>
			Previous
		</NcButton>
		<NcButton :disabled="true">
			Previous
		</NcButton>
		<NcButton type="primary">
			Previous
		</NcButton>

		<div class="pagination">
			<BPagination
				v-model="currentPage"
				:total-rows="searchStore.searchObjectsResult.total"
				:per-page="14"
				@change="(page) => EventBus.$emit('page-change', page)" />
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcCounterBubble, NcButton } from '@nextcloud/vue'
import { BPagination } from 'bootstrap-vue'
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
			console,
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
				{
					id: 'actions',
					label: 'Actions',
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
			// pagination
			currentPage: 1,
		}
	},
	computed: {
		selectedSchema() {
			return schemaStore.schemaList.find((schema) => schema.id.toString() === searchStore.searchObjectsResult?.results?.[0]?.schema?.toString())
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
		EventBus.$on('reset-page', () => {
			this.currentPage = 1
		})
	},
	beforeDestroy() {
		// Clean up the event listener
		EventBus.$off('object-search-set-column-filter')
		EventBus.$off('reset-page')
	},
	mounted() {
		// something
		this.setActiveHeaders()
	},
	methods: {
		setActiveHeaders() {
			this.activeHeaders = _.cloneDeep(this.headers.filter((header) => header.enabled))
		},
		openLink(link, type = '') {
			window.open(link, type)
		},
	},
}
</script>

<style scoped>
.search-list-table {
    overflow-x: auto;
}

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
.table-row > th > .sticky-header {
    position: sticky;
    left: 0;
}

.sort-target > th {
    cursor: move;
}

.pagination {
    margin-block-start: 1rem;
    display: flex;
}
.pagination :deep(.page-item > .page-link) {
    color: var(--color-primary-element-light-text) !important;
    background-color: var(--color-primary-element-light) !important;
    padding: calc((var(--default-clickable-area) - 1lh)/2) calc(3*var(--default-grid-baseline)) !important;
    font-size: var(--default-font-size) !important;
    min-height: var(--default-clickable-area) !important;
    margin: 3px !important;
    margin-inline-start: 0 !important;
    border-radius: var(--border-radius-element) !important;
}
</style>
