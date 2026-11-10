<template>
	<div class="section">
		<h2>{{ t('openregister', 'Avatar') }}</h2>
		<div v-if="!canChangeAvatar" class="section__disabled">
			{{
				t(
					'openregister',
					'Avatar changes are not supported by your authentication provider.',
				)
			}}
		</div>
		<div v-else class="avatar-section">
			<NcAvatar :user="userId" :size="128" :showUserStatus="false" />
			<div class="avatar-section__actions">
				<NcButton variant="primary" @click="triggerUpload">
					{{ t('openregister', 'Upload new avatar') }}
				</NcButton>
				<NcButton variant="error" @click="deleteAvatar">
					{{ t('openregister', 'Remove avatar') }}
				</NcButton>
				<input
					ref="fileInput"
					type="file"
					accept="image/jpeg,image/png,image/gif,image/webp"
					style="display: none"
					@change="uploadAvatar" />
			</div>
			<SectionStatus :message="message" :isError="isError" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar, NcButton } from '@nextcloud/vue'
import SectionStatus from '../../../components/shared/SectionStatus.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'
import { fetchSignedInUser } from './signedInUser.js'

export default {
	name: 'AvatarSection',
	components: { NcAvatar, NcButton, SectionStatus },

	mixins: [sectionStatus],

	data() {
		return {
			userId: '',
			canChangeAvatar: true,
		}
	},

	/**
	 * Lifecycle hook: load the current user's id and avatar capability for display.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch for display only
	 * @return {Promise<void>}
	 */
	async mounted() {
		try {
			const { data } = await fetchSignedInUser()
			this.userId = data?.uid || ''
			this.canChangeAvatar = data?.backendCapabilities?.avatar ?? true
		} catch {
			// Default to showing the section.
		}
	},

	methods: {
		t,
		/**
		 * Open the native file picker by programmatically clicking the hidden
		 * file input. Avatar upload itself runs from the input's `change` handler
		 * (`uploadAvatar`), not from this method.
		 *
		 * @spec openspec/specs/account-self-service/spec.md
		 * @return {void}
		 */
		triggerUpload() {
			this.$refs.fileInput.click()
		},

		/**
		 * Upload the selected image as the user's avatar via the account API.
		 *
		 * @param {Event} event The file input change event.
		 * @spec exclude UI plumbing — delegates to the account avatar API
		 * @return {Promise<void>}
		 */
		async uploadAvatar(event) {
			const file = event.target.files[0]
			if (!file) return
			this.message = ''
			try {
				const data = await file.arrayBuffer()
				await axios.post(
					generateUrl('/apps/openregister/api/user/me/avatar'),
					data,
					{ headers: { 'Content-Type': file.type } },
				)
				this.setStatus(t('openregister', 'Avatar updated successfully'))
			} catch (e) {
				this.setStatusError(e, t('openregister', 'Failed to upload avatar'))
			}
		},

		/**
		 * Remove the user's avatar via the account API.
		 *
		 * @spec exclude UI plumbing — delegates to the account avatar API
		 * @return {Promise<void>}
		 */
		async deleteAvatar() {
			this.message = ''
			try {
				await axios.delete(
					generateUrl('/apps/openregister/api/user/me/avatar'),
				)
				this.setStatus(t('openregister', 'Avatar removed'))
			} catch (e) {
				this.setStatusError(e, t('openregister', 'Failed to remove avatar'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/views.scss' as *;

@include section;

@include section-disabled-rules;

.avatar-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
	align-items: flex-start;
}

.avatar-section__actions {
	display: flex;
	gap: 8px;
}
</style>
