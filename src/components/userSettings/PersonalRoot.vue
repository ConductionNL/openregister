<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PersonalRoot — OpenRegister's "Additional settings" personal page.
  -
  - Hosts the browser Web-Push opt-in and the credential-broker section.
  -
  - The broker shipped without any UI: `CnCredentials` existed in the library and the
  - REST surface existed on the server, but nothing mounted the component, so the only
  - way to create a credential was to hand-craft a POST. No administrator does that —
  - which is part of why every app in the fleet kept custody of its own secrets. This
  - is the missing seam between the two.
  -
  - The component is deliberately given the whole broker surface (no `appId` filter):
  - this is the user's own credential wallet, and a credential is granted to apps via
  - its `allowedApps` list, not by which page created it.
  -->
<template>
	<div class="openregister-personal">
		<BrowserNotificationsSection />

		<CnCredentials />
	</div>
</template>

<script>
import { CnCredentials } from '@conduction/nextcloud-vue'
import BrowserNotificationsSection from './BrowserNotificationsSection.vue'

export default {
	name: 'PersonalRoot',
	components: {
		BrowserNotificationsSection,
		CnCredentials,
	},
}
</script>

<style scoped>
.openregister-personal {
	display: flex;
	flex-direction: column;
}
</style>
