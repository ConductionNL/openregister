<template>
	<SettingsSection
		id="edepot-host-key"
		:name="t('openregister', 'e-Depot SFTP host key')"
		:description="
			t(
				'openregister',
				'An SFTP transfer to the e-Depot only connects to a server whose host key matches this fingerprint.',
			)
		">
		<div class="field-group">
			<NcTextField
				id="edepot-host-key-fingerprint"
				v-model="fingerprint"
				:label="t('openregister', 'Host key fingerprint')"
				placeholder="SHA256:..."
				:disabled="loading || saving" />
			<p class="field-hint">
				{{
					t(
						'openregister',
						'Use the SHA256 fingerprint the depot operator gives you, as ssh-keygen -lf prints it. Without one, SFTP transfers stop and the connection test names the key the server presented.',
					)
				}}
			</p>
		</div>

		<NcButton
			variant="primary"
			:disabled="loading || saving"
			@click="save">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
				<Save v-else :size="20" />
			</template>
			{{ t('openregister', 'Save') }}
		</NcButton>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

/**
 * The pinned host key fingerprint of the e-Depot SFTP server.
 *
 * Reads and saves the one setting through the e-Depot settings API.
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */
export default {
	name: 'EdepotHostKeyConfiguration',
	components: { NcButton, NcLoadingIcon, NcTextField, Save, SettingsSection },

	data() {
		return {
			fingerprint: '',
			loading: false,
			saving: false,
		}
	},

	/**
	 * Load the saved fingerprint.
	 *
	 * @spec exclude UI plumbing: initial data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read the saved fingerprint from the e-Depot settings.
		 *
		 * @spec openspec/specs/edepot-transfer/spec.md
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/settings/edepot'),
				)
				this.fingerprint = response.data?.hostKeyFingerprint || ''
			} catch {
				showError(
					this.t('openregister', 'Could not load the e-Depot host key'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the fingerprint, trimmed, and nothing else.
		 *
		 * @spec openspec/specs/edepot-transfer/spec.md
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				const fingerprint = this.fingerprint.trim()
				await axios.patch(
					generateUrl('/apps/openregister/api/settings/edepot'),
					{ hostKeyFingerprint: fingerprint },
				)
				this.fingerprint = fingerprint
				showSuccess(this.t('openregister', 'e-Depot host key saved'))
			} catch {
				showError(
					this.t('openregister', 'Could not save the e-Depot host key'),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use './sectionStyles.scss' as *;

.field-group {
	margin-bottom: 16px;
}

.field-hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}
</style>
