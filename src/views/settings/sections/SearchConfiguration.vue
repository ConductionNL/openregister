<!--
  Accent-insensitive object search: whether a search for "cafe" also finds
  "Café". ADMINISTRATOR setting, on by default where the database can fold
  accents; where it cannot, the switch is off and disabled and the reason the
  server gives is shown.

  @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<SettingsSection
		id="search-configuration"
		:name="t('openregister', 'Search Configuration')"
		:description="t('openregister', 'A search for cafe also finds Café, and reunie finds reünie.')">
		<NcLoadingIcon v-if="loading" :size="20" />
		<template v-else>
			<NcCheckboxRadioSwitch
				:modelValue="state.enabled"
				type="switch"
				:disabled="saving || !state.available"
				@update:modelValue="save">
				{{ t('openregister', 'Ignore accents when searching') }}
			</NcCheckboxRadioSwitch>
			<NcNoteCard v-if="!state.available" type="warning">
				{{ t('openregister', 'Unavailable') }}<template v-if="state.reason">
					: {{ state.reason }}
				</template>
			</NcNoteCard>
		</template>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

const ENDPOINT = '/apps/openregister/api/settings/search-backend'

export default {
	name: 'SearchConfiguration',

	components: {
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		SettingsSection,
	},

	data() {
		return {
			loading: true,
			saving: false,
			state: { enabled: false, available: false, reason: '' },
		}
	},

	async mounted() {
		try {
			const response = await axios.get(generateUrl(ENDPOINT))
			this.apply(response.data)
		} catch (error) {
			showError(this.t('openregister', 'Failed to load settings: {error}', { error: error?.message ?? '' }))
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Take the accent state from a search backend configuration.
		 *
		 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
		 * @param {object} config The configuration the server answered.
		 * @return {void}
		 */
		apply(config) {
			const accent = config?.accentInsensitive ?? {}
			this.state = {
				enabled: accent.enabled === true,
				available: accent.available === true,
				reason: typeof accent.reason === 'string' ? accent.reason : '',
			}
		},

		/**
		 * Switch accent folding on or off; a refusal shows the server's reason.
		 *
		 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
		 * @param {boolean} enabled Whether searches are to ignore accents.
		 * @return {Promise<void>}
		 */
		async save(enabled) {
			this.saving = true
			try {
				const response = await axios.patch(generateUrl(ENDPOINT), { accentInsensitive: enabled === true })
				this.apply(response.data)
				showSuccess(this.t('openregister', 'Configuration saved'))
			} catch (error) {
				showError(error?.response?.data?.error || this.t('openregister', 'Failed to save configuration.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
