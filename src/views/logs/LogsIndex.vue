<script setup>
import { navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="container">
			<!-- Header -->
			<div class="header">
				<h1>{{ t('openregister', 'System Logs') }}</h1>
				<p>{{ t('openregister', 'View and analyze system logs with advanced filtering capabilities') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="actions-bar">
				<div class="log-info">
					<span class="total-count">
						{{ t('openregister', '{count} log entries', { count: filteredLogs.length }) }}
					</span>
					<span v-if="filters.levels?.length" class="filter-indicator">
						({{ t('openregister', 'Filtered') }})
					</span>
				</div>
				<div class="actions">
					<NcButton @click="exportLogs">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openregister', 'Export') }}
					</NcButton>
					<NcButton @click="clearLogs">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Clear Filtered') }}
					</NcButton>
					<NcButton @click="refreshLogs">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Refresh') }}
					</NcButton>
				</div>
			</div>

			<!-- Logs Table -->
			<div v-if="loading" class="loading">
				<NcLoadingIcon :size="64" />
				<p>{{ t('openregister', 'Loading logs...') }}</p>
			</div>

			<NcEmptyContent v-else-if="!filteredLogs.length"
				:name="t('openregister', 'No log entries found')"
				:description="t('openregister', 'There are no log entries matching your current filters.')">
				<template #icon>
					<TextBoxOutline />
				</template>
			</NcEmptyContent>

			<div v-else class="table-container">
				<table class="logs-table">
					<thead>
						<tr>
							<th class="level-column">{{ t('openregister', 'Level') }}</th>
							<th class="timestamp-column">{{ t('openregister', 'Timestamp') }}</th>
							<th class="source-column">{{ t('openregister', 'Source') }}</th>
							<th class="message-column">{{ t('openregister', 'Message') }}</th>
							<th class="user-column">{{ t('openregister', 'User') }}</th>
							<th class="actions-column">{{ t('openregister', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="log in paginatedLogs"
							:key="log.id"
							class="log-row"
							:class="`log-level-${log.level}`">
							<td class="level-column">
								<NcChip :class="`level-${log.level}`">
									<AlertCircle v-if="log.level === 'error'" :size="16" />
									<Alert v-else-if="log.level === 'warning'" :size="16" />
									<Information v-else-if="log.level === 'info'" :size="16" />
									<BugOutline v-else-if="log.level === 'debug'" :size="16" />
									{{ log.level.toUpperCase() }}
								</NcChip>
							</td>
							<td class="timestamp-column">
								<NcDateTime :timestamp="log.timestamp" :ignore-seconds="false" />
							</td>
							<td class="source-column">{{ log.source }}</td>
							<td class="message-column">
								<div class="message-content">
									<span class="message-text">{{ log.message }}</span>
									<span v-if="log.context" class="context-indicator">
										<CogOutline :size="14" />
									</span>
									<span v-if="log.stackTrace" class="stack-indicator">
										<Bug :size="14" />
									</span>
								</div>
							</td>
							<td class="user-column">{{ log.user || '-' }}</td>
							<td class="actions-column">
								<NcActions>
									<NcActionButton @click="viewDetails(log)">
										<template #icon>
											<Eye :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
									<NcActionButton v-if="log.context" @click="viewContext(log)">
										<template #icon>
											<CogOutline :size="20" />
										</template>
										{{ t('openregister', 'View Context') }}
									</NcActionButton>
									<NcActionButton @click="copyMessage(log)">
										<template #icon>
											<ContentCopy :size="20" />
										</template>
										{{ t('openregister', 'Copy Message') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<div v-if="totalPages > 1" class="pagination">
				<NcButton
					:disabled="currentPage === 1"
					@click="currentPage = 1">
					{{ t('openregister', 'First') }}
				</NcButton>
				<NcButton
					:disabled="currentPage === 1"
					@click="currentPage--">
					{{ t('openregister', 'Previous') }}
				</NcButton>
				<span class="page-info">
					{{ t('openregister', 'Page {current} of {total}', { current: currentPage, total: totalPages }) }}
				</span>
				<NcButton
					:disabled="currentPage === totalPages"
					@click="currentPage++">
					{{ t('openregister', 'Next') }}
				</NcButton>
				<NcButton
					:disabled="currentPage === totalPages"
					@click="currentPage = totalPages">
					{{ t('openregister', 'Last') }}
				</NcButton>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import {
	NcAppContent,
	NcEmptyContent,
	NcButton,
	NcLoadingIcon,
	NcActions,
	NcActionButton,
	NcChip,
	NcDateTime,
} from '@nextcloud/vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import Information from 'vue-material-design-icons/Information.vue'
import BugOutline from 'vue-material-design-icons/BugOutline.vue'
import Bug from 'vue-material-design-icons/Bug.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

export default {
	name: 'LogsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcChip,
		NcDateTime,
		TextBoxOutline,
		Download,
		Delete,
		Refresh,
		Eye,
		AlertCircle,
		Alert,
		Information,
		BugOutline,
		Bug,
		CogOutline,
		ContentCopy,
	},
	data() {
		return {
			loading: false,
			currentPage: 1,
			itemsPerPage: 50,
			filters: {},
			// Mock data - replace with actual API calls
			logs: [
				{
					id: '1',
					level: 'error',
					timestamp: new Date(Date.now() - 3600000).toISOString(), // 1 hour ago
					source: 'ObjectService',
					message: 'Failed to validate object schema: Missing required field "title"',
					user: 'admin',
					context: { objectId: '123', schema: 'user-schema' },
					stackTrace: 'Error at ObjectService.validate(...)',
				},
				{
					id: '2',
					level: 'warning',
					timestamp: new Date(Date.now() - 7200000).toISOString(), // 2 hours ago
					source: 'ValidationService',
					message: 'Deprecated API endpoint used: /api/v1/objects',
					user: 'user1',
					context: { endpoint: '/api/v1/objects', method: 'GET' },
					stackTrace: null,
				},
				{
					id: '3',
					level: 'info',
					timestamp: new Date(Date.now() - 10800000).toISOString(), // 3 hours ago
					source: 'AuthService',
					message: 'User logged in successfully',
					user: 'user2',
					context: { userId: '456', ip: '192.168.1.100' },
					stackTrace: null,
				},
				{
					id: '4',
					level: 'debug',
					timestamp: new Date(Date.now() - 14400000).toISOString(), // 4 hours ago
					source: 'CacheService',
					message: 'Cache miss for key: user_preferences_789',
					user: null,
					context: { cacheKey: 'user_preferences_789', ttl: 3600 },
					stackTrace: null,
				},
				{
					id: '5',
					level: 'error',
					timestamp: new Date(Date.now() - 18000000).toISOString(), // 5 hours ago
					source: 'ObjectService',
					message: 'Database connection failed',
					user: null,
					context: { database: 'openregister', host: 'localhost' },
					stackTrace: 'PDOException: Connection refused at ObjectService.connect(...)',
				},
				{
					id: '6',
					level: 'info',
					timestamp: new Date(Date.now() - 21600000).toISOString(), // 6 hours ago
					source: 'ObjectService',
					message: 'New object created successfully',
					user: 'admin',
					context: { objectId: '789', type: 'schema' },
					stackTrace: null,
				},
			],
		}
	},
	computed: {
		filteredLogs() {
			// Apply filters from sidebar
			let filtered = [...this.logs]

			if (this.filters.levels?.length) {
				filtered = filtered.filter(log => this.filters.levels.includes(log.level))
			}
			if (this.filters.sources?.length) {
				filtered = filtered.filter(log => this.filters.sources.includes(log.source))
			}
			if (this.filters.users?.length) {
				filtered = filtered.filter(log => this.filters.users.includes(log.user))
			}
			if (this.filters.dateFrom) {
				filtered = filtered.filter(log => new Date(log.timestamp) >= new Date(this.filters.dateFrom))
			}
			if (this.filters.dateTo) {
				filtered = filtered.filter(log => new Date(log.timestamp) <= new Date(this.filters.dateTo))
			}
			if (this.filters.message) {
				filtered = filtered.filter(log => log.message.toLowerCase().includes(this.filters.message.toLowerCase()))
			}
			if (this.filters.onlyWithErrors) {
				filtered = filtered.filter(log => log.stackTrace)
			}

			// Sort by timestamp descending (newest first)
			return filtered.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp))
		},
		paginatedLogs() {
			const start = (this.currentPage - 1) * this.itemsPerPage
			return this.filteredLogs.slice(start, start + this.itemsPerPage)
		},
		totalPages() {
			return Math.ceil(this.filteredLogs.length / this.itemsPerPage)
		},
	},
	mounted() {
		this.loadLogs()

		// Listen for filter changes from sidebar
		this.$root.$on('logs-filters-changed', this.handleFiltersChanged)
		this.$root.$on('logs-export', this.handleExport)
		this.$root.$on('logs-clear-filtered', this.clearLogs)
		this.$root.$on('logs-refresh', this.refreshLogs)

		// Emit counts to sidebar
		this.updateCounts()
	},
	beforeDestroy() {
		this.$root.$off('logs-filters-changed')
		this.$root.$off('logs-export')
		this.$root.$off('logs-clear-filtered')
		this.$root.$off('logs-refresh')
	},
	watch: {
		filteredLogs() {
			this.updateCounts()
		},
	},
	methods: {
		/**
		 * Load logs from API
		 * @return {Promise<void>}
		 */
		async loadLogs() {
			this.loading = true
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/logs')
				// this.logs = await response.json()
				
				// Mock delay
				await new Promise(resolve => setTimeout(resolve, 500))
			} catch (error) {
				console.error('Error loading logs:', error)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Handle filter changes from sidebar
		 * @param {object} filters - Filter object from sidebar
		 * @return {void}
		 */
		handleFiltersChanged(filters) {
			this.filters = filters
			this.currentPage = 1 // Reset to first page when filters change
		},
		/**
		 * Handle export request from sidebar
		 * @param {object} options - Export options from sidebar
		 * @return {void}
		 */
		handleExport(options) {
			this.exportFilteredLogs(options)
		},
		/**
		 * View detailed information for a log entry
		 * @param {object} log - Log entry to view
		 * @return {void}
		 */
		viewDetails(log) {
			// TODO: Implement details modal or navigation
			console.log('View details for log:', log)
		},
		/**
		 * View context information for a log entry
		 * @param {object} log - Log entry with context
		 * @return {void}
		 */
		viewContext(log) {
			// TODO: Implement context modal
			console.log('View context for log:', log.context)
		},
		/**
		 * Copy log message to clipboard
		 * @param {object} log - Log entry to copy
		 * @return {Promise<void>}
		 */
		async copyMessage(log) {
			try {
				await navigator.clipboard.writeText(log.message)
				OC.Notification.showSuccess(this.t('openregister', 'Message copied to clipboard'))
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				OC.Notification.showError(this.t('openregister', 'Failed to copy message'))
			}
		},
		/**
		 * Export logs with current filters
		 * @return {void}
		 */
		exportLogs() {
			this.exportFilteredLogs({ format: 'csv', includeContext: true, includeStackTrace: false })
		},
		/**
		 * Export filtered logs with specified options
		 * @param {object} options - Export options
		 * @return {void}
		 */
		exportFilteredLogs(options) {
			// TODO: Implement export functionality
			console.log('Export logs:', this.filteredLogs, 'with options:', options)
			OC.Notification.showSuccess(this.t('openregister', 'Export started'))
		},
		/**
		 * Clear filtered logs
		 * @return {Promise<void>}
		 */
		async clearLogs() {
			if (!confirm(this.t('openregister', 'Are you sure you want to clear the filtered logs? This action cannot be undone.'))) {
				return
			}

			try {
				// TODO: Replace with actual API call
				// await fetch('/api/logs/clear', {
				//     method: 'DELETE',
				//     body: JSON.stringify({ filters: this.filters })
				// })

				// Remove filtered logs from array (mock)
				const filteredIds = this.filteredLogs.map(log => log.id)
				this.logs = this.logs.filter(log => !filteredIds.includes(log.id))
				
				OC.Notification.showSuccess(this.t('openregister', 'Logs cleared successfully'))
			} catch (error) {
				console.error('Error clearing logs:', error)
				OC.Notification.showError(this.t('openregister', 'Error clearing logs'))
			}
		},
		/**
		 * Refresh logs list
		 * @return {Promise<void>}
		 */
		async refreshLogs() {
			await this.loadLogs()
		},
		/**
		 * Update counts for sidebar
		 * @return {void}
		 */
		updateCounts() {
			this.$root.$emit('logs-filtered-count', this.filteredLogs.length)
		},
	},
}
</script>

<style scoped>
.container {
	padding: 20px;
	max-width: 100%;
}

.header {
	margin-bottom: 30px;
}

.header h1 {
	margin: 0 0 10px 0;
	font-size: 2rem;
	font-weight: 300;
}

.header p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.actions-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	padding: 10px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.log-info {
	display: flex;
	align-items: center;
	gap: 10px;
}

.total-count {
	font-weight: 500;
	color: var(--color-main-text);
}

.filter-indicator {
	font-size: 0.9em;
	color: var(--color-primary);
}

.actions {
	display: flex;
	gap: 10px;
}

.loading {
	text-align: center;
	padding: 50px;
}

.loading p {
	margin-top: 20px;
	color: var(--color-text-maxcontrast);
}

.table-container {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
}

.logs-table {
	width: 100%;
	border-collapse: collapse;
}

.logs-table th,
.logs-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.logs-table th {
	background: var(--color-background-hover);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.level-column {
	width: 100px;
}

.timestamp-column {
	width: 180px;
}

.source-column {
	width: 150px;
}

.message-column {
	min-width: 300px;
}

.user-column {
	width: 120px;
}

.actions-column {
	width: 100px;
	text-align: center;
}

.log-row:hover {
	background: var(--color-background-hover);
}

.log-row.log-level-error {
	border-left: 4px solid var(--color-error);
}

.log-row.log-level-warning {
	border-left: 4px solid var(--color-warning);
}

.log-row.log-level-info {
	border-left: 4px solid var(--color-info);
}

.log-row.log-level-debug {
	border-left: 4px solid var(--color-text-maxcontrast);
}

.message-content {
	display: flex;
	align-items: center;
	gap: 8px;
}

.message-text {
	flex: 1;
}

.context-indicator,
.stack-indicator {
	color: var(--color-text-maxcontrast);
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 20px;
	margin-top: 30px;
	padding: 20px;
}

.page-info {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

/* Log level chip styling */
:deep(.chip.level-error) {
	background: var(--color-error);
	color: white;
}

:deep(.chip.level-warning) {
	background: var(--color-warning);
	color: white;
}

:deep(.chip.level-info) {
	background: var(--color-info);
	color: white;
}

:deep(.chip.level-debug) {
	background: var(--color-text-maxcontrast);
	color: white;
}
</style>
