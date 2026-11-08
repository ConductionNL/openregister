<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<h1>{{ t('openregister', 'Queue / sync health') }}</h1>
				<p>
					{{
						t(
							'openregister',
							'Read-only webhook delivery telemetry: per-webhook delivered / failed / pending-retry counts and recent failures.',
						)
					}}
				</p>
			</div>

			<template v-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<template v-else-if="webhooks.length === 0">
				<NcEmptyContent
					:name="t('openregister', 'No webhooks configured')"
					:description="
						t(
							'openregister',
							'Configure a webhook to see its delivery health here.',
						)
					">
					<template #icon>
						<Webhook :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<section class="webhookHealthSection">
					<h2>{{ t('openregister', 'Per-webhook health') }}</h2>
					<CnDataTable :columns="healthColumns" :rows="healthRows" />
				</section>

				<section class="recentFailuresSection">
					<h2>{{ t('openregister', 'Recent failures') }}</h2>
					<NcEmptyContent
						v-if="webhookFailures.length === 0"
						:name="t('openregister', 'No recent failures')">
						<template #icon>
							<Webhook :size="48" />
						</template>
					</NcEmptyContent>
					<CnDataTable
						v-else
						:columns="failureColumns"
						:rows="failureRows"
						rowKey="rowKey" />
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import { qualityStore } from '../../store/store.js'

export default {
	name: 'QueueHealthIndex',

	components: {
		CnDataTable,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		Webhook,
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — proxies the store's loading flag, no backend contract of its own
		 */
		loading() {
			return qualityStore.isLoading
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		webhooks() {
			return qualityStore.webhooks
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		webhookFailures() {
			return qualityStore.webhookFailures
		},

		/**
		 * @spec exclude UI plumbing — static column definitions for the health table
		 * @return {Array<object>}
		 */
		healthColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Webhook') },
				{ key: 'successful', label: t('openregister', 'Delivered') },
				{ key: 'failed', label: t('openregister', 'Failed') },
				{ key: 'pendingRetries', label: t('openregister', 'Pending retries') },
			]
		},

		/**
		 * One row per webhook with its cached counts; a missing count reads 0.
		 *
		 * @spec exclude UI plumbing — shapes the store's webhooks and stats for the table
		 * @return {Array<object>}
		 */
		healthRows() {
			return this.webhooks.map((webhook) => {
				const stats = this.statsFor(webhook.id)
				return {
					id: webhook.id,
					name: webhook.name || webhook.url,
					successful: stats.successful ?? 0,
					failed: stats.failed ?? 0,
					pendingRetries: stats.pendingRetries ?? 0,
				}
			})
		},

		/**
		 * @spec exclude UI plumbing — static column definitions for the failures table
		 * @return {Array<object>}
		 */
		failureColumns() {
			return [
				{ key: 'webhook', label: t('openregister', 'Webhook') },
				{ key: 'eventClass', label: t('openregister', 'Event') },
				{ key: 'statusCode', label: t('openregister', 'Status code') },
				{ key: 'errorMessage', label: t('openregister', 'Error') },
				{ key: 'created', label: t('openregister', 'Created') },
			]
		},

		/**
		 * The recent failures, keyed by id or position; an absent value reads '—'.
		 *
		 * @spec exclude UI plumbing — shapes the store's failures for the table
		 * @return {Array<object>}
		 */
		failureRows() {
			return this.webhookFailures.map((log, index) => ({
				...log,
				rowKey: log.id || index,
				statusCode: log.statusCode ?? '—',
				errorMessage: log.errorMessage ?? '—',
				created: log.created ?? '—',
			}))
		},
	},

	mounted() {
		this.loadData()
	},

	methods: {
		/**
		 * Load the webhook list, per-webhook stats, and recent failures.
		 * When no webhooks are configured, no per-webhook stats requests are
		 * issued (the store short-circuits on an empty list).
		 *
		 * @spec exclude UI data-loading orchestration — delegates to fetchWebhookHealth which carries the @spec tag
		 */
		async loadData() {
			await qualityStore.fetchWebhookHealth()
		},

		/**
		 * Look up the cached per-webhook stats for a webhook id.
		 *
		 * @param {number|string} webhookId Webhook id.
		 * @spec exclude UI plumbing — reads the store's stats cache map
		 * @return {object}
		 */
		statsFor(webhookId) {
			return qualityStore.webhookStats?.[webhookId] || {}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include view-container-3-subtitled;

.webhookHealthSection,
.recentFailuresSection {
	margin-top: 24px;
}
</style>
