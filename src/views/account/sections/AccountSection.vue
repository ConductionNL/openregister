<template>
	<div class="section">
		<h2>{{ t('openregister', 'Account') }}</h2>

		<div v-if="status === 'pending'" class="account-section__pending">
			<p>{{ t('openregister', 'A deactivation request is pending.') }}</p>
			<p v-if="requestedAt">
				{{ t('openregister', 'Requested at') }}:
				{{ formatDate(requestedAt) }}
			</p>
			<NcButton variant="warning" @click="cancelDeactivation">
				{{ t('openregister', 'Cancel deactivation request') }}
			</NcButton>
		</div>

		<div v-else class="account-section__active">
			<p>
				{{
					t(
						'openregister',
						'Request account deactivation. This will notify administrators for review.',
					)
				}}
			</p>
			<NcButton variant="error" @click="showConfirmModal = true">
				{{ t('openregister', 'Request account deactivation') }}
			</NcButton>
		</div>

		<ConfirmDeactivationModal
			v-if="showConfirmModal"
			:username="username"
			:reason="reason"
			:confirmUsername="confirmUsername"
			@close="showConfirmModal = false"
			@confirm="requestDeactivation"
			@update:reason="reason = $event"
			@update:confirmUsername="confirmUsername = $event" />

		<p
			v-if="message"
			:class="{ section__error: isError, section__success: !isError }">
			{{ message }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import ConfirmDeactivationModal from '../../../modals/account/ConfirmDeactivationModal.vue'

export default {
	name: 'AccountSection',
	components: { NcButton, ConfirmDeactivationModal },
	data() {
		return {
			status: 'active',
			requestedAt: null,
			username: '',
			showConfirmModal: false,
			reason: '',
			confirmUsername: '',
			message: '',
			isError: false,
		}
	},

	/**
	 * Prime username + deactivation status on mount.
	 *
	 * @spec exclude UI plumbing — lifecycle hook hydrating local display state; account self-service contract owned by account-self-service.
	 * @return {Promise<void>}
	 */
	async mounted() {
		try {
			const [userRes, statusRes] = await Promise.all([
				axios.get(generateUrl('/apps/openregister/api/user/me')),
				axios.get(
					generateUrl(
						'/apps/openregister/api/user/me/deactivation-status',
					),
				),
			])
			this.username = userRes.data?.uid || ''
			this.status = statusRes.data?.status || 'active'
			this.requestedAt = statusRes.data?.pendingRequest?.requestedAt || null
		} catch (e) {
			// Default to active.
		}
	},

	methods: {
		t,
		/**
		 * Submit a deactivation request for the signed-in user. Soft state change —
		 * does not end the current session; an admin must approve before any account
		 * effect.
		 *
		 * @spec openspec/specs/account-self-service/spec.md
		 * @return {Promise<void>}
		 */
		async requestDeactivation() {
			try {
				await axios.post(
					generateUrl('/apps/openregister/api/user/me/deactivate'),
					{ reason: this.reason },
				)
				this.status = 'pending'
				this.requestedAt = new Date().toISOString()
				this.showConfirmModal = false
				this.message = t('openregister', 'Deactivation request submitted')
				this.isError = false
			} catch (e) {
				this.message =
					e.response?.data?.error
					|| t('openregister', 'Failed to request deactivation')
				this.isError = true
			}
		},

		/**
		 * Cancel a pending deactivation request.
		 *
		 * @spec exclude UI plumbing — inverse of the requestDeactivation contract (account-self-service); thin DELETE + local state reset.
		 * @return {Promise<void>}
		 */
		async cancelDeactivation() {
			try {
				await axios.delete(
					generateUrl('/apps/openregister/api/user/me/deactivate'),
				)
				this.status = 'active'
				this.requestedAt = null
				this.message = t('openregister', 'Deactivation request cancelled')
				this.isError = false
			} catch (e) {
				this.message =
					e.response?.data?.error
					|| t('openregister', 'Failed to cancel deactivation')
				this.isError = true
			}
		},

		/**
		 * Format a date string for display.
		 *
		 * @spec exclude UI plumbing — pure display formatter, no observable contract.
		 * @param {string} dateStr - ISO date string
		 * @return {string} localized date/time
		 */
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString()
		},
	},
}
</script>

<style scoped>
.section {
	margin-bottom: 32px;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.section__field {
	margin-bottom: 12px;
}

.section__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.section__error {
	color: var(--color-error);
	margin-top: 8px;
}

.section__success {
	color: var(--color-success);
	margin-top: 8px;
}

.account-section__pending {
	background: var(--color-warning-background, #fff3cd);
	padding: 16px;
	border-radius: 8px;
	margin-bottom: 16px;
}
</style>
