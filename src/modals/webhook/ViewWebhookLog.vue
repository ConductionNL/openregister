<template>
	<NcDialog
		v-if="navigationStore.modal === 'viewWebhookLog'"
		:name="t('openregister', 'Webhook Log Details')"
		size="large"
		:can-close="true"
		:open="true"
		@update:open="handleDialogClose">
		<div v-if="logItem" class="logDetailsContainer">
			<!-- Basic Information -->
			<div class="logSection">
				<h3>{{ t('openregister', 'Basic Information') }}</h3>
				<table class="logDetailsTable">
					<tr>
						<td class="logLabel">{{ t('openregister', 'Webhook') }}</td>
						<td class="logValue">{{ getWebhookName(logItem.webhookId) }}</td>
					</tr>
					<tr>
						<td class="logLabel">{{ t('openregister', 'Event') }}</td>
						<td class="logValue">
							<code class="event-class">{{ logItem.eventClass }}</code>
						</td>
					</tr>
					<tr>
						<td class="logLabel">{{ t('openregister', 'Status') }}</td>
						<td class="logValue">
							<span :class="logItem.success ? 'status-success' : 'status-failed'">
								{{ logItem.success ? t('openregister', 'Success') : t('openregister', 'Failed') }}
							</span>
						</td>
					</tr>
					<tr>
						<td class="logLabel">{{ t('openregister', 'Status Code') }}</td>
						<td class="logValue">
							<span v-if="logItem.statusCode">{{ logItem.statusCode }}</span>
							<span v-else class="text-muted">-</span>
						</td>
					</tr>
					<tr>
						<td class="logLabel">{{ t('openregister', 'Attempt') }}</td>
						<td class="logValue">{{ logItem.attempt }}</td>
					</tr>
					<tr>
						<td class="logLabel">{{ t('openregister', 'Created') }}</td>
						<td class="logValue">{{ formatDate(logItem.created) }}</td>
					</tr>
				</table>
			</div>

			<!-- Error Information (if failed) -->
			<div v-if="!logItem.success && logItem.errorMessage" class="logSection">
				<h3>{{ t('openregister', 'Error Information') }}</h3>
				<div class="errorMessage">
					<strong>{{ t('openregister', 'Error Message') }}:</strong>
					<p>{{ logItem.errorMessage }}</p>
				</div>
			</div>

			<!-- Request Details -->
			<div class="logSection">
				<h3>{{ t('openregister', 'Request Details') }}</h3>
				<div v-if="logItem.requestBody" class="codeBlock">
					<strong>{{ t('openregister', 'Request Body') }}:</strong>
					<pre><code>{{ formatJson(logItem.requestBody) }}</code></pre>
				</div>
				<div v-else class="text-muted">
					{{ t('openregister', 'No request body available') }}
				</div>
			</div>

			<!-- Response Details -->
			<div class="logSection">
				<h3>{{ t('openregister', 'Response Details') }}</h3>
				<div v-if="logItem.responseBody" class="codeBlock">
					<strong>{{ t('openregister', 'Response Body') }}:</strong>
					<pre><code>{{ formatJson(logItem.responseBody) }}</code></pre>
				</div>
				<div v-else class="text-muted">
					{{ t('openregister', 'No response body available') }}
				</div>
			</div>
		</div>

		<div v-else class="loadingContainer">
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
} from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

/**
 * ViewWebhookLog modal component
 *
 * Displays detailed information about a webhook log entry.
 *
 * @module Modals/Webhook
 * @category Modals
 * @package openregister
 * @author Conduction
 * @copyright 2024 Conduction
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/conduction/openregister
 */
export default {
	name: 'ViewWebhookLog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
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
				const { generateUrl } = await import('@nextcloud/router')
				const axios = (await import('@nextcloud/axios')).default
				const response = await axios.get(generateUrl('/apps/openregister/api/webhooks'))
				if (response.data && Array.isArray(response.data)) {
					this.webhooksList = response.data
				}
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
.logDetailsContainer {
	padding: 20px;
}

.logSection {
	margin-bottom: 30px;
}

.logSection h3 {
	margin-bottom: 15px;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.logDetailsTable {
	width: 100%;
	border-collapse: collapse;
}

.logDetailsTable tr {
	border-bottom: 1px solid var(--color-border);
}

.logDetailsTable td {
	padding: 12px 0;
	vertical-align: top;
}

.logLabel {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	width: 200px;
	min-width: 200px;
}

.logValue {
	color: var(--color-main-text);
	word-break: break-word;
}

.event-class {
	background: var(--color-background-dark);
	padding: 4px 8px;
	border-radius: 4px;
	font-family: monospace;
	font-size: 12px;
}

.status-success {
	color: var(--color-success);
	font-weight: 600;
}

.status-failed {
	color: var(--color-error);
	font-weight: 600;
}

.text-muted {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.errorMessage {
	background: var(--color-error-background);
	border-left: 4px solid var(--color-error);
	padding: 15px;
	border-radius: 4px;
}

.errorMessage strong {
	color: var(--color-error);
	display: block;
	margin-bottom: 8px;
}

.errorMessage p {
	margin: 0;
	color: var(--color-main-text);
	white-space: pre-wrap;
	word-break: break-word;
}

.codeBlock {
	margin-top: 10px;
}

.codeBlock strong {
	display: block;
	margin-bottom: 8px;
	color: var(--color-main-text);
}

.codeBlock pre {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 15px;
	overflow-x: auto;
	margin: 0;
}

.codeBlock code {
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', 'source-code-pro', monospace;
	font-size: 12px;
	line-height: 1.5;
	color: var(--color-main-text);
	white-space: pre;
	word-wrap: normal;
}

.loadingContainer {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
}

.loadingContainer p {
	margin-top: 20px;
	color: var(--color-text-maxcontrast);
}
</style>

