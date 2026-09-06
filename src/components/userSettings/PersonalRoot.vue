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
  -
  - `OAuth2ConnectionsSection` sits BESIDE it rather than inside it. `CnCredentials`
  - lives in @conduction/nextcloud-vue, a different repository, and connecting an
  - account is broker behaviour whose status vocabulary belongs to this server; adding
  - it to the library first would mean shipping a library release before the server
  - that backs it exists.
  -->
<template>
	<div class="openregister-personal">
		<BrowserNotificationsSection />

		<CnCredentials />

		<OAuth2ConnectionsSection />
	</div>
</template>

<script>
import { CnCredentials } from '@conduction/nextcloud-vue'
import BrowserNotificationsSection from './BrowserNotificationsSection.vue'
import OAuth2ConnectionsSection from './OAuth2ConnectionsSection.vue'

export default {
	name: 'PersonalRoot',
	components: {
		BrowserNotificationsSection,
		CnCredentials,
		OAuth2ConnectionsSection,
	},
}
</script>

<style scoped>
.openregister-personal {
	display: flex;
	flex-direction: column;
}
</style>
