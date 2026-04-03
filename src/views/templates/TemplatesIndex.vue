<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<CnIndexPage
			ref="indexPage"
			title="Templates"
			description="Manage document templates and themes"
			:show-title="true"
			:show-add="false"
			:objects="templatesList"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="viewMode"
			:selectable="false"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-form-dialog="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:loading="loading"
			:refreshing="isRefreshing"
			empty-text="No templates found"
			@refresh="handleRefresh"
			@row-click="viewTemplate"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event">
			<template #column-type="{ row }">
				<span class="badge badgeType">{{ row.type }}</span>
			</template>

			<template #column-updatedAt="{ row }">
				{{ formatDate(row.updatedAt) }}
			</template>

			<template #header-actions>
				<NcButton
					type="tertiary"
					:aria-label="t('openregister', 'Toggle search sidebar')"
					@click="toggleSidebar">
					<template #icon>
						<FilterVariant :size="20" />
					</template>
					{{ sidebarOpen ? t('openregister', 'Hide Filters') : t('openregister', 'Show Filters') }}
				</NcButton>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'

import { NcAppContent, NcButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'

import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'

export default {
	name: 'TemplatesIndex',
	components: {
		NcAppContent,
		NcButton,
		CnIndexPage,
		FilterVariant,
	},
	data() {
		return {
			templatesList: [],
			loading: false,
			isRefreshing: false,
			totalTemplates: 0,
			viewMode: 'table',
			sidebarOpen: false,
			pagination: {
				page: 1,
				limit: 50,
			},
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), sortable: true },
				{ key: 'type', label: t('openregister', 'Type') },
				{ key: 'description', label: t('openregister', 'Description') },
				{ key: 'updatedAt', label: t('openregister', 'Updated At'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page
			const limit = this.pagination.limit
			const total = this.totalTemplates
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
	},
	mounted() {
		this.loadTemplates()
	},
	methods: {
		t,

		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		async loadTemplates() {
			this.loading = true
			try {
				// TODO: Replace with actual templates API endpoint when available
				// For now, show empty state
				this.templatesList = []
				this.totalTemplates = 0

				// Uncomment when API is available:
				// const params = {
				//     limit: this.pagination.limit,
				//     offset: (this.pagination.page - 1) * this.pagination.limit,
				// }
				//
				// const response = await axios.get(
				//     generateUrl('/apps/openregister/api/templates'),
				//     { params },
				// )
				//
				// if (response.data.success) {
				//     this.templatesList = response.data.data
				//     this.totalTemplates = response.data.count || this.templatesList.length
				// }
			} catch (error) {
				// TODO: Uncomment when API is implemented
				// console.error('Failed to load templates:', error)
				// showError(t('openregister', 'Failed to load templates'))
			} finally {
				this.loading = false
			}
		},

		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadTemplates()
			} finally {
				this.isRefreshing = false
			}
		},

		onPageChanged(page) {
			this.pagination.page = page
			this.loadTemplates()
		},

		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			this.loadTemplates()
		},

		viewTemplate(_template) {
			// TODO: Navigate to template details page when available
		},

		formatDate(date) {
			if (!date) return '-'
			return new Date(date).toLocaleString()
		},
	},
}
</script>

<style scoped>
.badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.badgeType {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}
</style>
