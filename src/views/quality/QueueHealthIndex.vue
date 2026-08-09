<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<h1>{{ t('openregister', 'Queue / sync health') }}</h1>
				<p>{{ t('openregister', 'Read-only webhook delivery telemetry: per-webhook delivered / failed / pending-retry counts and recent failures.') }}</p>
			</div>

			<template v-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<template v-else-if="webhooks.length === 0">
				<NcEmptyContent
					:name="t('openregister', 'No webhooks configured')"
					:description="t('openregister', 'Configure a webhook to see its delivery health here.')">
					<template #icon>
						<Webhook :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<section class="webhookHealthSection">
					<h2>{{ t('openregister', 'Per-webhook health') }}</h2>
					<table class="webhookHealthTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Webhook') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Delivered') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Failed') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Pending retries') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="webhook in webhooks" :key="webhook.id">
								<td>{{ webhook.name || webhook.url }}</td>
								<td>{{ statsFor(webhook.id).successful ?? 0 }}</td>
								<td>{{ statsFor(webhook.id).failed ?? 0 }}</td>
								<td>{{ statsFor(webhook.id).pendingRetries ?? 0 }}</td>
							</tr>
						</tbody>
					</table>
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
					<table v-else class="recentFailuresTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Webhook') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Event') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Status code') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Error') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Created') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(log, index) in webhookFailures" :key="log.id || index">
								<td>{{ log.webhook }}</td>
								<td>{{ log.eventClass }}</td>
								<td>{{ log.statusCode ?? '—' }}</td>
								<td>{{ log.errorMessage ?? '—' }}</td>
								<td>{{ log.created ?? '—' }}</td>
							</tr>
						</tbody>
					</table>
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import { qualityStore } from '../../store/store.js'

export default {
	name: 'QueueHealthIndex',

	components: {
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

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 1200px;
}

.viewHeader h1 {
	margin-bottom: 4px;
}

.webhookHealthSection,
.recentFailuresSection {
	margin-top: 24px;
}

.webhookHealthTable,
.recentFailuresTable {
	width: 100%;
	border-collapse: collapse;
}

.webhookHealthTable th,
.webhookHealthTable td,
.recentFailuresTable th,
.recentFailuresTable td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
