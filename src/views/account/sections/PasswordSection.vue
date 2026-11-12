<template>
	<div class="section">
		<h2>{{ t('openregister', 'Password') }}</h2>
		<div v-if="!canChangePassword" class="section__disabled">
			{{
				t(
					'openregister',
					'Password changes are not supported by your authentication provider.',
				)
			}}
		</div>
		<form v-else @submit.prevent="changePassword">
			<div class="section__field">
				<label for="current-password">{{
					t('openregister', 'Current password')
				}}</label>
				<NcTextField
					id="current-password"
					v-model="currentPassword"
					type="password"
					:label="t('openregister', 'Current password')"
					:disabled="loading" />
			</div>
			<div class="section__field">
				<label for="new-password">{{
					t('openregister', 'New password')
				}}</label>
				<NcTextField
					id="new-password"
					v-model="newPassword"
					type="password"
					:label="t('openregister', 'New password')"
					:disabled="loading" />
			</div>
			<NcButton
				:disabled="loading || !currentPassword || !newPassword"
				variant="primary"
				type="submit">
				{{ t('openregister', 'Change password') }}
			</NcButton>
			<SectionStatus :message="message" :isError="isError" />
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import SectionStatus from '../../../components/shared/SectionStatus.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'

export default {
	name: 'PasswordSection',
	components: { NcButton, NcTextField, SectionStatus },

	mixins: [sectionStatus],

	data() {
		return {
			currentPassword: '',
			newPassword: '',
			loading: false,
			canChangePassword: true,
		}
	},

	/**
	 * Detect whether the auth backend supports password changes.
	 *
	 * @spec exclude UI plumbing — lifecycle hook toggling form visibility; password change contract owned by account-self-service.
	 * @return {Promise<void>}
	 */
	async mounted() {
		try {
			const { data } = await axios.get(
				generateUrl('/apps/openregister/api/user/me'),
			)
			this.canChangePassword = data?.backendCapabilities?.password ?? true
		} catch {
			// Default to showing the form.
		}
	},

	methods: {
		t,
		/**
		 * Submit the current password + new password to the API and surface the result
		 * inline. Does not sign the user out of other sessions.
		 *
		 * @spec openspec/specs/account-self-service/spec.md
		 * @return {Promise<void>}
		 */
		async changePassword() {
			this.loading = true
			this.message = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/openregister/api/user/me/password'),
					{
						currentPassword: this.currentPassword,
						newPassword: this.newPassword,
					},
				)
				this.setStatus(
					data.message
						|| t('openregister', 'Password updated successfully'),
				)
				this.currentPassword = ''
				this.newPassword = ''
			} catch (e) {
				this.setStatusError(
					e,
					t('openregister', 'Failed to change password'),
				)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/common.scss' as *;
@use '../../../styles/views.scss' as *;

@include section;

.section__field {
	margin-bottom: 12px;
}

@include section-field-label;

@include section-disabled-rules;
</style>
