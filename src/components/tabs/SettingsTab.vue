<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<AppTab active>
		<template #title>
			<Cog :size="16" />
			<span>{{ t('openregister', 'Settings') }}</span>
		</template>
		<slot />
	</AppTab>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Cog from 'vue-material-design-icons/Cog.vue'
import AppTab from './AppTab.vue'

/**
 * The active "Settings" tab that opens an edit dialog's tab strip.
 *
 * @spec exclude presentational tab wrapper; the edit-dialog contracts are owned by each dialog.
 */
export default {
	name: 'SettingsTab',
	components: {
		AppTab,
		Cog,
	},

	methods: {
		t,
	},
}
</script>
