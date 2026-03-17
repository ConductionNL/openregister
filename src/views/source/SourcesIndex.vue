<script setup>
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			title="Sources"
			description="Manage your data sources and their configurations"
			:show-title="true"
			:objects="sourceStore.sourceList"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="viewMode"
			:selectable="true"
			:selected-ids="selectedSources"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			add-label="Add Source"
			empty-text="No sources found"
			:refreshing="isRefreshing"
			@add="createSource"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event"
			@select="selectedSources = $event">
			<!-- Custom card template -->
			<template #card="{ object }">
				<SourceCard :item="object" />
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

			<!-- Custom row actions -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click
						@click="sourceStore.setSourceItem(row); navigationStore.setModal('viewSource')">
						<template #icon>
							<Eye :size="20" />
						</template>
						View
					</NcActionButton>
					<NcActionButton close-after-click
						@click="sourceStore.setSourceItem(row); navigationStore.setModal('editSource')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton close-after-click
						@click="sourceStore.setSourceItem(row); navigationStore.setDialog('deleteSource')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

import SourceCard from '../../components/cards/SourceCard.vue'

export default {
	name: 'SourcesIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		SourceCard,
		DotsHorizontal,
		Eye,
		Pencil,
		TrashCanOutline,
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
		createSource() {
			sourceStore.setSourceItem(null)
			navigationStore.setModal('editSource')
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
