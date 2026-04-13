<template>
	<div class="section">
		<h2>{{ t('openregister', 'Account') }}</h2>

		<div v-if="status === 'pending'" class="account-section__pending">
			<p>{{ t('openregister', 'A deactivation request is pending.') }}</p>
			<p v-if="requestedAt">
				{{ t('openregister', 'Requested at') }}: {{ formatDate(requestedAt) }}
			</p>
			<NcButton type="warning" @click="cancelDeactivation">
				{{ t('openregister', 'Cancel deactivation request') }}
			</NcButton>
		</div>

		<div v-else class="account-section__active">
			<p>{{ t('openregister', 'Request account deactivation. This will notify administrators for review.') }}</p>
			<NcButton type="error" @click="showConfirmModal = true">
				{{ t('openregister', 'Request account deactivation') }}
			</NcButton>
		</div>

		<NcModal v-if="showConfirmModal" @close="showConfirmModal = false">
			<div class="account-section__modal">
				<h3>{{ t('openregister', 'Confirm Account Deactivation') }}</h3>
				<p>{{ t('openregister', 'This action will submit a deactivation request to your administrators.') }}</p>
				<div class="section__field">
					<label for="deactivation-reason">{{ t('openregister', 'Reason (optional)') }}</label>
					<NcTextField id="deactivation-reason"
						v-model="reason"
						:label="t('openregister', 'Reason')" />
				</div>
				<div class="section__field">
					<label for="confirm-username">
						{{ t('openregister', 'Type your username to confirm') }}: <strong>{{ username }}</strong>
					</label>
					<NcTextField id="confirm-username"
						v-model="confirmUsername"
						:label="t('openregister', 'Username')" />
				</div>
				<NcButton type="error"
					:disabled="confirmUsername !== username"
					@click="requestDeactivation">
					{{ t('openregister', 'Confirm deactivation') }}
				</NcButton>
			</div>
		</NcModal>

		<p v-if="message" :class="{ 'section__error': isError, 'section__success': !isError }">
			{{ message }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

export default {
	name: 'AccountSection',
	components: { NcButton, NcModal, NcTextField },
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
	async mounted() {
		try {
			const [userRes, statusRes] = await Promise.all([
				axios.get(generateUrl('/apps/openregister/api/user/me')),
				axios.get(generateUrl('/apps/openregister/api/user/me/deactivation-status')),
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
				this.message = e.response?.data?.error || t('openregister', 'Failed to request deactivation')
				this.isError = true
			}
		},
		async cancelDeactivation() {
			try {
				await axios.delete(generateUrl('/apps/openregister/api/user/me/deactivate'))
				this.status = 'active'
				this.requestedAt = null
				this.message = t('openregister', 'Deactivation request cancelled')
				this.isError = false
			} catch (e) {
				this.message = e.response?.data?.error || t('openregister', 'Failed to cancel deactivation')
				this.isError = true
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString()
		},
	},
}
</script>

<style scoped>
.section { margin-bottom: 32px; padding: 16px; border-bottom: 1px solid var(--color-border); }
.section__field { margin-bottom: 12px; }
.section__field label { display: block; margin-bottom: 4px; font-weight: bold; }
.section__error { color: var(--color-error); margin-top: 8px; }
.section__success { color: var(--color-success); margin-top: 8px; }
.account-section__pending { background: var(--color-warning-background, #fff3cd); padding: 16px; border-radius: 8px; margin-bottom: 16px; }
.account-section__modal { padding: 24px; }
</style>
