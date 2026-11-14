<template>
	<SettingsSection
		id="federated-config-trust"
		:name="t('openregister', 'Shared configuration trust')"
		:description="
			t(
				'openregister',
				'Where shared configuration may be installed from, whose signed bundles this instance accepts, and who may publish or install it.',
			)
		">
		<div class="field-group">
			<NcTextField
				id="federated-trust-sources"
				v-model="trust.sourceAllowlist"
				:label="t('openregister', 'Allowed sources')"
				:disabled="loading || saving" />
			<p class="field-hint">
				{{
					t(
						'openregister',
						'GitHub owners or repositories, separated by commas. An owner covers all of its repositories. An empty list allows every source.',
					)
				}}
			</p>
		</div>

		<div class="field-group">
			<NcTextField
				id="federated-trust-keys"
				v-model="trust.trustedKeys"
				:label="t('openregister', 'Trusted publisher keys')"
				:disabled="loading || saving" />
			<p class="field-hint">
				{{
					t(
						'openregister',
						'Public keys of the instances whose bundles this instance accepts, separated by commas. With no key, every bundle is refused unless unsigned bundles are allowed.',
					)
				}}
			</p>
		</div>

		<div class="field-group">
			<NcCheckboxRadioSwitch
				v-model="trust.allowUnverifiedBundles"
				type="switch"
				:disabled="loading || saving">
				{{ t('openregister', 'Allow bundles that no trusted key signed') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="field-group">
			<NcTextField
				id="federated-trust-publish-groups"
				v-model="trust.publishGroups"
				:label="t('openregister', 'Groups that may publish')"
				:disabled="loading || saving" />
			<NcTextField
				id="federated-trust-install-groups"
				v-model="trust.installGroups"
				:label="t('openregister', 'Groups that may install')"
				:disabled="loading || saving" />
			<p class="field-hint">
				{{
					t(
						'openregister',
						'Group IDs, separated by commas. An empty list lets every signed-in user act. Administrators always may.',
					)
				}}
			</p>
		</div>

		<NcButton variant="primary" :disabled="loading || saving" @click="save">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
				<Save v-else :size="20" />
			</template>
			{{ t('openregister', 'Save') }}
		</NcButton>

		<div class="field-group public-key">
			<NcTextField
				id="federated-trust-public-key"
				:modelValue="publicKey"
				:label="t('openregister', 'Public key of this instance')"
				readonly />
			<p class="field-hint">
				{{
					t(
						'openregister',
						'Other organisations add this key to trust what this instance publishes.',
					)
				}}
			</p>
			<NcButton
				variant="secondary"
				:disabled="publicKey === ''"
				@click="copyPublicKey">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				{{ t('openregister', 'Copy to clipboard') }}
			</NcButton>
		</div>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

/** The comma-separated text settings, in the order they are saved. */
const TEXT_FIELDS = [
	'sourceAllowlist',
	'trustedKeys',
	'publishGroups',
	'installGroups',
]

/**
 * The trust configuration of federated configuration sharing.
 *
 * Reads and saves the source allowlist, the trusted publisher keys, the
 * unsigned-bundle allowance and the publish/install groups through the
 * federated-config trust API, and shows this instance's public key so another
 * organisation can trust it.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
export default {
	name: 'FederatedTrustConfiguration',
	components: {
		ContentCopy,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
		Save,
		SettingsSection,
	},

	data() {
		return {
			trust: {
				sourceAllowlist: '',
				trustedKeys: '',
				publishGroups: '',
				installGroups: '',
				allowUnverifiedBundles: false,
			},

			saved: null,
			publicKey: '',
			loading: false,
			saving: false,
		}
	},

	/**
	 * Load the trust configuration and the public key.
	 *
	 * @spec exclude UI plumbing: initial data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read the trust configuration and this instance's public key.
		 *
		 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const [trust, key] = await Promise.all([
					axios.get(
						generateUrl('/apps/openregister/api/federated-config/trust'),
					),
					axios.get(
						generateUrl(
							'/apps/openregister/api/federated-config/public-key',
						),
					),
				])
				this.apply(trust.data)
				this.publicKey = key.data?.publicKey || ''
			} catch {
				showError(
					this.t('openregister', 'Could not load the trust settings'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show a trust configuration as the server returned it.
		 *
		 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
		 * @param {object} data The server's trust configuration
		 * @return {void}
		 */
		apply(data) {
			const trust = {}
			for (const field of TEXT_FIELDS) {
				trust[field] = data?.[field] || ''
			}
			trust.allowUnverifiedBundles = data?.allowUnverifiedBundles === 'true'
			this.trust = trust
			this.saved = { ...trust }
		},

		/**
		 * The settings that differ from what was last loaded or saved, as the
		 * `{field, value}` bodies the trust API takes.
		 *
		 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
		 * @return {Array<{field: string, value: string}>} The updates to send
		 */
		changes() {
			const updates = []
			for (const field of TEXT_FIELDS) {
				const value = (this.trust[field] || '')
					.split(',')
					.map((entry) => entry.trim())
					.filter((entry) => entry !== '')
					.join(',')
				if (this.saved === null || value !== this.saved[field]) {
					updates.push({ field, value })
				}
			}
			const allow = this.trust.allowUnverifiedBundles === true
			if (this.saved === null || allow !== this.saved.allowUnverifiedBundles) {
				updates.push({
					field: 'allowUnverifiedBundles',
					value: allow ? 'true' : '',
				})
			}
			return updates
		},

		/**
		 * Save every changed setting, one at a time, and show what the server
		 * holds afterwards.
		 *
		 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				let last = null
				for (const update of this.changes()) {
					last = await axios.put(
						generateUrl('/apps/openregister/api/federated-config/trust'),
						update,
					)
				}
				if (last !== null) {
					this.apply(last.data)
				}
				showSuccess(this.t('openregister', 'Trust settings saved'))
			} catch {
				showError(
					this.t('openregister', 'Could not save the trust settings'),
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Copy this instance's public key.
		 *
		 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
		 * @return {Promise<void>}
		 */
		async copyPublicKey() {
			try {
				await navigator.clipboard.writeText(this.publicKey)
				showSuccess(this.t('openregister', 'Copied'))
			} catch {
				showError(this.t('openregister', 'Failed to copy to clipboard'))
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

.public-key {
	margin-top: 24px;
}

.field-hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}
</style>
