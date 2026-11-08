<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'Webhook Logs') }}
					</h1>
					<NcButton variant="tertiary" @click="goBack">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('openregister', 'Back to Webhooks') }}
					</NcButton>
				</div>
				<p>
					{{
						t(
							'openregister',
							'View webhook delivery logs and filter by webhook',
						)
					}}
				</p>
			</div>

			<!-- Filters -->
			<ListActionsBar
				:summary="
					t('openregister', 'Showing {showing} of {total} log entries', {
						showing: logsList.length,
						total: totalLogs,
					})
				">
				<NcSelect
					v-model="selectedWebhookId"
					:options="webhookOptions"
					:placeholder="t('openregister', 'Filter by webhook')"
					:clearable="true"
					:labelOutside="true"
					:inputLabel="t('openregister', 'Filter by webhook')"
					@update:modelValue="handleWebhookFilterChange">
					<template #option="{ option }">
						{{ option.label }}
					</template>
				</NcSelect>
				<NcButton variant="secondary" @click="refreshLogs">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('openregister', 'Refresh') }}
				</NcButton>
			</ListActionsBar>

			<!-- Logs Table -->
			<div class="tableContainer" :class="{ 'is-loading': loading }">
				<div v-if="loading" class="loadingWrapper">
					<NcLoadingIcon :size="64" />
				</div>

				<NcEmptyContent
					v-else-if="!logsList.length"
					:name="t('openregister', 'No log entries found')"
					:description="
						t(
							'openregister',
							'There are no webhook log entries matching your filters.',
						)
					">
					<template #icon>
						<FileDocumentOutline :size="64" />
					</template>
				</NcEmptyContent>

				<CnDataTable v-else :columns="logColumns" :rows="logsList">
					<template #column-webhookId="{ row }">
						{{ getWebhookName(row.webhookId) }}
					</template>
					<template #column-eventClass="{ row }">
						<span class="event-class">{{ truncateEventClass(row.eventClass) }}</span>
					</template>
					<template #column-success="{ row }">
						<span :class="row.success ? 'status-success' : 'status-failed'">
							{{
								row.success
									? t('openregister', 'Success')
									: t('openregister', 'Failed')
							}}
						</span>
					</template>
					<template #column-statusCode="{ row }">
						{{ row.statusCode || '-' }}
					</template>
					<template #column-attempt="{ row }">
						{{ row.attempt }}
					</template>
					<template #column-created="{ row }">
						{{ formatDate(row.created) }}
					</template>
					<template #column-errorMessage="{ row }">
						<span
							v-if="row.errorMessage"
							class="error-message"
							:title="row.errorMessage">
							{{ truncateText(row.errorMessage, 50) }}
						</span>
						<span v-else>-</span>
					</template>
					<template #actions-header>
						{{ t('openregister', 'Actions') }}
					</template>
					<template #row-actions="{ row }">
						<NcActions>
							<NcActionButton closeAfterClick @click="viewLogDetails(row)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openregister', 'View Details') }}
							</NcActionButton>
						</NcActions>
					</template>
				</CnDataTable>

				<!-- Pagination -->
				<OffsetPager
					:offset="offset"
					:limit="limit"
					:total="totalLogs"
					@previous="previousPage"
					@next="nextPage" />
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActions,
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
} from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import OffsetPager from '../../components/shared/OffsetPager.vue'
import { displayDateTime } from '../../services/displayDateTime.js'
import { navigationStore } from '../../store/store.js'

/**
 * Webhook Logs Index View
 */
export default {
	name: 'WebhookLogsIndex',
	components: {
		CnDataTable,
		ListActionsBar,
		OffsetPager,
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
		 * @spec exclude UI plumbing — static column definitions for the logs table
		 * @return {Array<object>}
		 */
		logColumns() {
			return [
				{ key: 'webhookId', label: t('openregister', 'Webhook') },
				{ key: 'eventClass', label: t('openregister', 'Event') },
				{ key: 'success', label: t('openregister', 'Status') },
				{ key: 'statusCode', label: t('openregister', 'Status Code') },
				{ key: 'attempt', label: t('openregister', 'Attempt') },
				{ key: 'created', label: t('openregister', 'Created') },
				{ key: 'errorMessage', label: t('openregister', 'Error') },
			]
		},

		/**
		 * Webhook options for filter dropdown
		 *
		 * @spec exclude UI plumbing — derived select-option list
		 * @return {Array} Webhook options
		 */
		webhookOptions() {
			const options = [
				{ value: null, label: t('openregister', 'All Webhooks') },
			]
			return options.concat(
				this.webhooksList.map((webhook) => ({
					value: webhook.id,
					label: webhook.name || webhook.url,
				})),
			)
		},
	},

	/**
	 * Lifecycle hook: load webhooks and logs and subscribe to retry events.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch and event wiring
	 * @return {void}
	 */
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

	/**
	 * Lifecycle hook: remove the retry event listener before teardown.
	 *
	 * @spec exclude UI plumbing — event-listener teardown
	 * @return {void}
	 */
	beforeUnmount() {
		// Clean up event listener.
		window.removeEventListener('webhook-log-retried', this.loadLogs)
	},

	methods: {
		/**
		 * Load webhooks list
		 *
		 * @spec exclude UI plumbing — fetches the webhook list for display
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
		 * @spec exclude UI plumbing — fetches webhook logs for display
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
		 * @spec exclude UI plumbing — filter handler reloads logs
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
		 * @spec exclude UI plumbing — refresh button reloads logs
		 * @return {void}
		 */
		refreshLogs() {
			this.loadLogs()
		},

		/**
		 * Go to previous page
		 *
		 * @spec exclude UI plumbing — pagination handler reloads logs
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
		 * @spec exclude UI plumbing — pagination handler reloads logs
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
		 * @spec exclude UI plumbing — display lookup helper
		 * @return {string} Webhook name
		 */
		getWebhookName(webhookId) {
			const webhook = this.webhooksList.find((w) => w.id === webhookId)
			return webhook ? webhook.name || webhook.url : `#${webhookId}`
		},

		/**
		 * Truncate event class name
		 *
		 * @param {string} eventClass - Event class name
		 * @spec exclude UI plumbing — pure presentation helper
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
		 * @spec exclude UI plumbing — pure presentation helper
		 * @return {string} Truncated text
		 */
		truncateText(text, maxLength) {
			if (!text) return ''
			if (text.length <= maxLength) return text
			return text.substring(0, maxLength) + '...'
		},

		formatDate: displayDateTime,

		/**
		 * View log details
		 *
		 * @param {object} log - Log entry
		 * @spec exclude UI plumbing — opens the log-details modal
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
		 * @spec exclude UI plumbing — router navigation
		 * @return {void}
		 */
		goBack() {
			this.$router.push('/webhooks')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/common.scss' as *;
@use '../../styles/views.scss' as *;

@include view-container-2;

.viewHeader {
	margin-bottom: 20px;
}

@include view-header-title-rules;

@include table-container-2-rules;

@include status-success-rules;

.event-class {
	font-family: monospace;
	font-size: 0.9em;
}

.error-message {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
