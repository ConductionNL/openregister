<template>
	<NcDialog
		v-if="navigationStore.modal === 'viewWebhookLog'"
		:name="t('openregister', 'Webhook Log Details')"
		size="large"
		:can-close="true"
		:open="true"
		@update:open="handleDialogClose">
		<div v-if="logItem" class="cn-webhook-log">
			<!-- Basic Information -->
			<CnDetailCard :title="t('openregister', 'Basic Information')">
				<CnDetailGrid
					layout="horizontal"
					class="no-margin-bottom"
					:items="basicInfoItems"
					:label-width="200">
					<template #item-1="{ item }">
						<code class="cn-webhook-log__event-class">{{ item.value }}</code>
					</template>
					<template #item-2>
						<CnStatusBadge
							:label="logItem.success ? t('openregister', 'Success') : t('openregister', 'Failed')"
							:variant="logItem.success ? 'success' : 'error'"
							solid />
					</template>
				</CnDetailGrid>
			</CnDetailCard>

			<!-- Error Information (if failed) -->
			<NcNoteCard
				v-if="!logItem.success && logItem.errorMessage"
				type="error"
				:heading="t('openregister', 'Error Message')">
				{{ logItem.errorMessage }}
			</NcNoteCard>

			<!-- Request Details -->
			<CnDetailCard :title="t('openregister', 'Request Details')">
				<CnJsonViewer
					v-if="logItem.requestBody"
					:value="formatJson(logItem.requestBody)"
					:read-only="true"
					language="json" />
				<p v-else class="cn-webhook-log__empty">
					{{ t('openregister', 'No request body available') }}
				</p>
			</CnDetailCard>

			<!-- Response Details -->
			<CnDetailCard :title="t('openregister', 'Response Details')">
				<CnJsonViewer
					v-if="logItem.responseBody"
					:value="logItem.responseBody"
					:read-only="true"
					language="auto" />
				<p v-else class="cn-webhook-log__empty">
					{{ t('openregister', 'No response body available') }}
				</p>
			</CnDetailCard>
		</div>

		<div v-else class="cn-webhook-log__loading">
			<NcLoadingIcon :size="64" />
			<p>{{ t('openregister', 'Loading log details...') }}</p>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				{{ t('openregister', 'Close') }}
			</NcButton>
			<NcButton
				v-if="logItem && !logItem.success"
				type="primary"
				:disabled="retrying"
				@click="retryWebhook">
				<template #icon>
					<Refresh v-if="!retrying" :size="20" />
					<NcLoadingIcon v-else :size="20" />
				</template>
				{{ retrying ? t('openregister', 'Retrying...') : t('openregister', 'Retry') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { navigationStore } from '../../store/store.js'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import {
	CnDetailCard,
	CnDetailGrid,
	CnJsonViewer,
	CnStatusBadge,
} from '@conduction/nextcloud-vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

/**
 * ViewWebhookLog modal component
 *
 * Displays detailed information about a webhook log entry.
 *
 * @module Modals/Webhook
 * @author Conduction
 * @copyright 2024 Conduction
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */
export default {
	name: 'ViewWebhookLog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		CnDetailCard,
		CnDetailGrid,
		CnJsonViewer,
		CnStatusBadge,
		Refresh,
	},
	data() {
		return {
			logItem: null,
			webhooksList: [],
			retrying: false,
		}
	},
	computed: {
		/**
		 * Navigation store computed property for template access.
		 *
		 * @return {object} Navigation store instance
		 */
		navigationStore() {
			return navigationStore
		},
		/**
		 * Detail grid items for the basic information section.
		 *
		 * @return {Array<{label: string, value?: string|number}>} Grid items
		 */
		basicInfoItems() {
			if (!this.logItem) return []
			return [
				{ label: t('openregister', 'Webhook'), value: this.getWebhookName(this.logItem.webhook) },
				{ label: t('openregister', 'Event'), value: this.logItem.eventClass },
				{ label: t('openregister', 'Status') },
				{ label: t('openregister', 'Status Code'), value: this.logItem.statusCode || '-' },
				{ label: t('openregister', 'Attempt'), value: this.logItem.attempt },
				{ label: t('openregister', 'Created'), value: this.formatDate(this.logItem.created) },
			]
		},
	},
	mounted() {
		this.loadLogData()
		this.loadWebhooks()
	},
	methods: {
		/**
		 * Load log data from navigation store transferData.
		 *
		 * @return {void}
		 */
		loadLogData() {
			const transferData = navigationStore.getTransferData()
			if (transferData && transferData.log) {
				this.logItem = { ...transferData.log }
				// Map payload to requestBody if requestBody is not available.
				if (!this.logItem.requestBody && this.logItem.payload) {
					this.logItem.requestBody = typeof this.logItem.payload === 'string'
						? this.logItem.payload
						: JSON.stringify(this.logItem.payload)
				}
			}
		},

		/**
		 * Load webhooks list for name lookup.
		 *
		 * @return {Promise<void>}
		 */
		async loadWebhooks() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/webhooks'))
				this.webhooksList = response.data.results || []
			} catch (error) {
				// Silently fail - webhook name lookup is not critical.
			}
		},

		/**
		 * Get webhook name by ID.
		 *
		 * @param {number} webhookId - Webhook ID
		 * @return {string} Webhook name or ID
		 */
		getWebhookName(webhookId) {
			const webhook = this.webhooksList.find(w => w.id === webhookId)
			return webhook ? webhook.name : `#${webhookId}`
		},

		/**
		 * Format date string.
		 *
		 * @param {string} dateString - Date string to format
		 * @return {string} Formatted date
		 */
		formatDate(dateString) {
			if (!dateString) {
				return '-'
			}
			try {
				const date = new Date(dateString)
				return date.toLocaleString()
			} catch (error) {
				return dateString
			}
		},

		/**
		 * Format JSON string with indentation.
		 *
		 * @param {string} jsonString - JSON string to format
		 * @return {string} Formatted JSON or original string if invalid
		 */
		formatJson(jsonString) {
			if (!jsonString) {
				return '-'
			}
			try {
				const parsed = JSON.parse(jsonString)
				return JSON.stringify(parsed, null, 2)
			} catch (error) {
				// If not valid JSON, return as-is.
				return jsonString
			}
		},

		/**
		 * Handle dialog close.
		 *
		 * @param {boolean} open - Dialog open state
		 * @return {void}
		 */
		handleDialogClose(open) {
			if (!open) {
				this.closeModal()
			}
		},

		/**
		 * Retry failed webhook delivery.
		 *
		 * @return {Promise<void>}
		 */
		async retryWebhook() {
			if (!this.logItem || !this.logItem.id) {
				return
			}

			this.retrying = true
			try {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/webhooks/logs/${this.logItem.id}/retry`),
				)

				if (response.data && response.data.success === true) {
					showSuccess(t('openregister', 'Webhook retry delivered successfully'))
					// Close modal and refresh parent view.
					this.closeModal()
					// Trigger a custom event to refresh logs list.
					window.dispatchEvent(new CustomEvent('webhook-log-retried'))
				} else {
					const message = response.data?.message || response.data?.error || t('openregister', 'Webhook retry delivery failed')
					showError(message)
				}
			} catch (error) {
				const errorMessage = error.response?.data?.error || error.response?.data?.message || t('openregister', 'Failed to retry webhook')
				showError(errorMessage)
			} finally {
				this.retrying = false
			}
		},

		/**
		 * Close modal.
		 *
		 * @return {void}
		 */
		closeModal() {
			navigationStore.setModal(false)
			navigationStore.clearTransferData()
			this.logItem = null
		},
	},
}
</script>

<style scoped>
.cn-webhook-log {
	padding: 16px 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.cn-webhook-log__event-class {
	background: var(--color-background-dark);
	padding: 4px 8px;
	border-radius: 4px;
	font-family: monospace;
	font-size: 12px;
}

.cn-webhook-log__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.cn-webhook-log__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
}

.cn-webhook-log__loading p {
	margin-top: 20px;
	color: var(--color-text-maxcontrast);
}

.no-margin-bottom {
    margin-bottom: 0 !important;
}
</style>
