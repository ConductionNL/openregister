<template>
	<NcModal :name="consumer.name || t('openregister', 'Grant')" @close="$emit('close')">
		<div class="consumer-grant-modal">
			<h3>{{ consumer.name }}</h3>

			<TokenGrantEditor
				:grant="grant"
				:busy="busy"
				:removable="grant !== null"
				@save="save"
				@remove="remove" />

			<p v-if="error" class="consumer-grant-modal__error" role="alert">
				{{ error }}
			</p>

			<section v-if="renewals.length" class="consumer-grant-modal__renewals">
				<h4>{{ t('openregister', 'Renewals') }}</h4>
				<ul>
					<li v-for="renewal in renewals" :key="renewal.at" data-renewal>
						{{ renewal.by }}: {{ formatDate(renewal.from) }} → {{ formatDate(renewal.expiresAt) }} ({{ formatDate(renewal.at) }})
					</li>
				</ul>
			</section>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcModal } from '@nextcloud/vue'
import TokenGrantEditor from '../../components/tokens/TokenGrantEditor.vue'

export default {
	name: 'ConsumerGrantModal',
	components: { NcModal, TokenGrantEditor },

	props: {
		/** The consumer whose grant is edited, as `/api/consumers` returns it. */
		consumer: { type: Object, required: true },
	},

	emits: ['close', 'saved'],

	data() {
		return { busy: false, error: '' }
	},

	computed: {
		configuration() {
			const configuration = this.consumer.authorizationConfiguration
			return configuration && typeof configuration === 'object' && !Array.isArray(configuration) ? configuration : {}
		},

		grant() {
			const grant = this.configuration.grant
			return grant && typeof grant === 'object' ? grant : null
		},

		renewals() {
			const renewals = this.configuration.grantRenewals
			return Array.isArray(renewals) ? [...renewals].reverse() : []
		},
	},

	methods: {
		t,
		/**
		 * Store the grant in the consumer's authorization configuration, keeping
		 * the rest of it; the server records a changed end date as a renewal.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
		 * @param {object} grant - the grant from the editor
		 * @return {Promise<void>}
		 */
		async save(grant) {
			await this.patch({ ...this.configuration, grant }, t('openregister', 'Grant saved'))
		},

		/**
		 * Take the grant off the consumer, keeping the rest of its configuration.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @return {Promise<void>}
		 */
		async remove() {
			const { grant, ...rest } = this.configuration
			await this.patch(rest, t('openregister', 'Grant removed'))
		},

		/**
		 * Send the consumer's new authorization configuration.
		 *
		 * @spec exclude UI plumbing — PATCH with the busy flag and the server's reason on failure.
		 * @param {object} authorizationConfiguration - the configuration to store
		 * @param {string} message - what to report on success
		 * @return {Promise<void>}
		 */
		async patch(authorizationConfiguration, message) {
			this.busy = true
			this.error = ''
			try {
				await axios.patch(generateUrl(`/apps/openregister/api/consumers/${this.consumer.id}`), { authorizationConfiguration })
				this.$emit('saved', message)
			} catch (e) {
				this.error = e?.response?.data?.error || t('openregister', 'Failed to save grant')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Format a date for the renewal list.
		 *
		 * @spec exclude UI plumbing — pure display formatter.
		 * @param {string|null} value - an ISO date
		 * @return {string} the localized date, or a dash
		 */
		formatDate(value) {
			return value ? new Date(value).toLocaleDateString() : '—'
		},
	},
}
</script>

<style scoped>
.consumer-grant-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.consumer-grant-modal__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error));
}

.consumer-grant-modal__renewals ul {
	padding-inline-start: 16px;
}
</style>
