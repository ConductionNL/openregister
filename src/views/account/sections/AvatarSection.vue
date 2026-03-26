<template>
	<div class="section">
		<h2>{{ t('openregister', 'Avatar') }}</h2>
		<div v-if="!canChangeAvatar" class="section__disabled">
			{{ t('openregister', 'Avatar changes are not supported by your authentication provider.') }}
		</div>
		<div v-else class="avatar-section">
			<NcAvatar :user="userId" :size="128" :show-user-status="false" />
			<div class="avatar-section__actions">
				<NcButton type="primary" @click="triggerUpload">
					{{ t('openregister', 'Upload new avatar') }}
				</NcButton>
				<NcButton type="error" @click="deleteAvatar">
					{{ t('openregister', 'Remove avatar') }}
				</NcButton>
				<input ref="fileInput"
					type="file"
					accept="image/jpeg,image/png,image/gif,image/webp"
					style="display: none;"
					@change="uploadAvatar">
			</div>
			<p v-if="message" :class="{ 'section__error': isError, 'section__success': !isError }">
				{{ message }}
			</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
	name: 'AvatarSection',
	components: { NcAvatar, NcButton },
	data() {
		return {
			userId: '',
			canChangeAvatar: true,
			message: '',
			isError: false,
		}
	},
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/openregister/api/user/me'))
			this.userId = data?.uid || ''
			this.canChangeAvatar = data?.backendCapabilities?.avatar ?? true
		} catch (e) {
			// Default to showing the section.
		}
	},
	methods: {
		t,
		triggerUpload() {
			this.$refs.fileInput.click()
		},
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
				this.message = t('openregister', 'Avatar updated successfully')
				this.isError = false
			} catch (e) {
				this.message = e.response?.data?.error || t('openregister', 'Failed to upload avatar')
				this.isError = true
			}
		},
		async deleteAvatar() {
			this.message = ''
			try {
				await axios.delete(generateUrl('/apps/openregister/api/user/me/avatar'))
				this.message = t('openregister', 'Avatar removed')
				this.isError = false
			} catch (e) {
				this.message = e.response?.data?.error || t('openregister', 'Failed to remove avatar')
				this.isError = true
			}
		},
	},
}
</script>

<style scoped>
.section { margin-bottom: 32px; padding: 16px; border-bottom: 1px solid var(--color-border); }
.section__disabled { color: var(--color-text-maxcontrast); font-style: italic; }
.section__error { color: var(--color-error); margin-top: 8px; }
.section__success { color: var(--color-success); margin-top: 8px; }
.avatar-section { display: flex; flex-direction: column; gap: 16px; align-items: flex-start; }
.avatar-section__actions { display: flex; gap: 8px; }
</style>
