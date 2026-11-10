<template>
	<NcAppContent :showDetails="sidebarOpen" @update:showDetails="toggleSidebar">
		<div class="viewContainer">
			<!-- Header -->
			<FilterToggleHeader
				:title="t('openregister', 'Files')"
				:description="
					t(
						'openregister',
						'Manage and monitor file text extraction status',
					)
				"
				:sidebarOpen="sidebarOpen"
				@toggle="toggleSidebar" />

			<!-- Actions Bar -->
			<ListActionsBar
				:summary="
					filesList.length
						? t('openregister', 'Showing {showing} of {total} files', {
								showing: filesList.length,
								total: totalFiles,
							})
						: ''
				"
				:actions="[refreshAction(refreshFiles)]" />

			<!-- Files Table -->
			<div class="tableContainer">
				<NcLoadingIcon v-if="loading" :size="64" />

				<NcEmptyContent
					v-else-if="!filesList.length"
					:name="t('openregister', 'No files found')"
					:description="
						t('openregister', 'No files have been extracted yet')
					">
					<template #icon>
						<FileDocumentOutline :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="filesTable">
					<thead>
						<tr>
							<template v-for="column in headerColumns" :key="column.key">
								<th
									v-if="column.sort"
									scope="col"
									:class="'column-' + column.key + ' sortable'"
									@click="toggleSort(column.sort)">
									{{ column.label }}
									<span
										v-if="sortField === column.sort"
										class="sort-indicator"
										>{{ sortOrder === 'ASC' ? '▲' : '▼' }}</span
									>
								</th>
								<th v-else scope="col" :class="'column-' + column.key">
									{{ column.label }}
								</th>
							</template>
						</tr>
					</thead>
					<tbody>
						<tr v-for="file in filesList" :key="file.id">
							<td class="column-filename">
								<div class="file-name-cell">
									<FileDocumentOutline
										:size="20"
										class="file-icon" />
									<span class="file-name">{{
										file.fileName
									}}</span>
								</div>
							</td>
							<td class="column-mimetype">
								<span class="badge badge-mimetype">{{
									formatMimeType(file.mimeType)
								}}</span>
							</td>
							<td class="column-size">
								{{ formatFileSize(file.fileSize) }}
							</td>
							<td class="column-chunks">
								{{ file.chunkCount || 0 }}
							</td>
							<td class="column-entities">
								{{ file.entityCount || 0 }}
							</td>
							<td class="column-risk">
								<span
									class="badge"
									:class="'badge-risk-' + file.riskLevel">
									{{ formatRiskLevel(file.riskLevel) }}
								</span>
							</td>
							<td class="column-extracted">
								{{ formatDate(file.extractedAt) }}
							</td>
							<td class="column-actions">
								<ActionMenu :actions="fileActions(file)" />
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<OffsetPager
					:offset="offset"
					:limit="limit"
					:total="totalFiles"
					@previous="previousPage"
					@next="nextPage" />
			</div>
		</div>

		<!-- Search Sidebar -->
		<template #details>
			<FilesSidebar
				v-model:search="searchQuery"
				v-model:status="statusFilter"
				v-model:riskLevel="riskLevelFilter"
				@update:search="handleSearchUpdate"
				@update:status="handleStatusUpdate"
				@update:riskLevel="handleRiskLevelUpdate" />
		</template>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FilesSidebar from '../../components/FilesSidebar.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import FilterToggleHeader from '../../components/shared/FilterToggleHeader.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import OffsetPager from '../../components/shared/OffsetPager.vue'
import { sidebarToggle } from '../../composables/sidebarToggle.js'
import { displayDateTime } from '../../services/displayDateTime.js'
import { refreshAction } from '../../services/menuActions.js'
import { offsetPaging } from '../offsetPaging.js'

/**
 * Main view for managing and monitoring file text extraction status
 */
