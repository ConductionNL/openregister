<template>
	<NcAppContent :showDetails="sidebarOpen" @update:showDetails="toggleSidebar">
		<div class="viewContainer">
			<!-- Header -->
			<FilterToggleHeader
				:title="t('openregister', 'Webhooks')"
				:description="
					t(
						'openregister',
						'Manage webhooks for event-driven integrations',
					)
				"
				:sidebarOpen="sidebarOpen"
				@toggle="toggleSidebar" />

			<!-- Actions Bar -->
			<ListActionsBar
				:summary="
					webhooksList.length
						? t(
								'openregister',
								'Showing {showing} of {total} webhooks',
								{
									showing: webhooksList.length,
									total: totalWebhooks,
								},
							)
						: ''
				"
				:actions="[refreshAction(refreshWebhooks)]">
				<NcButton variant="primary" @click="openCreateDialog">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Create Webhook') }}
				</NcButton>
			</ListActionsBar>

			<!-- Webhooks Table -->
			<div class="tableContainer" :class="{ 'is-loading': loading }">
				<div v-if="loading" class="loadingWrapper">
					<NcLoadingIcon :size="64" />
				</div>

				<NcEmptyContent
					v-else-if="!webhooksList.length"
					:name="t('openregister', 'No webhooks found')"
					:description="
						t('openregister', 'No webhooks have been configured yet')
					">
					<template #icon>
						<Webhook :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="webhooksTable">
					<thead>
						<tr>
							<th scope="col" class="column-name">
								{{ t('openregister', 'Name') }}
							</th>
							<th scope="col" class="column-url">
								{{ t('openregister', 'URL') }}
							</th>
							<th scope="col" class="column-method">
								{{ t('openregister', 'Method') }}
							</th>
							<th scope="col" class="column-status">
								{{ t('openregister', 'Status') }}
							</th>
							<th scope="col" class="column-last-triggered">
								{{ t('openregister', 'Last Triggered') }}
							</th>
							<th scope="col" class="column-success-rate">
								{{ t('openregister', 'Success Rate') }}
							</th>
							<th scope="col" class="column-actions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="webhook in webhooksList" :key="webhook.id">
							<td class="column-name">
								<div class="webhook-name-cell">
									<Webhook :size="20" class="webhook-icon" />
									<span class="webhook-name">{{
										webhook.name
									}}</span>
								</div>
							</td>
							<td class="column-url">
								<span class="webhook-url">{{
									truncateUrl(webhook.url)
								}}</span>
							</td>
							<td class="column-method">
								<span class="badge badge-method">{{
									webhook.method
								}}</span>
							</td>
							<td class="column-status">
								<span
									class="badge"
									:class="
										'badge-status-'
										+ (webhook.enabled ? 'enabled' : 'disabled')
									">
									{{
										webhook.enabled
											? t('openregister', 'Enabled')
											: t('openregister', 'Disabled')
									}}
								</span>
							</td>
							<td class="column-last-triggered">
								{{ formatDate(webhook.lastTriggeredAt) }}
							</td>
							<td class="column-success-rate">
								{{ formatSuccessRate(webhook) }}
							</td>
							<td class="column-actions">
								<ActionMenu :actions="webhookActions(webhook)" />
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<OffsetPager
					:offset="offset"
					:limit="limit"
					:total="totalWebhooks"
					@previous="previousPage"
					@next="nextPage" />
			</div>
		</div>

		<!-- Search Sidebar -->
		<template #details>
			<WebhooksSidebar
				v-model:search="searchQuery"
				v-model:enabled="enabledFilter"
				@update:search="handleSearchUpdate"
				@update:enabled="handleEnabledUpdate" />
		</template>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
/**
 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-request-interception-must-support-pre-event-webhooks
 */
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import PlayOutline from 'vue-material-design-icons/PlayOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import FilterToggleHeader from '../../components/shared/FilterToggleHeader.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import OffsetPager from '../../components/shared/OffsetPager.vue'
import WebhooksSidebar from '../../components/WebhooksSidebar.vue'
import { displayDateTime } from '../../services/displayDateTime.js'
import { editAction, refreshAction } from '../../services/menuActions.js'
import { navigationStore, webhookStore } from '../../store/store.js'
import { offsetPaging } from '../offsetPaging.js'

/**
 * Main view for managing webhooks
 */
