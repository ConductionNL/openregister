<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			title="Sources"
			description="Manage your data sources and their configurations"
			:show-title="true"
			:schema="sourceSchema"
			:objects="sourceStore.sourceList"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="viewMode"
			:selectable="true"
			:selected-ids="selectedSources"
			:include-fields="['title', 'description', 'databaseUrl', 'type']"
			:field-overrides="fieldOverrides"
			:show-copy-action="false"
			:actions="customActions"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			add-label="Add Source"
			empty-text="No sources found"
			:refreshing="isRefreshing"
			@create="onSaveSource"
			@edit="onSaveSource"
			@delete="onDeleteSource"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event"
			@select="selectedSources = $event">
			<!-- Custom card template -->
			<template #card="{ object }">
				<CnCard
					:title="object.title"
					:description="object.description"
					:title-tooltip="object.description"
					:stats="mapSourceStats(object)">
					<template #icon>
						<DatabaseArrowRightOutline :size="20" />
					</template>
					<template #actions>
						<NcActions :primary="true" menu-name="Actions">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click
								@click="$emit('view', object)">
								<template #icon>
									<Eye :size="20" />
								</template>
								View
							</NcActionButton>
							<NcActionButton close-after-click
								@click="$emit('edit', object)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								Edit
							</NcActionButton>
							<NcActionButton close-after-click
								@click="$emit('delete', object)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								Delete
							</NcActionButton>
						</NcActions>
					</template>
				</CnCard>
			</template>

			<!-- Custom column: title with description -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<strong>{{ row.title }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Custom column: database URL -->
			<template #column-databaseUrl="{ row }">
				<span v-if="row.databaseUrl" class="truncatedUrl">{{ row.databaseUrl }}</span>
				<span v-else>-</span>
			</template>

			<!-- Custom column: created -->
			<template #column-created="{ row }">
				{{ row.created ? new Date(row.created).toLocaleDateString() : '-' }}
			</template>

			<!-- Custom column: updated -->
			<template #column-updated="{ row }">
				{{ row.updated ? new Date(row.updated).toLocaleDateString() : '-' }}
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage, CnCard } from '@conduction/nextcloud-vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'SourcesIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		CnCard,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedSources: [],
			isRefreshing: false,
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		sourceSchema() {
			return {
				title: 'Source',
				properties: {
					title: {
						type: 'string',
						title: t('openregister', 'Title'),
						order: 1,
					},
					description: {
						type: 'string',
						title: t('openregister', 'Description'),
						format: 'textarea',
						order: 2,
					},
					databaseUrl: {
						type: 'string',
						title: t('openregister', 'Database URL'),
						order: 3,
					},
					type: {
						type: 'string',
						title: t('openregister', 'Type'),
						enum: ['internal', 'mongodb'],
						default: 'internal',
						order: 4,
					},
				},
				required: ['title'],
			}
		},
		customActions() {
			return [
				{
					label: 'View',
					icon: Eye,
					handler: (row) => this.openViewModal(row),
				},
			]
		},
		fieldOverrides() {
			return {
				type: {
					enumLabels: { internal: 'Internal', mongodb: 'MongoDB' },
				},
			}
		},
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'type', label: t('openregister', 'Type') },
				{ key: 'databaseUrl', label: t('openregister', 'Database URL') },
				{ key: 'created', label: t('openregister', 'Created'), sortable: true },
				{ key: 'updated', label: t('openregister', 'Updated'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page
			const limit = this.pagination.limit
			const total = sourceStore.sourceList.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
	},
	mounted() {
		sourceStore.refreshSourceList(null, true)
	},
	methods: {
		openViewModal(row) {
			sourceStore.setSourceItem(row)
			navigationStore.setModal('viewSource')
		},
		openEditDialog(row) {
			this.$refs.indexPage.openFormDialog(row)
		},
		openDeleteDialog(row) {
			this.$refs.indexPage.openDeleteDialog(row)
		},
		async onDeleteSource(id) {
			const source = sourceStore.sourceList.find(s => s.id === id)
			if (!source) return
			try {
				await sourceStore.deleteSource(source)
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setSingleDeleteResult({ error: error.message || 'An error occurred while deleting the source' })
			}
		},
		async onSaveSource(formData) {
			try {
				await sourceStore.saveSource({
					...formData,
					type: formData.type || 'internal',
				})
				this.$refs.indexPage.setFormResult({ success: true })
				sourceStore.refreshSourceList()
			} catch (error) {
				this.$refs.indexPage.setFormResult({ error: error.message || 'An error occurred while saving the source' })
			}
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await sourceStore.refreshSourceList()
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		mapSourceStats(object) {
			return [
				{ label: t('openregister', 'Type'), value: object.type || 'Unknown' },
				object.databaseUrl
					? { label: t('openregister', 'Database URL'), value: object.databaseUrl }
					: null,
			].filter(Boolean)
		},
	},
}
</script>

<style scoped>
.titleContent {
	display: flex;
	flex-direction: column;
}

.truncatedUrl {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}
</style>
