<template>
	<NcAppContent>
		<div class="viewContainer">
			<h2>{{ t('openregister', 'Consumers') }}</h2>

			<ListTableStates
				:loading="loading"
				:empty="!consumers.length"
				:emptyName="t('openregister', 'No consumers.')">
				<CnDataTable :columns="columns" :rows="consumers">
					<template #column-grant="{ row }">
						<span :data-grant="row.id">{{ grantSummary(row) }}</span>
					</template>
					<template #column-expires="{ row }">
						{{ formatDate(grantOf(row) && grantOf(row).expiresAt) }}
					</template>
					<template #actions-header>
						{{ t('openregister', 'Actions') }}
					</template>
					<template #row-actions="{ row }">
						<NcButton :data-edit="row.id" @click="editing = row">
							{{ t('openregister', 'Edit') }}
						</NcButton>
					</template>
				</CnDataTable>
			</ListTableStates>
		</div>

		<ConsumerGrantModal
			v-if="editing"
			:consumer="editing"
			@close="editing = null"
			@saved="saved" />
	</NcAppContent>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcButton } from '@nextcloud/vue'
import ConsumerGrantModal from '../../modals/consumers/ConsumerGrantModal.vue'
import ListTableStates from '../ListTableStates.vue'

export default {
	name: 'ConsumersIndex',
	components: { CnDataTable, ConsumerGrantModal, ListTableStates, NcAppContent, NcButton },

	data() {
		return {
			consumers: [],
			loading: false,
			editing: null,
		}
	},

	computed: {
		columns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), cellClass: 'cn-cell--wrap' },
				{ key: 'userId', label: t('openregister', 'User') },
				{ key: 'grant', label: t('openregister', 'Grant') },
				{ key: 'expires', label: t('openregister', 'Expires') },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		/**
		 * Load the consumers.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/consumers'))
				this.consumers = data?.results || []
			} catch {
				this.consumers = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * A consumer's grant, or null.
		 *
		 * @spec exclude UI plumbing — reads the grant out of the authorization configuration.
		 * @param {object} consumer - the consumer
		 * @return {object|null} the grant
		 */
		grantOf(consumer) {
			const grant = consumer?.authorizationConfiguration?.grant
			return grant && typeof grant === 'object' ? grant : null
		},

		/**
		 * The verbs and scope a consumer's grant allows, for the table.
		 *
		 * @spec exclude UI plumbing — display text for the grant column.
		 * @param {object} consumer - the consumer
		 * @return {string} the summary, or a dash without a grant
		 */
		grantSummary(consumer) {
			const grant = this.grantOf(consumer)
			if (grant === null) return '—'
			const scope = [...(grant.registers || []), ...(grant.schemas || [])]
			return [(grant.verbs || []).join(', '), scope.join(', ')].filter((part) => part !== '').join(' · ')
		},

		/**
		 * Format a date for the table.
		 *
		 * @spec exclude UI plumbing — pure display formatter.
		 * @param {string|null} value - an ISO date
		 * @return {string} the localized date, or a dash
		 */
		formatDate(value) {
			return value ? new Date(value).toLocaleDateString() : '—'
		},

		/**
		 * Close the editor after a save and reload.
		 *
		 * @spec exclude UI plumbing — toast and list refresh.
		 * @param {string} message - what happened
		 * @return {Promise<void>}
		 */
		async saved(message) {
			this.editing = null
			showSuccess(message)
			await this.load()
		},
	},
}
</script>
