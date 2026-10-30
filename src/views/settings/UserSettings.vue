<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The sections of OpenRegister's user settings dialog (the NcAppSettingsDialog
  - CnAppRoot hosts; Personal settings in the settings foldout opens it).
  -
  - Every per-person setting lives here. OpenRegister no longer adds a form to
  - Nextcloud's personal settings (Ruben, 11 Oct 2026: user configuration goes
  - into the user settings dialog, app configuration into the admin settings).
  - The three sections that form carried are below: browser notifications, the
  - credential wallet and connected accounts.
  -
  - The wallet keeps the whole broker surface (no appId filter): it is the
  - person's own wallet, and a credential reaches apps through its allowedApps
  - list, not through the page that created it.
  -
  - THE LIBRARY'S OWN SECTIONS ARE REPEATED HERE ON PURPOSE. Filling CnAppRoot's
  - #user-settings slot replaces its fallback content entirely, and nothing
  - reports that: the notifications pane and the walkthrough replay would have
  - vanished from the dialog in silence. App.vue hands in the walkthrough state
  - CnAppRoot owns.
  -->
<template>
	<!-- Order follows board OrPersoonlijkeInstellingen: notices (the library's
	     pane, then browser notifications), connected accounts, credentials. -->
	<CnNotificationPreferences />

	<BrowserNotificationsSection />

	<OAuth2ConnectionsSection />

	<!-- The library's own label, as CnAppRoot draws it, so no new app key. -->
	<NcAppSettingsSection id="credentials" :name="t('nextcloud-vue', 'Credentials')">
		<CnCredentials
			scope="personal"
			:appCredentials="(manifest && manifest.credentials) || []" />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		v-if="walkthrough"
		id="cn-walkthrough"
		:name="walkthrough.sectionName">
		<p class="openregister-user-settings__hint">
			{{ walkthrough.hint }}
		</p>
		<div class="openregister-user-settings__actions">
			<NcButton
				v-if="walkthrough.inProgress"
				variant="primary"
				data-testid="cn-walkthrough-continue"
				@click="$emit('continueWalkthrough')">
				{{ walkthrough.continueLabel }}
			</NcButton>
			<NcButton
				variant="secondary"
				data-testid="cn-walkthrough-restart"
				@click="$emit('restartWalkthrough')">
				{{ walkthrough.restartLabel }}
			</NcButton>
		</div>
	</NcAppSettingsSection>
</template>

<script>
import { CnCredentials, CnNotificationPreferences } from '@conduction/nextcloud-vue'
import { NcAppSettingsSection, NcButton } from '@nextcloud/vue'
import BrowserNotificationsSection from '../../components/userSettings/BrowserNotificationsSection.vue'
import OAuth2ConnectionsSection from '../../components/userSettings/OAuth2ConnectionsSection.vue'

export default {
	name: 'UserSettings',

	components: {
		BrowserNotificationsSection,
		CnCredentials,
		CnNotificationPreferences,
		NcAppSettingsSection,
		NcButton,
		OAuth2ConnectionsSection,
	},

	props: {
		/**
		 * The app manifest, for the credentials it declares.
		 */
		manifest: {
			type: Object,
			default: null,
		},

		/**
		 * The walkthrough replay CnAppRoot would have drawn, or null when the
		 * app declares no tour. Labels come from CnAppRoot so the wording stays
		 * the library's.
		 *
		 * @type {{sectionName: string, hint: string, inProgress: boolean, continueLabel: string, restartLabel: string}|null}
		 */
		walkthrough: {
			type: Object,
			default: null,
		},
	},

	emits: ['continueWalkthrough', 'restartWalkthrough'],
}
</script>

<style scoped>
.openregister-user-settings__hint {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	color: var(--color-text-maxcontrast);
}

.openregister-user-settings__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
