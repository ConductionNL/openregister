<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			title="Webhook Logs"
			description="View webhook delivery logs and filter by webhook"
			:show-title="true"
			:show-add="false"
			:objects="logsList"
			:columns="tableColumns"
			:pagination="paginationData"
			:selectable="false"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-form-dialog="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			:show-view-toggle="false"
			:loading="loading"
			:refreshing="isRefreshing"
			empty-text="No log entries found"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged">
			<!-- Header actions: back button + webhook filter -->
			<template #header-actions>
				<NcButton
					type="tertiary"
					@click="goBack">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('openregister', 'Back to Webhooks') }}
				</NcButton>
				<NcSelect
					v-model="selectedWebhookId"
					:options="webhookOptions"
					:placeholder="t('openregister', 'Filter by webhook')"
					:clearable="true"
					:label-outside="true"
					:input-label="t('openregister', 'Filter by webhook')"
					label="label"
					:reduce="option => option.value"
					@input="handleWebhookFilterChange" />
			</template>

			<!-- Custom column: webhook name lookup -->
			<template #column-webhook="{ row }">
				{{ getWebhookName(row.webhook) }}
			</template>

			<!-- Custom column: truncated event class -->
			<template #column-eventClass="{ row }">
				<span class="event-class">{{ truncateEventClass(row.eventClass) }}</span>
			</template>

			<!-- Custom column: success/failed badge -->
			<template #column-success="{ row }">
				<CnStatusBadge
					:label="row.success ? t('openregister', 'Success') : t('openregister', 'Failed')"
					:variant="row.success ? 'success' : 'error'"
					:solid="true" />
			</template>

			<!-- Custom column: status code -->
			<template #column-statusCode="{ row }">
				{{ row.statusCode || '-' }}
			</template>

			<!-- Custom column: created date -->
			<template #column-created="{ row }">
				{{ formatDate(row.created) }}
			</template>

			<!-- Custom column: error message -->
			<template #column-errorMessage="{ row }">
				<span v-if="row.errorMessage" class="error-message" :title="row.errorMessage">
					{{ truncateText(row.errorMessage, 50) }}
				</span>
				<span v-else>-</span>
			</template>

			<!-- Row actions -->
			<template #row-actions="{ row }">
				<NcActions>
					<NcActionButton
						close-after-click
						@click="viewLogDetails(row)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openregister', 'View Details') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
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
	NcSelect,
} from '@nextcloud/vue'

import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'

import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
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
		NcSelect,
		CnIndexPage,
		CnStatusBadge,
		ArrowLeft,
		Eye,
	},
	data() {
		return {
			logsList: [],
			webhooksList: [],
			loading: false,
			isRefreshing: false,
			totalLogs: 0,
			selectedWebhookId: null,
			pagination: {
				page: 1,
				limit: 50,
			},
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'webhook', label: t('openregister', 'Webhook') },
				{ key: 'eventClass', label: t('openregister', 'Event') },
				{ key: 'success', label: t('openregister', 'Status') },
				{ key: 'statusCode', label: t('openregister', 'Status Code') },
				{ key: 'attempt', label: t('openregister', 'Attempt') },
				{ key: 'created', label: t('openregister', 'Created') },
				{ key: 'errorMessage', label: t('openregister', 'Error') },
			]
		},

		paginationData() {
			return {
				page: this.pagination.page,
				pages: Math.ceil(this.totalLogs / this.pagination.limit),
				total: this.totalLogs,
				limit: this.pagination.limit,
			}
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
		// Get webhook ID from route params if available.
		if (this.$route.params.id) {
			this.selectedWebhookId = Number(this.$route.params.id)
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
		t,

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
					limit: this.pagination.limit,
					offset: (this.pagination.page - 1) * this.pagination.limit,
				}
				if (this.selectedWebhookId) {
					params.webhook = this.selectedWebhookId
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
		 * Handle refresh
		 *
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadLogs()
			} finally {
				this.isRefreshing = false
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
			this.pagination.page = 1

			// Update URL to reflect the filter
			const path = webhookId ? `/webhooks/logs/${webhookId}` : '/webhooks/logs'
			if (this.$route.path !== path) {
				this.$router.replace(path)
			}

			this.loadLogs()
		},

		/**
		 * Handle page change
		 *
		 * @param {number} page - New page number
		 * @return {void}
		 */
		onPageChanged(page) {
			this.pagination.page = page
			this.loadLogs()
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
			this.loadLogs()
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
.event-class {
	font-family: monospace;
	font-size: 0.9em;
}

.error-message {
	color: var(--color-error);
	font-size: 0.9em;
}

</style>
