<template>
	<div class="section">
		<h2>{{ t('openregister', 'Password') }}</h2>
		<div v-if="!canChangePassword" class="section__disabled">
			{{ t('openregister', 'Password changes are not supported by your authentication provider.') }}
		</div>
		<form v-else @submit.prevent="changePassword">
			<div class="section__field">
				<label for="current-password">{{ t('openregister', 'Current password') }}</label>
				<NcTextField id="current-password"
					v-model="currentPassword"
					type="password"
					:label="t('openregister', 'Current password')"
					:disabled="loading" />
			</div>
			<div class="section__field">
				<label for="new-password">{{ t('openregister', 'New password') }}</label>
				<NcTextField id="new-password"
					v-model="newPassword"
					type="password"
					:label="t('openregister', 'New password')"
					:disabled="loading" />
			</div>
			<NcButton :disabled="loading || !currentPassword || !newPassword"
				type="primary"
				native-type="submit">
				{{ t('openregister', 'Change password') }}
			</NcButton>
			<p v-if="message" :class="{ 'section__error': isError, 'section__success': !isError }">
				{{ message }}
			</p>
		</form>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

export default {
	name: 'PasswordSection',
	components: { NcButton, NcTextField },
	data() {
		return {
			currentPassword: '',
			newPassword: '',
			loading: false,
			message: '',
			isError: false,
			canChangePassword: true,
		}
	},
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/openregister/api/user/me'))
			this.canChangePassword = data?.backendCapabilities?.password ?? true
		} catch (e) {
			// Default to showing the form.
		}
	},
	methods: {
		t,
		async changePassword() {
			this.loading = true
			this.message = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/openregister/api/user/me/password'),
					{ currentPassword: this.currentPassword, newPassword: this.newPassword },
				)
				this.message = data.message || t('openregister', 'Password updated successfully')
				this.isError = false
				this.currentPassword = ''
				this.newPassword = ''
			} catch (e) {
				this.message = e.response?.data?.error || t('openregister', 'Failed to change password')
				this.isError = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.section { margin-bottom: 32px; padding: 16px; border-bottom: 1px solid var(--color-border); }
.section__field { margin-bottom: 12px; }
.section__field label { display: block; margin-bottom: 4px; font-weight: bold; }
.section__disabled { color: var(--color-text-maxcontrast); font-style: italic; }
.section__error { color: var(--color-error); margin-top: 8px; }
.section__success { color: var(--color-success); margin-top: 8px; }
</style>
