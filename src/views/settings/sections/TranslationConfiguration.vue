<!--
  AI translation: whether record fields are translated through Nextcloud's
  Task Processing, which provider that is and whether it runs on this server,
  and the administrator's confirmation that record text is sent to it. Until
  Task Processing is chosen and confirmed, the identity provider stays bound.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
-->
<template>
	<SettingsSection
		id="translation"
		:name="t('openregister', 'Translation')"
		:description="t('openregister', 'Translate record fields with the provider Nextcloud\'s Task Processing offers. Nothing is sent until you choose it and confirm.')">
		<NcCheckboxRadioSwitch
			v-model="useTaskProcessing"
			type="switch"
			:disabled="loading || saving">
			{{ t('openregister', 'Use Nextcloud Task Processing for translation') }}
		</NcCheckboxRadioSwitch>

		<template v-if="useTaskProcessing">
			<ul v-if="providers.length > 0" class="translation-providers">
				<li v-for="provider in providers" :key="provider.taskType" class="translation-providers__item">
					{{ providerLine(provider) }}
				</li>
			</ul>
			<p v-else class="field-hint">
				{{ t('openregister', 'Nextcloud reports no Task Processing provider for translation.') }}
			</p>

			<NcCheckboxRadioSwitch
				v-model="allowExternal"
				:disabled="loading || saving">
				{{ t('openregister', 'Record text is sent to this provider to be translated. I confirm this.') }}
			</NcCheckboxRadioSwitch>
		</template>

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
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

/**
 * The translation provider choice and the confirmation that record text is sent.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
 */
export default {
	name: 'TranslationConfiguration',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, Save, SettingsSection },

	data() {
		return {
			useTaskProcessing: false,
			allowExternal: false,
			providers: [],
			loading: false,
			saving: false,
		}
	},

	/**
	 * Load the stored choice.
	 *
	 * @spec exclude UI plumbing: initial data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read the stored choice and the providers Nextcloud reports.
		 *
		 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/translation'))
				this.apply(response.data)
			} catch (error) {
				showError(this.t('openregister', 'Failed to load settings: {error}', { error: error?.message || '' }))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show what the server answered.
		 *
		 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
		 * @param {object} data The stored settings and the providers.
		 * @return {void}
		 */
		apply(data) {
			this.useTaskProcessing = data?.provider === 'taskprocessing'
			this.allowExternal = data?.allowExternal === true
			this.providers = Array.isArray(data?.providers) ? data.providers : []
		},

		/**
		 * The line naming a provider and where it runs.
		 *
		 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
		 * @param {{name: string, local: boolean}} provider The provider.
		 * @return {string} The line.
		 */
		providerLine(provider) {
			return provider.local === true
				? this.t('openregister', '{name} runs on this server', { name: provider.name })
				: this.t('openregister', '{name} runs outside this server', { name: provider.name })
		},

		/**
		 * Save the choice; the confirmation is sent only with Task Processing.
		 *
		 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				const response = await axios.put(generateUrl('/apps/openregister/api/settings/translation'), {
					provider: this.useTaskProcessing ? 'taskprocessing' : 'identity',
					allowExternal: this.useTaskProcessing && this.allowExternal,
				})
				this.apply(response.data)
				showSuccess(this.t('openregister', 'Translation settings saved successfully'))
			} catch {
				showError(this.t('openregister', 'Failed to save settings'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use './sectionStyles.scss' as *;

.translation-providers {
	margin: 8px 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.field-hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 8px;
}
</style>
