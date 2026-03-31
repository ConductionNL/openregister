<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<CnIndexPage
			ref="indexPage"
			title="Webhooks"
			description="Manage webhooks for event-driven integrations"
			:show-title="true"
			:objects="webhooksList"
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
			add-label="Create Webhook"
			empty-text="No webhooks found"
			@add="openCreateDialog"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event">
			<!-- Card view template -->
			<template #card="{ object }">
				<CnCard
					:title="object.name"
					:labels="mapWebhookLabels(object)"
					:stats="mapWebhookStats(object)">
					<template #icon>
						<Webhook :size="20" />
					</template>
				</CnCard>
			</template>

			<!-- Custom column: name with icon -->
			<template #column-name="{ row }">
				<div class="webhook-name-cell">
					<Webhook :size="20" class="webhook-icon" />
					<span class="webhook-name">{{ row.name }}</span>
				</div>
			</template>

			<!-- Custom column: truncated URL -->
			<template #column-url="{ row }">
				<span class="webhook-url">{{ truncateUrl(row.url) }}</span>
			</template>

			<!-- Custom column: method badge -->
			<template #column-method="{ row }">
				<CnStatusBadge :label="row.method" :color-map="methodColorMap" :solid="true" />
			</template>

			<!-- Custom column: status badge -->
			<template #column-status="{ row }">
				<CnStatusBadge
					:label="row.enabled ? t('openregister', 'Enabled') : t('openregister', 'Disabled')"
					:variant="row.enabled ? 'success' : 'warning'"
					:solid="true" />
			</template>

			<!-- Custom column: last triggered date -->
			<template #column-lastTriggeredAt="{ row }">
				{{ formatDate(row.lastTriggeredAt) }}
			</template>

			<!-- Custom column: success rate -->
			<template #column-successRate="{ row }">
				{{ formatSuccessRate(row) }}
			</template>

			<!-- Row actions -->
			<template #row-actions="{ row }">
				<NcActions>
					<NcActionButton
						close-after-click
						@click="editWebhook(row)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openregister', 'Edit') }}
					</NcActionButton>
					<NcActionButton
						close-after-click
						@click="testWebhook(row.id)">
						<template #icon>
							<PlayOutline :size="20" />
						</template>
						{{ t('openregister', 'Test') }}
					</NcActionButton>
					<NcActionButton
						close-after-click
						@click="viewLogs(row.id)">
						<template #icon>
							<FileDocumentOutline :size="20" />
						</template>
						{{ t('openregister', 'View Logs') }}
					</NcActionButton>
					<NcActionButton
						close-after-click
						@click="toggleWebhook(row)">
						<template #icon>
							<PauseCircleOutline v-if="row.enabled" :size="20" />
							<PlayOutline v-else :size="20" />
						</template>
						{{ row.enabled ? t('openregister', 'Disable') : t('openregister', 'Enable') }}
					</NcActionButton>
					<NcActionButton
						close-after-click
						@click="deleteWebhook(row.id)">
						<template #icon>
							<DeleteOutline :size="20" />
						</template>
						{{ t('openregister', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>

			<!-- Filter toggle in header actions -->
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
} from '@nextcloud/vue'

import { CnIndexPage, CnCard, CnStatusBadge } from '@conduction/nextcloud-vue'

import WebhooksSidebar from '../../components/WebhooksSidebar.vue'

import Webhook from 'vue-material-design-icons/Webhook.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import PlayOutline from 'vue-material-design-icons/PlayOutline.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
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
		CnIndexPage,
		CnCard,
		CnStatusBadge,
		Webhook,
		FilterVariant,
		PlayOutline,
		PauseCircleOutline,
		Pencil,
		DeleteOutline,
		FileDocumentOutline,
		WebhooksSidebar,
	},
	data() {
		return {
			webhooksList: [],
			loading: false,
			isRefreshing: false,
			totalWebhooks: 0,
			viewMode: 'table',
			sidebarOpen: false,
			searchQuery: '',
			enabledFilter: null,
			pagination: {
				page: 1,
				limit: 50,
			},
		}
	},
	computed: {
		methodColorMap() {
			return {
				GET: 'success',
				POST: 'primary',
				PUT: 'warning',
				PATCH: 'info',
				DELETE: 'error',
			}
		},

		tableColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), sortable: true },
				{ key: 'url', label: t('openregister', 'URL') },
				{ key: 'method', label: t('openregister', 'Method') },
				{ key: 'status', label: t('openregister', 'Status') },
				{ key: 'lastTriggeredAt', label: t('openregister', 'Last Triggered') },
				{ key: 'successRate', label: t('openregister', 'Success Rate') },
			]
		},

		paginationData() {
			const page = this.pagination.page
			const limit = this.pagination.limit
			const total = this.totalWebhooks
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
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
			this.pagination.page = 1
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
			this.pagination.page = 1
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
					const start = (this.pagination.page - 1) * this.pagination.limit
					const end = start + this.pagination.limit
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
		 * Handle refresh
		 *
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadWebhooks()
			} finally {
				this.isRefreshing = false
			}
		},

		/**
		 * Handle page change
		 *
		 * @param {number} page - New page number
		 * @return {void}
		 */
		onPageChanged(page) {
			this.pagination.page = page
			this.loadWebhooks()
		},

		/**
		 * Handle page size change
		 *
		 * @param {number} pageSize - New page size
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			this.loadWebhooks()
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
			this.$router.push(`/webhooks/logs/${webhookId}`)
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

		/**
		 * Map webhook data to card labels
		 *
		 * @param {object} webhook - Webhook object
		 * @return {Array} Label objects
		 */
		mapWebhookLabels(webhook) {
			const labels = []
			if (webhook.method) {
				labels.push({ text: webhook.method, variant: 'default' })
			}
			labels.push({
				text: webhook.enabled ? t('openregister', 'Enabled') : t('openregister', 'Disabled'),
				variant: webhook.enabled ? 'success' : 'warning',
			})
			return labels
		},

		/**
		 * Map webhook data to card stats
		 *
		 * @param {object} webhook - Webhook object
		 * @return {Array} Stat objects
		 */
		mapWebhookStats(webhook) {
			return [
				{ label: t('openregister', 'URL'), value: this.truncateUrl(webhook.url) },
				{ label: t('openregister', 'Last Triggered'), value: this.formatDate(webhook.lastTriggeredAt) },
				{ label: t('openregister', 'Success Rate'), value: this.formatSuccessRate(webhook) },
			]
		},
	},
}
</script>

<style scoped>
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
</style>
