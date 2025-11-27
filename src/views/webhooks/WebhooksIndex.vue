<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'Webhooks') }}
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
					{{ t('openregister', 'Manage webhooks for event-driven integrations') }}
				</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="webhooksList.length" class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} webhooks', {
							showing: webhooksList.length,
							total: totalWebhooks
						}) }}
					</span>
				</div>
				<div class="viewActions">
					<NcButton
						type="primary"
						@click="openCreateDialog">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openregister', 'Create Webhook') }}
					</NcButton>
					<NcActions
						:force-name="true"
						:inline="1"
						menu-name="Actions">
						<NcActionButton
							close-after-click
							@click="refreshWebhooks">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Webhooks Table -->
			<div class="tableContainer" :class="{ 'is-loading': loading }">
				<div v-if="loading" class="loadingWrapper">
					<NcLoadingIcon :size="64" />
				</div>

				<NcEmptyContent
					v-else-if="!webhooksList.length"
					:name="t('openregister', 'No webhooks found')"
					:description="t('openregister', 'No webhooks have been configured yet')">
					<template #icon>
						<Webhook :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="webhooksTable">
					<thead>
						<tr>
							<th class="column-name">
								{{ t('openregister', 'Name') }}
							</th>
							<th class="column-url">
								{{ t('openregister', 'URL') }}
							</th>
							<th class="column-method">
								{{ t('openregister', 'Method') }}
							</th>
							<th class="column-status">
								{{ t('openregister', 'Status') }}
							</th>
							<th class="column-last-triggered">
								{{ t('openregister', 'Last Triggered') }}
							</th>
							<th class="column-success-rate">
								{{ t('openregister', 'Success Rate') }}
							</th>
							<th class="column-actions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="webhook in webhooksList" :key="webhook.id">
							<td class="column-name">
								<div class="webhook-name-cell">
									<Webhook :size="20" class="webhook-icon" />
									<span class="webhook-name">{{ webhook.name }}</span>
								</div>
							</td>
							<td class="column-url">
								<span class="webhook-url">{{ truncateUrl(webhook.url) }}</span>
							</td>
							<td class="column-method">
								<span class="badge badge-method">{{ webhook.method }}</span>
							</td>
							<td class="column-status">
								<span class="badge" :class="'badge-status-' + (webhook.enabled ? 'enabled' : 'disabled')">
									{{ webhook.enabled ? t('openregister', 'Enabled') : t('openregister', 'Disabled') }}
								</span>
							</td>
							<td class="column-last-triggered">
								{{ formatDate(webhook.lastTriggeredAt) }}
							</td>
							<td class="column-success-rate">
								{{ formatSuccessRate(webhook) }}
							</td>
							<td class="column-actions">
								<NcActions>
									<NcActionButton
										close-after-click
										@click="editWebhook(webhook)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										{{ t('openregister', 'Edit') }}
									</NcActionButton>
									<NcActionButton
										close-after-click
										@click="testWebhook(webhook.id)">
										<template #icon>
											<PlayOutline :size="20" />
										</template>
										{{ t('openregister', 'Test') }}
									</NcActionButton>
									<NcActionButton
										close-after-click
										@click="viewLogs(webhook.id)">
										<template #icon>
											<FileDocumentOutline :size="20" />
										</template>
										{{ t('openregister', 'View Logs') }}
									</NcActionButton>
									<NcActionButton
										close-after-click
										@click="toggleWebhook(webhook)">
										<template #icon>
											<PauseCircleOutline v-if="webhook.enabled" :size="20" />
											<PlayOutline v-else :size="20" />
										</template>
										{{ webhook.enabled ? t('openregister', 'Disable') : t('openregister', 'Enable') }}
									</NcActionButton>
									<NcActionButton
										close-after-click
										@click="deleteWebhook(webhook.id)">
										<template #icon>
											<DeleteOutline :size="20" />
										</template>
										{{ t('openregister', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="totalWebhooks > limit" class="pagination">
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
						:disabled="offset + limit >= totalWebhooks"
						@click="nextPage">
						{{ t('openregister', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Search Sidebar -->
		<template #details>
			<WebhooksSidebar
				:search.sync="searchQuery"
				:enabled.sync="enabledFilter"
				@update:search="handleSearchUpdate"
				@update:enabled="handleEnabledUpdate" />
		</template>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { navigationStore } from '../../store/store.js'

import {
	NcAppContent,
	NcActions,
	NcActionButton,
	NcButton,
	NcLoadingIcon,
	NcEmptyContent,
} from '@nextcloud/vue'

import WebhooksSidebar from '../../components/WebhooksSidebar.vue'

import Webhook from 'vue-material-design-icons/Webhook.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import PlayOutline from 'vue-material-design-icons/PlayOutline.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'

/**
 * Main view for managing webhooks
 */
export default {
	name: 'WebhooksIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		Webhook,
		Refresh,
		FilterVariant,
		PlayOutline,
		PauseCircleOutline,
		Pencil,
		DeleteOutline,
		Plus,
		FileDocumentOutline,
		WebhooksSidebar,
	},
	data() {
		return {
			webhooksList: [],
			loading: false,
			totalWebhooks: 0,
			limit: 50,
			offset: 0,
			sidebarOpen: false,
			searchQuery: '',
			enabledFilter: null,
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
			return Math.ceil(this.totalWebhooks / this.limit)
		},

		/**
		 * Get properties for selected events
		 *
		 * @return {Array} Array of property options
		 */
		selectedEventProperties() {
			if (!this.newWebhook.events || this.newWebhook.events.length === 0) {
				return []
			}

			// Get unique properties from all selected events.
			const propertiesSet = new Set()
			this.newWebhook.events.forEach(eventClass => {
				const event = this.availableEvents.find(e => e.class === eventClass)
				if (event && event.properties) {
					event.properties.forEach(prop => propertiesSet.add(prop))
				}
			})

			return Array.from(propertiesSet).map(prop => ({
				value: prop,
				label: prop,
			}))
		},
	},
	mounted() {
		this.loadWebhooks()
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
			this.loadWebhooks()
		},

		/**
		 * Handle enabled filter update
		 *
		 * @param {boolean|null} enabled - Enabled filter
		 * @return {void}
		 */
		handleEnabledUpdate(enabled) {
			this.enabledFilter = enabled
			this.offset = 0
			this.loadWebhooks()
		},

		/**
		 * Load webhooks from the API
		 *
		 * @return {Promise<void>}
		 */
		async loadWebhooks() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/webhooks'),
				)

				if (response.data.results) {
					let webhooks = response.data.results

					// Apply search filter
					if (this.searchQuery) {
						const query = this.searchQuery.toLowerCase()
						webhooks = webhooks.filter(w =>
							w.name.toLowerCase().includes(query)
							|| w.url.toLowerCase().includes(query),
						)
					}

					// Apply enabled filter
					if (this.enabledFilter !== null) {
						webhooks = webhooks.filter(w => w.enabled === this.enabledFilter)
					}

					// Apply pagination
					this.totalWebhooks = webhooks.length
					const start = this.offset
					const end = start + this.limit
					this.webhooksList = webhooks.slice(start, end)
				}
			} catch (error) {
				console.error('Failed to load webhooks:', error)
				showError(t('openregister', 'Failed to load webhooks'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refresh the webhooks list
		 *
		 * @return {void}
		 */
		refreshWebhooks() {
			this.loadWebhooks()
		},

		/**
		 * Go to previous page
		 *
		 * @return {void}
		 */
		previousPage() {
			if (this.offset > 0) {
				this.offset = Math.max(0, this.offset - this.limit)
				this.loadWebhooks()
			}
		},

		/**
		 * Go to next page
		 *
		 * @return {void}
		 */
		nextPage() {
			if (this.offset + this.limit < this.totalWebhooks) {
				this.offset += this.limit
				this.loadWebhooks()
			}
		},

		/**
		 * Test a webhook
		 *
		 * @param {number} webhookId - Webhook ID
		 * @return {Promise<void>}
		 */
		async testWebhook(webhookId) {
			try {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/webhooks/${webhookId}/test`),
				)
				// Check if the test was successful based on response data.
				if (response.data && response.data.success === true) {
					showSuccess(t('openregister', 'Test webhook sent successfully'))
				} else {
					const message = response.data?.message || response.data?.error || t('openregister', 'Test webhook delivery failed')
					showError(message)
				}
				// Always refresh webhook list to show updated statistics (last triggered, success rate).
				this.loadWebhooks()
			} catch (error) {
				console.error('Failed to test webhook:', error)
				const errorMessage = error.response?.data?.error || error.response?.data?.message || t('openregister', 'Failed to test webhook')
				showError(errorMessage)
				// Refresh even on error to show any partial updates.
				this.loadWebhooks()
			}
		},

		/**
		 * View logs for a webhook
		 *
		 * @param {number} webhookId - Webhook ID
		 * @return {void}
		 */
		viewLogs(webhookId) {
			navigationStore.setTransferData({ webhookId })
			this.$router.push('/webhooks/logs')
		},

		/**
		 * Toggle webhook enabled status
		 *
		 * @param {object} webhook - Webhook object
		 * @return {Promise<void>}
		 */
		async toggleWebhook(webhook) {
			try {
				await axios.put(
					generateUrl(`/apps/openregister/api/webhooks/${webhook.id}`),
					{ enabled: !webhook.enabled },
				)
				showSuccess(t('openregister', 'Webhook updated'))
				this.loadWebhooks()
			} catch (error) {
				console.error('Failed to toggle webhook:', error)
				showError(t('openregister', 'Failed to update webhook'))
			}
		},

		/**
		 * Delete a webhook
		 *
		 * @param {number} webhookId - Webhook ID
		 * @return {Promise<void>}
		 */
		async deleteWebhook(webhookId) {
			try {
				await axios.delete(
					generateUrl(`/apps/openregister/api/webhooks/${webhookId}`),
				)
				showSuccess(t('openregister', 'Webhook deleted'))
				this.loadWebhooks()
			} catch (error) {
				console.error('Failed to delete webhook:', error)
				showError(t('openregister', 'Failed to delete webhook'))
			}
		},

		/**
		 * Open create webhook dialog
		 *
		 * @return {void}
		 */
		openCreateDialog() {
			navigationStore.setTransferData({ webhook: null })
			navigationStore.setModal('editWebhook')
		},

		/**
		 * Open edit webhook dialog
		 *
		 * @param {object} webhook - Webhook object to edit
		 * @return {void}
		 */
		editWebhook(webhook) {
			navigationStore.setTransferData({ webhook })
			navigationStore.setModal('editWebhook')
		},

		/**
		 * Format success rate
		 *
		 * @param {object} webhook - Webhook object
		 * @return {string} Formatted success rate
		 */
		formatSuccessRate(webhook) {
			if (!webhook.totalDeliveries || webhook.totalDeliveries === 0) {
				return '-'
			}
			const rate = (webhook.successfulDeliveries / webhook.totalDeliveries) * 100
			return `${Math.round(rate)}%`
		},

		/**
		 * Truncate URL for display
		 *
		 * @param {string} url - Full URL
		 * @return {string} Truncated URL
		 */
		truncateUrl(url) {
			if (!url) return ''
			if (url.length <= 40) return url
			return url.substring(0, 37) + '...'
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

.webhooksTable thead .column-actions {
	position: sticky;
	right: 0;
	background: var(--color-background-hover);
	z-index: 10;
	min-width: 80px;
	width: 80px;
	text-align: right;
}

.webhooksTable tbody .column-actions {
	position: sticky;
	right: 0;
	background: var(--color-main-background);
	z-index: 5;
	min-width: 80px;
	width: 80px;
	text-align: right;
}

.webhooksTable tbody tr:hover .column-actions {
	background: var(--color-background-hover);
}

.webhook-name-cell {
	display: flex;
	align-items: center;
	gap: 8px;
}

.webhook-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.webhook-name {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.webhook-url {
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 12px;
}

.badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.badge-method {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.badge-status-enabled {
	background: var(--color-success-light);
	color: var(--color-success);
}

.badge-status-disabled {
	background: var(--color-warning-light);
	color: var(--color-warning);
}

.column-name {
	min-width: 200px;
}

.column-url {
	min-width: 200px;
}

.column-method {
	width: 100px;
}

.column-status {
	width: 120px;
}

.column-last-triggered {
	width: 180px;
}

.column-success-rate {
	width: 120px;
	text-align: center;
}

.column-actions {
	position: sticky;
	right: 0;
	background: var(--color-main-background);
	z-index: 10;
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

.webhooksTable tbody .column-actions {
	position: sticky;
	right: 0;
	background: var(--color-main-background);
	z-index: 5;
}

.webhooksTable tbody tr:hover .column-actions {
	background: var(--color-background-hover);
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
