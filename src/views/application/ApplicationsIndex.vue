<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			title="Applications"
			description="Manage your applications and modules"
			:show-title="true"
			:objects="applicationStore.list"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="viewMode"
			:selectable="true"
			:selected-ids="selectedApplications"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			add-label="Add Application"
			empty-text="No applications found"
			:refreshing="isRefreshing"
			@add="createApplication"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event"
			@select="selectedApplications = $event">
			<!-- Custom card template -->
			<template #card="{ object }">
				<ApplicationCard :item="object" />
			</template>

			<!-- Custom column: name with description -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Custom column: status -->
			<template #column-status="{ row }">
				<span :class="row.active ? 'status-active' : 'status-inactive'">
					{{ row.active ? 'Active' : 'Inactive' }}
				</span>
			</template>

			<!-- Custom column: configurations -->
			<template #column-configurations="{ row }">
				{{ row.configurations?.length || 0 }}
			</template>

			<!-- Custom column: registers -->
			<template #column-registers="{ row }">
				{{ row.registers?.length || 0 }}
			</template>

			<!-- Custom column: schemas -->
			<template #column-schemas="{ row }">
				{{ row.schemas?.length || 0 }}
			</template>

			<!-- Custom column: created -->
			<template #column-created="{ row }">
				{{ row.created ? new Date(row.created).toLocaleDateString() : '-' }}
			</template>

			<!-- Custom row actions -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click
						@click="applicationStore.setItem(row); navigationStore.setModal('editApplication')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton close-after-click
						@click="applicationStore.setItem(row); navigationStore.setDialog('deleteApplication')">
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
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

import ApplicationCard from '../../components/cards/ApplicationCard.vue'

export default {
	name: 'ApplicationsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		ApplicationCard,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedApplications: [],
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), sortable: true },
				{ key: 'version', label: t('openregister', 'Version') },
				{ key: 'status', label: t('openregister', 'Status') },
				{ key: 'configurations', label: t('openregister', 'Configurations') },
				{ key: 'registers', label: t('openregister', 'Registers') },
				{ key: 'schemas', label: t('openregister', 'Schemas') },
				{ key: 'created', label: t('openregister', 'Created'), sortable: true },
			]
		},
		paginationData() {
			const page = applicationStore.pagination.page || 1
			const limit = applicationStore.pagination.limit || 20
			const total = applicationStore.list.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
	},
	async mounted() {
		await applicationStore.loadNextcloudGroups()
		applicationStore.refreshList(null, true)
	},
	methods: {
		createApplication() {
			applicationStore.setItem(null)
			navigationStore.setModal('editApplication')
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await applicationStore.refreshList()
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChanged(page) {
			applicationStore.setPagination(page, applicationStore.pagination.limit)
		},
		onPageSizeChanged(pageSize) {
			applicationStore.setPagination(1, pageSize)
		},
	},
}
</script>

<style scoped>
.titleContent {
	display: flex;
	flex-direction: column;
}

.status-active {
	color: var(--color-success);
	font-weight: 600;
}

.status-inactive {
	color: var(--color-text-lighter);
}
</style>