export default {
	name: 'FilesIndex',
	components: {
		ActionMenu,
		ListActionsBar,
		OffsetPager,
		FilterToggleHeader,
		NcAppContent,
		NcLoadingIcon,
		NcEmptyContent,
		FileDocumentOutline,
		FilesSidebar,
	},

	mixins: [offsetPaging('totalFiles', 'loadFiles')],

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
			riskLevelFilter: null,
			sortField: 'extractedAt',
			sortOrder: 'DESC',
		}
	},

	computed: {
		/**
		 * The table's header cells, in column order; `sort` is the API field a
		 * click sorts on, absent for a column that does not sort.
		 *
		 * @spec exclude UI plumbing — static table header list for display.
		 * @return {Array<{key: string, label: string, sort?: string}>}
		 */
		headerColumns() {
			return [
				{ key: 'filename', label: t('openregister', 'File Name'), sort: 'fileName' },
				{ key: 'mimetype', label: t('openregister', 'Type') },
				{ key: 'size', label: t('openregister', 'Size'), sort: 'fileSize' },
				{ key: 'chunks', label: t('openregister', 'Chunks'), sort: 'chunkCount' },
				{ key: 'entities', label: t('openregister', 'Entities'), sort: 'entityCount' },
				{ key: 'risk', label: t('openregister', 'Risk Level'), sort: 'riskLevel' },
				{ key: 'extracted', label: t('openregister', 'Extracted At'), sort: 'extractedAt' },
				{ key: 'actions', label: t('openregister', 'Actions') },
			]
		},
	},

	mounted() {
		this.loadFiles()
	},

	methods: {
		refreshAction,

		/**
		 * The row menu of a file.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} file The file
		 * @return {Array<object>} The ActionMenu entries
		 */
		fileActions(file) {
			return [
				{
					label: t('openregister', 'Retry'),
					icon: Refresh,
					run: () => this.retryExtraction(file.id),
					hidden: file.extractionStatus !== 'failed',
				},
				{
					label: t('openregister', 'View Error'),
					icon: AlertCircleOutline,
					run: () => this.showError(file),
					hidden: !file.extractionError,
				},
			]
		},

		t,

		...sidebarToggle,

		/**
		 * Toggle sort field and direction
		 *
		 * @spec exclude list-view sort-toggle plumbing; resets offset and reloads
		 * @param {string} field - The field to sort by
		 * @return {void}
		 */
		toggleSort(field) {
			if (this.sortField === field) {
				this.sortOrder = this.sortOrder === 'ASC' ? 'DESC' : 'ASC'
			} else {
				this.sortField = field
				this.sortOrder = 'DESC'
			}
			this.offset = 0
			this.loadFiles()
		},

		/**
		 * Handle search query update
		 *
		 * @spec exclude list-view search-input handler; resets offset and reloads
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
		 * @spec exclude list-view filter-input handler; resets offset and reloads
		 * @param {string|null} status - Status filter
		 * @return {void}
		 */
		handleStatusUpdate(status) {
			this.statusFilter = status
			this.offset = 0
			this.loadFiles()
		},

		/**
		 * Handle risk level filter update
		 *
		 * @spec exclude list-view filter-input handler; resets offset and reloads
		 * @param {string|null} level - Risk level filter
		 * @return {void}
		 */
		handleRiskLevelUpdate(level) {
			this.riskLevelFilter = level
			this.offset = 0
			this.loadFiles()
		},

		/**
		 * Load files from the API
		 *
		 * @spec exclude list-view API fetch plumbing
		 * @return {Promise<void>}
		 */
		async loadFiles() {
			this.loading = true
			try {
				const params = {
					limit: this.limit,
					offset: this.offset,
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

		/**
		 * Refresh the files list
		 *
		 * @spec exclude list-view manual refresh plumbing
		 * @return {void}
		 */
		refreshFiles() {
			this.loadFiles()
		},

		/**
		 * Retry extraction for a failed file
		 *
		 * @spec exclude list-view row-action wiring; POSTs a re-extract request and reloads
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
		 * @spec exclude list-view toast-display helper for extraction errors
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
		 * @spec exclude list-view file-size formatting display helper
		 * @param {number} bytes - File size in bytes
		 * @return {string} Formatted file size
		 */
		formatFileSize(bytes) {
			if (!bytes) return '0 B'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
		},

		/**
		 * Format extraction status
		 *
		 * @spec exclude list-view status-label formatting display helper
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
		 * @spec exclude list-view mime-type formatting display helper
		 * @param {string} mimeType - MIME type
		 * @return {string} Formatted MIME type
		 */
		formatMimeType(mimeType) {
			if (!mimeType) return ''
			const category = mimeType.split('/')[0]
			return category.charAt(0).toUpperCase() + category.slice(1)
		},

		/**
		 * Format risk level for display
		 *
		 * @spec exclude list-view risk-level formatting display helper
		 * @param {string} level - Risk level
		 * @return {string} Formatted risk level
		 */
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

		formatDate: displayDateTime,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include view-container-2;

@include table-container;

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

.filesTable th.sortable {
	cursor: pointer;
	user-select: none;
}

.filesTable th.sortable:hover {
	background: var(--color-background-dark);
}

.sort-indicator {
	margin-left: 4px;
	font-size: 10px;
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

@include badge;

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

.column-filename {
	min-width: 200px;
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

.column-entities {
	width: 80px;
	text-align: center;
}

.column-risk {
	width: 120px;
}

.column-extracted {
	width: 180px;
}

.column-actions {
	width: 50px;
}
</style>
