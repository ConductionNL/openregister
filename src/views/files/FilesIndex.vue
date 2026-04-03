<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<CnIndexPage
			ref="indexPage"
			title="Files"
			description="Manage and monitor file text extraction status"
			:show-title="true"
			:show-add="false"
			:objects="filesList"
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
			empty-text="No files found"
			@refresh="handleRefresh"
			@row-click="showFileDetails"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@sort="onSort"
			@view-mode-change="viewMode = $event">
			<template #column-mimeType="{ row }">
				<span class="badge badge-mimetype">{{ formatMimeType(row.mimeType) }}</span>
			</template>

			<template #column-fileSize="{ row }">
				{{ formatFileSize(row.fileSize) }}
			</template>

			<template #column-riskLevel="{ row }">
				<span class="badge" :class="'badge-risk-' + row.riskLevel">
					{{ formatRiskLevel(row.riskLevel) }}
				</span>
			</template>

			<template #column-extractedAt="{ row }">
				{{ formatDate(row.extractedAt) }}
			</template>

			<template #row-actions="{ row }">
				<NcActions>
					<NcActionButton
						v-if="row.extractionStatus === 'failed'"
						close-after-click
						@click="retryExtraction(row.id)">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Retry') }}
					</NcActionButton>
					<NcActionButton
						v-if="row.extractionError"
						close-after-click
						@click="showError(row)">
						<template #icon>
							<AlertCircleOutline :size="20" />
						</template>
						{{ t('openregister', 'View Error') }}
					</NcActionButton>
				</NcActions>
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

		<!-- Search Sidebar -->
		<template #details>
			<FilesSidebar
				:search.sync="searchQuery"
				:status.sync="statusFilter"
				:risk-level.sync="riskLevelFilter"
				@update:search="handleSearchUpdate"
				@update:status="handleStatusUpdate"
				@update:riskLevel="handleRiskLevelUpdate" />
		</template>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

import {
	NcAppContent,
	NcActions,
	NcActionButton,
	NcButton,
} from '@nextcloud/vue'

import { CnIndexPage } from '@conduction/nextcloud-vue'

import Refresh from 'vue-material-design-icons/Refresh.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'

import FilesSidebar from '../../components/FilesSidebar.vue'

export default {
	name: 'FilesIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		CnIndexPage,
		Refresh,
		AlertCircleOutline,
		FilterVariant,
		FilesSidebar,
	},
	data() {
		return {
			filesList: [],
			loading: false,
			isRefreshing: false,
			totalFiles: 0,
			viewMode: 'table',
			sidebarOpen: false,
			searchQuery: '',
			statusFilter: null,
			riskLevelFilter: null,
			sortField: 'extractedAt',
			sortOrder: 'DESC',
			pagination: {
				page: 1,
				limit: 50,
			},
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'fileName', label: t('openregister', 'File Name'), sortable: true },
				{ key: 'mimeType', label: t('openregister', 'Type') },
				{ key: 'fileSize', label: t('openregister', 'Size'), sortable: true },
				{ key: 'chunkCount', label: t('openregister', 'Chunks'), sortable: true },
				{ key: 'entityCount', label: t('openregister', 'Entities'), sortable: true },
				{ key: 'riskLevel', label: t('openregister', 'Risk Level'), sortable: true },
				{ key: 'extractedAt', label: t('openregister', 'Extracted At'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page
			const limit = this.pagination.limit
			const total = this.totalFiles
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
	},
	mounted() {
		this.loadFiles()
	},
	methods: {
		t,

		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		handleSearchUpdate(query) {
			this.searchQuery = query
			this.pagination.page = 1
			this.loadFiles()
		},

		handleStatusUpdate(status) {
			this.statusFilter = status
			this.pagination.page = 1
			this.loadFiles()
		},

		handleRiskLevelUpdate(level) {
			this.riskLevelFilter = level
			this.pagination.page = 1
			this.loadFiles()
		},

		async loadFiles() {
			this.loading = true
			try {
				const params = {
					limit: this.pagination.limit,
					offset: (this.pagination.page - 1) * this.pagination.limit,
					sort: this.sortField,
					order: this.sortOrder,
				}

				if (this.searchQuery) {
					params.search = this.searchQuery
				}

				if (this.statusFilter) {
					params.status = this.statusFilter
				}

				if (this.riskLevelFilter) {
					params.riskLevel = this.riskLevelFilter
				}

				const response = await axios.get(
					generateUrl('/apps/openregister/api/files'),
					{ params },
				)

				if (response.data.success) {
					this.filesList = response.data.data
					this.totalFiles = response.data.count || this.filesList.length
				}
			} catch (error) {
				console.error('Failed to load files:', error)
				showError(t('openregister', 'Failed to load files'))
			} finally {
				this.loading = false
			}
		},

		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadFiles()
			} finally {
				this.isRefreshing = false
			}
		},

		onPageChanged(page) {
			this.pagination.page = page
			this.loadFiles()
		},

		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			this.loadFiles()
		},

		onSort({ key, order }) {
			this.sortField = key
			this.sortOrder = order.toUpperCase()
			this.pagination.page = 1
			this.loadFiles()
		},

		showFileDetails(_file) {
			// TODO: Navigate to file details when available
		},

		async retryExtraction(fileId) {
			try {
				await axios.post(
					generateUrl(`/apps/openregister/api/files/${fileId}/extract`),
					{ forceReExtract: true },
				)
				showSuccess(t('openregister', 'File extraction queued'))
				this.loadFiles()
			} catch (error) {
				console.error('Failed to retry extraction:', error)
				showError(t('openregister', 'Failed to retry extraction'))
			}
		},

		showError(file) {
			showError(
				t('openregister', 'Extraction error for {file}: {error}', {
					file: file.fileName,
					error: file.extractionError || 'Unknown error',
				}),
				{ timeout: 10000 },
			)
		},

		formatFileSize(bytes) {
			if (!bytes) return '0 B'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
		},

		formatMimeType(mimeType) {
			if (!mimeType) return ''
			const category = mimeType.split('/')[0]
			return category.charAt(0).toUpperCase() + category.slice(1)
		},

		formatRiskLevel(level) {
			const labels = {
				none: t('openregister', 'None'),
				low: t('openregister', 'Low'),
				medium: t('openregister', 'Medium'),
				high: t('openregister', 'High'),
				very_high: t('openregister', 'Very High'),
			}
			return labels[level] || level || t('openregister', 'None')
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

.badge-mimetype {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.badge-risk-none {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.badge-risk-low {
	background: var(--color-success-light);
	color: var(--color-success);
}

.badge-risk-medium {
	background: var(--color-warning-light);
	color: var(--color-warning);
}

.badge-risk-high {
	background: var(--color-error-light);
	color: var(--color-error);
}

.badge-risk-very_high {
	background: var(--color-error);
	color: var(--color-main-background);
}
</style>