export default {
	name: 'WebhooksIndex',
	components: {
		ActionMenu,
		ListActionsBar,
		OffsetPager,
		FilterToggleHeader,
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		Webhook,
		Plus,
		WebhooksSidebar,
	},

	mixins: [offsetPaging('totalWebhooks')],

	data() {
		return {
			limit: 50,
			offset: 0,
			sidebarOpen: false,
			searchQuery: '',
			enabledFilter: null,
		}
	},

	computed: {
		/**
		 * Whether the webhook list is being fetched.
		 *
		 * @spec exclude UI plumbing — store loading flag passthrough.
		 * @return {boolean} True while a fetch is in flight
		 */
		loading() {
			return webhookStore.loading
		},

		/**
		 * The store's webhooks narrowed by the sidebar's search and enabled
		 * filters. Filtering is client-side because the list endpoint returns
		 * every webhook in one page.
		 *
		 * @spec exclude UI plumbing — client-side filter over the store list; webhook contract owned by webhook-payload-mapping.
		 * @return {Array} Filtered webhooks
		 */
		filteredWebhooks() {
			let webhooks = webhookStore.webhookList

			if (this.searchQuery) {
				const query = this.searchQuery.toLowerCase()
				webhooks = webhooks.filter(
					(w) =>
						w.name.toLowerCase().includes(query)
						|| w.url.toLowerCase().includes(query),
				)
			}

			if (this.enabledFilter !== null) {
				webhooks = webhooks.filter((w) => w.enabled === this.enabledFilter)
			}

			return webhooks
		},

		/**
		 * Number of webhooks matching the active filters (pre-pagination).
		 *
		 * @spec exclude UI plumbing — derived count for the actions bar.
		 * @return {number} Filtered webhook count
		 */
		totalWebhooks() {
			return this.filteredWebhooks.length
		},

		/**
		 * The webhooks shown on the current page.
		 *
		 * @spec exclude UI plumbing — client-side pagination slice; admin list contract owned by admin-list-views.
		 * @return {Array} Current page of webhooks
		 */
		webhooksList() {
			return this.filteredWebhooks.slice(this.offset, this.offset + this.limit)
		},

		/**
		 * Get properties for selected events
		 *
		 * @return {Array} Array of property options
		 *
		 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-request-interception-must-support-pre-event-webhooks
		 */
		selectedEventProperties() {
			if (!this.newWebhook.events || this.newWebhook.events.length === 0) {
				return []
			}

			// Get unique properties from all selected events.
			const propertiesSet = new Set()
			this.newWebhook.events.forEach((eventClass) => {
				const event = this.availableEvents.find(
					(e) => e.class === eventClass,
				)
				if (event && event.properties) {
					event.properties.forEach((prop) => propertiesSet.add(prop))
				}
			})

			return Array.from(propertiesSet).map((prop) => ({
				value: prop,
				label: prop,
			}))
		},
	},

	mounted() {
		this.loadWebhooks()
	},

	methods: {
		refreshAction,

		/**
		 * The row menu of a webhook.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} webhook The webhook
		 * @return {Array<object>} The ActionMenu entries
		 */
		webhookActions(webhook) {
			return [
				editAction(() => this.editWebhook(webhook)),
				{
					label: t('openregister', 'Test'),
					icon: PlayOutline,
					run: () => this.testWebhook(webhook.id),
				},
				{
					label: t('openregister', 'View Logs'),
					icon: FileDocumentOutline,
					run: () => this.viewLogs(webhook.id),
				},
				{
					label: webhook.enabled
						? t('openregister', 'Disable')
						: t('openregister', 'Enable'),

					icon: webhook.enabled ? PauseCircleOutline : PlayOutline,
					run: () => this.toggleWebhook(webhook),
				},
				{
					label: t('openregister', 'Delete'),
					icon: DeleteOutline,
					run: () => this.deleteWebhook(webhook.id),
				},
			]
		},

		t,

		/**
		 * Toggle sidebar visibility
		 *
		 * @spec openspec/specs/admin-list-views/spec.md
		 * @return {void}
		 */
		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		/**
		 * Handle search query update
		 *
		 * @spec exclude UI plumbing — local filter state + reload; webhook contract owned by webhook-payload-mapping.
		 * @param {string} query - Search query
		 * @return {void}
		 */
		handleSearchUpdate(query) {
			this.searchQuery = query
			// Filtering is a computed over the store list — no refetch needed.
			this.offset = 0
		},

		/**
		 * Handle enabled filter update
		 *
		 * @spec exclude UI plumbing — local filter state + reload; webhook contract owned by webhook-payload-mapping.
		 * @param {boolean|null} enabled - Enabled filter
		 * @return {void}
		 */
		handleEnabledUpdate(enabled) {
			this.enabledFilter = enabled
			// Filtering is a computed over the store list — no refetch needed.
			this.offset = 0
		},

		/**
		 * Load webhooks into the store
		 *
		 * @spec exclude UI plumbing — delegates the list load to webhookStore.
		 * @return {Promise<void>}
		 */
		async loadWebhooks() {
			try {
				await webhookStore.refreshWebhookList()
			} catch (error) {
				console.error('Failed to load webhooks:', error)
				showError(t('openregister', 'Failed to load webhooks'))
			}
		},

		/**
		 * Refresh the webhooks list
		 *
		 * @spec exclude UI plumbing — delegates to loadWebhooks.
		 * @return {void}
		 */
		refreshWebhooks() {
			this.loadWebhooks()
		},

		/**
		 * Test a webhook
		 *
		 * @spec exclude UI plumbing — thin POST + toast + list refresh; delivery contract owned by webhook-payload-mapping.
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
					const message =
						response.data?.message
						|| response.data?.error
						|| t('openregister', 'Test webhook delivery failed')
					showError(message)
				}
				// Always refresh webhook list to show updated statistics (last triggered, success rate).
				this.loadWebhooks()
			} catch (error) {
				console.error('Failed to test webhook:', error)
				const errorMessage =
					error.response?.data?.error
					|| error.response?.data?.message
					|| t('openregister', 'Failed to test webhook')
				showError(errorMessage)
				// Refresh even on error to show any partial updates.
				this.loadWebhooks()
			}
		},

		/**
		 * View logs for a webhook
		 *
		 * @spec exclude UI plumbing — transfer-data set + router navigation.
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
		 * @spec exclude UI plumbing — thin PUT + toast + list refresh; webhook contract owned by webhook-payload-mapping.
		 * @param {object} webhook - Webhook object
		 * @return {Promise<void>}
		 */
		async toggleWebhook(webhook) {
			try {
				await webhookStore.toggleWebhookEnabled(webhook)
				showSuccess(t('openregister', 'Webhook updated'))
			} catch (error) {
				console.error('Failed to toggle webhook:', error)
				showError(t('openregister', 'Failed to update webhook'))
			}
		},

		/**
		 * Delete a webhook
		 *
		 * @spec exclude UI plumbing — thin DELETE + toast + list refresh; webhook contract owned by webhook-payload-mapping.
		 * @param {number} webhookId - Webhook ID
		 * @return {Promise<void>}
		 */
		async deleteWebhook(webhookId) {
			try {
				await webhookStore.deleteWebhook(webhookId)
				showSuccess(t('openregister', 'Webhook deleted'))
			} catch (error) {
				console.error('Failed to delete webhook:', error)
				showError(t('openregister', 'Failed to delete webhook'))
			}
		},

		/**
		 * Open create webhook dialog
		 *
		 * @spec exclude UI plumbing — transfer-data set + modal dispatch.
		 * @return {void}
		 */
		openCreateDialog() {
			navigationStore.setTransferData({ webhook: null })
			navigationStore.setModal('editWebhook')
		},

		/**
		 * Open edit webhook dialog
		 *
		 * @spec exclude UI plumbing — transfer-data set + modal dispatch.
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
		 * @spec exclude UI plumbing — pure display formatter, no observable contract.
		 * @param {object} webhook - Webhook object
		 * @return {string} Formatted success rate
		 */
		formatSuccessRate(webhook) {
			if (!webhook.totalDeliveries || webhook.totalDeliveries === 0) {
				return '-'
			}
			const rate =
				(webhook.successfulDeliveries / webhook.totalDeliveries) * 100
			return `${Math.round(rate)}%`
		},

		/**
		 * Truncate URL for display
		 *
		 * @spec exclude UI plumbing — pure display formatter, no observable contract.
		 * @param {string} url - Full URL
		 * @return {string} Truncated URL
		 */
		truncateUrl(url) {
			if (!url) return ''
			if (url.length <= 40) return url
			return url.substring(0, 37) + '...'
		},

		formatDate: displayDateTime,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include view-container-2;

@include table-container-2-rules;

.webhooksTable tbody tr:hover {
	background: var(--color-background-hover);
}

@include webhooks-table-thead-column-actions;

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

@include badge;

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
</style>
