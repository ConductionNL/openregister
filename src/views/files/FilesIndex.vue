<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'Files') }}
					</h1>
					<NcButton
						type="tertiary"
						:aria-label="t('openregister', 'Toggle search sidebar')"
						@click="toggleSidebar">
						<template #icon>
							<FilterVariant :size="20" />
						</template>
						{{ sidebarOpen ? t('openregister', 'Hide Filters') : t('openregister', 'Show Filters') }}
					</NcButton>
				</div>
				<p>
					{{ t('openregister', 'Manage and monitor file text extraction status') }}
				</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="filesList.length" class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} files', {
							showing: filesList.length,
							total: totalFiles
						}) }}
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="1"
						menu-name="Actions">
						<NcActionButton
							close-after-click
							@click="refreshFiles">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Files Table -->
			<div class="tableContainer">
				<NcLoadingIcon v-if="loading" :size="64" />

				<NcEmptyContent
					v-else-if="!filesList.length"
					:name="t('openregister', 'No files found')"
					:description="t('openregister', 'No files have been extracted yet')">
					<template #icon>
						<FileDocumentOutline :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="filesTable">
					<thead>
						<tr>
							<th class="column-filename">
								{{ t('openregister', 'File Name') }}
							</th>
							<th class="column-path">
								{{ t('openregister', 'Path') }}
							</th>
							<th class="column-mimetype">
								{{ t('openregister', 'Type') }}
							</th>
							<th class="column-size">
								{{ t('openregister', 'Size') }}
							</th>
							<th class="column-status">
								{{ t('openregister', 'Extraction Status') }}
							</th>
							<th class="column-chunks">
								{{ t('openregister', 'Chunks') }}
							</th>
							<th class="column-extracted">
								{{ t('openregister', 'Extracted At') }}
							</th>
							<th class="column-actions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="file in filesList" :key="file.id">
							<td class="column-filename">
								<div class="file-name-cell">
									<FileDocumentOutline :size="20" class="file-icon" />
									<span class="file-name">{{ file.fileName }}</span>
								</div>
							</td>
							<td class="column-path">
								<span class="file-path" :title="file.filePath">{{ file.filePath }}</span>
							</td>
							<td class="column-mimetype">
								<span class="badge badge-mimetype">{{ formatMimeType(file.mimeType) }}</span>
							</td>
							<td class="column-size">
								{{ formatFileSize(file.fileSize) }}
							</td>
							<td class="column-status">
								<span class="badge" :class="'badge-status-' + file.extractionStatus">
									{{ formatStatus(file.extractionStatus) }}
								</span>
							</td>
							<td class="column-chunks">
								{{ file.chunkCount || 0 }}
							</td>
							<td class="column-extracted">
								{{ formatDate(file.extractedAt) }}
							</td>
							<td class="column-actions">
								<NcActions>
									<NcActionButton
										v-if="file.extractionStatus === 'failed'"
										close-after-click
										@click="retryExtraction(file.id)">
										<template #icon>
											<Refresh :size="20" />
										</template>
										{{ t('openregister', 'Retry') }}
									</NcActionButton>
									<NcActionButton
										v-if="file.extractionError"
										close-after-click
										@click="showError(file)">
										<template #icon>
											<AlertCircleOutline :size="20" />
										</template>
										{{ t('openregister', 'View Error') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="totalFiles > limit" class="pagination">
					<NcButton
						:disabled="offset === 0"
						@click="previousPage">
						{{ t('openregister', 'Previous') }}
					</NcButton>
					<span class="pagination-info">
						{{ t('openregister', 'Page {current} of {total}', {
							current: currentPage,
							total: totalPages
						}) }}
					</span>
					<NcButton
						:disabled="offset + limit >= totalFiles"
						@click="nextPage">
						{{ t('openregister', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Search Sidebar -->
		<template #details>
			<FilesSidebar
				:search.sync="searchQuery"
				:status.sync="statusFilter"
				@update:search="handleSearchUpdate"
				@update:status="handleStatusUpdate" />
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
	NcLoadingIcon,
	NcEmptyContent,
} from '@nextcloud/vue'

import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'

import FilesSidebar from '../../components/FilesSidebar.vue'

/**
 * Main view for managing and monitoring file text extraction status
 */
export default {
	name: 'FilesIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		FileDocumentOutline,
		Refresh,
		AlertCircleOutline,
		FilterVariant,
		FilesSidebar,
	},
	data() {
		return {
			filesList: [],
			loading: false,
			totalFiles: 0,
			limit: 50,
			offset: 0,
			sidebarOpen: false,
			searchQuery: '',
			statusFilter: null,
		}
	},
	computed: {
		/**
		 * Get current page number
		 *
		 * @return {number} Current page
		 */
		currentPage() {
			return Math.floor(this.offset / this.limit) + 1
		},

		/**
		 * Get total number of pages
		 *
		 * @return {number} Total pages
		 */
		totalPages() {
			return Math.ceil(this.totalFiles / this.limit)
		},
	},
	mounted() {
		this.loadFiles()
	},
	methods: {
		t,

		/**
		 * Toggle sidebar visibility
		 *
		 * @return {void}
		 */
		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		/**
		 * Handle search query update
		 *
		 * @param {string} query - Search query
		 * @return {void}
		 */
		handleSearchUpdate(query) {
			this.searchQuery = query
			this.offset = 0
			this.loadFiles()
		},

		/**
		 * Handle status filter update
		 *
		 * @param {string|null} status - Status filter
		 * @return {void}
		 */
		handleStatusUpdate(status) {
			this.statusFilter = status
			this.offset = 0
			this.loadFiles()
		},

		/**
		 * Load files from the API
		 *
		 * @return {Promise<void>}
		 */
		async loadFiles() {
			this.loading = true
			try {
				const params = {
					limit: this.limit,
					offset: this.offset,
				}

				if (this.searchQuery) {
					params.search = this.searchQuery
				}

				if (this.statusFilter) {
					params.status = this.statusFilter
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

		/**
		 * Refresh the files list
		 *
		 * @return {void}
		 */
		refreshFiles() {
			this.loadFiles()
		},

		/**
		 * Go to previous page
		 *
		 * @return {void}
		 */
		previousPage() {
			if (this.offset > 0) {
				this.offset = Math.max(0, this.offset - this.limit)
				this.loadFiles()
			}
		},

		/**
		 * Go to next page
		 *
		 * @return {void}
		 */
		nextPage() {
			if (this.offset + this.limit < this.totalFiles) {
				this.offset += this.limit
				this.loadFiles()
			}
		},

		/**
		 * Retry extraction for a failed file
		 *
		 * @param {number} fileId - File ID
		 * @return {Promise<void>}
		 */
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

		/**
		 * Show error details for a failed file
		 *
		 * @param {object} file - File object
		 * @return {void}
		 */
		showError(file) {
			showError(
				t('openregister', 'Extraction error for {file}: {error}', {
					file: file.fileName,
					error: file.extractionError || 'Unknown error',
				}),
				{ timeout: 10000 },
			)
		},

		/**
		 * Format file size in human-readable format
		 *
		 * @param {number} bytes - File size in bytes
		 * @return {string} Formatted file size
		 */
		formatFileSize(bytes) {
			if (!bytes) return '0 B'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
		},

		/**
		 * Format extraction status
		 *
		 * @param {string} status - Extraction status
		 * @return {string} Formatted status
		 */
		formatStatus(status) {
			const statusLabels = {
				pending: t('openregister', 'Pending'),
				processing: t('openregister', 'Processing'),
				completed: t('openregister', 'Completed'),
				failed: t('openregister', 'Failed'),
			}
			return statusLabels[status] || status
		},

		/**
		 * Format mime type for display
		 *
		 * @param {string} mimeType - MIME type
		 * @return {string} Formatted MIME type
		 */
		formatMimeType(mimeType) {
			if (!mimeType) return ''
			const parts = mimeType.split('/')
			return parts[parts.length - 1].toUpperCase()
		},

		/**
		 * Format date for display
		 *
		 * @param {string} date - Date string
		 * @return {string} Formatted date
		 */
		formatDate(date) {
			if (!date) return '-'
			return new Date(date).toLocaleString()
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 100%;
}

.viewHeader {
	margin-bottom: 20px;
}

.viewHeaderTitle {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 8px;
}

.viewHeaderTitleIndented {
	margin: 0;
	font-size: 28px;
	font-weight: 600;
}

.viewHeader p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.viewActionsBar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.viewInfo {
	display: flex;
	gap: 12px;
	align-items: center;
}

.viewTotalCount {
	font-weight: 600;
}

.viewActions {
	display: flex;
	gap: 8px;
}

.tableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.filesTable {
	width: 100%;
	border-collapse: collapse;
}

.filesTable thead {
	background: var(--color-background-hover);
	border-bottom: 2px solid var(--color-border);
}

.filesTable th {
	padding: 12px 16px;
	text-align: left;
	font-weight: 600;
	white-space: nowrap;
}

.filesTable td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.filesTable tbody tr:hover {
	background: var(--color-background-hover);
}

.file-name-cell {
	display: flex;
	align-items: center;
	gap: 8px;
}

.file-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.file-name {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-path {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: block;
	max-width: 300px;
}

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

.badge-status-pending {
	background: var(--color-warning-light);
	color: var(--color-warning);
}

.badge-status-processing {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

.badge-status-completed {
	background: var(--color-success-light);
	color: var(--color-success);
}

.badge-status-failed {
	background: var(--color-error-light);
	color: var(--color-error);
}

.column-filename {
	min-width: 200px;
}

.column-path {
	min-width: 250px;
}

.column-mimetype {
	width: 100px;
}

.column-size {
	width: 100px;
}

.column-status {
	width: 120px;
}

.column-chunks {
	width: 80px;
	text-align: center;
}

.column-extracted {
	width: 180px;
}

.column-actions {
	width: 50px;
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 16px;
	padding: 20px;
	border-top: 1px solid var(--color-border);
}

.pagination-info {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}
</style>
