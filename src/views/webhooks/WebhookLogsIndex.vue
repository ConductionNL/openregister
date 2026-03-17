<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'Webhook Logs') }}
					</h1>
					<NcButton
						type="tertiary"
						@click="goBack">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('openregister', 'Back to Webhooks') }}
					</NcButton>
				</div>
				<p>
					{{ t('openregister', 'View webhook delivery logs and filter by webhook') }}
				</p>
			</div>

			<!-- Filters -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} log entries', {
							showing: logsList.length,
							total: totalLogs
						}) }}
					</span>
				</div>
				<div class="viewActions">
					<NcSelect
						v-model="selectedWebhookId"
						:options="webhookOptions"
						:placeholder="t('openregister', 'Filter by webhook')"
						:clearable="true"
						:label-outside="true"
						:input-label="t('openregister', 'Filter by webhook')"
						@update:value="handleWebhookFilterChange">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
					<NcButton
						type="secondary"
						@click="refreshLogs">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Refresh') }}
					</NcButton>
				</div>
			</div>

			<!-- Logs Table -->
			<div class="tableContainer" :class="{ 'is-loading': loading }">
				<div v-if="loading" class="loadingWrapper">
					<NcLoadingIcon :size="64" />
				</div>

				<NcEmptyContent
					v-else-if="!logsList.length"
					:name="t('openregister', 'No log entries found')"
					:description="t('openregister', 'There are no webhook log entries matching your filters.')">
					<template #icon>
						<FileDocumentOutline :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="webhooksTable">
					<thead>
						<tr>
							<th>{{ t('openregister', 'Webhook') }}</th>
							<th>{{ t('openregister', 'Event') }}</th>
							<th>{{ t('openregister', 'Status') }}</th>
							<th>{{ t('openregister', 'Status Code') }}</th>
							<th>{{ t('openregister', 'Attempt') }}</th>
							<th>{{ t('openregister', 'Created') }}</th>
							<th>{{ t('openregister', 'Error') }}</th>
							<th class="column-actions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="log in logsList" :key="log.id">
							<td>
								{{ getWebhookName(log.webhookId) }}
							</td>
							<td>
								<span class="event-class">{{ truncateEventClass(log.eventClass) }}</span>
							</td>
							<td>
								<span :class="log.success ? 'status-success' : 'status-failed'">
									{{ log.success ? t('openregister', 'Success') : t('openregister', 'Failed') }}
								</span>
							</td>
							<td>
								{{ log.statusCode || '-' }}
							</td>
							<td>
								{{ log.attempt }}
							</td>
							<td>
								{{ formatDate(log.created) }}
							</td>
							<td>
								<span v-if="log.errorMessage" class="error-message" :title="log.errorMessage">
									{{ truncateText(log.errorMessage, 50) }}
								</span>
								<span v-else>-</span>
							</td>
							<td class="column-actions">
								<NcActions>
									<NcActionButton
										close-after-click
										@click="viewLogDetails(log)">
										<template #icon>
											<Eye :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="totalLogs > limit" class="pagination">
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
						:disabled="offset + limit >= totalLogs"
						@click="nextPage">
						{{ t('openregister', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { navigationStore } from '../../store/store.js'

import {
	NcAppContent,
	NcActions,
	NcActionButton,
	NcButton,
	NcLoadingIcon,
	NcEmptyContent,
	NcSelect,
} from '@nextcloud/vue'

import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

/**
 * Webhook Logs Index View
 */
export default {
	name: 'WebhookLogsIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcSelect,
		ArrowLeft,
		Refresh,
		FileDocumentOutline,
		Eye,
	},
	data() {
		return {
			logsList: [],
			webhooksList: [],
			loading: false,
			totalLogs: 0,
			limit: 50,
			offset: 0,
			selectedWebhookId: null,
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
			return Math.ceil(this.totalLogs / this.limit)
		},

		/**
		 * Webhook options for filter dropdown
		 *
		 * @return {Array} Webhook options
		 */
		webhookOptions() {
			const options = [
				{ value: null, label: t('openregister', 'All Webhooks') },
			]
			return options.concat(
				this.webhooksList.map(webhook => ({
					value: webhook.id,
					label: webhook.name || webhook.url,
				})),
			)
		},
	},
	mounted() {
		// Get webhook ID from transfer data if available.
		const transferData = navigationStore.getTransferData()
		if (transferData && transferData.webhookId) {
			this.selectedWebhookId = transferData.webhookId
		}
		this.loadWebhooks()
		this.loadLogs()

		// Listen for retry events to refresh logs.
		window.addEventListener('webhook-log-retried', this.loadLogs)
	},
	beforeDestroy() {
		// Clean up event listener.
		window.removeEventListener('webhook-log-retried', this.loadLogs)
	},
	methods: {
		/**
		 * Load webhooks list
		 *
		 * @return {Promise<void>}
		 */
		async loadWebhooks() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/webhooks'),
				)
				this.webhooksList = response.data.results || []
			} catch (error) {
				console.error('Failed to load webhooks:', error)
			}
		},

		/**
		 * Load logs list
		 *
		 * @return {Promise<void>}
		 */
		async loadLogs() {
			this.loading = true
			try {
				const params = {
					limit: this.limit,
					offset: this.offset,
				}
				if (this.selectedWebhookId) {
					params.webhook_id = this.selectedWebhookId
				}

				const response = await axios.get(
					generateUrl('/apps/openregister/api/webhooks/logs'),
					{ params },
				)
				this.logsList = response.data.results || []
				this.totalLogs = response.data.total || 0
			} catch (error) {
				console.error('Failed to load logs:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Handle webhook filter change
		 *
		 * @param {number|null} webhookId - Selected webhook ID
		 * @return {void}
		 */
		handleWebhookFilterChange(webhookId) {
			this.selectedWebhookId = webhookId
			this.offset = 0
			this.loadLogs()
		},

		/**
		 * Refresh logs
		 *
		 * @return {void}
		 */
		refreshLogs() {
			this.loadLogs()
		},

		/**
		 * Go to previous page
		 *
		 * @return {void}
		 */
		previousPage() {
			if (this.offset > 0) {
				this.offset -= this.limit
				this.loadLogs()
			}
		},

		/**
		 * Go to next page
		 *
		 * @return {void}
		 */
		nextPage() {
			if (this.offset + this.limit < this.totalLogs) {
				this.offset += this.limit
				this.loadLogs()
			}
		},

		/**
		 * Get webhook name by ID
		 *
		 * @param {number} webhookId - Webhook ID
		 * @return {string} Webhook name
		 */
		getWebhookName(webhookId) {
			const webhook = this.webhooksList.find(w => w.id === webhookId)
			return webhook ? (webhook.name || webhook.url) : `#${webhookId}`
		},

		/**
		 * Truncate event class name
		 *
		 * @param {string} eventClass - Event class name
		 * @return {string} Truncated event class
		 */
		truncateEventClass(eventClass) {
			if (!eventClass) return '-'
			const parts = eventClass.split('\\')
			return parts[parts.length - 1]
		},

		/**
		 * Truncate text
		 *
		 * @param {string} text - Text to truncate
		 * @param {number} maxLength - Maximum length
		 * @return {string} Truncated text
		 */
		truncateText(text, maxLength) {
			if (!text) return ''
			if (text.length <= maxLength) return text
			return text.substring(0, maxLength) + '...'
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

		/**
		 * View log details
		 *
		 * @param {object} log - Log entry
		 * @return {void}
		 */
		viewLogDetails(log) {
			// Set log data in navigation store and open modal.
			navigationStore.setTransferData({ log })
			navigationStore.setModal('viewWebhookLog')
		},

		/**
		 * Go back to webhooks list
		 *
		 * @return {void}
		 */
		goBack() {
			this.$router.push('/webhooks')
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
	align-items: center;
}

.tableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	overflow-x: auto;
	overflow-y: visible;
	min-height: 200px;
}

.tableContainer.is-loading {
	overflow: hidden;
	display: flex;
	align-items: center;
	justify-content: center;
}

.loadingWrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	padding: 40px;
}

.webhooksTable {
	width: 100%;
	border-collapse: collapse;
	min-width: 100%;
}

.webhooksTable thead {
	background: var(--color-background-hover);
	border-bottom: 2px solid var(--color-border);
}

.webhooksTable th {
	padding: 12px 16px;
	text-align: left;
	font-weight: 600;
	white-space: nowrap;
}

.webhooksTable td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.webhooksTable tbody tr:hover {
	background: var(--color-background-hover);
}

.column-actions {
	position: sticky;
	right: 0;
	background: var(--color-main-background);
	z-index: 5;
	min-width: 80px;
	width: 80px;
	text-align: right;
}

.webhooksTable thead .column-actions {
	position: sticky;
	right: 0;
	background: var(--color-background-hover);
	z-index: 10;
	min-width: 80px;
	width: 80px;
	text-align: right;
}

.webhooksTable tbody tr:hover .column-actions {
	background: var(--color-background-hover);
}

.webhooksTable thead .column-actions {
	z-index: 10;
}

.webhooksTable tbody tr:hover .column-actions {
	background: var(--color-background-hover);
}

.status-success {
	color: var(--color-success);
	font-weight: 600;
}

.status-failed {
	color: var(--color-error);
	font-weight: 600;
}

.event-class {
	font-family: monospace;
	font-size: 0.9em;
}

.error-message {
	color: var(--color-error);
	font-size: 0.9em;
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 16px;
	padding: 20px;
}

.pagination-info {
	font-weight: 600;
}
</style>
